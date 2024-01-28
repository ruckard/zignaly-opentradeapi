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


use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\Mediator\PositionMediator;
use Zignaly\Process\DIContainer;
use Zignaly\redis\ZignalyLastPriceRedisService;
use Zignaly\utils\PositionUtils;

require_once __DIR__ . '/loader.php';
global $RabbitMQ;
$processName = 'takeProfitWorker';
$queueName = (isset($argv['1'])) && $argv['1'] != 'false' ? $argv['1'] : 'takeProfit';

$container = DIContainer::getContainer();

$container->set('monolog', new Monolog($processName));
$Monolog = $container->get('monolog');
/** @var newPositionCCXT $newPositionCCXT */
$newPositionCCXT = $container->get('newPositionCCXT.model');
$newPositionCCXT->configureLogging($Monolog);
$RedisHandlerZignalyLastPrices = new RedisHandler($Monolog, 'ZignalyLastPrices');
/** @var RedisLockController $RedisLockController */
$RedisLockController = $container->get('RedisLockController');

$lastPriceService = new ZignalyLastPriceRedisService($RedisHandlerZignalyLastPrices, $Monolog);

$ExchangeCalls = new ExchangeCalls($Monolog);
$newPositionCCXT->configureExchangeCalls($ExchangeCalls);
$newPositionCCXT->configureLastPriceService($lastPriceService);
$scriptStartTime = time();

$callback = function ($msg) {
    require_once dirname(__FILE__) . '/loader.php';
    global $Accounting, $ExchangeCalls, $Monolog, $newPositionCCXT, $processName, $RabbitMQ, $continueLoop,
           $RedisLockController, $container, $queueName;

    if (!$continueLoop) {
        exit();
    }

    if (null == $msg) {
        return;
    }
    
    $RedisHandlerZignalyQueue = $container->get('redis.queue');

    $Monolog->trackSequence();
    try {
        $message = json_decode($msg->body, true);
        $Monolog->addExtendedKeys('positionId', $message['positionId']);
        $Monolog->sendEntry('info', "Received ", $message);
        // Ensure position exists in storage so any ephemeral position for automated tests
        // don't cause worker crash when deleted.
        $readOnlyPosition = $newPositionCCXT->getPosition($message['positionId']);
        if (!$readOnlyPosition) {
            throw new Exception(
                sprintf("Position %s not found on storage.", $message['positionId'])
            );
        }

        $position = $RedisLockController->positionHardLock($message['positionId'], $processName);


        // Position may exists but already locked so re-queue for later processing.
        if (!$position) {
            // If position exists, re-queue to retry processing when not locked.
            $method = $readOnlyPosition->closed ? 'warning' : 'error';
            $Monolog->sendEntry(
                $method,
                "Couldn't lock position, locked by "
                . $readOnlyPosition->lockedBy . " from "
                . $readOnlyPosition->lockedFrom . " at "
                . date('Y-m-d H:i:s', $readOnlyPosition->lockedAt->__toString() / 1000)
            );

            if ('takeProfitWorker' === $readOnlyPosition->lockedBy) {
                $Monolog->sendEntry('debug', "Position already locked by the takeProfitWorker process");
            } elseif (!$readOnlyPosition->closed) {
                $msg->delivery_info['channel']->basic_nack($msg->delivery_info['delivery_tag'], false, true);
                return;
            } else {
                $Monolog->sendEntry('debug', "Not resending because it's closed");
            }
        } else {
            $Monolog->addExtendedKeys('userId', $position->user->_id->__toString());
            $positionMediator = PositionMediator::fromMongoPosition($position);
            if (!$ExchangeCalls->setCurrentExchange(
                $positionMediator->getExchange()->getId(),
                $positionMediator->getExchangeType(),
                $positionMediator->getExchangeIsTestnet()
            )) {
                $Monolog->sendEntry('critical', 'Error connecting the exchange');
                $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
                $msg->delivery_info['channel']->basic_nack($msg->delivery_info['delivery_tag'], false, true);

                return;
            }
            $CheckOrdersCCXT = new CheckOrdersCCXT($position, $ExchangeCalls, $newPositionCCXT, $Monolog);
            $CheckOrdersCCXT->checkOrders(false, false, true);
            $position = $newPositionCCXT->getPosition($message['positionId']);
            $positionMediator->updatePositionEntity($position);
            if (checkIfNeedToSetTakeProfit($position)) {
                if ($newPositionCCXT->cancelPendingOrders($position, ['takeProfit', 'stopLoss'])) {
                    $position = $newPositionCCXT->getPosition($message['positionId']);
                    $positionMediator->updatePositionEntity($position);

                    list(, $remainingAmount) = $Accounting->recalculateAndUpdateAmounts($position);
                    if ($remainingAmount > 0) {
                        foreach ($position->takeProfitTargets as $target) {
                            // Skip targets that are done.
                            if ($target->done) {
                                continue;
                            }

                            sendTakeProfitOrder(
                                $positionMediator,
                                $Monolog,
                                $ExchangeCalls,
                                $newPositionCCXT,
                                $RedisHandlerZignalyQueue,
                                $position,
                                $target->targetId
                            );
                        }
                    }
                } else {
                    $Monolog->sendEntry('error', "Canceling order failed, so stopping the process.");
                    $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
                    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag'], false, true);
                    $RabbitMQ->publishMsg($queueName, $msg->body);

                    return;
                }
            }

            $newPositionCCXT->setPosition($position->_id, ['updating' => false], false);
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
        }
    } catch (Exception $e) {
        $Monolog->sendEntry('critical', "Failed: Message: " . $e->getMessage());
        if (!empty($position) && isset($position->_id)) {
            $RedisLockController->removeLock($position->_id->__toString(), $processName, 'all');
        }
    }

    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
};
$RabbitMQ->consumeMsg($queueName, $callback);

function checkIfNeedToSetTakeProfit($position)
{
    if ($position->closed) {
        return false;
    }

    if (!isset($position->status) || $position->status != 9) {
        return false;
    }

    if (empty($position->takeProfitTargets)) {
        return false;
    }

    if (!empty($position->reduceOrders)) {
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

    $targets = 0;
    foreach ($position->takeProfitTargets as $target) {
        if (!$target->done) {
            $targets++;
        }
    }

    return $targets > 0;
}

/**
 * @param PositionMediator $positionMediator
 * @param Monolog $Monolog
 * @param ExchangeCalls $ExchangeCalls
 * @param newPositionCCXT $newPositionCCXT
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param \MongoDB\Model\BSONDocument $position
 * @param int $targetId
 * @return bool
 */
function sendTakeProfitOrder(
    PositionMediator &$positionMediator,
    Monolog $Monolog,
    ExchangeCalls $ExchangeCalls,
    newPositionCCXT $newPositionCCXT,
    RedisHandler $RedisHandlerZignalyQueue,
    \MongoDB\Model\BSONDocument $position,
    int $targetId
) {
    $position = $newPositionCCXT->getPosition($position->_id);
    $positionMediator->updatePositionEntity($position);
    $exchangeHandler = $positionMediator->getExchangeMediator()->getExchangeHandler();

    $entryPrice = $positionMediator->getAverageEntryPrice();
    $targetPriceFactor = $position->takeProfitTargets->$targetId->priceTargetPercentage;

    //Todo: next two check are temporal, just until we are sure that users are sending targets with minus symbol
    //Todo: if needed in the signal.
    //Actually we are commenting this because it doesn't work when there is price and not percentage.
    /*if ($positionMediator->isLong() && $targetPriceFactor <= 1) {
        return false;
    }
    if ($positionMediator->isShort() && $targetPriceFactor >= 1) {
        return false;
    }*/

    if (!empty($position->takeProfitTargets->$targetId->pricePriority) && 'price' === $position->takeProfitTargets->$targetId->pricePriority
        && !empty($position->takeProfitTargets->$targetId->priceTarget)) {
        $targetPrice = $position->takeProfitTargets->$targetId->priceTarget;
    } else {
        $targetPrice = $entryPrice * $targetPriceFactor;
    }
    $priceLimit = $ExchangeCalls->getPriceToPrecision($targetPrice, $positionMediator->getSymbol());
    $amount = getAmountForMultiTargetSelling($position, $targetId);
    $cost = $exchangeHandler->calculateOrderCostZignalyPair(
        $positionMediator->getSymbol(),
        $amount,
        $priceLimit
    );
    if (!$amount) {
        return false;
    }
    if (!$ExchangeCalls->checkIfValueIsGood('cost', 'min', $cost, $positionMediator->getSymbol())) {
        return false;
    }
    $options = PositionUtils::extractOptionsForOrder($positionMediator, $position->takeProfitTargets->$targetId);

    $orderSide = $positionMediator->isShort() ? 'buy' : 'sell';

    $positionUserId = empty($position->profitSharingData) ? $position->user->_id : $position->profitSharingData->exchangeData->userId;
    $positionExchangeInternalId = empty($position->profitSharingData) ? $position->exchange->internalId : $position->profitSharingData->exchangeData->internalId;

    $order = $ExchangeCalls->sendOrder(
        $positionUserId,
        $positionExchangeInternalId,
        $positionMediator->getSymbol(),
        'LIMIT',
        $orderSide,
        $amount,
        $priceLimit,
        $options,
        true,
        $position->_id->__toString()
    );

    if (is_object($order)) {
        $newPositionCCXT->updatePositionFromTakeProfitOrder($position, $order, $targetId, $amount);
        if ($order->getType() == 'market') {
            $quickPriceWatcherQueueName = empty($position->testNet) && empty($position->paperTrading) ? 'quickPriceWatcher' : 'quickPriceWatcher_Demo';
            $RedisHandlerZignalyQueue->addSortedSet($quickPriceWatcherQueueName, time(), $position->_id->__toString());
        }
    } else {
        $newPositionCCXT->updatePositionFromTakeProfitOrderError($position, $order, $targetId, $amount);
    }
}

function getAmountForMultiTargetSelling($position, $targetId)
{
    global $Accounting, $ExchangeCalls, $Monolog;

    $totalAmount = $Accounting->getTotalAmountWithoutNonBNBFees($position->_id);
    list(, $remainingAmount) = $Accounting->recalculateAndUpdateAmounts($position);
    $leftOver = $remainingAmount;
    $lastTargetId = false;
    $undoneAmounts = [];
    $totalFactor = 0;
    
    $positionMediator = PositionMediator::fromMongoPosition($position);
    // $symbol = $position->signal->base . '/' . $position->signal->quote;
    $symbol = $positionMediator->getSymbol();

    //$Monolog->sendEntry('debug', " Total Amount: $totalAmount Remaining: $remainingAmount");
    foreach ($position->takeProfitTargets as $target) {
        $lastTargetId = $target->targetId;
        $amountFactor = $target->amountPercentage;
        $totalFactor += $amountFactor;
        $amount = $amountFactor * $totalAmount;
        $preciseAmount = $ExchangeCalls->getAmountToPrecision($amount, $symbol);
        $undoneAmounts[$lastTargetId] = $preciseAmount;

        //$Monolog->sendEntry('debug', "Target $lastTargetId, Remaining: $remainingAmount, LeftOver: $leftOver, Factor: $amountFactor, Amount: $amount, Precise: $preciseAmount, totalFactor: $totalFactor", $undoneAmounts);

        if (!$target->done) {
            $leftOver -= $preciseAmount;
        }
    }

    if ($targetId == $lastTargetId && $leftOver > 0 && $totalFactor == 1) {
        $undoneAmountPlusLeftOver = $undoneAmounts[$targetId] + $leftOver;
        //$Monolog->sendEntry('debug', "Target $lastTargetId, final total amount: $undoneAmountPlusLeftOver");
        $returnAmount = $ExchangeCalls->getAmountToPrecision($undoneAmountPlusLeftOver, $symbol);
    } else {
        $returnAmount = $undoneAmounts[$targetId];
    }

    if ($returnAmount > $remainingAmount) {
        $returnAmount = $remainingAmount;
    }

    return number_format($ExchangeCalls->getAmountToPrecision($returnAmount, $symbol), 12, '.', '');
}
