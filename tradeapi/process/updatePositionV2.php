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


use MongoDB\BSON\UTCDateTime;
use Symfony\Component\DependencyInjection\Container;
use Zignaly\Balance\BalanceService;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use Zignaly\redis\ZignalyLastPriceRedisService;
use MongoDB\Model\BSONDocument;

require_once __DIR__ . '/../loader.php';
global $continueLoop;

$container = DIContainer::getContainer();
$processName = 'updatePositionV2';
$container->set('monolog', new Monolog($processName));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
/** @var Accounting $Accounting */
$Accounting = $container->get('accounting');
/** @var RedisHandler $RedisHandlerZignalyLastPrices */
$RedisHandlerZignalyLastPrices = new RedisHandler($Monolog, 'ZignalyLastPrices');
$RedisHandlerZignalyQueue = $container->get('redis.queue');
/** @var newPositionCCXT $newPositionCCXT */
$newPositionCCXT = $container->get('newPositionCCXT.model');
$newPositionCCXT->configureLoggingByContainer($container);
/** @var ZignalyLastPriceRedisService $lastPriceService */
$lastPriceService = $container->get('lastPrice');
/** @var ExchangeCalls $ExchangeCalls */
$ExchangeCalls = $container->get('exchangeMediator');
$newPositionCCXT->configureExchangeCalls($ExchangeCalls);
$newPositionCCXT->configureLastPriceService($lastPriceService);

$queueName = 'updatePositionV2';
$Monolog->addExtendedKeys('queueName', $queueName);

$scriptStartTime = time();

while ($continueLoop) {
    try {
        $Monolog->trackSequence();
        list($position, $update) = popPositionUpdateMessage($newPositionCCXT, $Monolog, $RedisHandlerZignalyQueue, $queueName, $processName);
        if (!$position) {
            continue;
        }

        $positionMediator = PositionMediator::fromMongoPosition($position);

        $log = [];
        $setPosition = [
            'updating' => false,
        ];

        //We need to be connected to the exchange.
        if (!setExchangeFromPosition($ExchangeCalls, $position, $newPositionCCXT, $RedisHandlerZignalyQueue, $update, $queueName)) {
            $Monolog->sendEntry('critical', 'Error connecting the exchange');
            continue;
        }

        //Check if the buyTTL needs to be updated
        setBuyTTL($position, $update,$setPosition,$log);

        //Check if the sellTTL needs to be updated
        setSellTTL($position, $update,$setPosition,$log);

        //Check if the stop loss needs to be updated
        setStopLoss($positionMediator, $position, $update,$setPosition,$log);

        //Check boolean parameters and set them if needed.
        setBoolParameters($update, $setPosition);

        //Check if trailing stop needs to be updated.
        setTrailingStop($positionMediator, $position, $update,$setPosition,$log);

        //Check if there are parameters for removing reduce orders or options.
        setReduceOrdersRemoval($update,$position,$newPositionCCXT,$log);

        //Check if there are new reduce orders.
        $newReduceOrder = setNewReduceOrders($update, $position, $setPosition);

        //Check if there are DCA parameters and update the position if any
        setNewDCAOrders($position, $Monolog, $newPositionCCXT, $update, $setPosition, $log);

        //Check if there is any parameter for updating take profits and update if any.
        $updateTakeProfits = setTakeProfitOrders($update, $position, $setPosition, $newPositionCCXT, $Monolog, $Accounting, $newReduceOrder, $log);
        if (!empty($update['skipExitingAfterTP'])) {
            $setPosition['skipExitingAfterTP'] = true;
        }

        //Check if there is any parameter for increase the position size and update if any.
        setIncreasePositionSizeOrders($container, $ExchangeCalls, $Accounting, $position, $update, $setPosition, $log);

        $setPosition['updating'] = $updateTakeProfits;
        $setPosition['checkingOpenOrders'] = false;
        $setPosition['lastCheckingOpenOrdersAt'] = new UTCDateTime();
        $setPosition['locked'] = false;

        $pushLogs = empty($log) ? false : ['logs' => ['$each' => $log]];
        $newPositionCCXT->setPosition($position->_id, $setPosition, true, $pushLogs);

        if ($updateTakeProfits) {
            sendMessageToRedisQueue($RedisHandlerZignalyQueue, $position, 'takeProfit');
        }

        if (!empty($newReduceOrder)) {
            sendMessageToRedisQueue($RedisHandlerZignalyQueue, $position, 'reduceOrdersQueue');
        }

        if (isset($setPosition['stopLossPercentageLastUpdate']) || isset($setPosition['reBuyTargets'])) {
            sendMessageToRedisQueue($RedisHandlerZignalyQueue, $position, 'stopOrdersQueue');
        }
    } catch (Exception $e) {
        if (!isset($update)) {
            $update = [];
        }
        if (!isset($setPosition)) {
            $setPosition = [];
        }
        $update = array_merge($update, $setPosition);
        $Monolog->sendEntry('critical', "Updating failed: " . $e->getMessage(), $update);
    }
}

/**
 * Check if the position size needs to be increased.
 *
 * @param Container $container
 * @param ExchangeCalls $ExchangeCalls
 * @param Accounting $Accounting
 * @param BSONDocument $position
 * @param array $update
 * @param array $setPosition
 * @param array $log
 * @return void
 * @throws Exception
 */
function setIncreasePositionSizeOrders(
    Container $container,
    ExchangeCalls $ExchangeCalls,
    Accounting $Accounting,
    BSONDocument $position,
    array $update,
    array &$setPosition,
    array &$log
) : void {
    if (9 !== $position->status) {
        return;
    }

    list($amount, $price, $positionSize, $stopPrice, $triggerPercentage) =
        extractIncreasePSParameters($container, $ExchangeCalls, $Accounting, $position, $update);

    if (empty($amount) || empty($price) || empty($positionSize)) {
        return;
    }

    $log[] = [
        'date' => new UTCDateTime(),
        'message' => "Increasing position size by Amount: $amount, PS: $positionSize, Price: $price, Stop Price: $stopPrice.",
    ];
    $targetId = getTargetIdForIncreasingPositionSize($position);
    $signalSubId = md5(uniqid(rand(), true));

    if (empty($update['orderType'])) {
        $update['orderType'] = 'LIMIT';
    }

    $target = [
        'targetId' => $targetId,
        'triggerPercentage' => $triggerPercentage,
        'quantity' => $amount,
        'buying' => false,
        'done' => false,
        'orderId' => false,
        'cancel' => false,
        'skipped' => false,
        'subId' => $signalSubId,
        'orderType' => strtoupper($update['increasePSOrderType']),
        'limitPrice' => $price,
        'buyStopPrice' => $stopPrice,
        'newInvestment' => $positionSize,
        'postOnly' => checkIfParameterIsTrue($update, 'postOnly'),
    ];

    if (!$position->reBuyTargets) {
        $setPosition['reBuyTargets'] = [$targetId => $target];
    } else {
        if (!isset($setPosition['reBuyTargets'])) {
            foreach ($position->reBuyTargets as $rebuyTarget) {
                $setPosition['reBuyTargets'][$rebuyTarget->targetId] = $position->reBuyTargets->{$rebuyTarget->targetId};
            }
        }
        $setPosition['reBuyTargets'][$targetId] = $target;
    }

    if ($position->status >= 9) {
        $setPosition['reBuyProcess'] = true;
        $setPosition['increasingPositionSize'] = true;
    }
}

/**
 * Extract the amount, position size, price and stop price from the multiple possible parameters.
 * @param Container $container
 * @param ExchangeCalls $ExchangeCalls
 * @param Accounting $Accounting
 * @param BSONDocument $position
 * @param array $update
 * @return array|false[]
 * @throws Exception
 */
function extractIncreasePSParameters(Container $container, ExchangeCalls $ExchangeCalls, Accounting $Accounting, BSONDocument $position, array $update) : array
{
    if (!empty($update['increasePSAmount'])) {
        $amount = $update['increasePSAmount'];
    }

    $leverage = empty($position->leverage) ? 1 : $position->leverage;

    if (empty($update['amount']) && !empty($update['increasePSCost'])) {
        $positionSize = (float)($update['increasePSCost']); //We don't multiple by leverage yet:  * $leverage);
    }

    if (empty($amount) && empty($positionSize)) {
        $positionSizePercentage = checkPercentagePSParameter($update, 'increasePSPercentageFromTotalAccountBalance');
        if (!empty($positionSizePercentage)) {
            /** @var BalanceService $balanceService */
            $balanceService = $container->get('balanceService');
            /** @var newUser $newUser */
            $newUser = $container->get('newUser.model');
            $user = $newUser->getUser($position->user->_id);
            $balance = $balanceService->updateBalance($user, $position->exchange->internalId);
            $signalTotalQuote = str_contains($position->signal->quote, 'USD') ? 'USD' : 'BTC';
            $balanceField = 'total' . $signalTotalQuote;

            if (empty($balance['total'][$balanceField]) || $balance['total'][$balanceField] < 0) {
                return [false, false, false, false, false];
            }

            $positionSize = $balance['total'][$balanceField] / 100 * $positionSizePercentage;
        } else {
            list ($costIn, $costOut) = $Accounting->estimatedPositionSize($position);
            if (empty($costIn)) {
                return [false, false, false, false, false];
            }

            $positionSizePercentage = checkPercentagePSParameter($update, 'increasePSCostPercentageFromTotal');
            $targetPositionSize = $costIn;
            if (empty($positionSizePercentage)) {
                $positionSizePercentage = checkPercentagePSParameter($update, 'increasePSCostPercentageFromRemaining');
                $targetPositionSize = $costIn - $costOut;
            }

            if (empty($positionSizePercentage) || empty($targetPositionSize)) {
                return [false, false, false, false, false];
            }

            $positionSize = (float)($positionSizePercentage * $targetPositionSize / 100); //This position size already includes leverage.
            $positionSize = $positionSize / $leverage; //We don't wanna apply leverage yet.
        }
    }

    if (empty($amount) && empty($positionSize)) {
        return [false, false, false, false, false];
    }

    if (!empty($update['increasePSOrderType']) && 'market' === strtolower($update['increasePSOrderType'])) {
        $price = $ExchangeCalls->getLastPrice($position->signal->pair);
    }

    $tradesSideType = isset($position->side) && strtolower($position->side) == 'short' ? 'sell' : 'buy';
    $averageEntryPrice = $Accounting->getAveragePrice($position, $tradesSideType);
    if (empty($price) && !empty($update['increasePSPrice'])) {
        $price = $update['increasePSPrice'];
    }

    if (empty($price)) {
        $pricePercentage = checkPercentagePSParameter($update, 'increasePSPricePercentageFromAverageEntry');
        if (empty($pricePercentage)) {
            $pricePercentage = checkPercentagePSParameter($update, 'increasePSPricePercentageFromOriginalEntry');
            list($targetPrice) = $Accounting->getEntryPrice($position);
        } else {
            $targetPrice = $averageEntryPrice;
        }

        if (empty($pricePercentage)) {
            return [false, false, false, false, false];
        }

        $price = (float)($pricePercentage * $targetPrice / 100);
    }

    if (!empty($positionSize)) {
        if (checkIfParameterIsTrue($update, 'multiplyByLeverage')) {
            $positionSize = $positionSize * $leverage;
        }

        $amount = $positionSize / $price;
    } else {
        $positionSize = $amount * $price;
    }

    $stopPrice = !empty($update['increasePSStopPrice']) ? $update['increasePSStopPrice'] : false;
    $triggerPercentage = $averageEntryPrice === $price ? 1 : $price / $averageEntryPrice;


    return [$amount, $price, $positionSize, $stopPrice, $triggerPercentage];
}

/**
 * Check if the given parameter is a valid percentage for PS.
 * @param array $update
 * @param string $parameter
 * @param bool $allowNegativeValues
 * @return float
 */
function checkPercentagePSParameter(array $update, string $parameter, bool $allowNegativeValues = false) : float
{
    $fromValue = $allowNegativeValues ? -100 : 0;
    if (!empty($update[$parameter]) && $update[$parameter] >= $fromValue) {
        return (float)$update[$parameter];
    }

    return 0.0;
}
/**
 * Check if there is any parameter for updating take profits and update if any.
 * @param array $update
 * @param BSONDocument $position
 * @param array $setPosition
 * @param newPositionCCXT $newPositionCCXT
 * @param Monolog $Monolog
 * @param Accounting $Accounting
 * @param bool $newReduceOrder
 * @param array $log
 * @return bool
 */
function setTakeProfitOrders(
    array &$update,
    BSONDocument &$position,
    array &$setPosition,
    newPositionCCXT $newPositionCCXT,
    Monolog $Monolog,
    Accounting $Accounting,
    bool $newReduceOrder,
    array &$log
) : bool {
    if ($newReduceOrder || isset($update['removeTakeProfits'])) {
        $update['takeProfitTargets'] = false;
        //return false;
    }

    if (checkIfTargetsNeedToBeUpdated($position->takeProfitTargets, $update, 'takeProfitTargets', $position)) {
        //$Monolog->sendEntry('debug', "Updating take profit targets");
        $log[] = [
            'date' => new UTCDateTime(),
            'message' => 'Updating take profit targets.',
        ];

        if ($position->takeProfitTargets && !closeCurrentOpenOrders($newPositionCCXT, $position, $position->takeProfitTargets)) {
            $Monolog->sendEntry('debug', "Updating take profits targets failed");
            $log[] = [
                'date' => new UTCDateTime(),
                'message' => 'Error found when closing an open take profit order.',
            ];
        } else {
            $position = $newPositionCCXT->getPosition($position->_id);
            $setPosition['takeProfitTargets'] = getNewTargets($position->takeProfitTargets, $update['takeProfitTargets'], 'takeProfitTargets');
            $setPosition['lastUpdatingAt'] = new UTCDateTime();
            $updateTakeProfits = $position->status < 9 || !$setPosition['takeProfitTargets'] ? false : $position->buyPerformed;
            list(, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($position);

            if (!$updateTakeProfits) {
                $setPosition['remainAmount'] = (float)$remainAmount;
            }

            return $updateTakeProfits;
        }
    }

    return false;
}

/**
 * Check if there are DCA parameters and update the position if any.
 *
 * @param BSONDocument $position
 * @param Monolog $Monolog
 * @param newPositionCCXT $newPositionCCXT
 * @param array $update
 * @param array $setPosition
 * @param array $log
 * @return void
 */
function setNewDCAOrders(BSONDocument &$position, Monolog $Monolog, newPositionCCXT $newPositionCCXT, array &$update, array &$setPosition, array &$log) : void
{
    if (isset($update['removeDCAs'])) {
        $update['reBuyTargets'] = false;
        //return;
    }

    if (checkIfTargetsNeedToBeUpdated($position->reBuyTargets, $update, 'reBuyTargets')) {
        $log[] = [
            'date' => new UTCDateTime(),
            'message' => 'Updating DCA/ReBuy targets.',
        ];
        if ($position->reBuyTargets && !closeCurrentOpenOrders($newPositionCCXT, $position, $position->reBuyTargets)) {
            $Monolog->sendEntry('debug', "Updating DCAs Failed");
            $log[] = [
                'date' => new UTCDateTime(),
                'message' => 'Error found when closing an open reBuy order.',
            ];
        } else {
            $position = $newPositionCCXT->getPosition($position->_id);
            $setPosition['reBuyTargets'] = getNewTargets($position->reBuyTargets, $update['reBuyTargets'], 'reBuyTargets');
            if ($position->status >= 9) {
                $setPosition['increasingPositionSize'] = true;
                $setPosition['reBuyProcess'] = true;
            }
        }
    }
}

/**
 * Check if there are new reduce orders
 *
 * @param array $update
 * @param BSONDocument $position
 * @param array $setPosition
 * @return bool
 */
function setNewReduceOrders(array $update, BSONDocument $position, array &$setPosition) : bool
{
    if (checkIfNewReduceOrder($update)) {
        $targetId = getNextReduceOrderTargetId($position);
        if (empty($position->reduceOrders)) {
            $setPosition['reduceOrders'][$targetId] = [
                'targetId' => $targetId,
                'type' => empty($update['reduceOrderType']) ? 'limit' : $update['reduceOrderType'],
                'targetPercentage' => $update['reduceTargetPercentage'],
                'availablePercentage' => $update['reduceAvailablePercentage'],
                'amount' => $update['reduceTargetAmount'] ?? false,
                'priceTarget' => $update['reduceTargetPrice'] ?? false,
                'pricePriority' => $update['reduceTargetPriority'] ?? 'percentage',
                'recurring' => !empty($update['reduceRecurring']),
                'persistent' => !empty($update['reducePersistent']),
                'orderId' => false,
                'done' => false,
                'error' => false,
                'postOnly' => checkIfParameterIsTrue($update, 'reducePostOnly'),
            ];
        } else {
            $setPosition['reduceOrders.' . $targetId] = [
                'targetId' => $targetId,
                'type' => empty($update['reduceOrderType']) ? 'limit' : $update['reduceOrderType'],
                'targetPercentage' => $update['reduceTargetPercentage'],
                'availablePercentage' => $update['reduceAvailablePercentage'],
                'amount' => $update['reduceTargetAmount'] ?? false,
                'priceTarget' => $update['reduceTargetPrice'] ?? false,
                'pricePriority' => $update['reduceTargetPriority'] ?? 'percentage',
                'recurring' => !empty($update['reduceRecurring']),
                'persistent' => !empty($update['reducePersistent']),
                'orderId' => false,
                'done' => false,
                'error' => false,
                'postOnly' => checkIfParameterIsTrue($update, 'reducePostOnly'),
            ];
        }
        $setPosition['takeProfitTargets'] = getNewTargets($position->takeProfitTargets, false, 'takeProfitTargets');
        return true;
    }

    return false;
}

/**
 * Check if the given parameter inside the update signal exists and if it's true/false.
 *
 * @param array $update
 * @param string $parameter
 * @return bool
 */
function checkIfParameterIsTrue(array $update, string $parameter) : bool
{
    if (!isset($update[$parameter])) {
        return false;
    }

    if ('true' === $update[$parameter]) {
        return true;
    }

    if (true === $update[$parameter]) {
        return true;
    }

    if ($update[$parameter]) {
        return true;
    }

    return false;
}

/**
 * Check if there is reduce orders parameters for removing orders or parameters.
 *
 * @param array $update
 * @param BSONDocument $position
 * @param newPositionCCXT $newPositionCCXT
 * @param array $log
 * @return void
 */
function setReduceOrdersRemoval(array $update, BSONDocument &$position, newPositionCCXT $newPositionCCXT, array &$log) : void
{
    if (checkIfReduceOrdersNeedToBeUpdated($update)) {
        $log[] = [
            'date' => new UTCDateTime(),
            'message' => 'Updating Reduce Orders.',
        ];

        if (!empty($update['removeAllReduceOrders']) || !empty($update['removeReduceOrder'])) {
            if (!closeReduceOrders($newPositionCCXT, $position, $update)) {
                $log[] = [
                    'date' => new UTCDateTime(),
                    'message' => 'Error found when closing a reduce order.',
                ];
            } else {
                $position = $newPositionCCXT->getPosition($position->_id);
            }
        }

        if (!empty($update['removeReduceRecurringPersistent'])) {
            removeReduceOptions($newPositionCCXT, $position);
        }
    }
}

/**
 * Set the exchange from the position data.
 *
 * @param ExchangeCalls $ExchangeCalls
 * @param BSONDocument $position
 * @param newPositionCCXT $newPositionCCXT
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param array $update
 * @param string $queueName
 * @return bool
 */
function setExchangeFromPosition(
    ExchangeCalls &$ExchangeCalls,
    BSONDocument $position,
    newPositionCCXT $newPositionCCXT,
    RedisHandler $RedisHandlerZignalyQueue,
    array $update,
    string $queueName
) : bool {
    $exchangeConnected = $ExchangeCalls->setCurrentExchange($position->exchange->name, $position->exchange->exchangeType);

    if (!$exchangeConnected) {
        $newPositionCCXT->unlockPosition($position->_id);
        $score = microtime(true) * 1000;
        $message = json_encode($update);
        $RedisHandlerZignalyQueue->addSortedSet($queueName, $score, $message, true);
        return false;
    }

    return true;
}

/**
 * Check if the trailing stop parameters need to be udpated.
 *
 * @param PositionMediator $positionMediator
 * @param BSONDocument $position
 * @param array $update
 * @param array $setPosition
 * @param array $log
 * @return void
 */
function setTrailingStop(PositionMediator $positionMediator, BSONDocument $position, array $update, array &$setPosition, array &$log) : void
{
    if (!empty($update['trailingStopTriggerPrice']) && (!isset($update['trailingStopTriggerPercentage'])
            || (!empty($update['trailingStopTriggerPriority']) && 'price' === $update['trailingStopTriggerPriority']))) {
        $entryPrice = $positionMediator->getAverageEntryPrice();
        if ($entryPrice > 0) {
            $update['trailingStopTriggerPercentage'] = $update['trailingStopTriggerPrice'] / $entryPrice;
            $setPosition['trailingStopTriggerPriority'] = 'price';
            $setPosition['trailingStopTriggerPrice'] = $update['trailingStopTriggerPrice'];
        }
    }

    if ((isset($update['trailingStopDistancePercentage'])
            && $update['trailingStopDistancePercentage'] != $position->trailingStopDistancePercentage)
        || (isset($update['trailingStopTriggerPercentage'])
            && $update['trailingStopTriggerPercentage'] != $position->trailingStopTriggerPercentage)) {
        $setPosition['trailingStopPercentage'] = $positionMediator->isLong()
            ? $update['trailingStopDistancePercentage']
            : 2 - $update['trailingStopDistancePercentage'];
        $setPosition['trailingStopDistancePercentage'] = $positionMediator->isLong()
            ? $update['trailingStopDistancePercentage']
            : 2 - $update['trailingStopDistancePercentage'];
        $setPosition['trailingStopTriggerPercentage'] = $positionMediator->isLong()
            ? $update['trailingStopTriggerPercentage']
            : 2 - $update['trailingStopTriggerPercentage'];
        $setPosition['trailingStopLastUpdate'] = new UTCDateTime();
        $setPosition['trailingStopPrice'] = false;
        $setPosition['trailingStopTriggerPriority'] = empty($update['trailingStopTriggerPriority']) ? 'percentage'
            : $update['trailingStopTriggerPriority'];

        $log[] = [
            'date' => new UTCDateTime(),
            'message' => 'Updating trailing stop loss from (trigger/distance) '
                . $position->trailingStopTriggerPercentage . '/' . $position->trailingStopDistancePercentage . ' to ' .
                $setPosition['trailingStopTriggerPercentage'] . '/' . $setPosition['trailingStopDistancePercentage'],
        ];
    }
}

/**
 * Set boolean parameters if they come in the update.
 * @param array $update
 * @param array $setPosition
 * @return void
 */
function setBoolParameters(array $update, array &$setPosition) : void
{
    $parameters = [
        'stopLossFollowsTakeProfit',
        'stopLossToBreakEven',
        'stopLossToBreakEven',
    ];
    foreach ($parameters as $parameter) {
        setValueIfExists($update, $parameter, $setPosition);
    }
}

/**
 * Check if the value exists and if so prepare for updating the position.
 * @param array $update
 * @param string $value
 * @param array $setPosition
 * @return void
 */
function setValueIfExists(array $update, string $value, array &$setPosition) : void
{
    if (isset($update[$value])) {
        $setPosition[$value] = $update[$value];
    }
}

/**
 * Set a new stop loss if is sent in the update and different from the previous one.
 * @param PositionMediator $positionMediator
 * @param BSONDocument $position
 * @param array $update
 * @param array $setPosition
 * @param array $log
 * @return void
 */
function setStopLoss(PositionMediator $positionMediator, BSONDocument $position, array $update, array &$setPosition, array &$log) : void
{

    if (!isset($update['stopLossPercentage']) && empty($update['stopLossPrice'])) {
        return;
    }

    $stopLossPercentage = $update['stopLossPercentage'] ?? false;
    $stopLossPrice = $update['stopLossPrice'] ?? false;
    $stopLossPriority = getStopLossPriority($update);

    if ('price' === $stopLossPriority) {
        $entryPrice = $positionMediator->getAverageEntryPrice();
        if ($entryPrice > 0) {
            $update['stopLossPercentage'] = $update['stopLossPrice'] / $entryPrice;
        }
    }

    if ($positionMediator->stopLossNotEqualsTo($stopLossPriority, $stopLossPrice, $stopLossPercentage)) {
        $setPosition['stopLossPercentageLastUpdate'] = new UTCDateTime();
        $setPosition['stopLossPercentage'] = $stopLossPercentage;
        $setPosition['stopLossPriority'] = $stopLossPriority;
        $setPosition['stopLossPrice'] = $stopLossPrice;
        $log[] = [
            'date' => new UTCDateTime(),
            'message' => 'Updating stop loss from |' . $position->stopLossPercentage . '| to |' . $stopLossPercentage .
                '| from |' . $position->stopLossPriority . '| to |' . $stopLossPriority .
                '| from |' . $position->stopLossPrice . '| to |' . $stopLossPrice.'|',
        ];
    }
}

/**
 * Get the stop loss priority from the udpate.
 *
 * @param array $update
 * @return string
 */
function getStopLossPriority(array $update) : string
{
    if (!isset($update['stopLossPercentage']) && !empty($update['stopLossPrice'])) {
        return 'price';
    }

    if (isset($update['stopLossPercentage']) && empty($update['stopLossPrice'])) {
        return 'percentage';
    }

    return $update['stopLossPriority'] ?? 'percentage';
}

/**
 * Set the buy ttl parameter for updating in the position.
 * @param BSONDocument $position
 * @param array $update
 * @param array $setPosition
 * @param array $log
 * @return void
 */
function setBuyTTL(BSONDocument $position, array $update, array &$setPosition, array &$log) : void
{
    if (empty($update['buyTTL'])) {
        return;
    }

    if ($update['buyTTL'] != $position->buyTTL) {
        return;
    }

    $signalDate = $position->signal->datetime->__toString();
    $setPosition['buyTTL'] = $update['buyTTL'];
    $setPosition['cancelBuyAt'] = new UTCDateTime($signalDate + $update['buyTTL'] * 1000);
    $log[] = [
        'date' => new UTCDateTime(),
        'message' => 'Updating buy TTL from ' . $position->buyTTL . ' to ' . $setPosition['buyTTL'],
    ];
}

/**
 * Set the buy ttl parameter for updating in the position.
 * @param BSONDocument $position
 * @param array $update
 * @param array $setPosition
 * @param array $log
 * @return void
 */
function setSellTTL(BSONDocument $position, array $update, array &$setPosition, array &$log) : void
{
    if (empty($update['sellTTL'])) {
        return;
    }

    if ($update['sellTTL'] != $position->sellByTTL) {
        return;
    }

    $setPosition['sellByTTL'] = $update['sellTTL'];
    $log[] = [
        'date' => new UTCDateTime(),
        'message' => 'Updating sell TTL from ' . $position->sellByTTL . ' to ' . $setPosition['sellByTTL'],
    ];
}

/**
 * Send the message to the redis queue.
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param BSONDocument $position
 * @param string $queueName
 * @return bool
 */
function sendMessageToRedisQueue(RedisHandler $RedisHandlerZignalyQueue, BSONDocument $position, string $queueName) : bool
{
    $message = json_encode(['positionId' => $position->_id->__toString()], JSON_PRESERVE_ZERO_FRACTION);
    $key = empty($position->paperTrading) && empty($position->testNet) ? $queueName : $queueName . '_Demo';
    return $RedisHandlerZignalyQueue->addSortedSet($key, time(), $message, true) > 0;
}

/**
 * Check if there is a new reduce order to be placed.
 *
 * @param array $update
 * @return bool
 */
function checkIfNewReduceOrder(array & $update) : bool
{
    if (empty($update['reduceAvailablePercentage']) && empty($update['reduceTargetAmount'])) {
        return false;
    }

    if (!empty($update['reduceTargetPrice']) && ('price' === $update['reduceTargetPriority']) || !isset($update['reduceTargetPercentage'])) {
        $update['reduceTargetPriority'] = 'price';
        return true;
    }

    if (!empty($update['reduceOrderType']) && 'market' === $update['reduceOrderType']) {
        return true;
    }

    return !empty($update['reduceTargetPercentage']) && is_numeric($update['reduceTargetPercentage'])
        && $update['reduceTargetPercentage'] > 0 && $update['reduceTargetPercentage'] <= 100;
}

/**
 * Compose the new targets based on its type.
 *
 * @param object|bool $targets
 * @param array|bool $newTargets
 * @param string $targetType
 * @return bool|array
 */
function getNewTargets($targets, $newTargets, string $targetType)
{
    $setTargets = false;
    if ($targetType == 'takeProfitTargets') {
        $unDoneFields = ['priceTargetPercentage', 'amountPercentage', 'updating', 'done', 'orderId', 'priceTarget', 'pricePriority', 'postOnly'];
    } else {
        $unDoneFields = ['triggerPercentage', 'quantity', 'buying', 'done', 'orderId', 'cancel', 'skipped', 'buyType',
            'limitPrice', 'buyStopPrice', 'newInvestment', 'subId', 'orderType', 'postOnly', 'priceTarget', 'pricePriority'];
    }
    if (!$newTargets) {
        if ($targets) {
            foreach ($targets as $target) {
                $targetId = $target->targetId;
                if (checkIfTargetIsDone($targets->$targetId)) {
                    $setTargets[$targetId] = $targets->$targetId;
                }
            }
        }
    } else {
        foreach ($newTargets as $target) {
            unset($includeTarget);
            $targetId = $target['targetId'];
            if (isset($targets->$targetId) && checkIfTargetIsDone($targets->$targetId)) {
                $includeTarget = $targets->$targetId;
            } else {
                $includeTarget['targetId'] = $targetId;
                foreach ($unDoneFields as $field) {
                    switch ($field) {
                        case 'priceTargetPercentage':
                        case 'triggerPercentage':
                            $value = isset($target['priceTargetPercentage'])
                                ? $target['priceTargetPercentage'] : $target['triggerPercentage'];
                            break;
                        case 'quantity':
                        case 'amountPercentage':
                            $value = $target['amountPercentage'];
                            break;
                        case 'buyType':
                        case 'orderType':
                            if (!empty($target['orderType'])) {
                                $value = $target['orderType'];
                            } elseif (!empty($order['buyType'])) {
                                $value = $target['buyType'];
                            } else {
                                $value = 'LIMIT';
                            }
                            break;
                        case 'priceTarget':
                            $value = empty($target['priceTarget']) ? false : $target['priceTarget'];
                            break;
                        case 'pricePriority':
                            $value = empty($target['pricePriority']) ? 'percentage' : $target['pricePriority'];
                            break;
                        case 'buying':
                            $value = false;
                            break;
                        case 'limitPrice':
                            $value = isset($target['limitPrice']) ? $target['limitPrice'] : false;
                            break;
                        case 'buyStopPrice':
                            $value = isset($target['buyStopPrice']) ? $target['buyStopPrice'] : false;
                            break;
                        case 'newInvestment':
                            $value = isset($target['newInvestment']) ? $target['newInvestment'] : false;
                            break;
                        case 'subId':
                            $value = isset($target['subId']) ? $target['subId'] : false;
                            break;
                        case 'postOnly':
                            $value = isset($target['postOnly']) ? $target['postOnly'] : false;
                            break;
                        default:
                            $value = false;
                    }
                    $includeTarget[$field] = $value;
                }
            }
            if (empty($includeTarget['pricePriority'])) {
                $includeTarget['pricePriority'] = 'percentage';
            }
            $setTargets[$targetId] = $includeTarget;
        }
    }

    return $setTargets;
}

/**
 * Check if current reduce orders need to be updated.
 *
 * @param array|bool $update
 * @return bool
 */
function checkIfReduceOrdersNeedToBeUpdated($update) : bool
{
    $reduceOptions = ['removeAllReduceOrders', 'removeReduceOrder', 'removeReduceRecurringPersistent'];
    foreach ($reduceOptions as $option) {
        if (isset($update[$option])) {
            return true;
        }
    }

    return false;
}

/**
 * Checks if the current targets are different to the new ones.
 *
 * @param bool|object $targets
 * @param bool|array $update
 * @param string $targetsName
 * @param bool|\MongoDB\Model\BSONDocument $position
 * @return bool
 */
function checkIfTargetsNeedToBeUpdated($targets, $update, string $targetsName, $position = false) : bool
{
    if (!isset($update[$targetsName])) {
        return false;
    }

    if ($targetsName == 'takeProfitTargets' && !empty($position->reduceOrders)) {
        if (is_object($position->reduceOrders) || is_array($position->reduceOrders)) {
            $reduceOrders = 0;
            foreach ($position->reduceOrders as $reduceOrder) {
                if (isset($reduceOrder->done)) {
                    $reduceOrders++;
                }
            }
            if ($reduceOrders > 0) {
                return false;
            }
        }
    }

    $newTargets = $update[$targetsName];
    if (!$newTargets && !$targets) {
        return false;
    }

    if (!$newTargets || !$targets) {
        return true;
    }

    if (count($newTargets) != count((array)$targets)) {
        return true;
    }

    foreach ($newTargets as $target) {
        $targetId = $target['targetId'];
        if (isset($targets->$targetId) && checkIfTargetIsDone($targets->$targetId)) {
            continue;
        }

        if (!isset($targets->$targetId)) {
            return true;
        }

        if (isset($targets->$targetId->triggerPercentage) && $targets->$targetId->triggerPercentage != $target['priceTargetPercentage']) {
            return true;
        }

        if (isset($targets->$targetId->priceTargetPercentage) && $targets->$targetId->priceTargetPercentage != $target['priceTargetPercentage']) {
            return true;
        }

        if (isset($targets->$targetId->priceTarget) && $targets->$targetId->priceTarget != $target['priceTarget']) {
            return true;
        }

        if (isset($targets->$targetId->quantity) && $targets->$targetId->quantity != $target['amountPercentage']) {
            return true;
        }

        if (isset($targets->$targetId->amountPercentage) && $targets->$targetId->amountPercentage != $target['amountPercentage']) {
            return true;
        }

        if (($targets->$targetId->postOnly ?? false) != ($target['postOnly'] ?? false)) {
            return true;
        }

        if (($targets->$targetId->pricePriority ?? 'percentage') != ($target['pricePriority'] ?? 'percentage')) {
            return true;
        }
    }

    return false;
}

/**
 * Remove undone reduce orders from ordres and reduceOrders and update their statuses.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param BSONDocument $position
 * @param array $update
 * @return bool
 */
function closeReduceOrders(newPositionCCXT $newPositionCCXT, BSONDocument $position, array $update) : bool
{
    if (empty($position->reduceOrders)) {
        return false;
    }

    $targetsRemoved = [];
    foreach ($position->reduceOrders as $reduceOrder) {
        if (!empty($update['removeAllReduceOrders']) || in_array($reduceOrder->targetId, $update['removeReduceOrder'])) {
            if (!empty($reduceOrder->orderId) && !$reduceOrder->done) {
                if ($newPositionCCXT->cancelOrder($reduceOrder->orderId, $position)) {
                    $targetsRemoved[] = $reduceOrder->targetId;
                }
            } elseif (empty($reduceOrder->orderId) && !$reduceOrder->done) {
                $targetsRemoved[] = $reduceOrder->targetId;
            }
        }
    }

    if (!empty($targetsRemoved)) {
        $position = $newPositionCCXT->getPosition($position->_id);
        foreach ($targetsRemoved as $targetId) {
            if ($position->reduceOrders->$targetId->done && ($position->reduceOrders->$targetId->recurring || $position->reduceOrders->$targetId->persistent)) {
                $set = [
                    'reduceOrders.'.$targetId.'.recurring' => false,
                    'reduceOrders.'.$targetId.'.persistent' => false,
                ];
                $newPositionCCXT->setPosition($position->_id, $set);
            } elseif (!$position->reduceOrders->$targetId->done) {
                $unset = [
                    'reduceOrders.'.$targetId => 1
                ];
                $newPositionCCXT->unsetPosition($position->_id, $unset);
            }
        }
    }

    return true;
}

/**
 * Remove the flags recurring and persistent from all the reduce orders.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param BSONDocument $position
 * @return bool
 */
function removeReduceOptions(newPositionCCXT $newPositionCCXT, BSONDocument & $position) : bool
{
    if (empty($position->reduceOrders)) {
        return false;
    }

    foreach ($position->reduceOrders as $reduceOrder) {
        $targetId = $reduceOrder->targetId;
        if (($position->reduceOrders->$targetId->recurring || $position->reduceOrders->$targetId->persistent)) {
            $set = [
                'reduceOrders.'.$targetId.'.recurring' => false,
                'reduceOrders.'.$targetId.'.persistent' => false,
            ];
            $position = $newPositionCCXT->setPosition($position->_id, $set);
        }
    }

    return true;
}

/**
 * If there is any order not completed by any of the targets, it cancels it.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param BSONDocument $position
 * @param bool|object $targets
 * @return bool
 */
function closeCurrentOpenOrders(newPositionCCXT $newPositionCCXT, BSONDocument $position, $targets) : bool
{
    if (!$targets) {
        return true;
    }

    foreach ($targets as $target) {
        if ($target->done) {
            continue;
        }

        if (empty($target->orderId)) {
            continue;
        }

        if (!$newPositionCCXT->cancelOrder($target->orderId, $position)) {
            return false;
        }
    }

    return true;
}


/**
 * Checks if the given target is already been done, canceled or skipped.
 *
 * @param object $target
 * @return bool
 */
function checkIfTargetIsDone(object $target) : bool
{
    if (isset($target->done) && $target->done) {
        return true;
    }

    if (isset($target->cancel) && $target->cancel) {
        return true;
    }

    if (isset($target->skipped) && $target->skipped) {
        return true;
    }

    return false;
}


/**
 * Get an available Id for a rebuy target for increasing the position size. The targets that are from increasing
 * the position size are assigned starting from 1000.
 *
 * @param BSONDocument $position
 * @return int
 */
function getTargetIdForIncreasingPositionSize(BSONDocument $position) : int
{
    if (!$position->reBuyTargets) {
        return 1000;
    }

    $targetId = 1000;

    foreach ($position->reBuyTargets as $target) {
        while ($target->targetId >= $targetId) {
            $targetId++;
        }
    }

    return $targetId;
}

/**
 * Get a targetId for the next reduce Order.
 *
 * @param BSONDocument $position
 * @return int
 */
function getNextReduceOrderTargetId(BSONDocument $position) : int
{
    if (empty($position->reduceOrders)) {
        return 1;
    }

    foreach ($position->reduceOrders as $target) {
        $lastTargetId = $target->targetId;
    }

    return empty($lastTargetId) ? 1 : $lastTargetId + 1;
}

/**
 * @param newPositionCCXT $newPositionCCXT
 * @param Monolog $Monolog
 * @param RedisHandler $RedisHandlerZignalyUpdateSignals
 * @param string $queueName
 * @param string $processName
 * @return array|false
 */
function popPositionUpdateMessage(
    newPositionCCXT $newPositionCCXT,
    Monolog &$Monolog,
    RedisHandler $RedisHandlerZignalyUpdateSignals,
    string $queueName,
    string $processName
) {
    $pop = $RedisHandlerZignalyUpdateSignals->popFromSetOrBlock($queueName);
    if (empty($pop)) {
        return false;
    }

    $update = json_decode($pop[1], true);
    $Monolog->addExtendedKeys('positionId', $update['positionId']);

    $Monolog->sendEntry('info', "Received ", $update);
    $position = $newPositionCCXT->getAndLockPosition(
        $update['positionId'],
        $processName,
        false,
        false,
        true
    );

    if (!$position) {
        $position = $newPositionCCXT->getPosition($update['positionId']);
        if (!$position->closed) {
            $counterName = $processName . 'UnsuccessfulLockCounter';
            $counter = empty($position->$counterName) ? 0 : $position->$counterName;
            $Monolog->sendEntry('error', "Not able to lock the position for $counter times.");
            $newPositionCCXT->increaseUnsuccessfulLockCounter($position->_id, $processName, $counter);

            if (!$RedisHandlerZignalyUpdateSignals->addSortedSet($pop[0], $pop[2], $pop[1])) {
                $Monolog->sendEntry('critical', "Not able to sent to Redis ", $pop);
            }
        }
        return false;
    }

    return [$position, $update];
}