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


namespace Zignaly\Positions;

use GuzzleHttp\Client;
use MongoDB\Database;
use MongoDB\Model\BSONDocument;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Messaging\Dispatcher;
use Zignaly\Messaging\Messages\RecreateClosedPositions;
use Zignaly\Messaging\Messages\UpdateRemoteClosedPositions;
use Zignaly\Provider\ProviderService;

/**
 * Class ClosedPositionsService
 * @package Zignaly\Positions
 */
class ClosedPositionsService
{
    /**
     * @var Database
     */
    private $mongoDBLink;

    /**
     * @var ClosedPositionsStorage
     */
    private $closedPositionsStorage;

    /**
     * @var \newPositionCCXT
     */
    private $newPositionCCXT;

    /**
     * @var Dispatcher
     */
    private $dispatcher;

    /**
     * @var ProviderService
     */
    private $providerService;

    /**
     * @var \Status
     */
    private $status;

    /**
     * ClosedPositionsService constructor.
     */
    public function __construct(
        ClosedPositionsStorage $closedPositionsStorage,
        \newPositionCCXT $newPositionCCXT,
        Dispatcher $dispatcher,
        ProviderService $providerService,
        \Status $status
    ) {
        global $mongoDBLink;

        $this->mongoDBLink = $mongoDBLink;
        $this->closedPositionsStorage = $closedPositionsStorage;
        $this->newPositionCCXT = $newPositionCCXT;
        $this->dispatcher = $dispatcher;
        $this->providerService = $providerService;
        $this->status = $status;
    }

    /**
     * Enqueues RecreateClosedPositions messages for all users in database.
     */
    public function dispatchAllRecreate()
    {
        $users = $this->getAllUsers();
        foreach ($users as $user) {
            if ($user->exchanges) {
                foreach ($user->exchanges as $exchange) {
                    $this->sendRecreateClosedPositions((string)$user->_id, $exchange->internalId);
                }
            }
        }
    }

    /**
     * Enqueues RecreateClosedPositions messages for a specific user.
     */
    public function dispatchRecreate(string $userId, ?string $exchangeInternalId)
    {
        if ($exchangeInternalId) {
            $this->sendRecreateClosedPositions($userId, $exchangeInternalId);
            return;
        }
        $userService = new \User();
        $user = $userService->getUser($userId);
        if ($user->exchanges) {
            foreach ($user->exchanges as $exchange) {
                $this->sendRecreateClosedPositions($userId, $exchange->internalId);
            }
        }
    }

    /**
     * Enqueues UpdateRemoteClosedPositions messages for all users in database.
     */
    public function dispatchAllRemote()
    {
        $users = $this->getAllUsers();
        foreach ($users as $user) {
            if ($user->exchanges) {
                foreach ($user->exchanges as $exchange) {
                    $this->sendUpdateEdgeCache((string)$user->_id, $exchange->internalId);
                }
            }
        }
    }

    /**
     * Stores the closed positions into Redis storage.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @param int $limit
     * @return iterable
     */
    public function storeClosedPositions(string $userId, string $internalExchangeId, int $limit): iterable
    {
        $positions = $this->newPositionCCXT->getPositions();
        $processedPositions = [];
        foreach ($positions as $position) {
            $processedPositions[] = $this->fillPosition($position, $userId, $internalExchangeId);
        }
        $returnPositions = $this->closedPositionsStorage->storePositions(
            $userId,
            $internalExchangeId,
            $processedPositions,
            ClosedPositionsMap::FIELDS['closeDate']
        );
        $this->sendUpdateEdgeCache($userId, $internalExchangeId);
        return $returnPositions;
    }

    /**
     * Stores one closed position into Redis storage.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @param string $positionId
     * @param string $closingDate
     */
    public function storeClosedPosition(
        string $userId,
        string $internalExchangeId,
        string $positionId,
        string $closingDate
    ) {
        $position = $this->newPositionCCXT->getPosition($positionId);
        $processedPosition = $this->fillPosition($position, $userId, $internalExchangeId);
        $this->closedPositionsStorage->storePosition(
            $userId, 
            $internalExchangeId, 
            $processedPosition, 
            $closingDate
        );
    }

    /**
     * Stores new closed positions.
     *
     * @param string $positionId
     */
    public function newClosedPositions(string $positionId)
    {
        $position = $this->newPositionCCXT->getPosition($positionId);
            $processedPosition = $this->fillPosition(
                $position,
                (string)$position->user->_id,
                $position->exchange->internalId
            );
            $this->closedPositionsStorage->storePosition(
                (string)$position->user->_id, 
                $position->exchange->internalId, 
                $processedPosition, 
                $position->accounting->closingDate
            );
            $this->sendUpdateEdgeCache((string)$position->user->_id, $position->exchange->internalId);
            

    }

    /**
     * Returns a list of all closed positions stored in Redis.
     *
     * @param string $userId
     * @param string $internalExchangeId
     * @return iterable
     */
    public function getClosedPositions(string $userId, string $internalExchangeId): iterable
    {
        $positions = $this->closedPositionsStorage->getPositions($userId, $internalExchangeId);
        if (empty($positions)) {
            return [];
        }
        $resultPositions = [];
        $closeOrder = 0;
        foreach ($positions as $position) {
            $resultPosition = json_decode($position, true);
            $resultPosition[ClosedPositionsMap::FIELDS['close_order']] = ++$closeOrder;
            $resultPositions[] = $resultPosition;
        }

        // Set the open order
        uasort($resultPositions, static function ($value1, $value2) {
            $openDate1 = $value1[ClosedPositionsMap::FIELDS['openDateBackup']] ?? $value1[ClosedPositionsMap::FIELDS['openDate']];
            $openDate2 = $value2[ClosedPositionsMap::FIELDS['openDateBackup']] ?? $value2[ClosedPositionsMap::FIELDS['openDate']];
            return $openDate1 <=> $openDate2;
        });
        $openOrder = 0;
        foreach ($resultPositions as &$position) {
            $position[ClosedPositionsMap::FIELDS['open_order']] = ++$openOrder;
            unset($position[ClosedPositionsMap::FIELDS['openDateBackup']]);
        }
        
        return array_values($resultPositions);
    }

    /**
     * Dispatches all the recreate cache messages.
     *
     * @param array $positions
     * @return int
     */
    public function revertPosition(string $positionId): int
    {
        $count = 0;
        $position = $this->newPositionCCXT->getPosition($positionId);
            $this->sendRecreateClosedPositions((string)$position->user->_id, $position->exchange->internalId);
            $count = 1;

        return $count;
    }

    /**
     * Change old position fields for new ones.
     *
     * @param array $positions
     * @return array
     */
    public function remapFields(array $positions): array
    {
        foreach ($positions as &$position) {
            foreach (ClosedPositionsMap::FIELDS as $oldField => $newField) {
                if (isset($position[$oldField])) {
                    $position[$newField] = $position[$oldField];
                    unset($position[$oldField]);
                }
            }
        }
        return $positions;
    }

    /**
     * Calls the update positions endpoint in Cloudflare edge cache.
     *
     * @param string $userId
     * @param string $internalExchangeId
     */
    public function updateRemoteClosedPositions(string $userId, string $internalExchangeId)
    {
        $positions = $this->getClosedPositions($userId, $internalExchangeId);
        if ($positions) {
            $config = ['base_uri' => CLOUDFLARE_API_URL];
            $client = new Client($config);
            $options = [
                'json' => $positions,
                'query' => [
                    't' => 'ps',
                    'u' => $userId,
                    'ex' => $internalExchangeId,
                ],
            ];
            $client->request('POST', CLOUDFLARE_API_PATH, $options);
        }
    }

    /**
     * Calls the update token endpoint in Cloudflare edge cache.
     *
     * @param string $userId
     * @param string $token
     */
    public function updateRemoteToken(string $userId, string $token)
    {
        $config = ['base_uri' => CLOUDFLARE_API_URL];
        $client = new Client($config);
        $options = [
            'query' => [
                't' => 'tk',
                'u' => $userId,
                'tk' => $token,
            ],
        ];
        $client->request('POST', CLOUDFLARE_API_PATH, $options);
    }

    /**
     * Dispatches a message to update cached positions in Redis.
     *
     * @param string $userId
     * @param string $internalExchangeId
     */
    private function sendRecreateClosedPositions(string $userId, string $internalExchangeId)
    {
        $message = new RecreateClosedPositions;
        $message->userId = $userId;
        $message->internalExchangeId = $internalExchangeId;
        $this->dispatcher->sendRecreateClosedPositions($message);
    }

    /**
     * Dispatches a message to update positions in Cloudflare edge cache.
     *
     * @param string $userId
     * @param string $internalExchangeId
     */
    private function sendUpdateEdgeCache(string $userId, string $internalExchangeId)
    {
        $message = new UpdateRemoteClosedPositions;
        $message->userId = $userId;
        $message->internalExchangeId = $internalExchangeId;
        $this->dispatcher->sendUpdateEdgeCache($message);
    }

    /**
     * Returns all users.
     *
     * @return iterable
     */
    private function getAllUsers(): iterable
    {
        $find = ['exchanges' => ['$exists' => true]];
        return $this->mongoDBLink->selectCollection('user')->find($find);
    }

    /**
     * Parses a Mongo document and returns an assoc array with minimal closed position fields.
     * 
     * @param BSONDocument $document
     * @param string $userId
     * @param string $internalExchangeId
     * @return array
     */
    private function fillPosition(
        BSONDocument $document,
        string $userId,
        string $internalExchangeId
    ): array {
        $position = $this->composeSoldPosition($document);


        $position[ClosedPositionsMap::FIELDS['logoUrl']] = $this->providerService->getProviderLogoUrl($document->provider->_id, $userId);

        return $position;
    }

    /**
     * Returns final associated map from a position document.
     * 
     * @param BSONDocument $position
     */
    public function composeSoldPosition(BSONDocument $position)
    {
        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

            $allocatedBalance = empty($position->provider->allocatedBalance) ? 0 : $position->provider->allocatedBalance;
            if (is_object($allocatedBalance)) {
                $allocatedBalance = $allocatedBalance->__toString();
            }
            $profitsFromClosedBalance = empty($position->provider->profitsFromClosedBalance) ? 0 : $position->provider->profitsFromClosedBalance;
            if (is_object($profitsFromClosedBalance)) {
                $profitsFromClosedBalance = $profitsFromClosedBalance->__toString();
            }
            $currentAllocatedBalance = $allocatedBalance + $profitsFromClosedBalance;

        if (empty($position->accounting->fundingFees)) {
            $fundingFees = 0;
        } else {
            $fundingFees = is_object($position->accounting->fundingFees) ? $position->accounting->fundingFees->__toString() : $position->accounting->fundingFees;
        }

        $buyTotalQty = is_object($position->accounting->buyTotalQty) ? $position->accounting->buyTotalQty->__toString() : $position->accounting->buyTotalQty;
        $buyAvgPrice = is_object($position->accounting->buyAvgPrice) ? $position->accounting->buyAvgPrice->__toString() : $position->accounting->buyAvgPrice;
        $netProfit = is_object($position->accounting->netProfit) ? $position->accounting->netProfit->__toString() : $position->accounting->netProfit;
        $totalFees = is_object($position->accounting->totalFees) ? $position->accounting->totalFees->__toString() : $position->accounting->totalFees;

        $investment = $exchangeHandler->calculatePositionSize($positionMediator->getSymbol(), $buyTotalQty, $buyAvgPrice);
        $grossProfit = $netProfit + $totalFees - $fundingFees;

        // add units to position
        $positionMediator = PositionMediator::fromMongoPosition($position);

        $leverage = isset($position->leverage) && $position->leverage > 0? $position->leverage: 1;

        $realInvestment = $investment / $leverage;

        $buyPrice = is_object($position->avgBuyingPrice) ? $position->avgBuyingPrice->__toString() : $position->avgBuyingPrice;
        $sellAvgPrice = is_object($position->accounting->sellAvgPrice) ? $position->accounting->sellAvgPrice->__toString() : $position->accounting->sellAvgPrice;
        list ($stopLossPrice, $stopLossPercentage) = $positionMediator->getStopLossPriceAndPercentage($buyPrice);
        list ($trailingStopTriggerPrice, $trailingStopTriggerPercentage) = $positionMediator->getTrailingStopTriggerPriceAndPercentage($buyPrice);

        $positionToBeReturned = [
            ClosedPositionsMap::FIELDS['amount'] => $buyTotalQty,
            ClosedPositionsMap::FIELDS['base'] => $position->signal->base,
            ClosedPositionsMap::FIELDS['buyPrice'] => $buyAvgPrice,
            ClosedPositionsMap::FIELDS['buyTTL'] => isset($position->buyTTL) ? $position->buyTTL : false,
            ClosedPositionsMap::FIELDS['closeDate'] => isset($position->accounting) ? $position->accounting->closingDate->__toString() : false,
            ClosedPositionsMap::FIELDS['currentAllocatedBalance'] => empty($currentAllocatedBalance) ? false : $currentAllocatedBalance,
            ClosedPositionsMap::FIELDS['fees'] => $totalFees * -1,
            ClosedPositionsMap::FIELDS['fundingFees'] => $fundingFees,
            ClosedPositionsMap::FIELDS['invested'] => $investment,
            ClosedPositionsMap::FIELDS['isCopyTrader'] => !empty($position->provider->isCopyTrading) && isset($position->signal->userId) && $position->user->_id->__toString() == $position->signal->userId,
            ClosedPositionsMap::FIELDS['isCopyTrading'] => !empty($position->provider->isCopyTrading),
            ClosedPositionsMap::FIELDS['leverage'] => $leverage,
            ClosedPositionsMap::FIELDS['netProfit'] => $netProfit,
            ClosedPositionsMap::FIELDS['netProfitPercentage'] => $netProfit * 100 / $realInvestment,
            ClosedPositionsMap::FIELDS['openDate'] => isset($position->accounting) ? $position->accounting->openingDate->__toString() : $position->createdAt->__toString(),
            ClosedPositionsMap::FIELDS['pair'] => $positionMediator->getSymbol(),
            ClosedPositionsMap::FIELDS['positionId'] => $position->_id->__toString(),
            ClosedPositionsMap::FIELDS['positionSizeQuote'] => $investment,
            ClosedPositionsMap::FIELDS['positionSizePercentage'] => empty($position->signal->positionSizePercentage) ? false : $position->signal->positionSizePercentage,
            ClosedPositionsMap::FIELDS['profit'] => $grossProfit,
            ClosedPositionsMap::FIELDS['profitPercentage'] => $grossProfit * 100 / $realInvestment,
            ClosedPositionsMap::FIELDS['providerId'] => $position->provider->_id,
            ClosedPositionsMap::FIELDS['providerName'] => isset($position->signal->providerName) ? $position->signal->providerName : false,
            ClosedPositionsMap::FIELDS['quote'] => $position->signal->quote,
            ClosedPositionsMap::FIELDS['realInvestment'] => $realInvestment,
            ClosedPositionsMap::FIELDS['reBuyTargetsCountFail'] => 0,
            ClosedPositionsMap::FIELDS['reBuyTargetsCountSuccess'] => $this->newPositionCCXT->countFilledTargets($position->reBuyTargets, $position->orders),
            ClosedPositionsMap::FIELDS['reBuyTargetsCountPending'] => 0,
            ClosedPositionsMap::FIELDS['sellPrice'] => $sellAvgPrice,
            ClosedPositionsMap::FIELDS['side'] => isset($position->side) ? $position->side : 'LONG',
            ClosedPositionsMap::FIELDS['signalId'] => isset($position->signal->signalId) ? $position->signal->signalId : false,
            ClosedPositionsMap::FIELDS['status'] => $position->status,
            ClosedPositionsMap::FIELDS['stopLossPrice'] => $stopLossPrice,
            ClosedPositionsMap::FIELDS['takeProfitTargetsCountFail'] => 0,
            ClosedPositionsMap::FIELDS['takeProfitTargetsCountSuccess'] => $this->newPositionCCXT->countFilledTargets($position->takeProfitTargets, $position->orders),
            ClosedPositionsMap::FIELDS['takeProfitTargetsCountPending'] => 0,
            ClosedPositionsMap::FIELDS['trailingStopTriggerPercentage'] => $trailingStopTriggerPercentage,
            ClosedPositionsMap::FIELDS['trailingStopTriggered'] => !empty($position->trailingStopPrice),
        ];
        $extraSymbols = $positionMediator->getExtraSymbolsAsArray();
        foreach ($extraSymbols as $field => $value) {
            $extraSymbols[ClosedPositionsMap::FIELDS[$field]] = $value;
            unset($extraSymbols[$field]);
        }
        return array_merge(
            $positionToBeReturned,
            $extraSymbols
        );
    }
}