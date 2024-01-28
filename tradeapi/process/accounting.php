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


use Zignaly\Mediator\BitmexPositionMediator;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Messaging\Messages\AccountingDone;
use Zignaly\Metrics\MetricServiceInterface;
use Zignaly\Process\DIContainer;

$createSecondaryDBLink = true;
require_once __DIR__ . '/../loader.php';
global $Accounting, $Exchange, $RabbitMQ, $Monolog, $newPositionCCXT, $continueLoop;

$processName = 'accounting';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
$newPositionCCXT->configureLoggingByContainer($container);
$newPositionCCXT->configureMongoDBLinkRO();
$RedisHandlerZignalyQueue = $container->get('redis.queue');
$Signal = $container->get('signal.model');
$Dispatcher = $container->get('dispatcher');
/** @var Notification */
$notification = $container->get('Notification');
/** @var MetricServiceInterface */
$MetricService = $container->get('metricService');

$RedisLockController = $container->get('RedisLockController');

$HistoryDB = new \HistoryDB2();
$scriptStartTime = time();
$isLocal = getenv('LANDO') === 'ON';
$queueName = (isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : 'accountingQueue';
$positionId = (isset($argv['2'])) && $argv['2'] != 'false' ? $argv['2'] : false;
$ExchangeCalls = new ExchangeCalls($Monolog);

do {
    $Monolog->trackSequence();
    $Monolog->addExtendedKeys('queueName', $queueName);
    $startTime = time();
    $workingAt = time();

    list($position, $inQueueAt) = getPosition($Monolog, $newPositionCCXT, $RedisHandlerZignalyQueue, $RedisLockController, $processName, $queueName, $positionId);

    if (!isset($position->status)) {
        //$Monolog->sendEntry('debug', "Nothing to do.");
        if ($isLocal) {
            sleep(1);
        }
        continue;
    }

    if (!$isLocal) {
        if (!$position->closed) {
            continue;
        }
    }

    unset($entries);
    unset($exits);
    $Monolog->sendEntry('debug', "New position");
    if (!empty($position->reverseSignal) && empty($position->reverseSignalSent) && $Signal->checkIfUserIsServiceOwner($position)) {
        $Monolog->sendEntry('info', 'Sending reverse signal');
        $Signal->composeEntrySignalFromReverse($RabbitMQ, $position->reverseSignal);
        $position = $newPositionCCXT->setPosition($position->_id, ['reverseSignalSent' => true]);
    }

    $positionMediator = PositionMediator::fromMongoPosition($position);
    $base = $position->signal->base;
    $quote = $position->signal->quote;
    $exchangeName = $positionMediator->getExchange()->getId();

    if (!empty($position->trades)) {
        list($entries, $exits) = extractQtyPrices($positionMediator, $position->trades);
    }

    if (empty($entries) || empty($exits)) {
        //$Monolog->sendEntry('warning', "Missing entries or exits");
        $setPosition = ['accounted' => true];
        $newPositionCCXT->setPosition($position->_id, $setPosition);
        sendNotification($RabbitMQ, 'positionSoldError', $position);
        $RedisLockController->removeLockPositionEntryFromRedis($positionMediator->getPositionId()->__toString());
        continue;
    }

    list($entryTotalQty, $entryAvgPrice) = $Accounting->getComputedCosts($entries, $positionMediator);
    list($exitTotalQty, $exitAvgPrice) = $Accounting->getComputedCosts($exits, $positionMediator);
    $difference = empty($exitTotalQty) ? 0 : abs(($entryTotalQty - $exitTotalQty) / $exitTotalQty);
    if ('futures' === $positionMediator->getExchangeType() && $difference > 0.000001) {
        $Monolog->sendEntry('debug', "Exit amount and entry amount $exitTotalQty / $entryTotalQty, don't match.");
        $Accounting->checkIfRemainingAmountFromTradesIsZero($newPositionCCXT, $ExchangeCalls, $position, false, false);
        $position = $newPositionCCXT->getPosition($position->_id);
        $positionMediator = PositionMediator::fromMongoPosition($position);
        list($entryTotalQty, $entryAvgPrice) = $Accounting->getComputedCosts($entries, $positionMediator);
        list($exitTotalQty, $exitAvgPrice) = $Accounting->getComputedCosts($exits, $positionMediator);
    }

    list($entryBNBFees, $entryOrigFees, $exitBNBFees, $exitOrigFees) = extractFeeData($positionMediator, $position);

    $entryDate = isset($position->buyPerformedAt) ? $position->buyPerformedAt : $position->signal->datetime;
    $exitDate = end($position->trades)->time;

    $entryBNBQuotePrice = 0;
    if (0 != $entryBNBFees) {
        $entryBNBQuotePrice = getPrice($Accounting, $HistoryDB, $exchangeName, 'BNB', $quote, $entryAvgPrice, $entryDate); //Todo: get avg price from dcas/rebuys.
        if (0 === $entryBNBQuotePrice) {
            $entryBNBQuotePrice = getPrice($Accounting, $HistoryDB, $exchangeName, $quote, 'BNB', $entryAvgPrice, $entryDate); //Todo: get avg price from dcas/rebuys.
        }
    }

    $exitBNBQuotePrice = 0;
    if (0 != $exitBNBFees) {
        $exitBNBQuotePrice = getPrice($Accounting, $HistoryDB, $exchangeName, 'BNB', $quote, $exitAvgPrice, $exitDate); //Todo: get avg price from dcas/rebuys.
        if (0 === $exitBNBQuotePrice) {
            $exitBNBQuotePrice = getPrice($Accounting, $HistoryDB, $exchangeName, $quote, 'BNB', $exitAvgPrice, $exitDate);
        }
    }

    $accountingDelayedCount = isset($position->accountingDelayedCount) ? $position->accountingDelayedCount : 0;
    // check if BNB fees and prices are ready
    if ((0 == $entryBNBQuotePrice && 0 != $entryBNBFees) || (0 == $exitBNBQuotePrice && 0 != $exitBNBFees)) {
        // increase count and delay this accounting
        $accountingDelayedCount += 1;
        $Monolog->sendEntry('debug', "BNB prices not ready entryBNBQuotePrice: ${entryBNBQuotePrice} ".
            "exitBNBQuotePrice: ${exitBNBQuotePrice} ".
            "accountingDelayedCount: ${accountingDelayedCount}");

        $Monolog->sendEntry('error', "Accounting delayed ${accountingDelayedCount} time(s)");
        if (0 == ($accountingDelayedCount % 10)) {
            $notification->sendToSlack(
                'Accounting delayed',
                "Accounting delayed ${accountingDelayedCount} time(s)",
                'opt-todo'
            );
        }

        $positionDelayedUpdate = [
            'accountingDelayedCount' => $accountingDelayedCount,
            'accountingDelayedUntil' => new \MongoDB\BSON\UTCDateTime((time() + 1 * 60) * 1000),
        ];

        if (1 == $accountingDelayedCount) {
            $positionDelayedUpdate['accountingDelayedStart'] = new \MongoDB\BSON\UTCDateTime();
        }

        $newPositionCCXT->setPosition($position->_id, $positionDelayedUpdate);
        
        $RedisLockController->removeLockPositionEntryFromRedis($positionMediator->getPositionId()->__toString());
        continue;
    }
    // if reaccounting avoid to send metrics
    if (!isset($position->accounting) && ($accountingDelayedCount > 0)) {
        try {
            $MetricService->startTransaction($processName);
            $MetricService->recordCustomMetric('Custom/Accounting/DelayedCount', $accountingDelayedCount);
            $currentMetricMs = time() * 1000;
            $MetricService->recordCustomMetric(
                'Custom/Accounting/DelayedSeconds',
                $currentMetricMs - ($position->accountingDelayedStart->__toString()?? $currentMetricMs)
            );
        } catch (\Exception $ex) {
            $Monolog->sendEntry('debug', "Error sending metrics: ". $ex->getMessage());
        } finally {
            $MetricService->endTransaction();
        }
    }

    $takeProfitTargetsCompleted = isset($position->takeProfitTargets)
        ? $newPositionCCXT->countFilledTargets($position->takeProfitTargets, $position->orders) : 0;
    $dcaTargetsCompleted = isset($position->reBuyTargets)
        ? $newPositionCCXT->countFilledTargets($position->reBuyTargets, $position->orders) : 0;
    list($originalEntryPrice, $originalInvestedAmount) = $Accounting->getEntryPrice($position);

    $totalFees = getFeesFromPosition($entryBNBFees, $entryOrigFees, $entryBNBQuotePrice, $exitBNBFees, $exitOrigFees, $exitBNBQuotePrice, $positionMediator);

    $grossProfit = $Accounting->computeGrossProfit($position, $positionMediator, false, true);
    $fundingFee = getFundingFees($ExchangeCalls, $position, $positionMediator, $Monolog);

    $netProfit = $grossProfit - $totalFees + $fundingFee;

    list($totalAllocatedBalance, $profitFromTotalAllocatedBalance) =
        getTotalAllocatedBalance($position, $originalInvestedAmount, $netProfit);

    $setPosition = [
        'accounting' => [
            'openingDate' => $entryDate,
            'closingDate' => new \MongoDB\BSON\UTCDateTime($exitDate),
            'originalBuyingPrice' => (float)($originalEntryPrice),
            'originalInvestedAmount' => (float)($originalInvestedAmount),
            'buyTotalQty' => (float)($entryTotalQty),
            'buyAvgPrice' => (float)($entryAvgPrice),
            'buyBNBQuotePrice' => (float)($entryBNBQuotePrice),
            'buyBNBFees' => (float)($entryBNBFees),
            'buyOrigFees' => (float)($entryOrigFees),
            'sellTotalQty' => (float)($exitTotalQty),
            'sellAvgPrice' => (float)($exitAvgPrice),
            'sellBNBQuotePrice' => (float)($exitBNBQuotePrice),
            'sellBNBFees' => (float)($exitBNBFees),
            'sellOrigFees' => (float)($exitOrigFees),
            'totalFees' => (float)($totalFees),
            'fundingFees' => (float)($fundingFee),
            'netProfit' => (float)($netProfit),
            'isWin' => $netProfit > 0 ? 1 : 0,
            'takeProfitTargetsCompleted' => (float)($takeProfitTargetsCompleted),
            'dcaTargetsCompleted' => (float)($dcaTargetsCompleted),
            'totalAllocatedBalance' => (float)($totalAllocatedBalance),
            'profitFromTotalAllocatedBalance' => (float)($profitFromTotalAllocatedBalance),
            'done' => true,
        ],
        'accounted' => true,
    ];

    $position = $newPositionCCXT->getPosition($position->_id);
    if (!$positionId && !empty($position->accounting->done)) {
        $RedisLockController->removeLockPositionEntryFromRedis($positionMediator->getPositionId()->__toString());
        continue;
    }

    $position = $newPositionCCXT->setPosition($position->_id, $setPosition);

    $RedisHandlerZignalyQueue->addSortedSet('accounting_done', time(), $position->_id->__toString(), true);

    $RedisLockController->removeLockPositionEntryFromRedis($positionMediator->getPositionId()->__toString());

    if (!$positionId && time() - $startTime < 10) {
        sleep(10);
    }
} while ($continueLoop && !$positionId);

/**
 * Return the real first date and last date from the trades.
 * @param \MongoDB\Model\BSONDocument $position
 * @return array
 */
function getEntryAndExitDate(\MongoDB\Model\BSONDocument $position)
{
    $entryDate = false;
    $exitDate = false;

    if (empty($position->trades)) {
        return [$entryDate, $exitDate];
    }

    $side = !empty($position->side) ? strtolower($position->side) : 'long';
    $isBuyEntry = 'long' === $side;
    foreach ($position->trades as $trade) {
        if ($trade->time < 10000000000) {
            $trade->time = $trade->time * 1000;
        }

        if (($isBuyEntry && $trade->isBuyer) || (!$isBuyEntry && !$trade->isBuyer)) {
            if (!$entryDate || $trade->time < $entryDate) {
                $entryDate = $trade->time;
            }
        } else {
            if (!$exitDate || $trade->time > $exitDate) {
                $exitDate = $trade->time;
            }
        }
    }

    if ($entryDate === $exitDate) {
        $exitDate++;
    }

    return [$entryDate, $exitDate];
}

/**
 * Get the funding fee from this position.
 *
 * @param ExchangeCalls $ExchangeCalls
 * @param \MongoDB\Model\BSONDocument $position
 * @param PositionMediator $positionMediator
 * @param Monolog $Monolog
 * @return float
 */
function getFundingFees(
    ExchangeCalls $ExchangeCalls,
    \MongoDB\Model\BSONDocument $position,
    PositionMediator $positionMediator,
    Monolog $Monolog
) {
    if ($positionMediator->getExchangeType() != 'futures' || !empty($position->paperTrading)) {
        return 0.0;
    }

    $isTestnet = $positionMediator->getExchangeIsTestnet();
    if (!$ExchangeCalls->setCurrentExchange(
        $positionMediator->getExchange()->getId(),
        $positionMediator->getExchangeType(),
        $isTestnet
    )) {
        $Monolog->sendEntry('critical', 'Error connecting the exchange');

        return 0.0;
    }

    $positionUserId = empty($position->profitSharingData) ? $position->user->_id : $position->profitSharingData->exchangeData->userId;
    $positionExchangeInternalId = empty($position->profitSharingData) ? $position->exchange->internalId : $position->profitSharingData->exchangeData->internalId;

    $ExchangeCalls->reConnectExchangeWithKeys($positionUserId, $positionExchangeInternalId);

    list($entryDate, $exitDate) = getEntryAndExitDate($position);
    $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
    $fundingFeesTag = $exchangeHandler->getFundingFeesTag();
    $feesEntries = $ExchangeCalls->userIncome($positionMediator->getSymbolWithSlash(), $fundingFeesTag, $entryDate, $exitDate);
    if (is_array($feesEntries) && array_key_exists('error', $feesEntries)) {
        if (empty($position->paperTrading)) {
            $Monolog->sendEntry('critical', 'Error retrieving funding fees: ', $feesEntries);
        }

        return 0.0;
    }
    $totalFees = 0.0;
    foreach ($feesEntries as $feesEntry) {
        //TODO: We don't have into consideration here the Hedge mode, if we implement it, we could count the funding fee twice.
        $totalFees = $totalFees + $exchangeHandler->calculateFundingFeeForExchangeIncome(
            $positionMediator->getSymbol(),
            $positionMediator->isShort(),
            $feesEntry
        ); // $feesEntry->getIncome();
    }

    return $totalFees;
}

/**
 * If the position is from a copy-trading service, it recalculated the profits from closed position balance for
 * the user and updated it.
 *
 * @param Monolog $Monolog
 * @param newPositionCCXT $newPositionCCXT
 * @param \MongoDB\Model\BSONDocument $user
 * @param \MongoDB\Model\BSONDocument $position
 */
function recalculateCopyTradingProfitsForUserFromClosedPositions(
    Monolog &$Monolog,
    newPositionCCXT $newPositionCCXT,
    \MongoDB\Model\BSONDocument $position
) {
    global $newUser;

    $newUser->configureLogging($Monolog);

    $providerId = isset($position->signal->providerId) ? $position->signal->providerId : false;

    if (!$providerId) {
        $Monolog->sendEntry('error', "Missing signal providerId in copy trading");
    } else {
        $user = $newUser->getUser($position->user->_id);
        $providerIdString = is_object($providerId) ? $providerId->__toString() : $providerId;
        if ($position->exchange->internalId == $user->provider->$providerIdString->exchangeInternalId) {
            $fromDate = isset($user->provider) && isset($user->provider->$providerIdString) &&
                isset($user->provider->$providerIdString->allocatedBalanceUpdatedAt)
                ? $user->provider->$providerIdString->allocatedBalanceUpdatedAt : $user->createdAt;
            $sinceDate = isset($user->provider) && isset($user->provider->$providerIdString) &&
                isset($user->provider->$providerIdString->resetProfitSinceCopyingAt)
                ? $user->provider->$providerIdString->resetProfitSinceCopyingAt : $user->createdAt;
            $setUser = [
                'provider.' . $providerIdString . '.profitsFromClosedBalance' => $newPositionCCXT->calculateProfitFromCopyTradingClosedPositions($position->user->_id, $position->provider->_id, $fromDate),
                'provider.' . $providerIdString . '.profitsSinceCopying' => $newPositionCCXT->calculateProfitFromCopyTradingClosedPositions($position->user->_id, $position->provider->_id, $sinceDate),
            ];

            $newUser->updateUser($position->user->_id, $setUser);
        }
    }
}

/**
 * Get all fees from any coin and return them converted to the original coin.
 *
 * @param $entryBNBFees
 * @param $entryOrigFees
 * @param $entryBNBQuotePrice
 * @param $exitBNBFees
 * @param $exitOrigFees
 * @param $exitBNBQuotePrice
 * @param PositionMediator $positionMediator
 * @return float|int
 */
function getFeesFromPosition(
    $entryBNBFees,
    $entryOrigFees,
    $entryBNBQuotePrice,
    $exitBNBFees,
    $exitOrigFees,
    $exitBNBQuotePrice,
    PositionMediator $positionMediator
) {
    if ($positionMediator->getQuote() == 'BNB') {
        $entryFromBNBToQuoteFees = $entryBNBFees;
    } else {
        $entryBNBPrice = $entryBNBQuotePrice;
        $entryFromBNBToQuoteFees = $entryBNBFees == 0 ? 0 : $entryBNBPrice * $entryBNBFees;
    }
    $entryFees = $entryOrigFees + $entryFromBNBToQuoteFees;

    if ($positionMediator->getQuote() == 'BNB') {
        $exitFromBNBToQuoteFees = $exitBNBFees;
    } else {
        $exitBNBPrice = $exitBNBQuotePrice;
        $exitFromBNBToQuoteFees = $exitBNBFees == 0 ? 0 : $exitBNBFees * $exitBNBPrice;
    }
    $exitFees = $exitOrigFees + $exitFromBNBToQuoteFees;

    return $entryFees + $exitFees;
}

/**
 * Check if the position is from copy-trading and if so, extract the allocated balance and the profit from it.
 *
 * @param \MongoDB\Model\BSONDocument $position
 * @param $originalInvestedAmount
 * @param $netProfit
 * @return array
 */
function getTotalAllocatedBalance(\MongoDB\Model\BSONDocument $position, $originalInvestedAmount, $netProfit)
{
    if (!empty($position->provider->isCopyTrading) && isset($position->signal->positionSizePercentage)) {
        if (!empty($position->profitSharingData)) {
            $totalAllocatedBalance = is_object($position->profitSharingData->sumUserAllocatedBalance)
                ? $position->profitSharingData->sumUserAllocatedBalance->__toString() : $position->profitSharingData->sumUserAllocatedBalance;
        } else {
            if (!empty($position->provider->allocatedBalance) && $position->provider->allocatedBalance > 0) {
                $allocatedBalance = $position->provider->allocatedBalance;
                $profitsFromClosedBalance = isset($position->provider->profitsFromClosedBalance) ?
                    $position->provider->profitsFromClosedBalance : 0;
                $totalAllocatedBalance = $allocatedBalance + $profitsFromClosedBalance;
            } else {
                $positionSizePercentage = $position->signal->positionSizePercentage;
                //We don't apply leverage because originalInvestdAmount already have it applied.
                $totalAllocatedBalance = $originalInvestedAmount * 100 / $positionSizePercentage;
            }
        }
        $profitFromTotalAllocatedBalance = $netProfit * 100 / $totalAllocatedBalance;
    } else {
        $totalAllocatedBalance = 0;
        $profitFromTotalAllocatedBalance = 0;
    }

    return [$totalAllocatedBalance, $profitFromTotalAllocatedBalance];
}

/**
 * Prepare a message for sending a notification to the user.
 *
 * @param string $command
 * @param \MongoDB\Model\BSONDocument $position
 * @param bool $error
 */
function sendNotification(RabbitMQ $RabbitMQ, string $command, \MongoDB\Model\BSONDocument $position, $error = false)
{
    $parameters = [
        'userId' => $position->user->_id->__toString(),
        'positionId' => $position->_id->__toString(),
        'status' => $position->status,
    ];

    if ($error) {
        $parameters['error'] = $error;
    }

    $message = [
        'command' => $command,
        'chatId' => false,
        'code' => false,
        'parameters' => $parameters
    ];

    $message = json_encode($message, JSON_PRESERVE_ZERO_FRACTION);
    $RabbitMQ->publishMsg('profileNotifications', $message);
}

/**
 * Get the higher price for LONG positions or the lower for SHORT ones from the position existing trades.
 *
 * @param PositionMediator $positionMediator
 * @param $trades
 * @return int
 */
function getExtremeExitPriceFromTrades(PositionMediator $positionMediator, $trades)
{
    if (!$trades) {
        return 0;
    }
    $extremePrice = 0;
    foreach ($trades as $trade) {
        if ($positionMediator->isLong()) {
            if (!$trade->isBuyer && $trade->price > $extremePrice) {
                $extremePrice = $trade->price;
            } else {
                if ($trade->isBuyer && $trade->price < $extremePrice) {
                    $extremePrice = $trade->price;
                }
            }
        }
    }

    return $extremePrice;
}

/**
 * Get the price for a symbol at certain time.
 *
 * @param Accounting $Accounting
 * @param HistoryDB2 $HistoryDB
 * @param string $exchangeName
 * @param string $quote
 * @param string $originalQuote
 * @param $originalPrice
 * @param $since
 * @return bool|string
 */
function getPrice(
    Accounting $Accounting,
    \HistoryDB2 $HistoryDB,
    string $exchangeName,
    string $quote,
    string $originalQuote,
    $originalPrice,
    $since
)
{
        if ($quote == $originalQuote) {
            return $originalPrice;
        }

        if ($quote == 'USDT' && $Accounting->checkIfStableCoin($originalQuote)) {
            return $originalPrice;
        }

        $price = $HistoryDB->findPriceAt($exchangeName . 'Trade', $originalQuote . $quote, $since, true);
        if (!$price || $price == 0) {
            $price = number_format($HistoryDB->findPriceAt($exchangeName . 'Trade', $quote . $originalQuote, $since, true), 12, '.', '');
        }

        return $price;
}

/**
 * Extract the data from the buys and sells, but also separated it by BNB payments or acquired coin payment.
 *
 * @param PositionMediator $positionMediator
 * @param \MongoDB\Model\BSONDocument $position
 * @return array
 */
function extractFeeData(PositionMediator $positionMediator, \MongoDB\Model\BSONDocument $position)
{
    $trades = $position->trades;
    $buyBNBFees = 0;
    $buyOrigFees = 0;
    $sellBNBFees = 0;
    $sellOrigFees = 0;

    $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

    $tradesIds = [];
    foreach ($trades as $trade) {
        $tradeIdOrderId = $trade->id . $trade->orderId;
        if (in_array($tradeIdOrderId, $tradesIds)) {
            continue;
        }

        $tradesIds[] = $tradeIdOrderId;

        $BNBFees = $trade->isBuyer ? 'buyBNBFees' : 'sellBNBFees';
        $origFees = $trade->isBuyer ? 'buyOrigFees' : 'sellOrigFees';
        /*
        if ($trade->commissionAsset == 'BNB') {
            $$BNBFees += number_format($trade->commission, 12, '.', '');
        } elseif ($trade->commissionAsset == $positionMediator->getQuote()) {
            $$origFees += number_format($trade->commission, 12, '.', '');
        } else {
            $$origFees += number_format($trade->commission * $trade->price, 12, '.', '');
        }
*/
        if ($trade->commissionAsset == 'BNB') {
            $$BNBFees += number_format($trade->commission, 12, '.', '');
        } else {
            $$origFees += number_format(
                $exchangeHandler->calculateTradeCommission(
                    $positionMediator->getSymbol(),
                    $trade->commissionAsset,
                    $trade->commission,
                    $trade->price,
                    $positionMediator->getQuote()
                ),
                12,
                '.',
                ''
            );
        }
    }

    return $positionMediator->isLong() ? [$buyBNBFees, $buyOrigFees, $sellBNBFees, $sellOrigFees]
        : [$sellBNBFees, $sellOrigFees, $buyBNBFees, $buyOrigFees];
}

/**
 * Given an array with price/quantity data, it will return the average price and the total quantity.
 *
 * @param array $data
 * @return array
 */
function getComputedCosts(array $data)
{
    $totalQty = 0;
    $totalCost = 0;
    foreach ($data as $datum) {
        $cost = $datum['price'] * $datum['quantity'];
        $totalCost += $cost;
        $totalQty += $datum['quantity'];
    }
    $avgPrice = $totalQty > 0 ? $totalCost / $totalQty : false;

    return [
        number_format($totalQty, 12, '.', ''),
        number_format($avgPrice, 12, '.', ''),
    ];
}


/**
 * Given the trades from a position, return the prices and quantities per each one group by side.
 *
 * @param PositionMediator $positionMediator
 * @param mixed $trades
 * @return array
 */
function extractQtyPrices(PositionMediator $positionMediator, $trades)
{
    if (!$trades) {
        return [false, false];
    }

    $buys = false;
    $sells = false;
    $tradesIds = [];
    foreach ($trades as $trade) {
        $tradeIdOrderId = $trade->id . $trade->orderId;
        if (in_array($tradeIdOrderId, $tradesIds)) {
            continue;
        }
        $tradesIds[] = $tradeIdOrderId;
        $data = [
            'price' => $trade->price,
            'quantity' => $trade->qty,
        ];
        if ($trade->isBuyer) {
            $buys[] = $data;
        } else {
            $sells[] = $data;
        }
    }

    return $positionMediator->isLong() ? [$buys, $sells] : [$sells, $buys];
}

/**
 * Get the position from a given id or from the list.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param RedisLockController $RedisLockController
 * @param string $processName
 * @param string $queueName
 * @param bool|string $positionId
 * @return array
 */
function getPosition(
    Monolog $Monolog,
    newPositionCCXT $newPositionCCXT,
    RedisHandler $RedisHandlerZignalyQueue,
    RedisLockController $RedisLockController,
    string $processName,
    string $queueName,
    $positionId
) {
    if (!$positionId) {
        $popMember = $RedisHandlerZignalyQueue->popFromSetOrBlock($queueName);
        if (!empty($popMember)) {
            $positionId = $popMember[1];
            $inQueueAt = $popMember[2];
        } else {
            return [false, false];
        }
        $previousPositionId = false;
    } else {
        $previousPositionId = true;
        $inQueueAt = time();
    }

    $position = $newPositionCCXT->getPosition($positionId);

    if (!empty($position->user)) {
        $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());
    }

    if (!$previousPositionId && !empty($position->accounting->done)) {
        return [false, false];
    }

    if (!$previousPositionId) {
        if (empty($position->_id) || !$RedisLockController->positionHardLock($position->_id->__toString(), $processName, 600, true)) {
            return [false, false];
        }
    }

    return [$position, $inQueueAt];
}
