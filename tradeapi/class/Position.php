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


use MongoDB\BSON\ObjectId;
use Symfony\Component\DependencyInjection\Container;
use Zignaly\exchange\ExchangeFactory;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\ExchangeHandler\ExchangeHandler;
use Zignaly\Mediator\ExchangeMediator\ExchangeMediator;
use Zignaly\Process\DIContainer;
use Zignaly\Mediator\PositionMediator;
use Zignaly\redis\ZignalyLastPriceRedisService;

class Position
{
    /** @var \MongoDB\Database */
    private $mongoDBLink;
    /** @var \MongoDB\Database */
    private $mongoDBLinkRO;
    /** @var Monolog */
    private $Monolog;
    private $RabbitMQ;
    private $Status;
    private $Ticker;
    private $User;
    /** @var newPositionCCXT */
    private $newPositionCCXT;
    /** @var PositionCacheGenerator */
    private $PositionCacheGenerator = false;
    /** @var PositionMediator */
    private $positionMediator;
    private $positionStatusError;
    private $RedisHandler = false;
    /** @var ZignalyLastTradesService */
    private $lastTradesProvider;
    /** @var newUser */
    private $newUser;

    /**
     * Dependency Injection Container.
     *
     * @var \Symfony\Component\DependencyInjection\Container
     */
    private $container;

    public function __construct(RabbitMQ $RabbitMQ = null)
    {
        global $mongoDBLink, $RabbitMQ, $Status, $Ticker, $User,
               $lastTradesProvider;

        $this->container = DIContainer::getContainer();
        $this->mongoDBLink = $mongoDBLink;
        $this->RabbitMQ = $RabbitMQ;
        $this->Status = $Status;
        $this->User = $User;
        $this->Ticker = $Ticker;
        $this->lastTradesProvider = $lastTradesProvider;
        $this->newUser = $this->container->get('newUser.model');
        $this->newPositionCCXT = $this->container->get('newPositionCCXT.model');
    }

    public function configureMongoDBLinkRO()
    {
        global $mongoDBLinkRO;

        $this->mongoDBLinkRO = $mongoDBLinkRO;
    }

    public function getPositionsForProviderPerformanceStats($quote, $providerId, $from, $to)
    {
        $find = [
            'closed' => true,
            'accounting.closingDate' => [
                '$gte' => new \MongoDB\BSON\UTCDateTime($from),
                '$lt' => new \MongoDB\BSON\UTCDateTime($to),
            ],
            'signal.quote' => $quote,
            '$or' => [
                ['provider._id' => $providerId],
                ['signal.providerId' => $providerId->__toString()],
            ],
        ];

        return $this->mongoDBLink->selectCollection('position')->find($find);
    }

    public function checkIfPositionIsGoodForSendingBuyOrder($positionId, ExchangeCalls $ExchangeCalls)
    {
        global $Provider, $Status;

        //$this->Monolog->sendEntry('debug', "Starting checks.");

        $position = $this->getPosition($positionId);

        // Some buy messages was using provider exchange name which is null in some cases
        // in order to allow proper processing of positions created with incomplete exchange data
        // we fallback the name to internalName.
        if ($position->exchange && !$position->exchange->name) {
            $position->exchange->name = $position->exchange->internalName;
        }

        $this->positionMediator = PositionMediator::fromMongoPosition($position);

        //$this->Monolog->sendEntry('debug', "Position retrieved.");

        if ($position->closed) {
            //$this->Monolog->sendEntry('debug', "Position {$position->_id->__toString()} closed ({$position->status}) from creation, nothing to do here.");

            return false;
        }
        // change base.quote codification to zignaly pair valid 4 BitMEX
        $symbol = $position->signal->pair;

        $user = $this->User->getUser($position->user->_id);
        //$this->Monolog->sendEntry('debug', "User retrieved.");

        $amount = is_object($position->amount) ? $position->amount->__toString() : $position->amount;
        $price = is_object($position->realBuyPrice) ? $position->realBuyPrice->__toString() : $position->realBuyPrice;
        // $cost = $amount * $price;
        $exchangeHandler = $this->positionMediator->getExchangeMediator()->getExchangeHandler();
        $cost = $exchangeHandler->calculateOrderCostZignalyPair(
            $this->positionMediator->getSymbol(),
            $amount,
            $price
        );
        $provider = !isset($position->provider->_id) || $position->provider->_id == 1
            ? false : $Provider->getProviderFromId($position->provider->_id);

        if (!$position->exchange) {
            $status = 57;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkAllowedSide($this->positionMediator)) {
            $status = 104;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkIfBaseCurrencyIsEnabled($position)) {
            $status = 52;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkExchangeAndType()) {
            $status = 88;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (empty($position->exchange->areKeysValid)) {
            $status = 32;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkPositionsForThisMarket($position)) {
            $status = 45;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        /*} elseif (!$this->checkProviderAllowsClones($provider)) {
            $status = 89;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($this->checkIfFollowerIsSuspended($position, $user)) {
            $status = 85;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());*/
        } elseif ($this->checkIfOpenFuturesPositionAlreadyExists()) {
            $status = 83;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkIfPositionSizeQuoteIsSameThanCopyTraderCoin($position, $provider)) {
            $status = 78;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkIfBalanceIsConfiguredOnCopyTradingProvider($position, $user, $provider)) {
            $status = 75;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($this->checkIfSymbolOnList($position, 'blacklist', 'black')) {
            $status = 53;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($this->checkIfSymbolOnList($position, 'globalBlacklist', 'black')) {
            $status = 61;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($this->checkIfSymbolOnList($position, 'whitelist', 'white')) {
            $status = 62;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($this->checkIfSymbolOnList($position, 'globalWhitelist', 'white')) {
            $status = 63;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($this->checkIfCoinIsDelisted($position)) {
            $status = 64;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($position->buyType != 'MARKET'
            && !$ExchangeCalls->checkIfValueIsGood('price', 'min', $price, $symbol)) {
            $status = 5;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$ExchangeCalls->checkIfValueIsGood('amount', 'min', $amount, $symbol)) {
            $status = 12;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$ExchangeCalls->checkIfValueIsGood('amount', 'max', $amount, $symbol)) {
            $status = 100;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$ExchangeCalls->checkIfValueIsGood('cost', 'min', $cost, $symbol)) {
            //$status = 38;
            $status = 12;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (time() - $position->signal->datetime->__toString() / 1000 > $position->buyTTL) {
            $status = 3;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkStopLossPriceForSelling($position, $position->realBuyPrice, $position->amount, $position->stopLossPercentage, $symbol, $ExchangeCalls)) {
            $status = 20;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkTakeProfitsAmounts($position, $position->takeProfitTargets, $position->realBuyPrice, $position->amount, $symbol, $ExchangeCalls)) {
            $status = 39;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($position->buyType == 'stop-limit' && !$position->buyStopPrice) {
            $status = 42;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkVolume($position)) {
            $status = 44;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($this->checkForDuplicateSignalId($position)) {
            $status = 46;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($this->checkForDuplicateSignalId($position, false)) {
            $status = 47;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        /*} elseif (!$this->checkSignalTerms($position)) {
            $status = 49;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif ($this->checkIfSignalShouldBeRejectedBecauseItsPremiumProviderAndUserDidntPay($position, $user)) {
            $status = 50;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkIfRiskLevelIsAllowed($position)) {
            $status = 51;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());*/
        } elseif (!$this->checkVolume($position, true)) {
            $status = 59;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        /*} elseif ($this->checkProviderDisclaimer($position, $provider)) {
            $status = 65;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkIfSuccessRateIsAllowed($position)) {
            $status = 70;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());*/
        } elseif (!$this->checkAllocatedBalance($position, $user)) {
            $status = 74;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->countCurrentOpenPositions($position)) {
            $status = 43;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->countCurrentOpenPositions($position, true)) {
            $status = 58;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkPositionsForThisMarket($position, true)) {
            $status = 60;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkFutureExchangeFilter($position)) {
            $status = 81;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } elseif (!$this->checkIfContractAmountIsGood($ExchangeCalls, $position)) {
            $status = 103;
            //$statusMsg = $Status->getPositionStatusText($status);
            $setPosition = ['status' => $status, 'closed' => true];
            //$this->Monolog->sendEntry('info', " $statusMsg " . $positionId->__toString());
        } else {
            $this->Monolog->sendEntry('info', "Everything OK for position: " . $positionId->__toString());
            return true;
        }

        if (isset($setPosition)) {
            $this->setPosition($positionId, $setPosition);
        }

        return false;
    }

    /**
     * Retrieve the contracts from an exchange account and check if the one for the current market has any amount.
     * @param ExchangeCalls $ExchangeCalls
     * @param \MongoDB\Model\BSONDocument $position
     * @return bool
     */
    private function checkIfContractAmountIsGood(ExchangeCalls $ExchangeCalls, \MongoDB\Model\BSONDocument $position)
    {
        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeType = $positionMediator->getExchangeType();
        if ('futures' !== $exchangeType || 'IMPORT' === $position->buyType) {
            return true;
        }

        if ($position->paperTrading) {
            return true;
        }

        $positionUserId = $position->user->_id;
        $positionExchangeInternalId = $position->exchange->internalId;

        $contracts = $ExchangeCalls->getContracts($positionUserId, $positionExchangeInternalId);
        if (empty($contracts)) {
            return true;
        }

        if (isset($contracts['error'])) {
            $this->Monolog->sendEntry('critical', 'Error retrieving contracts', $contracts);
            return true;
        }

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $marketEncoder = $positionMediator->getExchangeMediator()->getMarketEncoder();
        foreach ($contracts as $contract) {
            try {
                $zigId = $marketEncoder->fromCcxt($contract->getSymbol());
                if ($zigId === $position->signal->pair) {
                    $contractSide = strtolower($contract->getSide());
                    $positionSide = strtolower($position->side);
                    //$this->Monolog->sendEntry('debug', "contract side: $contractSide / $positionSide .");
                    if ('both' === $contractSide || $contractSide === $positionSide) {
                        if (abs($contract->getAmount()) > 0) {
                            //$this->Monolog->sendEntry('warning', "Existing contract with amount {$contract->getAmount()}.");
                            //Todo: we need to send a notification to the user here.
                            return false;
                        }
                    }
                }
            } catch (\Exception $ex) {
                // catching exception here?!?
                $this->Monolog->sendEntry('error', 'Error retrieving zignaly id from ccxt symbol', $contract->getSymbol());
            }
        }

        return true;
    }

    /**
     * Check if the provider allows clones.
     *
     * @param bool|\MongoDB\Model\BSONDocument $provider
     * @return bool
     */
    private function checkProviderAllowsClones(
        $provider
    ) {
        if (!isset($provider->options->allowClones) || $provider->options->allowClones) {
            return true;
        }

        if (!empty($provider->clonedFrom)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the configured exchange and type is the same than the one from the signal.
     *
     * @return bool
     */
    private function checkExchangeAndType()
    {

        /* @var \MongoDB\Model\BSONDocument */
        $position = $this->positionMediator->getPositionEntity();
        $userExchangeType = $this->positionMediator->getExchangeType();

        if (!empty($position->signal->exchangeAccountType)) {
            $signalExchangeType = $position->signal->exchangeAccountType;
        } else {
            $signalExchangeType = 'spot';
        }

        if ($userExchangeType != $signalExchangeType) {
            return false;
        }

        $exchangeName = $this->positionMediator->getExchangeMediator()->getName();
        $userExchangeName = ExchangeFactory::exchangeNameResolution($exchangeName, $userExchangeType);
        /* TODO: LFERN DELETE
        if (!empty($position->exchange->name)) {
            $userExchangeName = ExchangeFactory::exchangeNameResolution($position->exchange->name, $userExchangeType);
        } else {
            $userExchangeName = 'noneUser';
        }
        */

        if (!empty($position->signal->exchange)) {
            $signalExchangeName = ExchangeFactory::exchangeNameResolution($position->signal->exchange, $signalExchangeType);
        } else {
            $signalExchangeName = 'noneSignal';
        }

        if ($userExchangeName != $signalExchangeName) {
            return false;
        }

        return true;
    }

    /**
     * Check if the follower is suspended.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @param \MongoDB\Model\BSONDocument $user
     * @return bool
     */
    private function checkIfFollowerIsSuspended(
        \MongoDB\Model\BSONDocument $position,
        \MongoDB\Model\BSONDocument $user
    )
    {
        $providerId = $position->provider->_id;
        if (isset($user->provider->$providerId) && isset($user->provider->$providerId->suspended)) {
            return $user->provider->$providerId->suspended;
        }

        return false;
    }

    /**
     * Check if there is an existing position for the same market, exchange account and side.
     *
     * @return bool
     */
    private function checkIfOpenFuturesPositionAlreadyExists()
    {
        if ($this->positionMediator->getExchangeType() !== 'futures')
            return false;

        $find = [
            'closed' => false,
            'user._id' => '',
            'signal.pair' => $this->positionMediator->getSymbol(),
            // 'signal.base' => $this->positionMediator->getBase(),
            // 'signal.quote' => $this->positionMediator->getQuote(),
            'exchange.internalId' => '',
            //'side' => $this->positionMediator->getSide(),
            '_id' => [
                '$ne' => $this->positionMediator->getPositionId(),
            ],
        ];

        return $this->mongoDBLink->selectCollection('position')->count($find) > 0;
    }

    private function checkFutureExchangeFilter($position)
    {
        if ($this->getExchangeAccountTypeFromSignal($position) !== 'futures')
            return true;

        $userExchangeType = $this->positionMediator->getExchangeType();

        return $userExchangeType == 'futures';
    }

    private function getExchangeAccountTypeFromSignal($position)
    {
        return isset($position->signal->exchangeAccountType)
            ? strtolower($position->signal->exchangeAccountType) : 'spot';
    }

    private function checkIfPositionSizeQuoteIsSameThanCopyTraderCoin($position, $provider)
    {
        $providerId = $position->provider->_id;

        if ($providerId == 1) {
            return true;
        }
        if (empty($provider->isCopyTrading)) {
            return true;
        }
        if (!isset($position->signal->positionSizeQuote) || !isset($provider->quote)) {
            return false;
        }
        if (!empty($position->exchange->exchangeName) && 'bitmex' === strtolower($position->exchange->exchangeName)) {
            $bitmexQuotes = ['btc', 'xbt'];
            return in_array(strtolower($provider->quote), $bitmexQuotes);
        }

        return $position->signal->positionSizeQuote == $provider->quote;
    }

    private function checkIfBalanceIsConfiguredOnCopyTradingProvider($position, $user, $provider)
    {
        $providerId = $position->provider->_id;
        if ($providerId == 1) {
            return true;
        }

        if (!isset($provider->isCopyTrading) || !$provider->isCopyTrading) {
            return true;
        }
        if (!isset($user->provider->$providerId->balanceFilter) || !$user->provider->$providerId->balanceFilter) {
            return false;
        }

        if (!isset($user->provider->$providerId->allocatedBalance) || !$user->provider->$providerId->allocatedBalance) {
            $allocatedBalance = is_object($user->provider->$providerId->allocatedBalance) ?
                $user->provider->$providerId->allocatedBalance->__toString() :
                $user->provider->$providerId->allocatedBalance;
            if (0 === $allocatedBalance) {
                return false;
            }
        }

        return true;
    }

    private function getPositionSize($percentage, $user, $provider)
    {
        $providerId = $provider['_id'];

        $positionSize = 0;
            $balance = $this->getCurrentBalanceForProvider($user, $providerId);
            if (!empty($balance)) {
                $positionSize = $balance / 100 * $percentage;
            }


        return $positionSize;
    }

    /**
     * Given an user and providerId, return the  max allocated balance counting the profits from closed positions.
     *
     * @param \MongoDB\Model\BSONDocument $user
     * @param string $providerId
     * @return bool|int|float
     */
    private function getCurrentBalanceForProvider(\MongoDB\Model\BSONDocument $user, string $providerId)
    {
        if (!isset($user->provider) || !$user->provider)
            return false;

        foreach ($user->provider as $fProvider)
            if (isset($fProvider->_id) && is_object($fProvider->_id) && $fProvider->_id->__toString() == $providerId)
                $provider = $fProvider;

        //$this->Monolog->sendEntry('debug', "Looking for provider");
        if (!isset($provider))
            return false;

        //$this->Monolog->sendEntry('debug', "Checking if balanceFilter is good");
        if (!isset($user->provider->$providerId->balanceFilter) || !$user->provider->$providerId->balanceFilter)
            return false;

        //$this->Monolog->sendEntry('debug', "Checking if allocatedBalance and profitsFromClosedBalance is good");
        if (!isset($provider->allocatedBalance) || !isset($provider->profitsFromClosedBalance))
            return false;

        $allocatedBalance = is_object($provider->allocatedBalance) ? $provider->allocatedBalance->__toString() : $provider->allocatedBalance;
        $profitsFromClosed = is_object($provider->profitsFromClosedBalance) ? $provider->profitsFromClosedBalance->__toString() : $provider->profitsFromClosedBalance;

        //$this->Monolog->sendEntry('debug', "Allocated Balance: $allocatedBalance, Profits: $profitsFromClosed");

        return $allocatedBalance + $profitsFromClosed;
    }


    /**
     * If the position is from a copy-trading, we estimated the current consumed balance, plus the balance that this
     * new position will consume and return if the sum of both is less than the maximum allocated by the user.
     *
     * @param \MongoDB\Model\BSONDocument $position
     * @param \MongoDB\Model\BSONDocument $user
     * @param bool|int|float $newPositionSize
     * @return bool
     */
    public function checkAllocatedBalance(
        \MongoDB\Model\BSONDocument $position,
        \MongoDB\Model\BSONDocument $user,
        $newPositionSize = false
    ) {
        if (empty($position->provider->isCopyTrading) || 3 === $position->version) {
            return true;
        }

        $providerId = is_object($position->provider->_id) ? $position->provider->_id->__toString() : $position->provider->_id;

        if (empty($user->provider->$providerId->allocatedBalance)) {
            return false;
        }

        $availableBalance = $this->getCurrentBalanceForProvider($user, $providerId);
        $leverage = isset($position->leverage) && $position->leverage > 0 ? $position->leverage : 1;
        if ($newPositionSize) {
            $positionSize = $newPositionSize / $leverage;
        } else {
            $positionSizeValue = is_object($position->positionSize) ? $position->positionSize->__toString() : $position->positionSize;
            $positionSize = $positionSizeValue / $leverage;
        }

        $consumedBalanceFromOpenPositions = $this->getCurrentConsumedBalance($user, $providerId, $leverage);
        $this->Monolog->sendEntry('debug', "Position size: $positionSize, Consumed Balance: $consumedBalanceFromOpenPositions, Available Balance: $availableBalance");

        return $positionSize + $consumedBalanceFromOpenPositions <= $availableBalance;
    }



    /**
     * Given an user and a providerId, extract the consumed balance from current open positions.
     *
     * @param \MongoDB\Model\BSONDocument $user
     * @param string $providerId
     * @param int $leverage
     * @return float|int
     */
    public function getCurrentConsumedBalance(\MongoDB\Model\BSONDocument $user, string $providerId, int $leverage)
    {
        $currentConsumedBalance = 0;

        $positions = $this->getCurrentOpenPositionsForUserAndProvider($user->_id, $providerId);

        foreach ($positions as $position) {
            if (isset($position->orders)) {
                $positionMediator = PositionMediator::fromMongoPosition($position);
                $currentConsumedBalance += $this->getCostFromEntryOrders($positionMediator, $position->orders) / ($leverage > 0 ? $leverage : 1);
            }
        }

        return $currentConsumedBalance;
    }


    /**
     * Given an array of orders extract the total cost from the ones which type is entry and have been completed or
     * are still pending.
     *
     * @param PositionMediator $positionMediator
     * @param array|bool $orders
     * @return float|int
     */
    private function getCostFromEntryOrders($positionMediator, $orders)
    {
        if (!$orders) {
            return 0;
        }

        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
        $cost = 0;

        foreach ($orders as $order) {
            if ('entry' !== $order->type && 'buy' !== $order->type) {
                continue;
            }

            if ('open' !== $order->status && 'closed' !== $order->status) {
                continue;
            }

            if ($order->cost > 0) {
                $cost += $order->cost;
            } else {
                //$cost += $order->amount * $order->price;
                $cost += $exchangeHandler->calculateOrderCostZignalyPair(
                    $positionMediator->getSymbol(),
                    $order->amount,
                    $order->price
                );
            }
        }

        return $cost;
    }

    /**
     * Look for open positions from an user for a given providerId.
     *
     * @param \MongoDB\BSON\ObjectId $userId
     * @param string $providerId
     * @return \MongoDB\Driver\Cursor
     */
    private function getCurrentOpenPositionsForUserAndProvider(\MongoDB\BSON\ObjectId $userId, string $providerId)
    {
        $find = [
            'closed' => false,
            'user._id' => $userId,
            'provider._id' => $providerId,
        ];

        return $this->mongoDBLink->selectCollection('position')->find($find);
    }


    /**
     * Return the profits and balance from the open positions.
     *
     * @param \MongoDB\BSON\ObjectId $userId
     * @param \MongoDB\BSON\ObjectId $providerId
     * @param bool|\MongoDB\BSON\ObjectId $positionId
     * @return array
     * @throws Exception
     */
    public function getProfitsAndBalanceFromOpenPositions(
        \MongoDB\BSON\ObjectId $userId,
        \MongoDB\BSON\ObjectId $providerId,
        $positionId = false)
    {
        global $Accounting;

        $profitsFromOpenPositions = 0;
        $consumedBalanceFromOpenPositions = 0;

        try {
            $lastPriceService = $this->container->get('lastPrice');

            $find = [
                'user._id' => $userId,
                'signal.providerId' => $providerId,
                'closed' => false,
            ];

            $positions = $this->mongoDBLink->selectCollection('position')->find($find);

            foreach ($positions as $position) {
                if ($positionId && $positionId == $position->_id)
                    continue;

                $positionMediator = PositionMediator::fromMongoPosition($position);
                $exchangeAccountType = $positionMediator->getExchangeType();
                $exchangeName = ZignalyExchangeCodes::getExchangeFromCaseInsensitiveStringWithType($position->exchange->name, $exchangeAccountType);

                //$leverage = empty($position->leverage) ? 1 : $position->leverage;
                if (!empty($position->trades)) {
                    list($positionSize, $positionSizeSold, $unitsUnsold,) = $Accounting->estimatedPositionSize($position);
                    $currentPrice = $lastPriceService->lastPriceStrForSymbol(
                        $exchangeName,
                        // change base.quote codification to zignaly pair valid 4 BitMEX
                        $position->signal->pair
                    );

                    if (!$currentPrice)
                        $currentPrice = 0;

                    $positionSizeUnsold = $unitsUnsold * $currentPrice * 0.9985;
                    $profitsFromOpenPositions += $positionSizeSold * 0.9985 + $positionSizeUnsold - $positionSize;
                } else {
                    $profitsFromOpenPositions += 0;
                }
                $consumedBalanceFromOpenPositions += $this->getRealPositionSizeFromEntryOrders($position);
            }
        } catch (Exception $e) {
            $container = DIContainer::getContainer();
            $Monolog = $container->get('monolog');
            $Monolog->sendEntry('critical', "getProfitsAndBalanceFromOpenPositions: " . $e->getMessage());
        }

        return [$profitsFromOpenPositions, $consumedBalanceFromOpenPositions];
    }

    /**
     * Get the position size from the given position
     * @param \MongoDB\Model\BSONDocument $position
     * @return float
     */
    private function getRealPositionSizeFromEntryOrders(\MongoDB\Model\BSONDocument $position)
    {
        if (empty($position->orders)) {
            return 0.0;
        }

        $positionSize = 0.0;
        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

        $leverage = empty($position->leverage) ? 1 : $position->leverage;
        foreach ($position->orders as $order) {
            if ('entry' !== $order->type && 'buy' !== $order->type) {
                continue;
            }
            $amount = is_object($order->amount) ? $order->amount->__toString() : $order->amount;
            $price = is_object($order->price) ? $order->price->__toString() : $order->price;
            // $cost = $amount * $price;
            $cost = $exchangeHandler->calculateOrderCostZignalyPair(
                $positionMediator->getSymbol(),
                $amount,
                $price
            );
            $realCost = $cost / $leverage;
            $positionSize += $realCost;
        }

        return (float) $positionSize;
    }

    private function checkIfCoinIsDelisted($position)
    {
        //$this->Monolog->sendEntry('debug', "Checking if coin is delisted.");

        if (!isset($position->exchange->globalDelisting) || !$position->exchange->globalDelisting)
            return false;

        require_once __DIR__ . '/GlobalBlackList.php';
        $GlobalBlackList = new GlobalBlackList();

        return $GlobalBlackList->checkIfCoinsAreListed('Binance', $position->signal->quote, $position->signal->base);
    }

    private function checkIfSymbolOnList($position, $list, $listType)
    {
        //$this->Monolog->sendEntry('debug', "Checking is symbol is on the list");

        if (isset($position->signal->origin) && ($position->signal->origin == 'manualBuy'
                || $position->signal->origin == 'manual' || $position->signal->origin == 'copyTrading'))
            return false;

        if (!isset($position->exchange->$list) || !$position->exchange->$list)
            return false;

        $positionMediator = PositionMediator::fromMongoPosition($position);
        //$symbol = strtoupper($position->signal->base . $position->signal->quote);
        $symbol = strtoupper($positionMediator->getSymbol());
        foreach ($position->exchange->$list as $listSymbol) {
            $listSymbol = strtoupper($listSymbol);
            if ($symbol == $listSymbol) {
                return $listType == 'black' ? true : false;
            }
        }

        return $listType == 'black' ? false : true;
    }

    private function checkIfBaseCurrencyIsEnabled($position)
    {
        if (isset($position->signal->origin) && ($position->signal->origin == 'manualBuy'
                || $position->signal->origin == 'manual' || $position->signal->origin == 'copyTrading')) {
            return true;
        }

        $psExchangeData = null;

        $exchangeMediator = ExchangeMediator::fromMongoExchange($position->exchange, $psExchangeData);
        if ('bitmex' === strtolower($exchangeMediator->getName())) { //Todo: temporal fix until $exchangeMediator->getQuote4PositionSize return BTC for BitMEX
            $positionQuote = 'BTC';
        } else {
            $positionQuote = $exchangeMediator->getQuote4PositionSize($position->signal->pair, $position->signal->quote);
        }

        if (isset($position->exchange->positionsSize->$positionQuote)) {
            return $position->exchange->positionsSize->$positionQuote->value > 0;
        }

        if (empty($position->exchange->allowedQuoteCurrencies)) {
            return false;
        }

        foreach ($position->exchange->allowedQuoteCurrencies as $quote) {
            if ($positionQuote === $quote) {
                return true;
            }
        }

        return false;
    }

    private function checkProviderDisclaimer($position, $provider)
    {
        //global $Provider;

        //$this->Monolog->sendEntry('debug', "Checking provider disclaimer option.");

        if ($position->provider->_id == 1 || $position->provider->_id === "1")
            return false;

        //$provider = $Provider->getProviderFromId($position->provider->_id);

        if (!isset($provider->options->disclaimer) || !$provider->options->disclaimer)
            return false;

        if (isset($position->provider->disclaimer))
            return !$position->provider->disclaimer;

        return false;
    }

    private function checkIfSignalShouldBeRejectedBecauseItsPremiumProviderAndUserDidntPay($position, $user)
    {
        global $Provider;

        if ($position->paperTrading || $position->testNet) {
            return false;
        }

        //$this->Monolog->sendEntry('debug', "Checking if signal should be rejected because its a premium provider.");

        if ($position->provider->_id == 1)
            return false;

        if (isset($user->stripe->planId) && $user->stripe->planId == "008")
            return false;

        $masterId = isset($position->provider->clonedFrom) ? $position->provider->clonedFrom : $position->provider->_id;
        $provider = $Provider->getProviderFromId($masterId);

        if (!isset($provider->isPaid) || !$provider->isPaid && (!isset($provider->options->customerKey) || !$provider->options->customerKey))
            return false;

        if ($provider->userId == $position->user->_id)
            return false;

        $providerId = is_object($provider->_id) ? $provider->_id->__toString() : $provider->_id;

        if (isset($provider->options->customerKey) && $provider->options->customerKey)
            if (isset($user->provider->$providerId->enableInProvider) && $user->provider->$providerId->enableInProvider)
                return false;

        if (isset($provider->internalPaymentInfo) && isset($provider->internalPaymentInfo->isPremium)
            && $provider->internalPaymentInfo->isPremium)
            if (isset($user->provider->$providerId->stripe) && isset($user->provider->$providerId->stripe->enable)
                && $user->provider->$providerId->stripe->enable)
                return false;

        return true;
    }

    private function checkIfRiskLevelIsAllowed($position)
    {
        //$this->Monolog->sendEntry('debug', "Checking is risk level is allowed.");

        if (!isset($position->provider->riskFilter) || !$position->provider->riskFilter)
            return true;

        if (!isset($position->signal->risk) || !is_numeric($position->signal->risk))
            return true;

        return $position->signal->risk > $position->provider->risk ? false : true;
    }

    private function checkIfSuccessRateIsAllowed($position)
    {
        //$this->Monolog->sendEntry('debug', "Checking success rate.");

        if (empty($position->provider->successRateFilter))
            return true;

        if (!isset($position->signal->successRate) || !is_numeric($position->signal->successRate))
            return true;

        return $position->signal->successRate < $position->provider->successRate ? false : true;
    }

    private function checkSignalTerms($position)
    {
        //$this->Monolog->sendEntry('debug', "Checking signal terms");

        if (!isset($position->provider->terms) || !$position->provider->terms)
            return true;

        if (isset($position->signal->term)) {
            foreach ($position->provider->terms as $term) {
                if ($position->signal->term == $term)
                    return true;
            }

            return false;
        }

        return true;
    }

    /*private function checkIfUserIsActive($user)
    {
        $this->Monolog->sendEntry('debug', "Checking if user is active.");

        return $user->status == 3 || $user->status == 4 ? false : true;
    }*/

    private function checkForDuplicateSignalId($position, $closed = null)
    {
        if (!isset($position->signal->signalId))
            return false;

        if ($closed !== false && !empty($position->provider->isCopyTrading))
            return false;

        $providerId = is_object($position->provider->_id) ? $position->provider->_id
            : ($position->provider->_id == 1 ? $position->provider->_id
                : new \MongoDB\BSON\ObjectId($position->provider->_id));

        $find = [
            'user._id' => $position->user->_id,
            'signal.signalId' => $position->signal->signalId,
            'signal.providerId' => $providerId,
        ];

        if ($closed !== null)
            $find['closed'] = $closed;

        $options = [
            'projection' => [
                '_id' => 1,
            ]
        ];

        $positionsFound = $this->mongoDBLinkRO->selectCollection('position')->find($find, $options);

        $positionsCount = 0;

        foreach ($positionsFound as $positionFound) {
            if ($positionFound->_id != $position->_id) {
                $positionsCount++;
            }
        }

        if ($closed === false && $positionsCount > 0) {
            return true;
        }

        if ($positionsCount > 0 && empty($position->provider->reUseSignalIdIfClosed)) {
            return true;
        }

        return false;
    }
    /**
     * Check if side is accept for this user provider
     *
     * @param PositionMediator $positionMediator position
     * @param string $side     side 'long'|'short'|'both'
     * 
     * @return boolean
     */
    private function checkAllowedSide($positionMediator): bool
    {
        $position = $positionMediator->getPositionEntity();
        $exchangeType = $positionMediator->getExchangeType();
        if (('futures' !== $exchangeType) && ('long' != strtolower($position->side))) {
            return false;
        }

        return !isset($position->exchange->allowedSide)
            || (false === $position->exchange->allowedSide)
            || ('both' === $position->exchange->allowedSide)
            || (strtolower($position->side) === $position->exchange->allowedSide);
    }

    private function checkPositionsForThisMarket($position, $globalCheck = false)
    {
        //$this->Monolog->sendEntry('debug', "Checking positions for this market.");

        if ($position->signal->origin == 'manualBuy' || $position->signal->origin == 'manual'
            || $position->signal->origin == 'copyTrading')
            return true;

        if ($globalCheck) {
            if (empty($position->exchange->globalPositionsPerMarket))
                return true;

            $positionsPerMarket = $position->exchange->globalPositionsPerMarket;
        } else {
            if (empty($position->exchange->positionsPerMarket))
                return true;

            $positionsPerMarket = $position->exchange->positionsPerMarket;
        }

        $find = [
            '_id' => [
                '$ne' => $position->_id,
            ],
            'user._id' => $position->user->_id,
            'closed' => false,
            'signal.pair' => $position->signal->pair,
            //'signal.base' => $position->signal->base,
            //'signal.quote' => $position->signal->quote,
            'exchange.internalId' => $position->exchange->internalId,
        ];

        if (!$globalCheck)
            $find['provider._id'] = $position->provider->_id;

        $positionsCount = $this->mongoDBLink->selectCollection('position')->count($find);

        return $positionsCount < $positionsPerMarket ? true : false;
    }

    private function checkVolume($position, $globalCheck = false)
    {
        //$this->Monolog->sendEntry('debug', "Checking volume");

        if ($position->signal->origin == 'manualBuy' || $position->signal->origin == 'manual'
            || $position->signal->origin == 'copyTrading')
            return true;

        if ($globalCheck) {
            if (!isset($position->exchange->globalMinVolume) || !$position->exchange->globalMinVolume)
                return true;

            $minVolume = $position->exchange->globalMinVolume;
        } else {
            if (!isset($position->exchange->minVolume) || !$position->exchange->minVolume)
                return true;

            $minVolume = $position->exchange->minVolume;
        }

        $exchangeId = strtolower($this->positionMediator->getExchange()->getId());
        /** @var ZignalyLastPriceRedisService $lastPriceService */
        $lastPriceService = $this->container->get('lastPrice');
        $hash = $lastPriceService->getExchangeVolume4Symbol($exchangeId, $this->positionMediator->getSymbol());
        if (!is_array($hash))
            $hash[] = $hash;

        //$this->Monolog->sendEntry('debug', "Volume data for exchange $exchangeId, symbol: {$this->positionMediator->getSymbol()}", $hash);

        if (!isset($hash['btcVolume']))
            return false;

        return $hash['btcVolume'] > $minVolume;
    }

    private function countCurrentOpenPositions($position, $globalCheck = false)
    {

        if ($globalCheck) {
            $maxPositions = isset($position->exchange->globalMaxPositions) ? $position->exchange->globalMaxPositions: null;
        } else {
            $maxPositions = isset($position->exchange->maxPositions) ? $position->exchange->maxPositions : null;
        }

        if ('manualBuy' === $position->signal->origin
            || 'manual' === $position->signal->origin
            || empty($maxPositions)
        ) {
            return true;
        }

        $find = [
            '_id' => [
                '$ne' => $position->_id,
            ],
            'user._id' => $position->user->_id,
            'closed' => false,
        ];

            $find['exchange.internalId'] = $position->exchange->internalId;


        if (!$globalCheck) {
            $find['provider._id'] = $position->provider->_id;
        }

        $try = 3; //We are disabling this because it slow down the processing a lot.
        do {
            $positionsCount = $this->mongoDBLink->selectCollection('position')->count($find);

            $return = $positionsCount < $maxPositions;

            if ($return)
                return true;

            //The following process is for being sure that signals with the same market but different term doesn't detect each other.
            $sleepSeconds = rand(1, 10);
            //$this->Monolog->sendEntry('debug', "Max concurrent positions reached ($try), sleeping $sleepSeconds");

            $try++;

            if ($try < 3)
                sleep(rand(1, 10));
        } while ($try < 3);

        return $return;
    }

    /**
     * Check if the exchange and type from the signal match the linked by the user to the service.
     *
     * @param object $exchange
     * @param array $signal
     * @return bool
     */
    public function checkIfConfiguredExchangeMatchesSignal(object $exchange, array $signal)
    {


        $signalExchangeName = strtolower($signal['exchange']);
        if ($signalExchangeName == 'zignaly') {
            $signalExchangeName = 'binance';
        }
        $signalExchangeType = empty($signal['exchangeAccountType']) ? 'spot' : strtolower($signal['exchangeAccountType']);

        if (isset($exchange->exchangeName)) {
            $userExchangeName = $exchange->exchangeName;
        } elseif (isset($exchange->name)) {
            $userExchangeName = $exchange->name;
        } else {
            return false;
        }

        $userExchangeName = strtolower($userExchangeName);
        if ($userExchangeName == 'zignaly') {
            $userExchangeName = 'binance';
        }
        $userExchangeType = empty($exchange->exchangeType) ? 'spot' : strtolower($exchange->exchangeType);

        return $userExchangeName == $signalExchangeName && $userExchangeType == $signalExchangeType;
    }

    /**
     * Check if the exchange is internal.
     *
     * @param object $exchange
     * @return bool
     */
    public function checkIfExchangeIsInternal(object $exchange) : bool
    {
        if (!empty($exchange->subAccountId) || !empty($exchange->zignalyApiCode)) {
            return true;
        }

        return false;
    }
    /**
     * Create position document from signal's parsed parameters.
     *
     * @param array $exchange
     * @param string $userId
     * @param array $signal
     * @param array $provider
     * @param string $process
     * @param ExchangeCalls $ExchangeCalls
     * @return array
     */
    public function composePosition(array $exchange, string $userId, array $signal, array $provider, string $process,
                                    ExchangeCalls $ExchangeCalls)
    {
        $userId = new \MongoDB\BSON\ObjectId($userId);
        $user = $this->User->getUser($userId);
        $exchange = $this->User->getExchangeSettings($user, $exchange, $provider);
        $exchangeAndTypeMatch = !$exchange ? false : $this->checkIfConfiguredExchangeMatchesSignal($exchange, $signal);
        $exchangeIsInternal = !$exchange ? false : $this->checkIfExchangeIsInternal($exchange);
        $isUserActive = !in_array($user->status, [4]); //4=ban
        $isExchangeActive = !$exchange ? false : true;
        $isPaperTrading = $isExchangeActive && isset($exchange->paperTrading) ? $exchange->paperTrading : false;
        $isTestNet = $isExchangeActive && isset($exchange->isTestnet) ? $exchange->isTestnet : false;

        // $doesSymbolExistsOnExchange = $isUserActive && $isExchangeActive && $exchangeAndTypeMatch
        //    ? $ExchangeCalls->checkIfSymbolExistsInExchange($signal['base'] . $signal['quote']) : false;
        // change base.quote codification to zignaly pair valid 4 BitMEX
        $doesSymbolExistsOnExchange = $isUserActive && $isExchangeActive && $exchangeAndTypeMatch
            ? $ExchangeCalls->checkIfSymbolExistsInExchange($signal['pair']) : false;

        $composeFullPosition = $exchangeIsInternal && $isUserActive && $isExchangeActive
            && $doesSymbolExistsOnExchange && $exchangeAndTypeMatch;

        // change base.quote codification to zignaly pair valid 4 BitMEX
        $symbol = $signal['pair'];
        $side = !empty($signal['side']) ? strtoupper($signal['side']) : 'LONG';
        $entryType = $this->getOrderType($signal, $provider);

        if (!getenv('LANDO') === 'ON') {
            if ($isPaperTrading || $isTestNet) {
                $composeFullPosition = false;
                $status = 107;
            }
        }


        if ($composeFullPosition) {
            $exchangeMediator = ExchangeMediator::fromMongoExchange($exchange);
            $exchangeHandler = $exchangeMediator->getExchangeHandler();
            $multiFirstData = $this->composeMultiData($ExchangeCalls, $exchangeHandler, $user, $signal, $provider,
                $exchange, $symbol, 'original', $entryType);
            $limitPrice = $multiFirstData['limitPrice'];
            $buyStopPrice = $multiFirstData['buyStopPrice'];
            $buyStopPrice = !$buyStopPrice ? $buyStopPrice : (float)$buyStopPrice;
            $leverage = $this->getLeverageFromUserSettingsOrSignal($exchange, $signal, $provider);
            $positionSize = $multiFirstData['positionSize'];
            $amount = $multiFirstData['amount'];
            $trailingStopData = $this->extractTrailingStopData($exchange, $signal, $limitPrice, $provider, $side);
            $reduceOrders = $this->composeReduceOrders($signal, $provider, $side);
            $reBuyTargets = $this->composeReBuyTargets($exchange, $signal, $provider, $side);
            $takeProfitTargets = !$reduceOrders ? $this->composeTakeProfitTargets($exchange, $signal, $provider, $side) : false;
            $multiSecondData = 'MULTI' === $entryType || 'MULTI-STOP' === $entryType ?
                $this->composeMultiData($ExchangeCalls, $exchangeHandler, $user, $signal, $provider, $exchange, $symbol,
                    'short', $entryType)
                : false;
        } else {
            $exchangeHandler = new ExchangeHandler(
                ZignalyExchangeCodes::ZignalyBinance,
                "spot"
            );
            $limitPrice = false;
            $buyStopPrice = false;
            $leverage = 1;
            $positionSize = false;
            $trailingStopData = false;
            $reduceOrders = false;
            $reBuyTargets = false;
            $takeProfitTargets = false;
            $multiSecondData = false;
            $multiFirstData = false;
            $amount = 0;
        }

        if (!$isUserActive) {
            $status = 48;
        } elseif (!$isExchangeActive) {
            $status = 57;
        } elseif (!$exchangeAndTypeMatch) {
            $status = 88;
        } elseif (!$doesSymbolExistsOnExchange) {
            $status = 82;
        } elseif (empty($status)) {
            $status = 0;
        }

        return [
            'user' => [
                '_id' => $userId,
            ],
            'exchange' => $exchange,
            'signal' => $this->composeSignal($signal, $provider),
            'provider' => $this->composeProvider($provider),
            'multiFirstData' => $multiFirstData,
            'multiSecondData' => $multiSecondData,
            'takeProfitTargets' => $takeProfitTargets,
            'DCAFromBeginning' => !empty($signal['DCAFromBeginning']),
            'DCAPlaceAll' => !empty($signal['DCAPlaceAll']),
            'reBuyProcess' => !empty($signal['DCAFromBeginning']),
            'reBuyTargets' => $reBuyTargets,
            'reduceOrders' => $reduceOrders,
            'createdAt' => new \MongoDB\BSON\UTCDateTime(),
            'side' => $side,
            'amount' => !$amount ? 0 : (float)$amount,
            'realAmount' => 0,
            'buyTTL' => $this->extractTTLs($exchange, 'buyTTL', $signal),
            'cancelBuyAt' => $this->extractCancelBuyAt($exchange, $signal),
            'limitPrice' => empty($limitPrice) ? 0 : (float)$limitPrice,
            'buyType' => $entryType,
            'buyStopPrice' => $buyStopPrice,
            'status' => $status,
            'realBuyPrice' => empty($limitPrice) ? 0 : (float)$limitPrice,
            'avgBuyingPrice' => empty($limitPrice) ? 0 : (float)$limitPrice,
            'positionSize' => !$positionSize ? false : (float)$positionSize,
            'closed' => !$composeFullPosition,
            'checkStop' => false,
            'buyPerformed' => false,
            'remainAmount' => 0,
            'lastUpdate' => new \MongoDB\BSON\UTCDateTime(),
            'stopLossPercentage' => $this->extractStopLossForBuying($exchange, $signal, $limitPrice, $provider, $side),
            'stopLossPrice' => $this->extractStopLossPriceFromSignal($signal, $provider),
            'stopLossPriority' => empty($signal['stopLossPriority']) ? 'percentage' : strtolower($signal['stopLossPriority']),
            'stopLossFollowsTakeProfit' => !empty($signal['stopLossFollowsTakeProfit']),
            'stopLossToBreakEven' => !empty($signal['stopLossToBreakEven']),
            'stopLossForce' => !empty($signal['stopLossForce']),
            'stopLossPercentageLastUpdate' => new \MongoDB\BSON\UTCDateTime(),
            'trailingStopPercentage' => $trailingStopData ? $trailingStopData['trailingStopDistancePercentage'] : false,
            'trailingStopDistancePercentage' => $trailingStopData ? $trailingStopData['trailingStopDistancePercentage'] : false,
            'trailingStopTriggerPercentage' => $trailingStopData ? $trailingStopData['trailingStopTriggerPercentage'] : false,
            'trailingStopTriggerPrice' => $trailingStopData ? $trailingStopData['trailingStopTriggerPrice'] : false,
            'trailingStopTriggerPriority' => $trailingStopData ? $trailingStopData['trailingStopTriggerPriority'] : false,
            'trailingStopLastUpdate' => new \MongoDB\BSON\UTCDateTime(),
            'trailingStopPrice' => false,
            'sellByTTL' => $this->extractTTLs($exchange, 'sellByTTL', $signal),
            'updating' => false,
            'increasingPositionSize' => false,
            'version' => 2,
            'sellPerformed' => false,
            'accounted' => false,
            'watchingPrice' => false,
            'watchingPriceAt' => new \MongoDB\BSON\UTCDateTime(),
            'lastCheckingOpenOrdersAt' => new \MongoDB\BSON\UTCDateTime(),
            'checkingOpenOrdersLastGlobalCheck' => new \MongoDB\BSON\UTCDateTime(),
            'checkingOpenOrders' => false,
            'locked' => true,
            'lockedAt' => new \MongoDB\BSON\UTCDateTime(),
            'lockedBy' => $process,
            'lockedFrom' => gethostname(),
            'lockId' => md5(uniqid(microtime(true) * rand(1, 1000), true)),
            'paperTrading' => $isPaperTrading,
            'testNet' => $isTestNet,
            'leverage' => $leverage,
            'realInvestment' => $this->getRealInvestment($positionSize, $signal, $leverage, $exchangeHandler),
            'redisRemoved' => false,
            'copyTraderStatsDone' => false,
            'marginMode' => $signal['marginMode'] ?? $exchangeHandler->getDefaultMarginMode(),
            'order' => [],
            'trades_updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];
    }

    /**
     * Return the amount for a position.
     * @param ExchangeCalls $ExchangeCalls
     * @param string $symbol
     * @param float|bool $positionSize
     * @param float|bool $limitPrice
     * @param ExchangeHandler $exchangeHandler
     * @return float
     */
    private function getAmount(ExchangeCalls $ExchangeCalls, string $symbol, float $positionSize, float $limitPrice, ExchangeHandler $exchangeHandler)
    {
        if (empty($limitPrice) || empty($positionSize)) {
            return 0.0;
        }

        $this->Monolog->sendEntry('debug', "Amount: $positionSize / $limitPrice");

        $amount = empty($limitPrice) ? false : $ExchangeCalls->getAmountToPrecision(
            $exchangeHandler->calculateAmountFromPositionSize(
                $symbol,
                $positionSize,
                $limitPrice
            ),
            $symbol
        );

        return (float)$amount;
    }

    /**
     * Compose the data for the short order in a multi type position.
     * @param ExchangeCalls $ExchangeCalls
     * @param \MongoDB\Model\BSONDocument $user
     * @param array $signal
     * @param array $provider
     * @param array $exchange
     * @param string $symbol
     * @param string $suffix
     * @param string $entryType
     * @return array
     */
    private function composeMultiData(
        ExchangeCalls $ExchangeCalls,
        ExchangeHandler $exchangeHandler,
        \MongoDB\Model\BSONDocument $user,
        array $signal,
        array $provider,
        object $exchange,
        string $symbol,
        string $suffix,
        string $entryType
    ) {
        $multiOrderType = $this->extractParameterFromMulti($signal, $suffix, 'orderType');
        $signalCopy = $signal;
        $signalCopy['orderType'] = $multiOrderType;
        if (isset($signalCopy['buyType'])) {
            unset($signalCopy['buyType']);
        }
        $multiOrderType = $this->getOrderType($signalCopy, $provider);
        if (empty($multiOrderType) || ('MARKET' === $multiOrderType) && 'MULTI' === $entryType) {
            $multiOrderType = 'LIMIT';
        }
        $multiLimitPrice = $this->extractParameterFromMulti($signal, $suffix, 'limitPrice');
        $multiPrice = $this->extractParameterFromMulti($signal, $suffix, 'price');
        $limitPrice = $ExchangeCalls->getPrice($symbol, $provider, $exchange, $multiOrderType, $multiLimitPrice, $multiPrice);

        if (!empty($provider['isCopyTrading'])) {
            $multiPositionSizePercentage = $this->extractParameterFromMulti($signal, $suffix, 'positionSizePercentage');
            $signal['positionSize'] = !empty($multiPositionSizePercentage) ?
                $this->getPositionSize($multiPositionSizePercentage, $user, $provider) : false;
        }

        $multiBuyStopPrice = $this->extractParameterFromMulti($signal, $suffix, 'buyStopPrice');
        $buyStopPrice = !empty($multiBuyStopPrice) ? $ExchangeCalls->getPriceToPrecision($multiBuyStopPrice, $symbol) : false;
        $buyStopPrice = !$buyStopPrice ? $buyStopPrice : (float)$buyStopPrice;

        $leverage = $this->getLeverageFromUserSettingsOrSignal($exchange, $signal, $provider);
        $positionSize = $this->extractPositionSize($exchange, $signal, $user, $ExchangeCalls, $leverage);
        $amount = $this->getAmount($ExchangeCalls, $symbol, $positionSize, $limitPrice, $exchangeHandler);
        $side = !empty($signal['side']) ? strtoupper($signal['side']) : 'LONG';

        return [
            'orderType' => $multiOrderType,
            'limitPrice' => $limitPrice,
            'buyStopPrice' => $buyStopPrice,
            'positionSize' => $positionSize,
            'amount' => $amount,
            'side' => 'original' === $suffix || 'long' === $suffix ? $side : 'SHORT',
        ];
    }

    /**
     * Extract the parameter having multi entries into consideration.
     * @param array $signal
     * @param string $suffix
     * @param string $field
     * @return bool|mixed
     */
    private function extractParameterFromMulti(array $signal, string $suffix, string $field)
    {
        if ('original' === $suffix || 'long' === $suffix) {
            if (!empty($signal[$field . '_long'])) {
                $parameter = $signal[$field . '_long'];
            } elseif (!empty($signal[$field])) {
                $parameter = $signal[$field];
            } else {
                $parameter = false;
            }
        } else {
            if (!empty($signal[$field . '_short'])) {
                $parameter = $signal[$field . '_short'];
            } elseif (!empty($signal[$field])) {
                $parameter = $signal[$field];
            } else {
                $parameter = false;
            }
        }

        return is_numeric($parameter) ? (float)$parameter : $parameter;
    }



    /**
     * Extract the real investment from the signal and position Size.
     *
     * @param bool|int|float $positionSize
     * @param array $signal
     * @param int $leverage
     * @param ExchangeHandler $exchangeHandler
     * @return bool|float
     */
    private function getRealInvestment($positionSize, array $signal, int $leverage, $exchangeHandler)
    {
        $investment = false;

        if (!isset($signal['realInvestment'])) {
            if ($positionSize) {
                // $realInvestment = $positionSize;
                $investment = $exchangeHandler->calculateRealInvestmentFromPositionSize(
                    $signal['pair'],
                    $positionSize
                );
            }
        }

        if (isset($signal['realInvestment']) && is_numeric($signal['realInvestment'])) {
            $investment = $signal['realInvestment'];
        }

        $realInvestment = $investment / ($leverage > 0? $leverage : 1);

        return $realInvestment > 0 ? (float)$realInvestment : 0;
    }

    /**
     * Select the proper leverage based on user preferences.
     *
     * @param bool|\MongoDB\Model\BSONDocument $exchange
     * @param array $signal
     * @param array $provider
     * @return int
     */
    public function getLeverageFromUserSettingsOrSignal($exchange, $signal, $provider)
    {
        $leverage = false;

        if (isset($signal['leverage'])) {
            if ($signal['origin'] == 'copyTrading' || $signal['origin'] == 'manual') {
                $leverage = $signal['leverage'];
            }
            if (isset($provider['useLeverageFromSignal']) && $provider['useLeverageFromSignal']) {
                $leverage = $signal['leverage'];
            }
        }

        if (!$leverage && isset($exchange->leverage)) {
            $leverage = $exchange->leverage;
        }

        if ($leverage >= 1 && $leverage <= 125) {
            $leverage = intval($leverage);
        }

        return !$leverage ? 1 : $leverage;
    }

    public function createImportData($positionId, $position)
    {
        $exchangeName = $position['exchange']['name'];
        $exchangeType = ExchangeMediator::getExchangeTypeFromArray($position['signal'], 'exchangeAccountType');
        $zignalyPair = $position['signal']['pair'];
        $exchangeHandler = ExchangeHandler::newInstance($exchangeName, $exchangeType);
        $limitPrice = is_object($position['limitPrice']) ? $position['limitPrice']->__toString() : $position['limitPrice'];
        $amount = is_object($position['amount']) ? $position['amount']->__toString() : $position['amount'];
        $update = [
            '$set' => [
                'orders.1' => $this->createFakeOrder($exchangeHandler, $limitPrice, $amount, $zignalyPair),
                'status' => 9,
                'buyPerformed' => true,
                'reBuyProcess' => true,
            ],
            '$push' => ['trades' => $this->createFakeTrade($exchangeHandler, $limitPrice, $amount, $zignalyPair, $position['side'])],
        ];
        if ($this->rawUpdatePosition($positionId, $update)) {
            $position = $this->getPosition($positionId);
            $this->recalculateNumbersFromTradesAndUpdatePosition($position);
            $RabbitMQ = new RabbitMQ();
            $message['positionId'] = $position->_id->__toString();
            $message = json_encode($message, JSON_PRESERVE_ZERO_FRACTION);
            $RabbitMQ->publishMsg('takeProfit', $message);
        } else {
            $update = [
                '$set' => [
                    'closed' => true,
                    'status' => 69,
                ],
            ];
            $this->rawUpdatePosition($positionId, $update);
            $position = $this->getPosition($positionId);
            /*if (!empty($position->closed)) {
                $this->copyDocumentToClosedPositionCollection($position);
            }*/
        }
    }
    /**
     * Undocumented function
     *
     * @param ExchangeHandler $exchangeHandler
     * @param float $price
     * @param float $amount
     * @param string $zignalyPair
     * @return array
     */
    private function createFakeOrder($exchangeHandler, $price, $amount, $zignalyPair)
    {
        return [
            "orderId" => 1,
            "status" => "closed",
            "type" => "entry",
            "price" => $price,
            "amount" => $amount,
            // "cost" => $price * $amount,
            "cost" => $exchangeHandler->calculateOrderCostZignalyPair($zignalyPair, $amount, $price),
            "transacTime" => new \MongoDB\BSON\UTCDateTime(),
            "orderType" => "IMPORT",
            "done" => true,
        ];
    }

    private function createFakeTrade(ExchangeHandler $exchangeHandler, $price, $quantity, $symbol, $side)
    {
        $tradeCost = number_format(
            $exchangeHandler->calculateOrderCostZignalyPair(
                $symbol,
                $quantity,
                $price
            ), 12, '.', '');
        return [
            "symbol" => $symbol,
            "id" => 1,
            "orderId" => 1,
            "price" => $price,
            "qty" => $quantity,
            //"quoteQty" => number_format($price * $quantity, 12, '.', ''),
            "cost" => $tradeCost,
            "quoteQty" => $tradeCost,
            "commission" => "0",
            "commissionAsset" => "BNB",
            "time" => time() * 1000,
            "isBuyer" => strtoupper($side) == 'LONG',
            "isMaker" => true,
            "isBestMatch" => true
        ];
    }

    /**
     * Extract the order type from the signal.
     *
     * @param array $signal
     * @param array $provider
     * @return string
     */
    private function getOrderType(array $signal, array $provider)
    {
        if (isset($signal['buyType'])) {
            $type = strtoupper($signal['buyType']);
        } elseif (isset($signal['orderType'])) {
            $type = strtoupper($signal['orderType']);
        }

        if (empty($type)) {
            $type = 'LIMIT';
        }

        if ($type == 'MARKET' && !$this->checkIfMarketOrderIsAccepted($signal, $provider)) {
            $type = 'LIMIT';
        }


        if ($type == 'STOP_LOSS_LIMIT') {
            $type = 'STOP-LIMIT';
        }

        return $type;
    }

    /**
     * Check if the user accept market entry orders.
     *
     * @param array $signal
     * @param array $provider
     * @return bool
     */
    private function checkIfMarketOrderIsAccepted(array $signal, array $provider)
    {
        if ($signal['origin'] == 'manualBuy' || $signal['origin'] == 'manual') {
            return true;
        }

        if ($signal['origin'] == 'copyTrading') {
            return true;
        }

        if (!empty($provider['allowSendingBuyOrdersAsMarket'])) {
            return true;
        }

        return false;
    }

    public function composeReBuyTargets($exchange, $signal, $provider, $side = 'LONG')
    {
        if (!$exchange)
            return false;

        if ($signal['origin'] == 'manualBuy' || $signal['origin'] == 'manual' || $signal['origin'] == 'copyTrading')
            return $this->composeTargetsFromManualBuy($signal, 'reBuyTargets', $side);

        return $this->composeReBuyTargetsFromNonManualBuy($exchange, $signal, $provider, $side);
    }

    /**
     * Compose the reBuyTargets from a signal having into consideration the position side.
     *
     * @param bool|\MongoDB\Model\BSONDocument $exchange
     * @param array $signal
     * @param array $provider
     * @param string $side
     * @return array|bool
     */
    private function composeReBuyTargetsFromNonManualBuy($exchange, array $signal, array $provider, string $side = 'LONG')
    {
        if (isset($provider['reBuysFromSignal']) && $provider['reBuysFromSignal']
            && isset($signal['reBuyTargets']) && $signal['reBuyTargets']) {
            $fromSignal = true;
        } elseif (isset($exchange->reBuyTargets) && $exchange->reBuyTargets) {
            $fromSignal = false;
        } else {
            return false;
        }

        $reBuyTargets = [];
        $targets = $fromSignal ? $signal['reBuyTargets'] : $exchange->reBuyTargets;
        foreach ($targets as $target) {
            $targetId = $fromSignal ? $target['targetId'] : $target->targetId;
            $priceTargetPercentage = $fromSignal ? $target['priceTargetPercentage'] : $target->priceTargetPercentage;
            if ($side == 'SHORT' && $priceTargetPercentage < 1) {
                $priceTargetPercentage = 2 - $priceTargetPercentage;
            }
            if ($side == 'LONG' && $priceTargetPercentage > 1) {
                $priceTargetPercentage = 2 - $priceTargetPercentage;
            }
            $amountPercentage = $fromSignal ? $target['amountPercentage'] : $target->amountPercentage;
            $reBuyTargets[$targetId] = [
                'targetId' => $targetId,
                'triggerPercentage' => $priceTargetPercentage,
                'quantity' => $amountPercentage,
                'buying' => false,
                'done' => false,
                'orderId' => false,
                'cancel' => false,
                'skipped' => false,
                'buyType' => 'LIMIT',
                'postOnly' => $target['postOnly'] ?? false
            ];
        }

        return ($fromSignal) ? $this->filterTargets($reBuyTargets, $provider, 'reBuyTargets') : $reBuyTargets;
    }

    private function filterTargets($targets, $provider, $type)
    {
        //Type: reBuyTargets | takeProfitTargets
        if (empty($targets)) {
            return $targets;
        }

        if ($type == 'reBuyTargets')
            $filterTargetName = 'reBuy';
        else
            $filterTargetName = 'takeProfit';

        if (isset($provider[$filterTargetName . 'All']) && $provider[$filterTargetName . 'All'])
            return $targets;

        if (isset($provider[$filterTargetName . 'First']) && $provider[$filterTargetName . 'First']
            && isset($provider[$filterTargetName . 'Last']) && $provider[$filterTargetName . 'Last']) {
            $returnTargets = [];
            $returnTargets[1] = $this->takeFirstOrLastTarget($targets, true);
            $returnTargets[1]['targetId'] = "1";
            $returnTargets[2] = $this->takeFirstOrLastTarget($targets, false);
            $returnTargets[2]['targetId'] = "2";
            if ($type == 'takeProfitTargets') {
                $returnTargets[1]['amountPercentage'] = "0.5";
                $returnTargets[2]['amountPercentage'] = "0.5";
            }

            return $returnTargets;
        }

        if (isset($provider[$filterTargetName . 'First']) && $provider[$filterTargetName . 'First']) {
            $returnTargets = [];
            $returnTargets[1] = $this->takeFirstOrLastTarget($targets, true);
            $returnTargets[1]['targetId'] = "1";
            if ($type == 'takeProfitTargets')
                $returnTargets[1]['amountPercentage'] = "1";

            return $returnTargets;
        }

        if (isset($provider[$filterTargetName . 'Last']) && $provider[$filterTargetName . 'Last']) {
            $returnTargets = [];
            $returnTargets[1] = $this->takeFirstOrLastTarget($targets, false);
            $returnTargets[1]['targetId'] = "1";
            if ($type == 'takeProfitTargets')
                $returnTargets[1]['amountPercentage'] = "1";

            return $returnTargets;
        }

        return false;
    }

    /**
     * Check if there are reduce parameters in the entry signal and compose the reduceTarget.
     *
     * @param array $signal
     * @param array $provider
     * @param string $side
     * @return bool|array
     */
    public function composeReduceOrders(array $signal, array $provider, string $side)
    {
        $doOrigins = ['manualBuy', 'manual', 'copyTrading'];
        $reduceOrders = false;
        if (in_array($signal['origin'], $doOrigins) || !empty($provider['acceptReduceOrders'])) {
            if (!empty($signal['reduceTargetPercentage']) && !empty(['reduceAvailablePercentage'])) {
                $error = false;
                $signal['reduceOrderType'] = empty($signal['reduceOrderType']) ? 'limit' : $signal['reduceOrderType'];
                if ($signal['reduceOrderType'] == 'market') {
                    $error = "Type can't be market from an entry signal.";
                }
                if (!empty($signal['reduceRecurring']) && $signal['reduceOrderType'] == 'market') {
                    $error = "Recurring option is incompatible with market order type.";
                }
                if ($side == 'LONG' && $signal['reduceTargetPercentage'] <= 1) {
                    $error = "Target price has to be bigger than 0 for LONG positions in entry signals.";
                }
                if ($side == 'SHORT' && $signal['reduceTargetPercentage'] >= 1) {
                    $error = "Target price has to be lower than 0 for SHORT positions in entry signals.";
                }

                $reduceOrders[1] = [
                    'targetId' => 1,
                    'type' => $signal['reduceOrderType'],
                    'targetPercentage' => empty($signal['reduceTargetPercentage']) ? false : $signal['reduceTargetPercentage'],
                    'priceTarget' => empty($signal['reduceTargetPrice']) ? false : $signal['reduceTargetPrice'],
                    'pricePriority' => empty($signal['reduceTargetPriority']) ? false : $signal['reduceTargetPriority'],
                    'availablePercentage' => $signal['reduceAvailablePercentage'],
                    'recurring' => !empty($signal['reduceRecurring']),
                    'persistent' => !empty($signal['reducePersistent']),
                    'orderId' => false,
                    'done' => !empty($error),
                    'error' => $error,
                    'postOnly' => $signal['reducePostOnly'] ?? false,
                ];
            }
        }

        return $reduceOrders;
    }

    public function composeTakeProfitTargets($exchange, $signal, $provider, $side = 'LONG')
    {
        if (!$exchange)
            return false;

        if ($signal['origin'] == 'manualBuy' || $signal['origin'] == 'manual' || $signal['origin'] == 'copyTrading')
            return $this->composeTargetsFromManualBuy($signal, 'takeProfitTargets', $side);

        return $this->composeTakeProfitTargetsFromNonManualBuy($exchange, $signal, $provider, $side);
    }

    /**
     * Compose the takeProfitTargets from a signal having into consideration the position side.
     *
     * @param bool|\MongoDB\Model\BSONDocument $exchange
     * @param array $signal
     * @param array $provider
     * @param string $side
     * @return array|bool
     */
    private function composeTakeProfitTargetsFromNonManualBuy($exchange, array $signal, array $provider, string $side = 'LONG')
    {
        if (!empty($provider['takeProfitsFromSignal']) && isset($signal['takeProfitTargets']) && $signal['takeProfitTargets']) {
            $fromSignal = true;
        } elseif (isset($exchange->takeProfitTargets) && $exchange->takeProfitTargets) {
            $fromSignal = false;
        } else {
            return false;
        }

        $takeProfitTargets = [];
        $targets = $fromSignal ? $signal['takeProfitTargets'] : $exchange->takeProfitTargets;
        foreach ($targets as $target) {
            $targetId = $fromSignal ? $target['targetId'] : $target->targetId;
            $priceTargetPercentage = $fromSignal ? $target['priceTargetPercentage'] : $target->priceTargetPercentage;
            if ($side == 'SHORT' && $priceTargetPercentage > 1) {
                $priceTargetPercentage = 2 - $priceTargetPercentage;
            }
            if ($side == 'LONG' && $priceTargetPercentage < 1) {
                $priceTargetPercentage = 2 - $priceTargetPercentage;
            }
            $amountPercentage = $fromSignal ? ($target['amountPercentage']?? false) : ($target->amountPercentage?? false);
            $priceTarget = $fromSignal ? ($target['priceTarget']?? false) : ($target->priceTarget?? false);
            $pricePriority = $fromSignal ? ($target['pricePriority']?? false) : ($target->pricePriority?? false);
            $takeProfitTargets[$targetId] = [
                'targetId' => $targetId,
                'priceTargetPercentage' => $priceTargetPercentage,
                'priceTarget' => $priceTarget,
                'pricePriority' => $pricePriority,
                'amountPercentage' => $amountPercentage,
                'updating' => false,
                'done' => false,
                'orderId' => false,
                'postOnly' => $target['postOnly'] ?? false
            ];
        }

        return ($fromSignal) ? $this->filterTargets($takeProfitTargets, $provider, 'takeProfitTargets')
            : $takeProfitTargets;
    }


    private function takeFirstOrLastTarget($targets, $first = true)
    {
        foreach ($targets as $target) {
            if ($first)
                return $target;
        }

        return $target;
    }

    /**
     * Compose the takeProfitsTargets or ReBuyTargets from the signal for a manual position having into consideration
     * the position side.
     *
     * @param array $signal
     * @param string $targetsType
     * @param string $side
     * @return array|bool
     */
    private function composeTargetsFromManualBuy(array $signal, string $targetsType, string $side = 'LONG')
    {
        //Targets Type: takeProfitTargets reBuyTargets
        if (empty($signal[$targetsType]))
            return false;

        $returnTargets = [];

        $targets = $signal[$targetsType];
        foreach ($targets as $target) {
            $targetId = $target['targetId'];
            $amountPercentage = isset($target['amountPercentage']) ? $target['amountPercentage'] : null;
            if ($targetsType == 'takeProfitTargets') {
                $priceTargetPercentage = $target['priceTargetPercentage'];
                if ($side == 'SHORT' && $target['priceTargetPercentage'] > 1) {
                    $priceTargetPercentage = 2 - $target['priceTargetPercentage'];
                }
                if ($side == 'LONG' && $target['priceTargetPercentage'] < 1) {
                    $priceTargetPercentage = 2 - $target['priceTargetPercentage'];
                }
                $returnTargets[$targetId] = [
                    'targetId' => $targetId,
                    'priceTargetPercentage' => $priceTargetPercentage,
                    'priceTarget' => empty($target['priceTarget']) ? false : $target['priceTarget'],
                    'pricePriority' => empty($target['pricePriority']) ? 'percentage' : $target['pricePriority'],
                    'amountPercentage' => $amountPercentage,
                    'updating' => false,
                    'done' => false,
                    'orderId' => false,
                    'postOnly' => $target['postOnly'] ?? false
                ];
            } else {
                $priceTargetPercentage = $target['priceTargetPercentage'];
                if ($side == 'SHORT' && $target['priceTargetPercentage'] < 1) {
                    $priceTargetPercentage = 2 - $target['priceTargetPercentage'];
                }
                if ($side == 'LONG' && $target['priceTargetPercentage'] > 1) {
                    $priceTargetPercentage = 2 - $target['priceTargetPercentage'];
                }

                $returnTargets[$targetId] = [
                    'targetId' => $targetId,
                    'triggerPercentage' => $priceTargetPercentage,
                    'priceTarget' => empty($target['priceTarget']) ? false : $target['priceTarget'],
                    'pricePriority' => empty($target['pricePriority']) ? 'percentage' : $target['pricePriority'],
                    'quantity' => $amountPercentage,
                    'newInvestment' => isset($target['newInvestment']) ? $target['newInvestment'] : null,
                    'buying' => false,
                    'done' => false,
                    'orderId' => false,
                    'cancel' => false,
                    'skipped' => false,
                    'buyType' => 'LIMIT',
                    'postOnly' => $target['postOnly'] ?? false
                ];
            }
        }

        return $returnTargets;
    }

    public function configureLogging(Monolog $Monolog)
    {
        $this->Monolog = $Monolog;
    }

    /**
     * Configure the logging container.
     *
     * @param Container $container
     */
    public function configureLoggingByContainer(Container $container)
    {
        try {
            $this->Monolog = $container->get('monolog');
        } catch (Exception $e) {
            echo time() . " " . $e->getMessage() . "\n";
        }
    }

    public function getActivePositionsFromProviderKeyAndSignalId($signal, $setBuyPerformed = true)
    {
        if (isset($signal['positionId'])) {
            $positionId = new \MongoDB\BSON\ObjectId($signal['positionId']);
            $find = [
                '_id' => $positionId,
            ];
        } else {
            $providerKey = $signal['key'];
            $signalId = !empty($signal['exitSignalId']) ? $signal['exitSignalId'] : $signal['signalId'];
            $base = isset($signal['base']) ? $signal['base'] : false;
            $quote = isset($signal['quote']) ? $signal['quote'] : false;
            $find = [
                'signal.signalId' => $signalId,
                'signal.key' => $providerKey,
                'signal.base' => $base,
                'signal.quote' => $quote,
                'closed' => false,
            ];

            if ($setBuyPerformed) {
                $find['buyPerformed'] = true;
            }

            if (!empty($signal['exitSide'])) {
                $find['side'] = strtoupper($signal['exitSide']);
            }
        }

        return $this->mongoDBLink->selectCollection('position')->find($find);
    }

    /**
     * Look for open positions with pending initial entry and mark them for canceling such entry.
     *
     * @param array $signal
     * @return \MongoDB\UpdateResult
     */
    public function markPositionsForCancelingEntry(array $signal)
    {
        $providerKey = $signal['key'];
        $signalId = $signal['signalId'];
        $base = $signal['base'];
        $quote = $signal['quote'];
        $find = [
            'signal.signalId' => $signalId,
            'signal.key' => $providerKey,
            'signal.base' => $base,
            'signal.quote' => $quote,
            'buyPerformed' => false,
            'closed' => false,
        ];
        $update = [
            '$set' => [
                'manualCancel' => true,
                'updating' => true,
                'lastUpdatingAt' => new \MongoDB\BSON\UTCDateTime(),
                'checkExtraParametersAt' => new \MongoDB\BSON\UTCDateTime(0),
            ],
        ];

        return $this->mongoDBLink->selectCollection('position')->updateMany($find, $update);
    }

    public function getOpenPositionsFromProviderKeyAndPanicOptions($providerKey, $panicBase, $panicQuote)
    {
        $find = [
            'signal.key' => $providerKey,
            'closed' => false,
        ];

        if ($panicBase)
            $find['signal.base'] = strtoupper($panicBase);

        if ($panicQuote)
            $find['signal.quote'] = strtoupper($panicQuote);

        return $this->mongoDBLink->selectCollection('position')->find($find);
    }

    public function getPosition($positionId)
    {
        $positionId = is_object($positionId) ? $positionId : new \MongoDB\BSON\ObjectId($positionId);

        return $this->mongoDBLink->selectCollection('position')->findOne(['_id' => $positionId]);
    }

    public function getPositions($status, $closed, $updating, $increasingPositionSize = 'any', $watchingPrice = 'any', $checkingOpenOrders = 'any', $quote = 'any', $userId = false)
    {
        $find['closed'] = $closed;

        if ($status !== false)
            $find['status'] = $status;

        if ($updating !== 'any')
            $find['updating'] = $updating;

        if ($increasingPositionSize !== 'any')
            $find['increasingPositionSize'] = $increasingPositionSize;

        if ($watchingPrice !== 'any')
            $find['watchingPrice'] = $watchingPrice;

        if ($checkingOpenOrders !== 'any')
            $find['checkingOpenOrders'] = $checkingOpenOrders;

        if ($quote !== 'any')
            $find['signal.quote'] = $quote;

        if ($userId)
            $find['user._id'] = $userId;

        $options = [
            'sort' => [
                'watchingPriceAt' => 1,
            ]
        ];

        return $this->mongoDBLink->selectCollection('position')->find($find, $options);
    }

    public function insertAndGetId($position)
    {
        return $this->mongoDBLink->selectCollection('position')->insertOne($position)->getInsertedId();
    }

    public function recalculateNumbersFromTradesAndUpdatePosition($position)
    {
        $realAmount = 0;
        $remainAmount = 0;
        $realPositionSize = 0;
        $tradesId = [];
        $side = empty($position->side) ? 'LONG' : $position->side;

        // avoid to substract the BNB commission when binance futures and BNB position
        $isBinanceExchange = ZignalyExchangeCodes::isBinance(
            ZignalyExchangeCodes::getRealExchangeName($position->exchange->exchangeName)
        );

        $positionMediator = PositionMediator::fromMongoPosition($position);

        $isFutures = 'futures' === $positionMediator->getExchangeType();
        $avoidSubstractCommision = ($isBinanceExchange && $isFutures && ('BNB' == $position->signal->base));

        if (isset($position->trades)) {
            foreach ($position->trades as $trade) {
                $tradeIdOrderId = $trade->id.$trade->orderId;
                if (!in_array($tradeIdOrderId, $tradesId)) {
                    $tradesId[] = $tradeIdOrderId;
                    if (($side == 'LONG' && $trade->isBuyer) || ($side == 'SHORT' && !$trade->isBuyer)) {
                        $remainAmount += $trade->qty;
                        $realAmount += $trade->qty;
                        $realPositionSize += $trade->qty * $trade->price;
                        if (($trade->commissionAsset == $position->signal->base) && $avoidSubstractCommision) {
                            $remainAmount -= $trade->commission;
                        }
                    } else {
                        $remainAmount -= $trade->qty;
                    }
                }
            }
        }
        $avgBuyingPrice = $realPositionSize / $realAmount;
        $setPosition = [
            'realAmount' => (float)$realAmount,
            'remainAmount' => (float)$remainAmount,
            'realPositionSize' => (float)$realPositionSize,
            'avgBuyingPrice' => (float)$avgBuyingPrice,
        ];

        $this->Monolog->sendEntry('debug', "Recalculating numbers from trades for position : "
            . $position->_id->__toString(), $setPosition);

        return $this->setPosition($position->_id, $setPosition, true);
    }

    /**
     * @param \MongoDB\Model\BSONDocument $position
     * @return mixed
     */
    function copyDocumentToClosedPositionCollection(\MongoDB\Model\BSONDocument $position)
    {
        $find = [
            '_id' => $position->_id,
        ];

        $update = [
            '$set' => $position
        ];

        $options = [
            'upsert' => true,
        ];

        return $this->mongoDBLink->selectCollection('position_closed')->updateOne($find, $update, $options);
    }

    public function rawUpdatePosition($positionId, $update)
    {
        $positionId = !is_object($positionId) ? new \MongoDB\BSON\ObjectId($positionId) : $positionId;

        $find = [
            '_id' => $positionId,
        ];

        $position = $this->mongoDBLink->selectCollection('position')->updateOne($find, $update);

        return $position->getModifiedCount() == 1;
    }

    public function setPosition($positionId, $setPosition, $updateLastUpdate = true, $returnDocument = false)
    {
        if ($updateLastUpdate)
            $setPosition['lastUpdate'] = new \MongoDB\BSON\UTCDateTime();

        $options = [
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ];

        $position = $this->mongoDBLink->selectCollection('position')->findOneAndUpdate(
            ['_id' => $positionId], ['$set' => $setPosition], $options);

        /*if (!empty($position->closed)) {
            $this->copyDocumentToClosedPositionCollection($position);
        }*/

        return isset($position->status) ? $position : false;
    }


    /**
     * Get cost buys - cost sells of non profit sharing accounted positions for service
     *
     * @param string $providerId
     * @return float
     */
    public function getNotAccountedServicePositionsAccountingSummary($providerId)
    {
        $pipeline = [
            ['$match' => [
                "provider._id" => $providerId,
                ]
            ],
            ['$unwind' => '$trades'],
            ['$project' => [
                '_id' => '$trades.id',
                'orderId' => '$trades.orderId',
                'isBuyer' => '$trades.isBuyer',
                'price' => '$trades.price',
                'qty' => '$trades.qty'
            ]],
        ];

        $summaryEntries = $this->mongoDBLink->selectCollection('position')->aggregate($pipeline);
        $summary = 0;
        $tradeIds = [];
        foreach ($summaryEntries as $summaryEntry) {
            $uniqueId = $summaryEntry->_id . $summaryEntry->orderId;
            if (!in_array($uniqueId, $tradeIds)) {
                $tradeIds[] = $uniqueId;
                $summary += ($summaryEntry->isBuyer)
                    ? ($summaryEntry->price * $summaryEntry->qty)
                    : (- $summaryEntry->price * $summaryEntry->qty);
            }
        }

        return $summary;
    }

    /**
     * Get Open positions for provider
     *
     * @param string $providerId
     * @return BSONDocument[]
     */
    public function getOpenPositionsForProvider($providerId)
    {
        $find = [
            'closed' => false,
            'provider._id' => $providerId,
        ];

        return $this->mongoDBLink->selectCollection('position')->find($find);
    }

    private function checkStopLossPriceForSelling($position, $priceValue, $amount, $stopLossPercentage, $symbol, ExchangeCalls $ExchangeCalls)
    {
        $price = is_object($priceValue) ? $priceValue->__toString() : $priceValue;

        if (!$stopLossPercentage)
            return true;

        if (!is_numeric($price) || !is_numeric($stopLossPercentage))
            return true;

        $amountToSell = is_object($amount) ? $amount->__toString() : $amount;
        $sellingPrice = $price * $stopLossPercentage;

        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
        $cost = $exchangeHandler->calculateOrderCostZignalyPair(
            $positionMediator->getSymbol(),
            $amountToSell,
            $sellingPrice
        );

        if (!$ExchangeCalls->checkIfValueIsGood('amount', 'min', $amountToSell, $symbol))
            return false;

        if (!$ExchangeCalls->checkIfValueIsGood('price', 'min', $sellingPrice, $symbol))
            return false;

        if (!$ExchangeCalls->checkIfValueIsGood('cost', 'min', $cost, $symbol))
            return false;

        return true;
    }

    private function checkTakeProfitsAmounts($position, $takeProfitTargets, $price, $amount, $symbol, ExchangeCalls $ExchangeCalls)
    {
        //$this->Monolog->sendEntry('debug', "Checking take profits amounts.");

        if (!$takeProfitTargets) {
            return true;
        }
        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

        foreach ($takeProfitTargets as $target) {
            $priceString = is_object($price) ? $price->__toString() : $price;
            $amountString = is_object($amount) ? $amount->__toString() : $amount;
            $sellingPrice = !empty($target->pricePriority) && 'price' === $target->pricePriority && !empty($target->priceTarget)
                ? $target->priceTarget : $priceString * $target->priceTargetPercentage;
            $amountToSell = $amountString * $target->amountPercentage;
            $cost = $exchangeHandler->calculateOrderCostZignalyPair(
                $positionMediator->getSymbol(),
                $amountToSell,
                $sellingPrice
            );
            if (!$ExchangeCalls->checkIfValueIsGood('amount', 'min', $amountToSell, $symbol)) {
                return false;
            }

            if (!$ExchangeCalls->checkIfValueIsGood('price', 'min', $sellingPrice, $symbol)) {
                return false;
            }

            if (!$ExchangeCalls->checkIfValueIsGood('cost', 'min', $cost, $symbol)) {
                return false;
            }
        }

        return true;
    }

    private function composeProvider($provider)
    {
        if (isset($provider->key))
            unset($provider->key);

        if (isset($provider['key']))
            unset($provider['key']);

        if (isset($provider['exchanges']))
            unset($provider['exchanges']);

        if (isset($provider['exchange']))
            unset($provider['exchange']);

        if (isset($provider['allocatedBalanceUpdatedAt']))
            unset($provider['allocatedBalanceUpdatedAt']);

        return $provider;
    }

    private function composeSignal($signal, $provider)
    {
        $signal['_id'] = isset($signal['_id']) ? new \MongoDB\BSON\ObjectId($signal['_id']) : false;
        $signal['providerId'] = $provider['_id'] == 1 ? $provider['_id'] : new \MongoDB\BSON\ObjectId($provider['_id']);
        $signal['providerName'] = $provider['name'];
        $signal['price'] = !isset($signal['price']) || !$signal['price'] ? false : (float)$signal['price'];
        $signal['limitPrice'] = !isset($signal['limitPrice']) || !$signal['limitPrice'] ? false : (float)$signal['limitPrice'];
        $signal['datetime'] = new \MongoDB\BSON\UTCDateTime($signal['datetime']);
        $signal['buyStopPrice'] = !isset($signal['buyStopPrice']) || !$signal['buyStopPrice'] ? false : (float)$signal['buyStopPrice'];

        return $signal;
    }

    public function pushLogsEntries($positionId, $entries)
    {
        foreach ($entries as $entry) {
            $this->mongoDBLink->selectCollection('position')
                ->updateOne(['_id' => $positionId], ['$push' => ['logs' => $entry]]);
        }
    }

    private function extractCancelBuyAt($exchange, $signal)
    {
        $buyTTL = $this->extractTTLs($exchange, 'buyTTL', $signal);

        return $buyTTL ? new \MongoDB\BSON\UTCDateTime($signal['datetime'] + $buyTTL * 1000) : false;
    }

    private function extractPositionSize($exchange, $signal, $user, ExchangeCalls $ExchangeCalls, $leverage)
    {
        $positionSize = false;

        if (isset($signal['positionSize']) && $signal['positionSize'] > 0 && isset($signal['positionSizeQuote'])) {
            $positionSize = $signal['positionSize'];
        } else {
            $exchangeMediator = ExchangeMediator::fromMongoExchange($exchange);
            $quote = $exchangeMediator->getQuote4PositionSizeExchangeSettings($signal['pair'], $signal['quote']);
            if (isset($exchange->positionsSize) && isset($exchange->positionsSize->$quote)) {
                $unit = $exchange->positionsSize->$quote->unit;
                $value = $exchange->positionsSize->$quote->value;
                $amount = $unit == '#' ? $value : $this->getPercentageAmountFromTotalAsset($user, $value, $quote, $exchange, $ExchangeCalls, $leverage);

                $positionSize = $amount;
            }
        }

        if ($positionSize) {
            //If realInvestment exists, that means that the signal comes from the trading terminal and the position size
            //already has applied the leverage, but we need to check that the value is bigger than 0.
            if (!isset($signal['realInvestment']) || !is_numeric($signal['realInvestment'])
                || !($signal['realInvestment'] > 0)) {
                $positionSize = $positionSize * ($leverage > 0? $leverage : 1);
            }
        }

        return $positionSize;
    }

    private function getPercentageAmountFromTotalAsset($user, $positionSize, $positionSizeQuote, $exchange, ExchangeCalls $ExchangeCalls, $leverage)
    {
        //$this->Monolog->sendEntry('debug', "Starting position size percentage: " .  $user->_id->__toString());
        $exchangeMediator = ExchangeMediator::fromMongoExchange($exchange);
        $positionHandler = $exchangeMediator->getPositionHandler();
        $exchangeId = $exchangeMediator->getInternalId();
        list($currentAmount, $currentFreeAmount) = $ExchangeCalls->getBalance($user, $exchangeId, $positionSizeQuote, 'all', true);
        if (isset($currentAmount['error'])) {
            $this->Monolog->sendEntry('debug', "Error getting current amount ", $currentAmount);
            $currentAmount = 0;
        }

        if ($currentFreeAmount == 0) {
            return 0;
        }
        //$this->Monolog->sendEntry('debug', "Getting open positions for position size %: " .  $user->_id->__toString());
        $openPositions = $positionHandler->getOpenPositionsForComputePositionSize($user->_id, $positionSizeQuote, $exchangeId);
        $positionsAmount = 0;

        // $lastPriceService = $this->container->get('lastPrice');
        foreach ($openPositions as $position) {
            $base = $position->signal->base;
            $quote = $position->signal->quote;
            if (!isset($position->trades))
                continue;
            $remainAmount = $positionHandler->getRemainingAmount($position->trades, $base);
            if ($remainAmount > 0) {
                /*
                $currentPrice = $lastPriceService->lastPriceStrForSymbol($exchange->name, $base.$quote);
                
                if (!$currentPrice)
                    $currentPrice = 0;

                $quoteAmount = $currentPrice * $remainAmount;
                $positionsAmount += $quoteAmount / $leverage;
                */
                $positionsAmount += $positionHandler->computeInvestedValue($remainAmount, $leverage, $position->signal->pair);
            }
        }

        $totalAmount = $currentAmount + $positionsAmount;

        $amountFromPercentage = $totalAmount / 100 * $positionSize;
        if ($amountFromPercentage > $currentFreeAmount) {
            $this->Monolog->sendEntry('debug', "Remaining amount $currentFreeAmount, lower than percentage amount $amountFromPercentage");
            $amountFromPercentage = $currentFreeAmount;
        }
        /*$this->Monolog->sendEntry('debug', "Percentage amount: Position Size: $positionSize, Total Amount: "
            . "$totalAmount, Current Amount: $currentAmount, Final Amount $amountFromPercentage, for user: "
            . $user->_id->__toString());*/

        return number_format($amountFromPercentage, 12, '.', '');
    }

    /**
     * Compose the stop loss percentage having into consideration the position side.
     *
     * @param bool|\MongoDB\Model\BSONDocument $exchange
     * @param array $signal
     * @param bool|float|int $price
     * @param array $provider
     * @param string $side
     * @return bool|int|mixed|string
     */
    private function extractStopLossForBuying($exchange, $signal, $price, $provider, $side = 'LONG')
    {
        if (!$price || $price == 0)
            return false;

        if (isset($signal['stopLossPercentage']) && (!empty($provider['stopLossFromSignal']) || $signal['origin'] == 'copyTrading')) {
            $stopLoss = isset($signal['stopLossPercentage']) ? $signal['stopLossPercentage'] : false;
        } elseif (isset($signal['stopLossPrice']) && (!empty($provider['stopLossFromSignal']) || $signal['origin'] == 'copyTrading')) {
            $stopLoss = number_format($signal['stopLossPrice'] / $price, 5);
        } elseif (!empty($exchange->stopLoss)) {
            $stopLoss = $exchange->stopLoss;
        } else {
            $stopLoss = false;
        }

        if ($stopLoss) {
            if ($side == 'SHORT' && $stopLoss < 1) {
                $stopLoss = 2 - $stopLoss;
            }

            if ($side == 'LONG' && $stopLoss > 1) {
                $stopLoss = 2 - $stopLoss;
            }
        }

        return $stopLoss;
    }

    /**
     * Extract the stop loss price from the signal.
     * @param array $signal
     * @param array $provider
     * @return bool|mixed
     */
    private function extractStopLossPriceFromSignal(array $signal, $provider)
    {
        if (!empty($signal['stopLossPrice'])
            && is_numeric($signal['stopLossPrice'])
            && (!empty($provider['stopLossFromSignal']) || 'copyTrading' === $signal['origin'])
        ) {
            return $signal['stopLossPrice'];
        }

        return false;
    }

    /**
     * Compose the trailing stop data having into consideration the position side.
     *
     * @param bool|\MongoDB\Model\BSONDocument $exchange
     * @param array $signal
     * @param bool|float|int $price
     * @param array $provider
     * @param string $side
     * @return bool|int|mixed|string
     */
    private function extractTrailingStopData($exchange, $signal, $price, $provider, $side = 'LONG')
    {
        if (!$price || $price == 0)
            return false;

        $trailingStopData = false;

        $manualOrigins = ['manualBuy', 'manual', 'copyTrading'];
        if (in_array($signal['origin'], $manualOrigins) || !empty($provider['trailingStopFromSignal'])) {
            if (!empty($signal['trailingStopDistancePercentage'])) {
                $trailingStopData['trailingStopDistancePercentage'] = $signal['trailingStopDistancePercentage'];
            }
            if (!empty($signal['trailingStopTriggerPercentage'])) {
                $trailingStopData['trailingStopTriggerPercentage'] = $signal['trailingStopTriggerPercentage'];
            } elseif (isset($signal['trailingStopTriggerPrice']) && $signal['trailingStopTriggerPrice'] > 0) {
                $trailingStopData['trailingStopTriggerPercentage'] = round($signal['trailingStopTriggerPrice'] / $price, 2);
            }

            if (empty($trailingStopData['trailingStopTriggerPercentage']) || empty($trailingStopData['trailingStopDistancePercentage'])) {
                $trailingStopData = false;
            }
        }

        if (empty($trailingStopData) && !in_array($signal['origin'], $manualOrigins)) {
            if (!empty($exchange->trailingStop)) {
                $trailingStopData['trailingStopDistancePercentage'] = $exchange->trailingStop;
            }

            if (!empty($exchange->trailingStopTrigger)) {
                $trailingStopData['trailingStopTriggerPercentage'] = $exchange->trailingStopTrigger;
            }

            if (empty($trailingStopData['trailingStopTriggerPercentage']) || empty($trailingStopData['trailingStopDistancePercentage'])) {
                $trailingStopData = false;
            }
        }

        if (!empty($trailingStopData)) {
            $trailingStopData['trailingStopTriggerPrice'] = empty($signal['trailingStopTriggerPrice']) ? false : $signal['trailingStopTriggerPrice'];
            $trailingStopData['trailingStopTriggerPriority'] = empty($signal['trailingStopTriggerPriority']) ? false
                : strtolower($signal['trailingStopTriggerPriority']);
            if ($side == 'SHORT') {
                if (isset($trailingStopData['trailingStopDistancePercentage']) && $trailingStopData['trailingStopDistancePercentage'] < 1) {
                    $trailingStopData['trailingStopDistancePercentage'] = 2 - $trailingStopData['trailingStopDistancePercentage'];
                }
                if (isset($trailingStopData['trailingStopTriggerPercentage']) && $trailingStopData['trailingStopTriggerPercentage'] > 1) {
                    $trailingStopData['trailingStopTriggerPercentage'] = 2 - $trailingStopData['trailingStopTriggerPercentage'];
                }
            }
            if ($side == 'LONG') {
                if (isset($trailingStopData['trailingStopDistancePercentage']) && $trailingStopData['trailingStopDistancePercentage'] > 1) {
                    $trailingStopData['trailingStopDistancePercentage'] = 2 - $trailingStopData['trailingStopDistancePercentage'];
                }
                if (isset($trailingStopData['trailingStopTriggerPercentage']) && $trailingStopData['trailingStopTriggerPercentage'] < 1) {
                    $trailingStopData['trailingStopTriggerPercentage'] = 2 - $trailingStopData['trailingStopTriggerPercentage'];
                }
            }
        }

        return $trailingStopData;
    }

    private function extractTTLs($exchange, $ttl, $signal)
    {
        if (isset($signal[$ttl])) {
            $seconds = $signal[$ttl] == 0 ? false : $signal[$ttl];
        } else {
            $seconds = isset($exchange->$ttl) && $exchange->$ttl && $exchange->$ttl > 0 ? $exchange->$ttl : false;
        }

        if (!$seconds && $ttl == 'buyTTL')
            $seconds = 5184000;

        return $seconds;
    }
}