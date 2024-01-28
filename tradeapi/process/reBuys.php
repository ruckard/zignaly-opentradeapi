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


use Zignaly\exchange\ExchangeOrder;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use Zignaly\redis\ZignalyLastPriceRedisService;
use Zignaly\redis\ZignalyLastTradesRedisService;
use Zignaly\utils\PositionUtils;

$excludeRabbit = true;
require_once __DIR__ . '/../loader.php';
global $Accounting, $Monolog, $newPositionCCXT, $Position, $newUser, $continueLoop;

$processName = 'reBuys';
$container = DIContainer::getContainer();

$container->set('monolog', new Monolog($processName));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
$ExchangeCalls = new ExchangeCalls($Monolog);
$newPositionCCXT->configureLoggingByContainer($container);
$RedisHandlerZignalyLastPrices = new RedisHandler($Monolog, 'ZignalyLastPrices');
$lastPriceService = new ZignalyLastPriceRedisService($RedisHandlerZignalyLastPrices, $Monolog);
$RedisHandlerZignalyQueue = $container->get('redis.queue');
$RedisLockController = $container->get('RedisLockController');

$redisLastTrades = new RedisHandler($Monolog, 'Last3DaysPrices');
$lastTradesProvider = new ZignalyLastTradesRedisService($redisLastTrades);

/** @var Position $Position */
$Position = $container->get('position.model');
$Position->configureLoggingByContainer($container);

$newPositionCCXT->configureExchangeCalls($ExchangeCalls);
$newPositionCCXT->configureLastPriceService($lastPriceService);
$scriptStartTime = time();
$queueName = (isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : 'reBuysQueue';
$positionId = (isset($argv['2'])) && $argv['2'] != 'false' ? $argv['2'] : false;

while ($continueLoop) {
    unset($setPosition);
    unset($orderArray);
    $Monolog->trackSequence();
    $Monolog->addExtendedKeys('queueName', $queueName);

    $workingAt = time();

    list($position, $inQueueAt) = getPosition(
        $RedisHandlerZignalyQueue,
        $RedisLockController,
        $processName,
        $queueName,
        $positionId
    );

    if (!empty($position->reBuyProcess)) {
        $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
        $Monolog->addExtendedKeys('positionId', $position->_id->__toString());
        //$Monolog->sendEntry('debug', "Status: " . $position->status);
        $positionMediator = PositionMediator::fromMongoPosition($position);
        $exchangeConnected = $ExchangeCalls->setCurrentExchange(
            $positionMediator->getExchange()->getId(),
            $positionMediator->getExchangeType(),
            $positionMediator->getExchangeIsTestnet()
        );

        if (!$exchangeConnected) {
            $Monolog->sendEntry('critical', 'Error connecting the exchange');
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
            continue;
        }

        if (!$newUser->checkIfKeysAreValidForExchange($position->user->_id, $position->exchange->internalId)) {
            $Monolog->sendEntry('debug', "Invalid keys");
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
            continue;
        }

        doubleCheckOpenOrders($ExchangeCalls, $Monolog, $newPositionCCXT, $position);
        $position = $newPositionCCXT->getPosition($position->_id);
        $positionMediator->updatePositionEntity($position);
        cancelFirstDCAIfPlaced($newPositionCCXT, $position);
        $position = $newPositionCCXT->getPosition($position->_id);
        $positionMediator->updatePositionEntity($position);

        $targetIds = getReBuyTargets($Monolog, $position);

        if (!$targetIds || ($position->status < 9 && empty($position->DCAFromBeginning))) {
            $setPosition = [
                'increasingPositionSize' => false,
                'reBuyProcess' => false,
            ];
            $newPositionCCXT->setPosition($position->_id, $setPosition);
        } else {
            //$Monolog->sendEntry('warning', "Targets found: " . count($targetIds), $targetIds);
            foreach ($targetIds as $targetId) {
                unset($setPosition);
                $orderSent = false;
                $sentToRedisForChecking = false;
                $dcaError = false;

                list($target, $limitPrice, $buyStopPrice) = getReBuyTarget($ExchangeCalls, $Monolog, $positionMediator, $position, intval($targetId));

                if ($target && $limitPrice) {
                    list($amount, $newInvestment) = getAmountAndInvestment($Accounting, $Monolog, $ExchangeCalls, $position, $limitPrice, $target);

                    $user = $newUser->getUser($position->user->_id);
                    if (!$ExchangeCalls->checkIfValueIsGood('amount', 'min', $amount, $positionMediator->getSymbol() /*$position->signal->base . $position->signal->quote*/)) {
                        $amount = number_format($amount, 12, '.', '');
                        $errorMsg = "The amount $amount is below the min allowed ";
                        $Monolog->sendEntry('debug', $errorMsg);
                        $setPosition = [
                            'reBuyTargets.' . $target->targetId . '.skipped' => true,
                            'reBuyTargets.' . $target->targetId . '.error' => ['msg' => $errorMsg],
                            'reBuyTargets.' . $target->targetId . '.updated' => new \MongoDB\BSON\UTCDateTime(),
                        ];
                    } elseif (!$ExchangeCalls->checkIfValueIsGood('cost', 'min', $newInvestment, $positionMediator->getSymbol() /*$position->signal->base . $position->signal->quote*/)) {
                        $newInvestment = number_format($newInvestment, 12, '.', '');
                        $errorMsg = "The cost $newInvestment is below the min allowed ";
                        $Monolog->sendEntry('debug', $errorMsg);
                        $setPosition = [
                            'reBuyTargets.' . $target->targetId . '.skipped' => true,
                            'reBuyTargets.' . $target->targetId . '.error' => ['msg' => $errorMsg],
                            'reBuyTargets.' . $target->targetId . '.updated' => new \MongoDB\BSON\UTCDateTime(),
                        ];
                    } elseif (!$Position->checkAllocatedBalance($position, $user, $newInvestment)) {
                        $errorMsg = "No free balance to perform the reBuy for position ";
                        $Monolog->sendEntry('debug', $errorMsg);
                        $setPosition = [
                            'reBuyTargets.' . $target->targetId . '.skipped' => true,
                            'reBuyTargets.' . $target->targetId . '.error' => ['msg' => $errorMsg],
                            'reBuyTargets.' . $target->targetId . '.updated' => new \MongoDB\BSON\UTCDateTime(),
                        ];
                    } else {
                        if (!empty($target->orderType)) {
                            $orderType = $target->orderType;
                        } elseif (!empty($order->buyType)) {
                            $orderType = $target->buyType;
                        } else {
                            $orderType = 'LIMIT';
                        }

                        $exchangeId = $position->exchange->internalId;
                        $options = PositionUtils::extractOptionsForRebuysOrder($positionMediator, $buyStopPrice, $target);
                        if ($buyStopPrice) {
                            $options['buyStopPrice'] = $buyStopPrice;
                        }
                        $orderSide = $positionMediator->isShort() ? 'sell' : 'buy';
                        $positionUserId = empty($position->profitSharingData)
                            ? $position->user->_id : $position->profitSharingData->exchangeData->userId;
                        $positionExchangeInternalId = empty($position->profitSharingData)
                            ? $position->exchange->internalId : $position->profitSharingData->exchangeData->internalId;
                        $order = $ExchangeCalls->sendOrder(
                            $positionUserId,
                            $positionExchangeInternalId,
                            $positionMediator->getSymbolWithSlash(),
                            $orderType,
                            $orderSide,
                            $amount,
                            $limitPrice,
                            $options,
                            true,
                            $position->_id->__toString()
                        );

                        if (is_object($order)) {
                            $sentToRedisForChecking = true;
                            $setPosition = $newPositionCCXT->updatePositionFromReBuyOrder($position, $order, $target);
                            $orderSent = true;
                        } else {
                            $setPosition = $newPositionCCXT->updatePositionFromReBuyOrderError($position, $order, $target);
                            $dcaError = true;
                        }
                    }
                } else {
                    $Monolog->sendEntry('error', "Target or price ($limitPrice) not found");
                }
                if (isset($setPosition)) {
                    if ($orderSent) {
                        $orderArray[] = [
                            'orderId' => $order->getId(),
                            'status' => $order->getStatus(),
                            'type' => 'entry',
                            'price' => $order->getPrice(),
                            'amount' => $order->getAmount(),
                            'cost' => $order->getCost(),
                            'transacTime' => new \MongoDB\BSON\UTCDateTime($order->getTimestamp()),
                            'orderType' => $order->getType(),
                            'done' => false,
                            'isIncreasingPositionSize' => true,
                            'clientOrderId' => $order->getRecvClientId(),
                        ];
                        $pushOrder = [
                            'order' => [
                                '$each' => $orderArray,
                            ],
                        ];
                    } else {
                        $pushOrder = false;
                    }
                    $newPositionCCXT->setPosition($position->_id, $setPosition, true, $pushOrder);

                    /*if (!empty($sentToRedisForChecking)) {
                        if ($order->getType() == 'market') {
                            $quickPriceWatcherQueueName = empty($position->testNet) && empty($position->paperTrading) ? 'quickPriceWatcher' : 'quickPriceWatcher_Demo';
                            $RedisHandlerZignalyQueue->addSortedSet($quickPriceWatcherQueueName, time(), $position->_id->__toString());
                        }
                    }*/

                    if (!empty($dcaError)) {
                        sendNotification($position, 'checkDCAFilledError', $order['error'], ['reBuyTargetId' => $targetId]);
                    }
                }
            }
        }
        $setPosition = [
            'reBuyProcess' => false,
        ];
        $newPositionCCXT->setPosition($position->_id, $setPosition, false);
        $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
        $stopOrderMessage = json_encode(['positionId' => $position->_id->__toString()], JSON_PRESERVE_ZERO_FRACTION);
        $RedisHandlerZignalyQueue->addSortedSet('stopOrdersQueue', time(), $stopOrderMessage, true);
    }

    if (isset($position->reBuyProcess)) {
        $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
    }

    if ($positionId) {
        exit();
    }

    if ($inQueueAt) {
        $elapsedTime = time() - $workingAt;
        $inQueue = $workingAt - $inQueueAt;
        $timeLimit = 'reBuysQueue_Demo' === $queueName ? 300 : 15;
        if ($inQueue && $inQueue > $timeLimit) {
            $performance = [
                'startedWorkingAt' => $workingAt,
                'timeProcessing' => $elapsedTime,
                'inQueue' => $inQueue
            ];
            $Monolog->sendEntry('critical', "Queue performance", $performance);
        }
    }
}

/**
 * Send a notification for a failed DCA.
 *
 * @param \MongoDB\Model\BSONDocument $position
 * @param string $command
 * @param string $error
 * @param array $extraParameters
 */
function sendNotification(\MongoDB\Model\BSONDocument $position, string $command, string $error, array $extraParameters)
{
    $parameters = [
        'userId' => $position->user->_id->__toString(),
        'positionId' => $position->_id->__toString(),
        'status' => $position->status,
    ];

    $parameters['error'] = $error;

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
    $RabbitMQ = new RabbitMQ();
    $RabbitMQ->publishMsg('profileNotifications', $message);
}

/**
 * If the position is from copy-trading, we need to check that the investment from this DCA wouldn't overpass
 * the allocated balance by the user.
 *
 * @param Accounting $Accounting
 * @param Monolog $Monolog
 * @param newPositionCCXT $newPositionCCXT
 * @param newUser $newUser
 * @param \MongoDB\Model\BSONDocument $position
 * @param float $newInvestment
 * @return bool
 */
function checkAllocatedBalance(
    Accounting $Accounting,
    Monolog $Monolog,
    newPositionCCXT $newPositionCCXT,
    newUser $newUser,
    \MongoDB\Model\BSONDocument $position,
    float $newInvestment
) {
    //$Monolog->sendEntry('debug', "Checking allocated balance.");

    $user = $newUser->getUser($position->user->_id);

    if (!isset($position->provider->isCopyTrading) || !$position->provider->isCopyTrading) {
        return true;
    }
    $providerId = is_object($position->provider->_id)
        ? $position->provider->_id->__toString() : $position->provider->_id;

    if (!isset($user->provider->$providerId->allocatedBalance) || $user->provider->$providerId->allocatedBalance === false) {
        return false;
    }
    $allocatedBalance = is_object($user->provider->$providerId->allocatedBalance)
        ? $user->provider->$providerId->allocatedBalance->__toString() : (float)$user->provider->$providerId->allocatedBalance;

    if (isset($user->provider->$providerId->profitsFromClosedBalance) && $user->provider->$providerId->profitsFromClosedBalance) {
        $profitsFromClosed = is_object($user->provider->$providerId->profitsFromClosedBalance) ? $user->provider->$providerId->profitsFromClosedBalance->__toString() : $user->provider->$providerId->profitsFromClosedBalance;
    } else {
        $profitsFromClosed = 0;
    }

    $consumedBalanceFromOpenPositions = $newPositionCCXT->getBalanceFromOpenPositions(
        $Accounting,
        $position->user->_id,
        $position->signal->providerId->__toString()
    );

    $availableBalance = $allocatedBalance + $profitsFromClosed;

    return $newInvestment + $consumedBalanceFromOpenPositions < $availableBalance;
}


/**
 * Check if there is a DCA already placed and cancel it if it's not from the increasing position size functionality,
 * which is detected by the reBuyTarget-subId.
 *
 * $param newPositionCCXT $newPositionCCXT
 * @param \MongoDB\Model\BSONDocument $position
 * @return bool
 */
function cancelFirstDCAIfPlaced(newPositionCCXT $newPositionCCXT, \MongoDB\Model\BSONDocument $position)
{
    if (!isset($position->reBuyTargets) || !$position->reBuyTargets) {
        return false;
    }

    $target = false;

    foreach ($position->reBuyTargets as $reBuyTarget) {
        if (!empty($reBuyTarget->done) || !empty($reBuyTarget->cancel) || !empty($reBuyTarget->skipped)) {
            continue;
        } elseif ((!isset($reBuyTarget->subId) || !$reBuyTarget->subId) && isset($reBuyTarget->orderId) && $reBuyTarget->orderId) {
            $target = $reBuyTarget;
        }
    }

    if (!$target) {
        return false;
    }

    return $newPositionCCXT->cancelOrder($target->orderId, $position);
}


/**
 * Check if orders from position are already filled, because if the last take profit has been filled, the
 * position needs to be closed and stops processing it.
 * @param ExchangeCalls $ExchangeCalls
 * @param Monolog $Monolog
 * @param newPositionCCXT $newPositionCCXT
 * @param \MongoDB\Model\BSONDocument $position
 * @throws Exception
 */
function doubleCheckOpenOrders(
    ExchangeCalls $ExchangeCalls,
    Monolog $Monolog,
    newPositionCCXT $newPositionCCXT,
    \MongoDB\Model\BSONDocument $position
) {
    $CheckOrders = new CheckOrdersCCXT($position, $ExchangeCalls, $newPositionCCXT, $Monolog);

    $CheckOrders->checkOrders(true, false);
}


/**
 * Extract the remain amount and its cost from the position for the new target order.
 *
 * @param Accounting $Accounting
 * @param Monolog $Monolog
 * @param ExchangeCalls $ExchangeCalls
 * @param \MongoDB\Model\BSONDocument $position
 * @param float $limitPrice
 * @param object $target
 * @return array
 */
function getAmountAndInvestment(
    Accounting $Accounting,
    Monolog $Monolog,
    ExchangeCalls $ExchangeCalls,
    \MongoDB\Model\BSONDocument $position,
    float $limitPrice,
    object $target
) {
    $positionMediator = PositionMediator::fromMongoPosition($position);

    if (isset($position->avgBuyingPrice)) {
        $entryPrice = is_object($position->avgBuyingPrice) ? $position->avgBuyingPrice->__toString() : $position->avgBuyingPrice;
    } else {
        $entryPrice = is_object($position->realBuyPrice) ? $position->realBuyPrice->__toString() : $position->realBuyPrice;
    }

    $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();
    if (!empty($target->newInvestment)) {
        //Todo: We should create DCAPositionSizePercentage too.
        //Todo: Check that the new cost is not bigger than 100% total balance in copy-trading and profit-sharing.
        $cost = $target->newInvestment;
    } else {
        list(, $remainAmount) = $Accounting->recalculateAndUpdateAmounts($position);
        $remainingAmount = $remainAmount * $target->quantity;
        $cost = $exchangeHandler->calculateOrderCostZignalyPair(
            $positionMediator->getSymbol(),
            $remainingAmount,
            $entryPrice
        );
    }

    /*$amount = !empty($position->version) && $position->version >= 3 && !empty($target->quantity)
        ? $target->quantity : $exchangeHandler->calculateAmountFromPositionSize(*/
    $amount = $exchangeHandler->calculateAmountFromPositionSize(
            $positionMediator->getSymbol(),
            $cost,
            $limitPrice
        );
    $symbol = $positionMediator->getSymbol();//$position->signal->base . '/' . $position->signal->quote;
    $amount = $ExchangeCalls->getAmountToPrecision($amount, $symbol);

    return [$amount, $cost];
}


/**
 * Get the pending targets from reBuyTargets, and if any, return an array with their ids.
 *
 * @param Monolog $Monolog
 * @param \MongoDB\Model\BSONDocument $position
 * @return array|bool
 */
function getReBuyTargets(Monolog $Monolog, \MongoDB\Model\BSONDocument $position)
{
    if (!isset($position->reBuyTargets) || !$position->reBuyTargets) {
        return false;
    }

    $targets = [];
    $firstTarget = false;

    foreach ($position->reBuyTargets as $reBuyTarget) {
        $targetId = (int) $reBuyTarget->targetId;
        if ($targetId < 1) {
            $Monolog->sendEntry('debug', "TargetId $targetId less than 0");
            continue;
        }
        if (!empty($reBuyTarget->done) || !empty($reBuyTarget->cancel) || !empty($reBuyTarget->skipped) || !empty($reBuyTarget->orderId)) {
            continue;
        } elseif (!empty($reBuyTarget->subId)) {
            $targets[] = $targetId;
        } else {
            if (!$firstTarget) {
                $targets[] = $targetId;
                $firstTarget = empty($position->DCAPlaceAll);
            }
        }
    }

    return count($targets) == 0 ? false : $targets;
}

/**
 * Get target information for sending order.
 *
 * @param ExchangeCalls $ExchangeCalls
 * @param Monolog $Monolog
 * @param PositionMediator $positionMediator
 * @param \MongoDB\Model\BSONDocument $position
 * @param int $targetId
 * @return array
 */
function getReBuyTarget(
    ExchangeCalls $ExchangeCalls,
    Monolog $Monolog,
    PositionMediator $positionMediator,
    \MongoDB\Model\BSONDocument $position,
    int $targetId
) {
    $price = false;
    $stopPrice = false;
    $target = !empty($position->reBuyTargets->$targetId) ? $position->reBuyTargets->$targetId : false;
    if (!$target) {
        return [$target, $price, $stopPrice];
    }
    if (!empty($target->subId)) {
        $price = !empty($target->limitPrice) ? $target->limitPrice : $positionMediator->getLastPrice();
        $stopPrice = !empty($target->buyStopPrice) ? $target->buyStopPrice : false;
    } elseif (!empty($target->pricePriority) && 'price' === $target->pricePriority && !empty($target->priceTarget)) {
        $price = $target->priceTarget;
    } elseif (isset($target->triggerPercentage)) {
        //Todo: next two check are temporal, just until we are sure that users are sending targets with minus symbol
        //Todo: if needed in the signal.
        if ($positionMediator->isLong() && $target->triggerPercentage >= 1) {
            return [false, false, false];
        }

        if ($positionMediator->isShort() && $target->triggerPercentage <= 1) {
            return [false, false, false];
        }

        $price = $positionMediator->getAverageEntryPrice() * $target->triggerPercentage;
    }

    $price = $ExchangeCalls->getPriceToPrecision($price, $positionMediator->getSymbol());
    if ($stopPrice) {
        $stopPrice = $ExchangeCalls->getPriceToPrecision($stopPrice, $positionMediator->getSymbol());
    }

    return [$target, $price, $stopPrice];
}

/**
 * Check if the returned message is critical.
 * @param $msg
 * @return bool
 */
function getCriticalErrors($msg)
{
    $nonCriticalErrors = [
        'Account has insufficient balance for requested action.',
        'Invalid quantity.',
        'Filter failure: MIN_NOTIONAL',
    ];

    return !in_array($msg, $nonCriticalErrors);
}

/**
 * Get the position from a given id or from the list.
 *
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param RedisLockController $RedisLockController
 * @param string $processName
 * @param string $queueName
 * @param bool|string $positionId
 * @return array
 */
function getPosition(
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
    } else {
        $inQueueAt = time();
    }

    $position = $RedisLockController->positionHardLock($positionId, $processName);

    if (!$position || $position->closed) {
        return [false, false];
    }

    return [$position, $inQueueAt];
}