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


use \MongoDB\Model\BSONDocument;
use Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;

require_once __DIR__ . '/../loader.php';
global $continueLoop;
$processName = 'accountStreamUpdatesWorker';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
/** @var RedisHandler $RedisAccountStreamUpdates */
$RedisAccountStreamUpdates = $container->get('redis.AccountStreamUpdates');
/** @var newPositionCCXT $newPositionCCXT */
$newPositionCCXT = $container->get('newPositionCCXT.model');
$newPositionCCXT->configureLogging($Monolog);
/** @var RedisLockController $RedisLockController */
$RedisLockController = $container->get('RedisLockController');
/** @var Accounting $Accounting */
$Accounting = $container->get('accounting');
/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');
/** @var ExchangeCalls $ExchangeCalls */
$ExchangeCalls = $container->get('exchangeMediator');
$newPositionCCXT->configureExchangeCalls($ExchangeCalls);
/** @var Order $Order */
$Order = $container->get('order.model');
/** @var RabbitMQ $RabbitMQ */
$RabbitMQ = new RabbitMQ();
$queueName = 'accountStreamUpdatesQueue';

do {
    try {
        $Monolog->trackSequence();

        $popMember = $RedisAccountStreamUpdates->popFromSetOrBlock($queueName);

        if (empty($popMember)) {
            continue;
        }

        $update = json_decode($popMember[1], true);

        $Monolog->sendEntry('debug', 'Received: ', $update);

        if ('balanceUpdate' === $update['e']) {
            if (str_contains($update['zignalyExchangeId']['exch'], 'Zignaly')) {
                //Remove futures prefix from spot events for futures accounts
                $exchangeId = $update['zignalyExchangeId']['exch'];
                $prefix = 'futures:';
                if (str_starts_with($exchangeId, $prefix)) {
                    $update['zignalyExchangeId']['exch'] = substr($exchangeId, strlen($prefix));
                }
                $RedisHandlerZignalyQueue->addSortedSet('updateBalanceFromStreamQueue', time(), json_encode($update['zignalyExchangeId'], JSON_PRESERVE_ZERO_FRACTION), true);
            }
            continue;
        }

        if ('ORDER_TRADE_UPDATE' !== $update['e'] && 'executionReport' !== $update['e']) {
            continue;
        }

        if (is_array($update['o'])) {
            $orderData = $update['o'];
            $orderData['zignalyExchangeId'] = empty($update['zignalyExchangeId']) ? [] : $update['zignalyExchangeId'];
            $orderData['exchangeName'] = 'binance';
            $orderData['exchangeAccountType'] = 'futures';
        } else {
            $orderData = $update;
            $orderData['exchangeName'] = 'binance';
            $orderData['exchangeAccountType'] = 'spot';
        }

        if (!isset($update['requeueCount'])) {
            $Order->insert($orderData);
            /*if (!empty($orderData['n']) && !empty($orderData['N'])) {
                $RedisHandlerZignalyQueue->addSortedSet('tradingFeeCashbackQueue', time(), $message = json_encode($orderData, JSON_PRESERVE_ZERO_FRACTION));
            }*/
        }

        if ('FILLED' !== $orderData['X']) {
            continue;
        }

        $orderId = $orderData['i'];
        $symbolWithoutSlash = $orderData['s'];
        /** @var BSONDocument $position */
        $position = $newPositionCCXT->getPositionByUserAndOrderId($orderId, $symbolWithoutSlash);
        if (empty($position->orders) || empty($position->orders->$orderId)) {
            // $Monolog->sendEntry('debug', 'Position or order not found', $orderData);

            $clientOrderIdString = extractClientOrderString($orderData);
            if (false !== stripos($clientOrderIdString, 'uozu148d')
                || false !== stripos($clientOrderIdString, 'zbx2yvyk')
                || false !== stripos($clientOrderIdString, 'kmfnj58x')
                || false !== stripos($clientOrderIdString, 'dmwd6wkj')
            ) {
                $update['requeueCount'] = !empty($update['requeueCount']) ? $update['requeueCount']++ : 1;
                if (10 < $update['requeueCount']) {
                    $RedisAccountStreamUpdates->addSet($queueName, json_encode($update));
                } else {
                    $Monolog->sendEntry('critical', "No order found after 10 tries, aborting for this order.", $orderData);
                }
            } else {
                // TODO: LFERN remove this log when checked
                $Monolog->sendEntry('debug', 'Order not from zignaly', $orderData);
            }
            continue;
        }

        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());

        if (!empty($position->orders->$orderId->done)) {
            //$Monolog->sendEntry('debug', 'Order already done', $orderData);
            continue;
        }

        $Monolog->sendEntry('info', 'Position found for live order update', $orderData);

        if (!$RedisLockController->positionHardLock($position->_id->__toString(), $processName)) {
            $Monolog->sendEntry('warning', 'Could not get the hard lock');
            $RedisAccountStreamUpdates->addSortedSet($queueName, time(), $popMember[1]);
            continue;
        }

        $position = $newPositionCCXT->getPosition($position->_id);
        if (!empty($position->orders->$orderId->done)) {
            $Monolog->sendEntry('debug', 'Order already done while we were asking for the lock.', $orderData);
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
            continue;
        }
        $trades = composeTempTrades($Order, $orderData);
        $position = $newPositionCCXT->pushDataAndReturnDocument($position->_id, 'trades', $trades);
        /** @var \Zignaly\Mediator\PositionMediator $positionMediator */
        $positionMediator = PositionMediator::fromMongoPosition($position);

        if (!$ExchangeCalls->setCurrentExchange(
            $positionMediator->getExchange()->getId(),
            $positionMediator->getExchangeType(),
            $positionMediator->getExchangeIsTestnet()
        )) {
            $Monolog->sendEntry('critical', 'Failed initiating exchange');
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
            $RedisAccountStreamUpdates->addSortedSet($queueName, time(), $popMember[1]);
            continue;
        }

        if ('entry' === $position->orders->$orderId->type && !empty($position->orders->$orderId->originalEntry)) {
            $Monolog->sendEntry('debug', "Entry order successfully filled.");
            $setPosition = getSettingsFromInitialEntryOrder($positionMediator, $newPositionCCXT, $position, $trades);
            $position = $newPositionCCXT->setPosition($position->_id, $setPosition, true);
            $newPositionCCXT->updateNewOrderField($position->_id, $setPosition, $orderId);
            $position = $newPositionCCXT->handleMultiPositions($position);
            sendReBuyOrder($RedisHandlerZignalyQueue, $position);
            sendTakeProfits($Monolog, $RabbitMQ, $position);
            sendReduceOrder($RedisHandlerZignalyQueue, $position);
            sendCopyTradingSignal($newPositionCCXT, $RabbitMQ, $position);
            sendStopOrders($RedisHandlerZignalyQueue, $position);
            if (!empty($setPosition['buyPerformedAt'])) {
                $event = [
                    'type' => 'openPosition',
                    'userId' => $position->user->_id->__toString(),
                    'parameters' => [
                        'positionId' => $position->_id->__toString(),
                    ],
                    'timestamp' => time(),
                ];
                $RedisHandlerZignalyQueue->insertElementInList('eventsQueue', json_encode($event));
            }
        } elseif ('entry' === $position->orders->$orderId->type) {
            $Monolog->sendEntry('debug', "ReEntry order successfully filled.");
            $setPosition = getSettingsFromReEntryOrder($Accounting, $positionMediator, $newPositionCCXT, $position, $trades);
            $position = $newPositionCCXT->setPosition($position->_id, $setPosition, true);
            $newPositionCCXT->updateNewOrderField($position->_id, $setPosition, $orderId);
            sendReBuyOrder($RedisHandlerZignalyQueue, $position);
            sendTakeProfits($Monolog, $RabbitMQ, $position);
            sendReduceOrder($RedisHandlerZignalyQueue, $position);
            sendStopOrders($RedisHandlerZignalyQueue, $position);
        } else {
            $Monolog->sendEntry('debug', "Exit order successfully filled.");
            $position = updateExitOrder($Accounting, $Monolog, $newPositionCCXT, $positionMediator, $RabbitMQ, $position, $trades);
            if ($position->closed) {
                $newPositionCCXT->cancelPendingOrders($position, ['stop']);
                sendClosedPositionToAccountingQueue($RedisHandlerZignalyQueue, $position);
            } else {
                sendStopOrders($RedisHandlerZignalyQueue, $position);
            }
        }

        $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Unknown error: " . $e->getMessage());
        if (true === stripos($e->getMessage(), 'OAUTH Authentication required') || true === stripos($e->getMessage(), 'Redis server')) {
            sleep(rand(1, 5));
            exit();
        }
        if (!empty($position->locked)) {
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
        }
    }
} while ($continueLoop);

/**
 * Return the client order id from the given order data.
 * @param $orderData
 * @return string
 */
function extractClientOrderString($orderData)
{
    if (!empty($orderData['c'])) {
        return strtolower($orderData['c']);
    }

    if (!empty($orderData['C'])) {
        return strtolower($orderData['C']);
    }

    return "";
}


/**
 * Update the position from an exit order.
 * @param Accounting $Accounting
 * @param Monolog $Monolog
 * @param newPositionCCXT $newPositionCCXT
 * @param PositionMediator $positionMediator
 * @param RabbitMQ $RabbitMQ
 * @param BSONDocument $position
 * @param array $trades
 * @return BSONDocument
 */
function updateExitOrder(
    Accounting $Accounting,
    Monolog $Monolog,
    newPositionCCXT $newPositionCCXT,
    PositionMediator $positionMediator,
    RabbitMQ $RabbitMQ,
    BSONDocument $position,
    array $trades
) {
    list($avgPrice, $realAmount, $orderId) = extractAveragePriceAndTotalAmountFromTrades($trades);


    $position = $newPositionCCXT->updateOrderAndCheckIfPositionNeedToBeClosed(
        $position,
        $orderId,
        $avgPrice,
        'closed'
    );

    if ('takeProfit' === $position->orders->$orderId->type) {
        sendNotification($RabbitMQ, $position, 'checkSellOpenOrdersSuccess', false, ['orderId' => $orderId, 'orderType' => 'takeProfit']);
        if ($newPositionCCXT->checkIfTakeProfitWasTheLastOneAndThereIsRemainingAmount($Accounting, $position)) {
            $Monolog->sendEntry('info', 'Selling remaining amount');
            $message = json_encode([
                'positionId' => $position->_id->__toString(),
                'status' => 17
            ], JSON_PRESERVE_ZERO_FRACTION);
            $queueName = empty($position->paperTrading) && empty($position->testNet) ? 'stopLoss' : 'stopLoss_Demo';
            $RabbitMQ->publishMsg($queueName, $message);
        }
    } elseif ('exit' === $position->orders->$orderId->type) {
        sendNotification($RabbitMQ, $position, 'checkSellOpenOrdersSuccess', false, ['orderId' => $orderId, 'orderType' => 'exit']);
    } elseif ('stopLoss' === $position->orders->$orderId->type && $positionMediator->getExchangeType() == 'futures'
        && isset($position->orders->$orderId->originalAmount)) {
        if ((float)$position->orders->$orderId->originalAmount > $realAmount) {
            $Monolog->sendEntry('error', "The contract was reduced from another source.)");
            $setPosition = [
                'closed' => true,
                'closedAt' => new \MongoDB\BSON\UTCDateTime(),
                'status' => 91,
                'lastUpdate' => new \MongoDB\BSON\UTCDateTime(),
            ];
            $position = $newPositionCCXT->setPosition($position->_id, $setPosition, true);
            $msgError = 'The stop loss tried to sell ' . $position->orders->$orderId->originalAmount . ' but only ' . $realAmount . ' were left in the contract. The contract was reduced outside this position, so we are closing it.';
            sendNotification($RabbitMQ, $position, 'checkSellOpenOrdersError', $msgError, ['orderId' => $orderId, 'orderType' => 'stopLoss']);
        }
    }

    return $position;
}

/**
 * Get the settings for a re entry order.
 * @param Accounting $Accounting
 * @param PositionMediator $positionMediator
 * @param newPositionCCXT $newPositionCCXT
 * @param BSONDocument $position
 * @param array $trades
 * @return array
 */
function getSettingsFromReEntryOrder(Accounting $Accounting, PositionMediator $positionMediator, newPositionCCXT $newPositionCCXT, BSONDocument $position, array $trades)
{
    list($orderPrice, $realAmount, $orderId) = extractAveragePriceAndTotalAmountFromTrades($trades);
    $tradesSideType = 'short' === strtolower($position->side) ? 'sell' : 'buy';
    $avgPrice = $Accounting->getAveragePrice($position, $tradesSideType);
    list($totalAmount, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($position);
    $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

    $amount = $realAmount;
    $realPositionSize = number_format(
        $exchangeHandler->calculatePositionSize(
            $positionMediator->getSymbol(),
            $totalAmount,
            $avgPrice
        ),
        12,
        '.',
        ''
    );

    $leverage = isset($position->leverage) && $position->leverage > 0 ? $position->leverage : 1;
    $position->leverage = $leverage;

    $realInvestment = number_format($realPositionSize / $position->leverage, 12, '.', '');

    $reEntryTargetId = $newPositionCCXT->getCurrentReEntryTargetId($position, $orderId);

    return [
        "orders.$orderId.status" => 'closed',
        "orders.$orderId.done" => true,
        "orders.$orderId.price" => $orderPrice,
        "orders.$orderId.amount" => $amount,
        "orders.$orderId.cost" => $exchangeHandler->calculateOrderCostZignalyPair(
            $positionMediator->getSymbol(),
            $amount,
            $orderPrice
        ),
        "reBuyTargets.$reEntryTargetId.done" => true,
        "reBuyTargets.$reEntryTargetId.updated" => new \MongoDB\BSON\UTCDateTime(),
        "increasingPositionSize" => false,
        "realAmount" => (float)($totalAmount),
        "remainAmount" => (float)($remainAmount),
        "realPositionSize" => (float)($avgPrice * $totalAmount),
        "realBuyPrice" => (float)($avgPrice),
        "avgBuyingPrice" => (float)($avgPrice),
        'stopLossPercentageLastUpdate' => new \MongoDB\BSON\UTCDateTime(),
        "trailingStopLastUpdate" => new \MongoDB\BSON\UTCDateTime(),
        "trailingStopPrice" => false,
        "updating" => true,
        'lastUpdatingAt' => new \MongoDB\BSON\UTCDateTime(),
        'reBuyProcess' => true,
        'realInvestment' => (float)($realInvestment),
    ];
}

/**
 * Get the settings from the initial entry order.
 * @param PositionMediator $positionMediator
 * @param newPositionCCXT $newPositionCCXT
 * @param BSONDocument $position
 * @param array $trades
 * @return array
 */
function getSettingsFromInitialEntryOrder(PositionMediator $positionMediator, newPositionCCXT $newPositionCCXT, BSONDocument $position, array $trades)
{
    list($avgPrice, $realAmount, $orderId) = extractAveragePriceAndTotalAmountFromTrades($trades);
    $realEntryPrice = (float)($avgPrice);
    $symbol = $positionMediator->getSymbol();
    $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

    $realPositionSize = number_format(
        $exchangeHandler->calculatePositionSize(
            $symbol,
            $realAmount,
            $avgPrice
        ),
        12,
        '.',
        ''
    );

    $realInvestment = number_format(
        $realPositionSize / $position->leverage,
        12,
        '.',
        ''
    );

    $setPosition = [
        "realAmount" => (float)($realAmount),
        "remainAmount" => (float)($realAmount),
        "realPositionSize" => (float)($realPositionSize),
        "origBuyPrice" => $realEntryPrice,
        "realBuyPrice" => $realEntryPrice,
        "avgBuyingPrice" => $realEntryPrice,
        "orders.$orderId.price" => $realEntryPrice,
        "orders.$orderId.amount" => $realAmount,
        "orders.$orderId.cost" => $exchangeHandler->calculateOrderCostZignalyPair($symbol, $realAmount, $avgPrice),
        "orders.$orderId.status" => 'closed',
        "orders.$orderId.done" => true,
        "buyPerformed" => true,
        "buyPerformedAt" => new \MongoDB\BSON\UTCDateTime(),
        'stopLossPercentageLastUpdate' => new \MongoDB\BSON\UTCDateTime(),
        "trailingStopLastUpdate" => new \MongoDB\BSON\UTCDateTime(),
        "status" => 9,
        "updating" => true,
        'lastUpdatingAt' => new \MongoDB\BSON\UTCDateTime(),
        'reBuyProcess' => true,
        'realInvestment' => (float)($realInvestment),
    ];

    $setFromFlipPosition = $newPositionCCXT->flipPosition($position, $orderId);

    return array_merge($setPosition, $setFromFlipPosition);
}

/**
 * Extract the average price and total amount from the given trades.
 * @param array $trades
 * @return array
 */
function extractAveragePriceAndTotalAmountFromTrades(array $trades)
{
    if (empty($trades)) {
        return [0.0, 0.0, false];
    }

    $totalAmount = 0.0;
    $totalCost = 0.0;
    $orderId = false;

    foreach ($trades as $trade) {
        $amount = $trade['qty'];
        $price = $trade['price'];
        $cost = $amount * $price;
        $totalCost += $cost;
        $totalAmount += $amount;
        $orderId = $trade['orderId'];
    }
    $avgPrice = $totalAmount > 0 ? $totalCost / $totalAmount : 0.0;

    return [(float)$avgPrice, (float)$totalAmount, $orderId];
}

/**
 * Compose the temporal trade from the order data.
 * @param Order $Order
 * @param array $orderData
 * @return array
 */
function composeTempTrades(Order $Order, array $orderData)
{
    $trades = [];
    $orderFromDB = $Order->getOrder($orderData['exchangeName'], $orderData['exchangeAccountType'], $orderData['i'], $orderData['s']);
    if (!empty($orderFromDB['status']) && 'closed' === $orderFromDB['status']) {
        $order = new ExchangeOrderCcxt($orderFromDB);
        foreach ($order->getTrades() as $trade) {
            $trades[] = [
                "symbol" => $trade->getSymbol(),
                "id" => $trade->getId(),
                "orderId" => $trade->getOrderId(),
                "orderListId" => -1,
                "price" => $trade->getPrice(),
                "qty" => $trade->getAmount(),
                "cost" => $trade->getCost(),
                "quoteQty" => 0,
                "commission" => $trade->getFeeCost(),
                "commissionAsset" => $trade->getFeeCurrency(),
                "time" => $trade->getTimestamp(),
                "isBuyer" => 'buy' === $order->getSide(),
                "isMaker" => $trade->isMaker(),
                "isBestMatch" => null,
                "tradeFromStream" => true,
            ];
        }
    } else {
        $isFutures = 'futures' === $orderData['exchangeAccountType'];
        $trades[] = [
            'symbol' => $orderData['s'],
            'id' => "temp-{$orderData['i']}",
            'orderId' => $orderData['i'],
            'orderListId' => -1,
            'price' => $isFutures ? $orderData['ap'] : $orderData['Z'] / $orderData['z'],
            'qty' => $orderData['z'],
            'cost' => $isFutures ? $orderData['ap'] * $orderData['z'] : $orderData['Z'],
            'quoteQty' => 0,
            'commission' => !empty($orderData['n']) ? $orderData['n'] : 0,
            'commissionAsset' => !empty($orderData['N']) ? $orderData['N'] : '',
            'time' => $orderData['T'],
            'isBuyer' => 'buy' === strtolower($orderData['S']),
            'isMaker' => $orderData['m'],
            'isBestMatch' => null,
            'isTemporal' => true,
        ];
    }

    return $trades;
}

/**
 * Send position to take profits queue.
 * @param Monolog $Monolog
 * @param RabbitMQ $RabbitMQ
 * @param BSONDocument $position
 */
function sendTakeProfits(Monolog $Monolog, RabbitMQ $RabbitMQ, BSONDocument $position): void
{
    $message = json_encode(['positionId' => $position->_id->__toString()], JSON_PRESERVE_ZERO_FRACTION);
    $queueName = empty($position->paperTrading) && empty($position->testNet) ? 'takeProfit' : 'takeProfit_Demo';
    $RabbitMQ->publishMsg($queueName, $message);
}

/**
 * Check if the position has any reduce order and send it to the queue if any.
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param BSONDocument $position
 */
function sendReduceOrder(RedisHandler $RedisHandlerZignalyQueue, BSONDocument $position): void
{
    if (!empty($position->reduceOrders)) {
        $message = json_encode(['positionId' => $position->_id->__toString()], JSON_PRESERVE_ZERO_FRACTION);
        $queueName = empty($position->paperTrading) && empty($position->testNet) ? 'reduceOrdersQueue' : 'reduceOrdersQueue_Demo';
        $RedisHandlerZignalyQueue->addSortedSet($queueName, time(), $message, true);
    }
}

/**
 * Check if the position has any rebuy order and send it to the queue if any.
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param BSONDocument $position
 */
function sendReBuyOrder(RedisHandler $RedisHandlerZignalyQueue, BSONDocument $position): void
{
    if (!empty($position->reBuyTargets)) {
        $message = $position->_id->__toString();
        $queueName = empty($position->paperTrading) && empty($position->testNet) ? 'reBuysQueue' : 'reBuysQueue_Demo';
        $RedisHandlerZignalyQueue->addSortedSet($queueName, time(), $message, true);
    }
}

/**
 * Check if the position has stop loss and send it to the queue if any.
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param BSONDocument $position
 */
function sendStopOrders(RedisHandler $RedisHandlerZignalyQueue, BSONDocument $position): void
{
    if (!empty($position->stopLossPercentage) || !empty($position->stopLossPrice)) {
        $message = json_encode(['positionId' => $position->_id->__toString()], JSON_PRESERVE_ZERO_FRACTION);
        $queueName = 'stopOrdersQueue';
        $RedisHandlerZignalyQueue->addSortedSet($queueName, time(), $message, true);
    }
}


/**
 * Send, if needed, the signal to the copy-trader followers.
 * @param newPositionCCXT $newPositionCCXT
 * @param RabbitMQ $RabbitMQ
 * @param BSONDocument $position
 */
function sendCopyTradingSignal(newPositionCCXT $newPositionCCXT, RabbitMQ $RabbitMQ, BSONDocument $position): void
{
    if (empty($position->provider->profitSharing)) {
        if (isset($position->signal->masterCopyTrader) && $position->signal->masterCopyTrader && !$newPositionCCXT->checkIfDCAHasBeenDone($position)) {
            $message = json_encode([
                'positionId' => $position->_id->__toString(),
                'origin' => 'copyTrading',
                'type' => 'buyFromFollowers'
            ], JSON_PRESERVE_ZERO_FRACTION);
            $RabbitMQ->publishMsg('signals', $message);
        }
    }
}

/**
 * Send the current position to the accounting queue if it's closed
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param BSONDocument $position
 */
function sendClosedPositionToAccountingQueue(RedisHandler $RedisHandlerZignalyQueue, BSONDocument $position)
{
    if (!empty($position->closed)) {
        $score = time();
        $positionsId = [];
        $positionsId[$position->_id->__toString()] = $score;
        $RedisHandlerZignalyQueue->addSortedSetPipeline('accountingQueue', $positionsId);
    }
}

/**
 * @param RabbitMQ $RabbitMQ
 * @param BSONDocument $position
 * @param string $command
 * @param bool|string $error
 * @param bool|array $extraParameters
 */
function sendNotification(RabbitMQ $RabbitMQ, BSONDocument $position, string $command, $error = false, $extraParameters = false)
{
    $parameters = [
        'userId' => $position->user->_id->__toString(),
        'positionId' => $position->_id->__toString(),
        'status' => $position->status,
    ];

    if ($error) {
        $parameters['error'] = $error;
    }

    if ($extraParameters and is_array($extraParameters)) {
        foreach ($extraParameters as $key => $value) {
            $parameters[$key] = $value;
        }
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