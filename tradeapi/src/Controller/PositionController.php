<?php

/**
 *
 * Copyright (C) 2023 Highend Technologies LLC
 * This file is part of Zignaly OpenTradeApi.
 *
 * Zignaly OpenTradeApi is free software: you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * Zignaly OpenTradeApi is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Zignaly OpenTradeApi.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace Zignaly\Controller;

use Exception;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Zignaly\exchange\ExchangeFactory;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Positions\ClosedPositionsService;
use Zignaly\Process\DIContainer;

class PositionController
{
    /**
     * Accounting service
     *
     * @var \Accounting
     */
    private $Accounting;

    /**
     * Process memory cache.
     *
     * @var \Symfony\Component\Cache\Adapter\ArrayAdapter|null
     */
    private $arrayCache;

    /**
     * @var \Zignaly\exchange\BaseExchange
     */
    private $exchange;

    /**
     * Exchange calls controller.
     *
     * @var \ExchangeCalls
     */
    private $exchangeCalls;

    /** @var string $internalExchangeId */
    private $internalExchangeId;

    /** @var $positionId */
    private $positionId;

    /** @var $providerId */
    private $providerId;

    /** @var $exchangeInstances */
    private $exchangeInstances;
    /**
     * Monolog service.
     *
     * @var \Monolog
     */
    private $monolog;

    /**
     * PositionCacheGenerator service
     *
     * @var \PositionCacheGenerator
     */
    private $PositionCacheGenerator;

    /**
     * newPositionCCXT model
     *
     * @var \newPositionCCXT
     */
    private $newPositionCCXT;

    /**
     * ProviderFE model.
     *
     * @var \ProviderFE
     */
    private $providerModel;

    /**
     * User model.
     *
     * @var \UserFE
     */
    private $userModel;

    /**
     * User repository.
     *
     * @var \User
     */
    private $userRepository;

    /**
     * ClosedPositions Service.
     *
     * @var ClosedPositionsService
     */
    private $closedPositions;

    /**
     * @var \Zignaly\redis\ZignalyLastPriceRedisService
     */
    private $ZignalyLastPriceService;

    public function __construct()
    {
        $container = DIContainer::getContainer();
        if (!$container->has('monolog')) {
            $container->set('monolog', new Monolog('PositionController'));
        }
        $this->monolog = $container->get('monolog');
        $this->PositionCacheGenerator = $container->get('PositionCacheGenerator');
        $this->ZignalyLastPriceService = $container->get('lastPrice');
        $this->newPositionCCXT = $container->get('newPositionCCXT.model');
        $this->userModel = new \UserFE();
        $this->providerModel = new \ProviderFE();
        $this->Accounting = $container->get('accounting');
        $this->arrayCache = $container->get('arrayCache');
        $this->exchangeCalls = new \ExchangeCalls($this->monolog);
        $this->userRepository = new \User;
        $this->closedPositions = $container->get('closedPositionsService');

        $this->arrayCache->clear();
    }

    /**
     * Parse a position for returning in the copy-trader closed-position list view.
     *
     * @param BSONDocument $position
     * @param bool $fromProvider
     * @return bool|array
     */
    private function composeClosedPosition(BSONDocument $position, $fromProvider = false)
    {
        $allocatedBalance = $this->getAllocatedBalance($position);

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

        $buyPrice = is_object($position->accounting->buyAvgPrice) ? $position->accounting->buyAvgPrice->__toString() : $position->accounting->buyAvgPrice;
        $buyPrice = $buyPrice > 1 ? round($buyPrice, 2) : round($buyPrice, 8);
        $sellPrice = is_object($position->accounting->sellAvgPrice) ? $position->accounting->sellAvgPrice->__toString() : $position->accounting->sellAvgPrice;
        $sellPrice = $sellPrice > 1 ? round($sellPrice, 2) : round($sellPrice, 8);
        $entryAmount = is_object($position->accounting->buyTotalQty) ? round($position->accounting->buyTotalQty->__toString(), 8) : round($position->accounting->buyTotalQty, 8);
        $positionSize = (float)$exchangeHandler->calculatePositionSize(
            $positionMediator->getSymbol(),
            $entryAmount,
            $buyPrice
        );
        $netProfit = is_object($position->accounting->netProfit) ? (float)$position->accounting->netProfit->__toString() : $position->accounting->netProfit;
        if (empty($position->accounting->totalFees)) {
            $totalFees = 0;
        } else {
            $totalFees = is_object($position->accounting->totalFees) ? $position->accounting->totalFees->__toString() : $position->accounting->totalFees;
        }
        if (empty($position->accounting->fundingFees)) {
            $fundingFees = 0;
        } else {
            $fundingFees = is_object($position->accounting->fundingFees) ? $position->accounting->fundingFees->__toString() : $position->accounting->fundingFees;
        }
        $grossProfit = $netProfit + $totalFees - $fundingFees;

        $leverage = isset($position->leverage) && $position->leverage > 0 ? $position->leverage : 1;
        $realInvestment = $positionSize / $leverage;

        $publicInfo = [
            'positionId' => $position->_id->__toString(),
            'signalId' => empty($position->signal->signalId) ? '-' : $position->signal->signalId,
            'openDate' => strtotime(date('Y-m-d', $position->accounting->openingDate->__toString() / 1000)) * 1000,
            'closeDate' => strtotime(date('Y-m-d', $position->accounting->closingDate->__toString() / 1000)) * 1000,
            'base' => $position->signal->base,
            'quote' => $position->signal->quote,
            'returnFromAllocated' => $this->getProfitPercentage($allocatedBalance, $netProfit),
            'exchange' => !empty($position->exchange) ? $position->exchange->name : false,
            'status' => $position->status,
            'side' => !empty($position->side) ? $position->side : 'LONG',
            'leverage' => $leverage,
        ];

        $privateInfo = [
            'openDate' => $position->accounting->openingDate->__toString(),
            'closeDate' => $position->accounting->closingDate->__toString(),
            'buyPrice' => (float)$buyPrice,
            'sellPrice' => (float)$sellPrice,
            'amount' => $entryAmount,
            'positionSize' => $positionSize,
            'returnFromInvestment' => $this->getProfitPercentage($realInvestment, $netProfit),
            'netProfitPercentage' => $this->getProfitPercentage($realInvestment, $netProfit),
            'netProfit' => number_format($netProfit, 12, '.', ''),
            'fees' => (float)$totalFees * -1,
            'profitPercentage' => $this->getProfitPercentage($realInvestment, $grossProfit),
            'profit' => number_format($grossProfit, 12),
            'fundingFees' => (float)$fundingFees,
        ];

        $extraSymbols = $positionMediator->getExtraSymbolsAsArray(true);

        return $fromProvider ? array_merge($publicInfo, $extraSymbols) : array_merge($publicInfo, $privateInfo, $extraSymbols);
    }

    /**
     * Parse a position from the log.
     *
     * @param BSONDocument $position
     * @return bool|array
     */
    private function composeLogPosition(BSONDocument $position)
    {
        $allocatedBalance = $this->getAllocatedBalance($position);
        if ($allocatedBalance <= 0) {
            return false;
        }

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()
            ->getExchangeHandler();

        if (empty($position->avgBuyingPrice)) {
            $buyPrice = 0;
        } else {
            $buyPrice = is_object($position->avgBuyingPrice) ? $position->avgBuyingPrice->__toString() : $position->avgBuyingPrice;
        }
        $buyPrice = $buyPrice > 1 ? round($buyPrice, 2) : round($buyPrice, 8);
        $sellPrice = 0;
        if (empty($position->realAmount)) {
            $entryAmount = 0;
        } else {
            $entryAmount = is_object($position->realAmount) ? $position->realAmount->__toString() : $position->realAmount;
        }

        $positionSize = (float)$exchangeHandler->calculatePositionSize(
            $positionMediator->getSymbol(),
            $entryAmount,
            $buyPrice
        );

        return [
            'openDate' => $position->createdAt->__toString(),
            'closeDate' => !empty($position->closedAt) && is_object($position->closedAt) ? $position->closedAt->__toString() : 0,
            'base' => $position->signal->base,
            'quote' => $position->signal->quote,
            'buyPrice' => (float)$buyPrice,
            'sellPrice' => (float)$sellPrice,
            'side' => !empty($position->side) ? $position->side : 'LONG',
            'amount' => $entryAmount,
            'positionSize' => $positionSize,
            'returnFromInvestment' => 0,
            'returnFromAllocated' => 0,
            'exchange' => $position->exchange ? $position->exchange->name : false,
            'status' => $position->status,
            'leverage' => isset($position->leverage) ? $position->leverage : 1,
        ];
    }

    /**
     * Compose the real-time data for an open position.
     *
     * @param array $openPosition
     * @param string $userId
     * @return array
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function composeOpenPositionExtraFields(array $openPosition, string $userId)
    {
        $sellingPrice = (float)$this->getCurrentPrice($openPosition);
        list($unrealizedProfitLosses, $unrealizedProfitLossesPercentage, $priceDifference, $PnL, $PnLPercentage, $uPnL, $uPnLPercentage) =
            $this->Accounting->computeGrossProfitFromCachedOpenPosition($openPosition, $sellingPrice);
        $leverage = empty($openPosition['leverage']) ? 1 : $openPosition['leverage'];
        $unrealizedProfitLossesPercentage = round($leverage * $unrealizedProfitLossesPercentage, 2);
        $PnLPercentage = round($leverage * $PnLPercentage, 2);
        $uPnLPercentage = round($leverage * $uPnLPercentage, 2);

        $currentData = [
            'unrealizedProfitLosses' => $unrealizedProfitLosses, //Todo: To delete
            'unrealizedProfitLossesPercentage' => $unrealizedProfitLossesPercentage, //Todo: To delete
            'priceDifference' => $priceDifference,
            'multiData' => 'MULTI' === $openPosition['side'] ? $this->addPriceDifferenceToMultiData($openPosition, $sellingPrice) : [],
            'logoUrl' => $this->getProviderLogoUrl($openPosition['providerId'], $userId),
            'sellPrice' => $sellingPrice,
            'PnL' => $PnL,
            'PnLPercentage' => $PnLPercentage,
            'uPnL' => $uPnL,
            'uPnLPercentage' => $uPnLPercentage,
        ];

        unset($openPosition['exitedAmount']);
        unset($openPosition['grossProfitsFromExitAmount']);

        return array_merge($openPosition, $currentData);
    }

    /**
     * Compose multiData
     * @param \MongoDB\Model\BSONDocument $position
     * @return array
     */
    private function addPriceDifferenceToMultiData(array $openPosition, float $sellingPrice)
    {
        $longPrice = $openPosition['multiData']['long']['price'];
        $priceDifferenceLong = !empty($longPrice) ? round(($sellingPrice - $longPrice) * 100 / $longPrice, 2) : '';
        $shortPrice = $openPosition['multiData']['short']['price'];
        $priceDifferenceShort = !empty($shortPrice) ? round(($sellingPrice - $shortPrice) * 100 / $shortPrice, 2) : '';

        return [
            'long' => [
                'price' => $longPrice,
                'amount' => $openPosition['multiData']['long']['amount'],
                'priceDifference' => $priceDifferenceLong,
            ],
            'short' => [
                'price' => $shortPrice,
                'amount' => $openPosition['multiData']['short']['amount'],
                'priceDifference' => $priceDifferenceShort,

            ]
        ];
    }

    /**
     * Return the allocated balance for the given position.
     *
     * @param BSONDocument $position
     * @return float
     */
    private function getAllocatedBalance(BSONDocument $position)
    {
        if (isset($position->positionSize)) {
            if (is_object($position->positionSize)) {
                $positionSize = $position->positionSize->__toString();
            } else {
                $positionSize = $position->positionSize;
            }
        } else {
            return 0;
        }

        $positionSizePercentage = isset($position->signal->positionSizePercentage) ? $position->signal->positionSizePercentage : 0;

        $leverage = !empty($position->leverage) ? $position->leverage : 1;

        $investment = $positionSize / $leverage;

        return empty($investment) || empty($positionSizePercentage) ? 0.0 : (float)($investment * 100 / $positionSizePercentage);
    }

    /**
     * Get the current price for a given symbol, looking first in the cache.
     *
     * @param array $position
     * @return bool|mixed|string
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function getCurrentPrice(array $position)
    {
        try {
            // $symbol = $position['base'] . $position['quote'];
            $symbol = isset($position['pair']) ? $position['pair'] : $position['base'] . $position['quote'];

            $cache = $this->arrayCache->getItem($symbol);
            if ($cache->isHit()) {
                return $cache->get();
            }

            $exchangeId = ZignalyExchangeCodes::getExchangeFromCaseInsensitiveStringWithType(
                $position['exchange'],
                $position['exchangeType']?? 'spot'
            );
            
            $price = $this->ZignalyLastPriceService->lastPriceStrForSymbol(
                $exchangeId, // $this->exchange->getId(),
                $symbol
            );

            if (empty($price)) {
                return 0;
            }

            $cache->set($price);
            $this->arrayCache->save($cache);

            return $price;
        } catch (Exception $e) {
            $this->monolog->sendEntry('ERROR', 'Getting last price: ' . $e->getMessage(), $position);

            return 0;
        }
    }

    /**
     * Return the exchange internal id connected to a provider for a user or send a error response.
     *
     * @param string $userId
     * @return string
     */
    private function getInternalExchangeIdFromProviderUser(string $userId)
    {
        $user = $this->userModel->getUserById($userId);

        $providerId = $this->providerId;
        if (empty($user->provider) || empty($user->provider->$providerId)) {
            sendHttpResponse(['error' => ['code' => 18]]);
        }

        if (empty($user->provider->$providerId->exchangeInternalId)) {
            sendHttpResponse(['error' => ['code' => 18]]);
        }

        $internalExchangeId = $user->provider->$providerId->exchangeInternalId;

        $this->setExchange($user, $internalExchangeId);

        return $internalExchangeId;
    }

    /**
     * Get current open positions for a given user.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getOpenPositions(Request $request, $payload)
    {
        $user = $this->validateCommonConstraints($payload);

        return new JsonResponse($this->retrieveAndComposeOpenPositions($user->_id->__toString(), $this->internalExchangeId));
    }

    /**
     * Get sold positions for a given user.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getSoldPositions(Request $request, $payload)
    {
        $user = $this->validateCommonConstraints($payload);

        return new JsonResponse($this->retrieveAndComposeSoldPositions((string)$user->_id, $this->internalExchangeId));
    }

    /**
     * Get sold positions for a given user, new version.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getSoldPositions2(Request $request, $payload)
    {
        $user = $this->validateCommonConstraints($payload);

        $positions = $this->closedPositions->getClosedPositions((string)$user->_id, $this->internalExchangeId);
        if (!$positions) {
            $positions = $this->closedPositions->remapFields(
                $this->retrieveAndComposeSoldPositions((string)$user->_id, $this->internalExchangeId)
            );
        }

        return new JsonResponse(array_values($positions));
    }

    /**
     * Get current open positions for the public provider.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getOpenPositionsFromProvider(Request $request, $payload)
    {
        $user = $this->validateProviderConstraints($payload);
        $provider = $this->providerModel->getProvider($user->_id->__toString(), $this->providerId);
        if (isset($provider['error'])) {
            sendHttpResponse($provider);
        }

        $internalExchangeId = $this->getInternalExchangeIdFromProviderUser($provider->userId->__toString());

        return new JsonResponse($this->retrieveAndComposeOpenPositions($provider->userId->__toString(), $internalExchangeId, $provider, true));
    }

    /**
     * Get the given position for the FE.
     *
     * @param Request $request
     * @param $payload
     * @return JsonResponse
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getPosition(Request $request, $payload)
    {
        $user = $this->validateCommonConstraints($payload, true);
        if (!isset($payload['positionId']))
            sendHttpResponse(['error' => ['code' => 23]]);

        return new JsonResponse($this->retrieveAndComposePositionWithoutFactors($user, $this->positionId));
    }

    /**
     * Recover a closed position to active again.
     * @param Request $request
     * @param $payload
     * @return JsonResponse
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function recoverPosition(Request $request, $payload)
    {
        $user = $this->validateCommonConstraints($payload, true);

        $position = $this->newPositionCCXT->getPositionByIdForUserOrCopyTrader($user->_id, $this->positionId);
        if (!$position) {
            if (empty($position->createdAt)) {
                sendHttpResponse(['error' => ['code' => 19]]);
            }
        }

        $log = [];
        $log[] = [
            'date' => new \MongoDB\BSON\UTCDateTime(),
            'message' => "Position recovered from status {$position->status}",
        ];
        $pushLogs = empty($log) ? false : ['logs' => ['$each' => $log]];

        $setPosition = [
            'closed' => false,
            'status' => 9,
            'updating' => false,
            'accounted' => false,
            'sellPerformed' => false,
            'copyTraderStatsDone' => false,
            'locked' => false,
            'accounting' => false,
        ];

        $this->newPositionCCXT->setPosition($position->_id, $setPosition, true, $pushLogs);

        return new JsonResponse($this->retrieveAndComposePositionWithoutFactors($user, $this->positionId));
    }

    /**
     * Get the given position with all data in raw format.
     *
     * @param Request $request
     * @param $payload
     * @return JsonResponse
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getRawPosition(Request $request, $payload)
    {
        $user = $this->validateCommonConstraints($payload, true);
        if (!isset($payload['positionId']))
            sendHttpResponse(['error' => ['code' => 23]]);

        $position = $this->newPositionCCXT->getPositionByIdForUserOrCopyTrader($user->_id, $this->positionId);
        if (!$position) {
            if (empty($position->createdAt)) {
                sendHttpResponse(['error' => ['code' => 19]]);
            }
        }

        return new JsonResponse($position->getArrayCopy());
    }

    /**
     * Return the percentage between 2 numbers.
     * @param $fromNumber
     * @param $toNumer
     * @return float|int
     */
    private function getProfitPercentage($fromNumber, $toNumer)
    {
        if ($fromNumber == 0)
            return 0;

        $percentage = $toNumer * 100 / $fromNumber;

        //$percentageProfit = ($percentage > 100) ? $percentage - 100 : (100 - $percentage) * -1;

        return round($percentage, 2);
    }

    /**
     * @param string $providerId
     * @param string $userId
     * @return mixed|string
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function getProviderLogoUrl(string $providerId, string $userId)
    {
        try {
            $key = 'providerLogo_' . $providerId;
            $cache = $this->arrayCache->getItem($key);

            if ($cache->isHit()) {
                return $cache->get();
            }

            if ($providerId != 1) {
                $provider = $this->providerModel->getProvider($userId, $providerId);
                $logoUrl = empty($provider->logoUrl) ? '' : $provider->logoUrl;
            }

            if (empty($logoUrl)) {
                $logoUrl = 'images/providersLogo/default.png';
            }

            $cache->set($logoUrl);
            $this->arrayCache->save($cache);

            return $logoUrl;
        } catch (Exception $e) {
            $this->monolog->sendEntry('ERROR', "Getting logo url for $providerId: " . $e->getMessage());

            return 'images/providersLogo/default.png';
        }
    }

    /**
     * Get sold positions for the public provider.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getSoldPositionsFromProvider(Request $request, $payload)
    {
        $user = $this->validateProviderConstraints($payload);
        $provider = $this->providerModel->getProvider($user->_id->__toString(), $this->providerId);
        if (isset($provider['error'])) {
            sendHttpResponse($provider);
        }

        return new JsonResponse($this->retrieveAndComposeClosedPositionsFromProvider($provider->userId, $this->providerId));
    }

    /**
     * @param $payload
     * @return JsonResponse
     */
    public function transferMargin($payload): JsonResponse
    {
        $user = $this->validateCommonConstraints($payload, true);
        $amount = $payload['amount'] ?? null;

        if (empty($amount)) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        $position = $this->newPositionCCXT->getPositionByIdForUserOrCopyTrader($user->_id, $this->positionId);

        if (false === $position) {
            sendHttpResponse(['error' => ['code' => 19]]);
        }

        $exchange = $this->resolveUserExchangeConnection($user, $this->internalExchangeId);

        try {
            return new JsonResponse(['margin' => $exchange->transferMargin($position, $amount)]);
        } catch (\Exception $e) {
            sendHttpResponse(['error' => ['code' => '1041']]);
        }
    }

    /**
     * Retrieve and parse a position for the FE.
     *
     * @param BSONDocument $user
     * @param string $positionId
     * @return array
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function retrieveAndComposePositionWithoutFactors(BSONDocument $user, string $positionId)
    {
        $position = $this->newPositionCCXT->getPositionByIdForUserOrCopyTrader($user->_id, $positionId);

        if (empty($position->createdAt)) {
            return ['error' => ['code' => 19]];
        }

        $contractData = $this->getLiquidationPrice($position);
        $positionCacheFormat = $this->PositionCacheGenerator->composePositionForCache($position);

        if ($positionCacheFormat['internalExchangeId'] !== $this->internalExchangeId) {
            if (
                !empty($position->provider->userId) && $position->user->_id->__toString() != $position->provider->userId
                && $position->provider->userId == $user->_id->__toString()
            ) {
                $this->providerId = $position->provider->_id;
                $this->getInternalExchangeIdFromProviderUser($user->_id->__toString());
            } else {
                $this->setExchange($user, $positionCacheFormat['internalExchangeId']);
            }
        }

        if ($position->closed) {
            if (empty($position->accounting->closingDate)) {
                $additionalFields = $this->composeLogPosition($position);
                $additionalFields['type'] = 'log';
            } else {
                $additionalFields = $this->composeClosedPosition($position);
                $additionalFields['type'] = 'closed';
            }
        } else {
            $additionalFields = $this->composeOpenPositionExtraFields($positionCacheFormat, $user->_id->__toString());
            $additionalFields['type'] = 'open';
            unset($positionCacheFormat['exitedAmount']);
            unset($positionCacheFormat['grossProfitsFromExitAmount']);
            $additionalFields = array_merge($additionalFields, $contractData);
        }

        if (!isset($additionalFields['logoUrl'])) {
            $additionalFields['logoUrl'] = $this->getProviderLogoUrl($positionCacheFormat['providerId'], $user->_id);
        }

        if (!empty($position->avgBuyingPrice)) {
            $entryPrice = is_object($position->avgBuyingPrice) ? $position->avgBuyingPrice->__toString() : $position->avgBuyingPrice;
        } elseif (!empty($position->limitPrice)) {
            $entryPrice = is_object($position->limitPrice) ? $position->limitPrice->__toString() : $position->limitPrice;
        } else {
            $entryPrice = 0;
        }
        $additionalFields['reBuyTargets'] = empty($position->reBuyTargets) || !is_object($position->reBuyTargets)
            ? false : $this->parseReBuysTargets($position->reBuyTargets, (float) $entryPrice);

        $additionalFields['takeProfitTargets'] = empty($position->takeProfitTargets) || !is_object($position->takeProfitTargets)
            ? false : $this->parseTakeProfits($position->takeProfitTargets, (float) $entryPrice);

        $additionalFields['reduceOrders'] = empty($position->reduceOrders) || !is_object($position->reduceOrders)
            ? false : $this->parseReduceOrders($position->reduceOrders, $position->orders);

        //$additionalFields['stopLossPercentage'] = empty($position->stopLossPercentage) ? false : $position->stopLossPercentage * 100 - 100;
        //$additionalFields['stopLossPrice'] = empty($position->stopLossPercentage) ? 0 : $position->stopLossPercentage * $positionCacheFormat['buyPrice'];
        //$additionalFields['trailingStopTriggerPercentage'] = empty($position->trailingStopTriggerPercentage) ? false : $position->trailingStopTriggerPercentage * 100 - 100;
        //$additionalFields['trailingStopPercentage'] = empty($position->trailingStopPercentage) ? false : $position->trailingStopPercentage * 100 - 100;
        $additionalFields['closed'] = $position->closed;
        if (!empty($position->realPositionSize)) {
            $invested = is_object($position->realPositionSize) ? $position->realPositionSize->__toString() : $position->realPositionSize;
        } elseif (!empty($position->positionSize)) {
            $invested = is_object($position->positionSize) ? $position->positionSize->__toString() : $position->positionSize;
        } else {
            $invested = 0;
        }
        $additionalFields['invested'] = $invested;
        if (!empty($position->provider->clonedFrom)) {
            $additionalFields['providerOwnerUserId'] = false;
        } elseif (empty($position->provider->userId)) {
            $additionalFields['providerOwnerUserId'] = $position->user->_id->__toString();
        } else {
            $additionalFields['providerOwnerUserId'] = $position->provider->userId;
        }

        return array_merge($positionCacheFormat, $additionalFields);
    }

    /**
     * Get the liquidation price for the given position.
     * @param BSONDocument $position
     * @return array
     */
    private function getLiquidationPrice(BSONDocument $position)
    {
        $data = [];
        if ($position->closed || !empty($position->paperTrading) || empty($position->exchange->exchangeType) || 'futures' !== $position->exchange->exchangeType) {
            return $data;
        }

            $user = $this->userModel->getUserById($position->user->_id->__toString());
            $exchangeInternalId = $position->exchange->internalId;


        $userExchangeInstance = $user->_id->__toString() . ':' . $exchangeInternalId;
        if (!empty($this->exchangeInstances[$userExchangeInstance])) {
            $exchange = $this->exchangeInstances[$userExchangeInstance]['exchange'];
        } else {
            $exchange = $this->resolveUserExchangeConnection($user, $exchangeInternalId);
            $this->exchangeInstances[$userExchangeInstance]['exchange'] = $exchange;
        }
        try {
            $marketEncoder = BaseMarketEncoder::newInstance(strtolower($exchange->getId()));
            $positionSymbol = $marketEncoder->toCcxt($position->signal->pair);
            if (isset($this->exchangeInstances[$userExchangeInstance]['contract'])) {
                $contracts = $this->exchangeInstances[$userExchangeInstance]['contract'];
            } else {
                $contracts = $exchange->getPosition();
                $this->exchangeInstances[$userExchangeInstance]['contract'] = $contracts;
            }
            foreach ($contracts as $contract) {
                $symbol = $contract->getSymbol();
                if (empty($symbol) || $symbol !== $positionSymbol) {
                    continue;
                }
                if (empty((float)$contract->getAmount())) {
                    continue;
                }
                $contractSide = null === $contract->getSide() ? 'BOTH' : strtoupper($contract->getSide());
                if ('BOTH' !== $contractSide && $position->side !== $contractSide) {
                    continue;
                }

                $data = [
                    'liquidationPrice' => (float)$contract->getLiquidationPrice(),
                    'margin' => $contract->getMargin(),
                    'markPrice' => (float)$contract->getMarkPrice(),
                    'isolated' => $contract->isIsolated()
                ];
            }
        } catch (\Exception $e) {
            $this->monolog->sendEntry(
                'WARNING',
                sprintf(
                    "Get position request %s failed: %s",
                    $e->getMessage()
                )
            );
        }

        return $data;
    }

    /**
     * Find user connected exchange and resolve the concrete exchange instance.
     *
     * @param \MongoDB\Model\BSONDocument $user User object.
     *
     * @param string $exchangeInternalId User exchange connection internal ID.
     *
     * @return \Zignaly\exchange\BaseExchange
     */
    private function resolveUserExchangeConnection(BSONDocument $user, string $exchangeInternalId)
    {
        $exchangeInternalId = filter_var($exchangeInternalId, FILTER_SANITIZE_STRING);
        $exchangeConnection = $this->userRepository->getConnectedExchangeById($user, $exchangeInternalId);
        if (!$exchangeConnection) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        $userId = $user->_id->__toString();
        if (!isset($exchangeConnection->exchangeType)) {
            $exchangeConnection->exchangeType = 'spot';
        }
        $exchange = $this->exchangeCalls->useConcreteExchangeForConnection($userId, $exchangeConnection, $exchangeConnection->exchangeType);
        if (!$exchange) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        return $exchange;
    }


    /**
     * Get target error message
     *
     * @param object $target
     * 
     * @return boolean
     */
    private function getTargetErrorMessage(object $target)
    {
        if (!empty($target->cancel) || !empty($target->skipped)) {
            if (!empty($target->error->msg)) {
                return $target->error->msg;
            } elseif (!empty($target->cancel)) {
                return 'Canceled';
            } else {
                return 'Skipped';
            }
        } else {
            return false;
        }
    }

    /**
     * Get price priority
     *
     * @param object $target
     * 
     * @return string
     */
    private function getTargetPricePriority(object $target)
    {
        return $target->pricePriority ?? 'percentage';
    }

    /**
     * Return the reBuy targets in FE Format.
     * @param object $reBuyTargets
     * @param float $entryPrice
     * @return array
     */
    private function parseReBuysTargets(object $reBuyTargets, float $entryPrice)
    {
        $targets = [];
        foreach ($reBuyTargets as $reBuyTarget) {
            if (!empty($reBuyTarget->cancel) || !empty($reBuyTarget->skipped)) {
                if (!empty($reBuyTarget->error->msg)) {
                    $errorMSG = $reBuyTarget->error->msg;
                } elseif (!empty($reBuyTarget->cancel)) {
                    $errorMSG = "Canceled";
                } else {
                    $errorMSG = "Skipped";
                }
            } else {
                $errorMSG = false;
            }

            list($priceTarget, $triggerPercentage) =
                PositionMediator::getRebuysTargetPriceAndPercentage($reBuyTarget, $entryPrice);
            /*if (!empty($entryPrice) && !empty($reBuyTarget->pricePriority) && 'price' === $reBuyTarget->pricePriority && !empty($reBuyTarget->priceTarget)) {
                $triggerPercentage = $reBuyTarget->priceTarget / $entryPrice;
            } else {
                $triggerPercentage = $reBuyTarget->triggerPercentage;
            }*/

            $targets[$reBuyTarget->targetId]  = [
                'targetId' => $reBuyTarget->targetId,
                'triggerPercentage' => $triggerPercentage, // * 100 - 100,
                'priceTarget' => $priceTarget,
                'pricePriority' => $this->getTargetPricePriority($reBuyTarget),
                'quantity' => $reBuyTarget->quantity * 100,
                'done' => $reBuyTarget->done,
                'cancel' => $reBuyTarget->cancel,
                'skipped' => $reBuyTarget->skipped,
                'orderId' => empty($reBuyTarget->orderId) ? false : $reBuyTarget->orderId,
                'errorMSG' => $this->getTargetErrorMessage($reBuyTarget),
                'postOnly' => empty($reBuyTarget->postOnly) ? false : $reBuyTarget->postOnly,
            ];
        }

        return $targets;
    }

    /**
     * Return the take profits targets in FE Format.
     * @param object $takeProfits
     * @param float $entryPrice
     * @return array
     */
    private function parseTakeProfits(object $takeProfits, float $entryPrice)
    {
        $targets = [];

        foreach ($takeProfits as $takeProfit) {
            list($priceTarget, $triggerPercentage) =
                PositionMediator::getTakeProfitTargetPriceAndPercentage($takeProfit, $entryPrice);
            /*if (!empty($entryPrice) && !empty($takeProfit->pricePriority) && 'price' === $takeProfit->pricePriority && !empty($takeProfit->priceTarget)) {
                $triggerPercentage = $takeProfit->priceTarget / $entryPrice;
            } else {
                $triggerPercentage = $takeProfit->priceTargetPercentage;
            }*/

            $targets[$takeProfit->targetId] = [
                'targetId' => $takeProfit->targetId,
                'priceTargetPercentage' => $triggerPercentage, // * 100 - 100,
                'priceTarget' => $priceTarget,
                'amountPercentage' => $takeProfit->amountPercentage * 100,
                'pricePriority' => $this->getTargetPricePriority($takeProfit),
                'done' => $takeProfit->done,
                'orderId' => empty($takeProfit->orderId) ? false : $takeProfit->orderId,
                'errorMSG' => $this->getTargetErrorMessage($takeProfit),
                'postOnly' => empty($takeProfit->postOnly) ? false : $takeProfit->postOnly,
                'skipped' => empty($takeProfit->skipped) ? false : $takeProfit->skipped,
            ];
        }

        return $targets;
    }

    /**
     * Return the take profits targets in FE Format.
     * @param object $reduceOrders
     * * @param object $orders
     * @return array
     */
    private function parseReduceOrders(object $reduceOrders, object $orders)
    {
        $targets = [];
        foreach ($reduceOrders as $reduceOrder) {
            $price = '';
            $amount = '';
            $orderId = empty($reduceOrder->orderId) ? false : $reduceOrder->orderId;
            if ($orderId && !empty($orders->$orderId)) {
                $price = !empty($orders->$orderId->price) ? $orders->$orderId->price : '';
                $amount = !empty($orders->$orderId->amount) ? $orders->$orderId->amount : '';
            }
            $targets[$reduceOrder->targetId] = [
                'targetId' => $reduceOrder->targetId,
                'type' => $reduceOrder->type,
                'targetPercentage' => $reduceOrder->targetPercentage * 100 - 100,
                'availablePercentage' => $reduceOrder->availablePercentage * 100,
                'pricePriority' => $this->getTargetPricePriority($reduceOrder),
                'done' => $reduceOrder->done,
                'recurring' => $reduceOrder->recurring,
                'persistent' => $reduceOrder->persistent,
                'orderId' => $orderId,
                'errorMSG' => $this->getTargetErrorMessage($reduceOrder),
                'price' => $price,
                'amount' => $amount,
                'postOnly' => empty($reduceOrder->postOnly) ? false : $reduceOrder->postOnly,
                'skipped' => empty($reduceOrder->skipped) ? false : $reduceOrder->skipped,
            ];
        }

        return $targets;
    }

    /**
     * Retrieve and parse the last closed positions for a given provider.
     *
     * @param ObjectId $userId
     * @param string $providerId
     * @return array
     */
    private function retrieveAndComposeClosedPositionsFromProvider(ObjectId $userId, string $providerId)
    {
        $positions = $this->newPositionCCXT->getSoldPositions($userId, $providerId, 200);

        if (!$positions) {
            sendHttpResponse([]);
        }

        $closedPositions = [];
        foreach ($positions as $position) {
            $parsedPosition = $this->composeClosedPosition($position, true);
            if ($parsedPosition) {
                $closedPositions[] = $parsedPosition;
            }
        }

        if (empty($closedPositions)) {
            sendHttpResponse(['error' => ['code' => 18]]);
        }

        return $closedPositions;
    }

    /**
     * Retrieve the open positions for a given user and internalExchangeId and optionally provider id.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @param bool|BSONDocument $provider
     * @param bool $publicOnly
     * @return array
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function retrieveAndComposeOpenPositions(string $userId, string $internalExchangeId, $provider = false, $publicOnly = false)
    {

        $providerId = $provider ? $provider->_id->__toString() : false;
        $openPositions = [];
        if (empty($positions)) {
            return $openPositions;
        }

        foreach ($positions as $position) {
            if (0 === $position->status) {
                continue;
            }
            $openPosition = $this->PositionCacheGenerator->composePositionForCache($position);

            if ($providerId && $openPosition['providerId'] != $providerId) {
                continue;
            }
            $currentData = $this->composeOpenPositionExtraFields($openPosition, $userId);

            unset($openPosition['exitedAmount']);
            unset($openPosition['grossProfitsFromExitAmount']);
            if ($providerId) {
                unset(
                    $openPosition['updating'],
                    $openPosition['providerId'],
                    $openPosition['providerName'],
                );
                $openPosition['providerOwnerUserId'] = empty($provider) && empty($provider->clonedFrom) ? $userId : false;
            }
            $positionData = array_merge($openPosition, $currentData);
            if ($publicOnly || $position->user->_id->__toString() !== $userId) {
            } else {
                $contractData = $this->getLiquidationPrice($position);
                $positionData = array_merge($positionData, $contractData);
            }
            $openPositions[] = $positionData;
        }

        return $openPositions;
    }


    /**
     * Retrieve the open positions for a given user and internalExchangeId and optionally provider id.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @param int $limit
     * @return array
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function retrieveAndComposeSoldPositions(string $userId, string $internalExchangeId, $limit = 500)
    {

        $returnPositions = [];

        $closeOrder = 0;

        foreach ($positions as $position) {
            $positionData = $this->PositionCacheGenerator->composeSoldPositionForCache($position);


            $positionData['logoUrl'] = $this->getProviderLogoUrl($position->provider->_id, $userId);
            //The response is already ordered by closing date (ascending), so incrementing it's enough
            $positionData['close_order'] = ++$closeOrder;
            $returnPositions[] = $positionData;
        }

        //Set the open order
        uasort($returnPositions, static function ($value1, $value2) {
            $openDate1 = $value1['openDateBackup'] ?? $value1['openDate'];
            $openDate2 = $value2['openDateBackup'] ?? $value2['openDate'];
            return $openDate1 <=> $openDate2;
        });

        $openOrder = 0;
        foreach ($returnPositions as &$position) {
            $position['open_order'] = ++$openOrder;
            unset($position['openDateBackup']);
        }

        return $returnPositions;
    }

    /**
     * Set the current exchange from the internal exchange id.
     *
     * @param BSONDocument $user
     * @param bool|string $internalExchangeId
     */
    private function setExchange(BSONDocument $user, $internalExchangeId = false)
    {
        if (empty($user->exchanges)) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        $internalExchangeId = !$internalExchangeId ? $this->internalExchangeId : $internalExchangeId;
        foreach ($user->exchanges as $exchange) {
            if ($exchange->internalId == $internalExchangeId) {
                $currentExchange = $exchange;
                break;
            }
        }

        if (empty($currentExchange)) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        $exchangeName = !empty($currentExchange->exchangeName) ? $currentExchange->exchangeName : $currentExchange->name;
        $exchangeType = !empty($currentExchange->exchangeType) ? $currentExchange->exchangeType : 'spot';
        $this->exchange = ExchangeFactory::createFromNameAndType($exchangeName, $exchangeType, []);
    }

    /**
     * Validate that request pass expected contraints.
     *
     * @param array $payload Request payload.
     * @param bool $requireInternalExchangeId
     * @param bool $requirePositionId
     *
     * @return BSONDocument Authenticated user.
     */
    private function validateCommonConstraints(
        array $payload,
        $requirePositionId = false
    ): BSONDocument {
        $token = checkSessionIsActive();

        if ($requirePositionId) {
            if (empty($payload['positionId'])) {
                sendHttpResponse(['error' => ['code' => 23]]);
            } else {
                $this->positionId = filter_var($payload['positionId'], FILTER_SANITIZE_STRING);
            }
        }

        $user = $this->userModel->getUser($token);
        if (empty($payload['internalExchangeId'])) {
            sendHttpResponse(['error' => ['code' => 12]]);
        }

        $this->internalExchangeId = filter_var($payload['internalExchangeId'], FILTER_SANITIZE_STRING);
        $this->setExchange($user);

        return $user;
    }

    /**
     * Validate that request pass expected contraints.
     *
     * @param array $payload Request payload.
     *
     * @return BSONDocument Authenticated user.
     */
    private function validateProviderConstraints(array $payload): BSONDocument
    {
        $token = checkSessionIsActive();

        if (empty($payload['providerId'])) {
            sendHttpResponse(['error' => ['code' => 30]]);
        }

        $this->providerId = filter_var($payload['providerId'], FILTER_SANITIZE_STRING);

        $user = $this->userModel->getUser($token);

        return $user;
    }
}
