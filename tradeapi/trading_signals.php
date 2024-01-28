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


//Uncomment for debugging
error_reporting(E_ALL);
ini_set('display_errors', 'on');

use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;
use Zignaly\exchange\ExchangeOrder;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Mediator\ExchangeMediator\ExchangeMediator;
use Zignaly\Process\DIContainer;
use Zignaly\redis\ZignalyLastPriceRedisService;
use Zignaly\redis\ZignalyMarketDataRedisService;
use Zignaly\utils\PositionUtils;

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    print_r('OK');
    exit();
}

$createSecondaryDBLink = true;
require_once dirname(__FILE__) . '/loader.php';

$processName = 'trading_signals';
$container = DIContainer::getContainer();
$container->set('monolog', new Monolog($processName));
/** @var Monolog $Monolog */
$Monolog = $container->get('monolog');
/** @var newUser $newUser */
$newUser = $container->get('newUser.model');
/** @var ZignalyMarketDataRedisService $marketDataService */
$marketDataService = $container->get('marketData');
/** @var Signal $Signal */
$Signal = $container->get('signal.model');
$Signal->configureLogging($Monolog);
/** @var MarketConversion $MarketConversion */
$MarketConversion = $container->get('MarketConversion');
/** @var newPositionCCXT $newPositionCCXT */
$newPositionCCXT = $container->get('newPositionCCXT.model');
$newPositionCCXT->configureLoggingByContainer($container);
$newPositionCCXT->configureMongoDBLinkRO();
/** @var RedisHandler $RedisHandlerZignalyQueue */
$RedisHandlerZignalyQueue = $container->get('redis.queue');
/** @var ZignalyLastPriceRedisService $lastPriceService */
$lastPriceService = $container->get('lastPrice');
/** @var Status $Status */
$Status = $container->get('position.status');
/** @var ExchangeCalls $ExchangeCalls */
$ExchangeCalls = $container->get('exchangeMediator');
$ExitPosition = new ExitPosition($Monolog, $processName, 'exitPosition');

try {
    $Monolog->trackSequence();

    //Get signal data from the HTTP request
    $signalData = (isset($_SERVER['HTTP_CONTENT_TYPE']) &&
        (strpos($_SERVER['HTTP_CONTENT_TYPE'], 'application/json') !== false)
        || (isset($_SERVER['CONTENT_TYPE']) && (strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false))
    ) ? json_decode(file_get_contents('php://input'), true) :
        $_REQUEST;

    //Let's be sure that the signal's parameters are there.
    if (empty($signalData)) {
        http_response_code(400);
        print_r("No signal's parameters sent or wrong format.");
        exit();
    }

    //Log the data as it comes from the request
    $Monolog->sendEntry('info', "New signal: ", $signalData);

    //Parse the request into a known signal data structure
    $signal = $Signal->composeCompleteSignalFromRequest($signalData);

    //Be sure that required fields exist and are as they should
    if (!$Signal->generalChecks($signal)) {
        exit();
    }

    //Add some extended keys to Monolog to have them in all the logs entries for this request.
    setMonologExtendedKeys($Monolog, $signal);

    //Find the user from the given signal key, if none exits the function will exit and return an error header.
    $user = getUserFromExchangeSignalsKey($newUser, $Monolog, $signal);
    if (empty($user->email)) {
        http_response_code(400);
        print_r("Invalid Key");
        exit();
    }

    //Find the exchange from that key inside the user. It has to exist, given that the user was found in the previous step.
    $exchange = extractExchangeFromKey($Monolog, $ExchangeCalls, $user, $signal);

    //Exclude non-Zignaly exchanges.
    if (empty($exchange->subAccountId) && empty($exchange->zignalyApiCode)) {
        http_response_code(400);
        print_r("Only Zignaly Exchange accounts are allowed.");
        exit();
    }

    $signal['exchangeAccountType'] = empty($exchange->exchangeType) ? 'SPOT' : $exchange->exchangeType;
    //We need to parse the pair parameter as the libraries need it.
    $symbol = getSymbolFromSignalPair($marketDataService, $Monolog, $signal);

    //We store the signal in the DB:
    $signal['_id'] = $Signal->storeSignal($signal);

    //Get the type of signal in lower case.
    $normalSignalType = strtolower($signal['type']);

    //Process an entry type signal
    if ('entry' === $normalSignalType || 'buy' === $normalSignalType) {
        processEntry($RedisHandlerZignalyQueue, $newPositionCCXT, $Monolog, $ExchangeCalls, $lastPriceService, $Status, $Signal, $exchange, $user, $signal);
    }

    //From here, all signals should have a signalId, if not, no point checking more. We need to be sure that the trading terminal works with signalIds.
    if (empty($signal['signalId'])) {
        $Monolog->sendEntry('info', "Empty signalId", $signal);
        header('HTTP/1.1 406 Not Acceptable');
        print_r("Empty signalId");
        exit();
    }

    //Process a cancel entry type signal.
    if ('cancelentry' === $normalSignalType) {
        processCancelEntry($newPositionCCXT, $signal);
    }

    //Process an exit type signal
    if ('sell' === $normalSignalType || 'exit' === $normalSignalType) {
        processSell($newPositionCCXT, $Monolog, $signal, $ExitPosition);
    }

    //Process an update type signal
    if ($normalSignalType == 'update') {
        processUpdate($RedisHandlerZignalyQueue, $Monolog, $newPositionCCXT, $signal);
    }

    //Process a reverse type signal
    if ('reverse' === $normalSignalType) {
        processReverse($Monolog, $newPositionCCXT, $signal, $ExitPosition, $Signal, $RedisHandlerZignalyQueue,
            $ExchangeCalls, $lastPriceService, $Status, $exchange, $user);
    }

    // Seems that we received an invalid signal type.
    $Monolog->sendEntry('critical', sprintf("Unsupported signal type %s", $normalSignalType));
    header('HTTP/1.1 406 Not Acceptable');
    print_r("Unsupported signal type: $normalSignalType");
    exit();

} catch (\Exception $e) {
    $Monolog->sendEntry(
        'critical',
        sprintf(
            "Signal processing failed.\n Signal data: %s\n Error: %s\n\n Trace: %s",
            print_r($signal, true),
            $e->getMessage(),
            $e->getTraceAsString(),
        )
    );
}

function processEntry(
    RedisHandler $RedisHandlerZignalyQueue,
    newPositionCCXT $newPositionCCXT,
    Monolog $Monolog,
    ExchangeCalls $ExchangeCalls,
    ZignalyLastPriceRedisService $lastPriceService,
    Status $Status,
    Signal $Signal,
    BSONDocument $exchange,
    BSONDocument $user,
    array $signal
) : void {
    /** @var ExchangeMediator $exchangeMediator */
    $exchangeMediator = ExchangeMediator::fromMongoExchange($exchange);
    $exchangeHandler = $exchangeMediator->getExchangeHandler();
    $Signal->convertToFactor($signal);

    $composedPosition = $newPositionCCXT->composePosition($ExchangeCalls, $exchangeHandler, $user, $exchange, $signal);
    $newPositionCCXT->checkIfPositionIsGoodForEntryOrder($ExchangeCalls, $Status, $exchange, $composedPosition);

    $positionId = $newPositionCCXT->insertAndGetId($composedPosition);
    $Monolog->addExtendedKeys('positionId', $positionId);

    if ($composedPosition['buyType'] == 'IMPORT') {
        $Monolog->sendEntry('info', "Importing position");
        $newPositionCCXT->createImportData($RedisHandlerZignalyQueue,  $exchangeHandler, $positionId, $composedPosition);
        $message = json_encode(['positionId' => $positionId], JSON_PRESERVE_ZERO_FRACTION);
        $RedisHandlerZignalyQueue->addSortedSet('reduceOrdersQueue', time(), $message, true);
    } else {
        $Monolog->sendEntry('info', "Sending Entry Order");
        $newPositionCCXT->configureExchangeCalls($ExchangeCalls);
        $newPositionCCXT->configureLastPriceService($lastPriceService);
        $position = $newPositionCCXT->getPosition($positionId);
        $order = sendEntryOrder($position, $ExchangeCalls, 'first');
        if (!is_object($order) && isset($order['error'])) {
            $setPosition = [
                'error' => [
                    'code' => $order['error'],
                ],
                'status' => $Status->getPositionStatusFromError($order['error']),
                'closed' => true,
                'closedAt' => new UTCDateTime()
            ];
            $newPositionCCXT->setPosition($position->_id, $setPosition);
            http_response_code(465);
            print_r('Error sending the entry order');
            exit();
        } else {
            $setPosition = [
                "status" => 1,
                'locked' => false,
            ];
            $parsedOrder = composeOrder($order);
            $setPosition["orders.{$order->getId()}"] = $parsedOrder;
            $orderArray[] = $parsedOrder;

            if ('futures' === $position->exchange->exchangeType && !empty($position->multiSecondData)) {
                $order = sendEntryOrder($position, $ExchangeCalls, 'second');
                if (!is_object($order) && isset($order['error'])) {
                    $setPosition['multiSecondData.error'] = $order['error'];
                } else {
                    $parsedOrder = composeOrder($order);
                    $setPosition["orders.{$order->getId()}"] = $parsedOrder;
                    $orderArray[] = $parsedOrder;
                }
            }

            $pushOrder = [
                'order' => [
                    '$each' => $orderArray,
                ],
            ];

            $newPositionCCXT->setPosition($position->_id, $setPosition, true, $pushOrder);
            $position = $newPositionCCXT->getPosition($position->_id);
            $CheckOrdersCCXT = new CheckOrdersCCXT($position, $ExchangeCalls, $newPositionCCXT, $Monolog);
            $CheckOrdersCCXT->checkOrders(false, true, true);
        }
    }

    http_response_code(200);
    print_r($positionId);
    exit();
}

/**
 * Prepare the exchangeOrder for the orders object inside position
 * @param ExchangeOrder $order
 * @return array
 */
function composeOrder(ExchangeOrder $order) : array
{
    return [
        'orderId' => $order->getId(),
        'status' => $order->getStatus(),
        'type' => 'entry',
        'price' => $order->getPrice(),
        'amount' => $order->getAmount(),
        'cost' => $order->getCost(),
        'transacTime' => new UTCDateTime($order->getTimestamp()),
        'orderType' => $order->getType(),
        'done' => false,
        'clientOrderId' => $order->getRecvClientId(),
        'side' => $order->getSide(),
        'originalEntry' => true,
    ];
}

/**
 * Send the entry order to the exchange.
 *
 * @param BSONDocument $position
 * @param ExchangeCalls $ExchangeCalls
 * @param string $firstOrSecond
 * @return array|string[]|\Zignaly\exchange\ccxtwrap\ExchangeOrderCcxt|\Zignaly\exchange\ExchangeOrder
 */
function sendEntryOrder(BSONDocument $position, ExchangeCalls $ExchangeCalls, string $firstOrSecond)
{

    $options = PositionUtils::extractOptionsToCreateOrder($position, 'first');
    $multiOrder = 'first' === $firstOrSecond ? 'multiFirstData' : 'multiSecondData';

    return $ExchangeCalls->sendOrder(
        $position->user->_id,
        $position->exchange->internalId,
        $position->signal->pair,
        $position->$multiOrder->orderType,
        $position->$multiOrder->side,
        $position->$multiOrder->amount,
        $position->$multiOrder->limitPrice,
        $options,
        true,
        $position->_id->__toString(),
        $position->leverage
    );
}

/**
 * Process the update signal
 *
 * @param RedisHandler $RedisHandlerZignalyQueue
 * @param Monolog $Monolog
 * @param newPositionCCXT $newPositionCCXT
 * @param array $signal
 * @return void
 */
function processUpdate(RedisHandler $RedisHandlerZignalyQueue, Monolog $Monolog, newPositionCCXT $newPositionCCXT, array $signal) : void
{
    $position = $newPositionCCXT->getActivePositionsFromExchangeKeyAndSignalId($signal, false);
    if (empty($position->user)) {
        http_response_code(462);
        print_r('No position found for exiting');
        exit();
    }
    $Monolog->sendEntry('info', "Sending position {$position->_id->__toString()} to update queue.");

    parseSignalOptionsForUpdatingProcess($signal);

    $score = microtime(true) * 1000;
    $signal['positionId'] = $position->_id->__toString();
    $message = json_encode($signal);
    if (1 === $RedisHandlerZignalyQueue->addSortedSet('updatePositionV2', $score, $message, true)) {
        $Monolog->sendEntry('info', "Position ".$position->_id->__toString(). " sent to update queue, now updating flag updating as true.");
        $setPosition = ['updating' => true,];
        $newPositionCCXT->setPosition($position->_id, $setPosition);
        http_response_code(200);
        print_r('OK');
        exit();
    }

    $Monolog->sendEntry('info', "Queue insert failed");
    http_response_code(463);
    print_r('Queue insert failed');
    exit();
}

/**
 * Adapt the signal to the updateProcess.
 *
 * @param array $signal
 * @return void
 */
function parseSignalOptionsForUpdatingProcess(array &$signal) : void
{
    if (isset($signal['trailingStopTriggerPercentage'])) {
        $signal['trailingStopTriggerPercentage'] = empty($signal['trailingStopTriggerPercentage'])
        || !is_numeric($signal['trailingStopTriggerPercentage']) ? false
            : 1 + (abs($signal['trailingStopTriggerPercentage']) / 100);
    }

    if (isset($signal['trailingStopDistancePercentage'])) {
        $signal['trailingStopDistancePercentage'] = empty($signal['trailingStopDistancePercentage'])
            ? false : 1 - abs($signal['trailingStopDistancePercentage']) / 100;
    }

    if (isset($signal['stopLossPercentage'])) {
        $signal['stopLossPercentage'] = empty($signal['stopLossPercentage'])
            ? false : $signal['stopLossPercentage'] / 100 + 1;
    }

    if (isset($signal['reduceAvailablePercentage'])) {
        $signal['reduceAvailablePercentage'] = $signal['reduceAvailablePercentage'] / 100;
    }

    if (isset($signal['reduceTargetPercentage'])) {
        $signal['reduceTargetPercentage'] = 1 + $signal['reduceTargetPercentage'] / 100;
    }
}


/**
 * Process the exit signal.
 *
 * @param newPositionCCXT $newPositionCCXT
 * @param Monolog $Monolog
 * @param array $signal
 * @param ExitPosition $ExitPosition
 * @return void
 */
function processSell(
    newPositionCCXT $newPositionCCXT,
    Monolog $Monolog,
    array $signal,
    ExitPosition $ExitPosition
): void {
    $Monolog->sendEntry('info', "Looking the position from this sell signal: ", $signal);
    $position = $newPositionCCXT->getActivePositionsFromExchangeKeyAndSignalId($signal);

    if (!empty($position->status)) {
        $Monolog->sendEntry('info', "Sending position {$position->_id->__toString()} to sell.");
        $status = $signal['status'] ?? 40;
        $response = $ExitPosition->process($position->_id->__toString(), $status, true);
        http_response_code($response[0]);
        print_r($response[1]);
        exit();
    }

    $Monolog->sendEntry('info', "No position found for exiting");
    http_response_code(462);
    print_r('No position found for exiting');
    exit();
}

function processReverse(
    Monolog $Monolog,
    newPositionCCXT $newPositionCCXT,
    array $signal,
    ExitPosition $ExitPosition,
    Signal $Signal,
    RedisHandler $RedisHandlerZignalyQueue,
    ExchangeCalls $ExchangeCalls,
    ZignalyLastPriceRedisService $lastPriceService,
    Status $Status,
    BSONDocument $exchange,
    BSONDocument $user
): void {
    $Monolog->sendEntry('info', "Looking the position from this reverse signal: ", $signal);
    $position = $newPositionCCXT->getActivePositionsFromExchangeKeyAndSignalId($signal, false);
    $side = 'LONG';
    if (!empty($position->status)) {
        if (!$position->buyPerformed) {
            http_response_code(480);
            print_r('The current open position did not fill the initial entry order yet');
            exit();
        }
        $Monolog->sendEntry('info', "Sending position {$position->_id->__toString()} to sell.");
        $status = $signal['status'] ?? 40;
        $response = $ExitPosition->process($position->_id->__toString(), $status, false);
        if (200 !== $response[0]) {
            http_response_code($response[0]);
            print_r($response[1]);
            exit();
        }
        $side = $position->side;
    }

    $buySignal = $Signal->composeEntrySignalFromReverseV2($signal, $side);
    $buySignal['_id'] = $Signal->storeSignal($buySignal);
    processEntry($RedisHandlerZignalyQueue, $newPositionCCXT, $Monolog, $ExchangeCalls, $lastPriceService, $Status, $Signal, $exchange, $user, $buySignal);
}

/**
 * @param newPositionCCXT $newPositionCCXT
 * @param array $signal
 * @return void
 */
function processCancelEntry(newPositionCCXT $newPositionCCXT, array $signal) : void
{
    if ($newPositionCCXT->markPositionsForCancelingEntry($signal)) {
        http_response_code(200);
        print_r('OK');
    } else {
        http_response_code(460);
        print_r('Position could not be update');
    }
    exit();
}

/**
 * @param ZignalyMarketDataRedisService $marketDataService
 * @param Monolog $Monolog
 * @param array $signal
 * @return string
 */
function getSymbolFromSignalPair(ZignalyMarketDataRedisService $marketDataService, Monolog $Monolog, array &$signal) : string
{
    if (empty($signal['pair'])
        || empty($signal['exchange'])
        || false === ZignalyExchangeCodes::getExchangeFromCaseInsensitiveString($signal['exchange'])) {
        $Monolog->sendEntry('warning', ": Not exchange or pair found: ", $signal);
        header('HTTP/1.1 406 Not Acceptable');
        print_r('Not exchange or pair found');
        exit();
    }

    $marketEncoder = BaseMarketEncoder::newInstance(
        $signal['exchange'],
        ExchangeMediator::getExchangeTypeFromArray($signal, 'exchangeAccountType')
    );
    // clean pair string received from signal to become Zignaly pair id
    $signal['pair'] = $marketEncoder->withoutSlash($signal['pair']);

    return signalAugmentSymbolInfo($marketDataService, $Monolog, $signal);
}

/**
 * @param ZignalyMarketDataRedisService $marketDataService
 * @param Monolog $Monolog
 * @param array $signal
 * @return string
 */
function signalAugmentSymbolInfo(ZignalyMarketDataRedisService $marketDataService, Monolog $Monolog, array &$signal) : string
{
    //ToDo: If we don't call BitMEXFutures to the redis data, this will fail.
    $type = empty($signal['exchangeAccountType']) ? 'SPOT' : $signal['exchangeAccountType'];
    if ($goodExchangeName = ZignalyExchangeCodes::getExchangeFromCaseInsensitiveStringWithType($signal['exchange'], $type)) {
        $symbolInfo = $marketDataService->getMarket($goodExchangeName, strtoupper($signal['pair']));
        if ($symbolInfo) {
            $marketEncoder = BaseMarketEncoder::newInstance(
                $goodExchangeName,
                ExchangeMediator::getExchangeTypeFromArray($signal, 'exchangeAccountType')
            );
            // BitMEX => baseId and quoteId
            // Others => base and quote
            $signal['base'] = $marketEncoder->getBaseFromMarketData($symbolInfo);
            $signal['quote'] = $marketEncoder->getQuoteFromMarketData($symbolInfo);
            // returning zignaly symbol would be equivalent to Zignaly pair id
            // in BitMEX is equal to ccxt.market.id
            // in others is equal to base.quote
            return $marketEncoder->getZignalySymbolFromMarketData($symbolInfo);
        } else {
            $Monolog->sendEntry(
                'debug',
                ": No base/quote found for symbol: ".$signal['pair']." in exchange: ".$signal['exchange']."/$goodExchangeName ($type)"
            );

            header('HTTP/1.1 406 Not Acceptable');
            print_r("No base/quote found for symbol: ".$signal['pair']." in exchange: ".$signal['exchange']);
            exit();
        }
    } else {
        $Monolog->sendEntry('warning', ": Not exchange found: ", $signal);
        header('HTTP/1.1 406 Not Acceptable');
        print_r("Not exchange found");
        exit();
    }
}

/**
 * Extract the exchange from the user document that matches the signal key.
 *
 * @param Monolog $Monolog
 * @param ExchangeCalls $ExchangeCalls
 * @param BSONDocument $user
 * @param array $signal
 * @return BSONDocument
 */
function extractExchangeFromKey(Monolog $Monolog, ExchangeCalls &$ExchangeCalls, BSONDocument $user, array $signal) : BSONDocument
{
    $key = $signal['key'] ?? null;
    $hash = md5($key);
    foreach ($user->exchanges as $exchange) {
        if (!empty($exchange->signalsKey)) {
            //If this field exists, the key is hashed
            if (!empty($exchange->signalsKeyEncrypted)) {
                //$hash = $key ? password_hash($key, PASSWORD_DEFAULT) : null;
                if ($hash === $exchange->signalsKey) {
                    $exchangeFromKey = $exchange;
                    break;
                }
            } else if ($exchange->signalsKey === $key) {
                $exchangeFromKey = $exchange;
                break;
            }
        }
    }

    //We know that the exchangeFromKey will always be found because the user returned is based on the exchange.
    $exchangeAccountType = empty($exchangeFromKey->exchangeType) ? 'spot' : $exchangeFromKey->exchangeType;
    if (!$ExchangeCalls->setCurrentExchange($exchangeFromKey->name, $exchangeAccountType)) {
        $Monolog->sendEntry('critical', 'Error connecting the exchange');
        http_response_code(465);
        print_r('Error connecting to the exchange');
        exit();
    }

    return $exchangeFromKey;
}

/**
 * @param newUser $newUser
 * @param Monolog $Monolog
 * @param array $signal
 * @return BSONDocument
 */
function getUserFromExchangeSignalsKey(newUser $newUser, Monolog $Monolog, array & $signal) : BSONDocument
{
    $user = $newUser->findUserFromExchangeSignalsKey($signal['key']);
    if (empty($user->exchanges)) {
        $Monolog->sendEntry('info', "The key {$signal['key']} doesn't belong to any user exchange.");
        http_response_code(401);
        exit();
    }

    if (4 === $user->status) {
        $Monolog->sendEntry('info', "The user {$user->email} is banned");
        http_response_code(401);
        exit();
    }

    $signal['userId'] = $user->_id->__toString();

    return $user;
}

/**
 * @param Monolog $Monolog
 * @param array $signal
 * @return void
 */
function setMonologExtendedKeys(Monolog $Monolog, array $signal) : void
{
    if (isset($signal['key'])) {
        $Monolog->addExtendedKeys('key', $signal['key']);
    }

    if (isset($signal['userId'])) {
        $Monolog->addExtendedKeys('userId', $signal['userId']);
    }

    if (isset($signal['positionId'])) {
        $Monolog->addExtendedKeys('positionId', $signal['positionId']);
    }

    if (isset($signal['signalId'])) {
        $Monolog->addExtendedKeys('signalId', $signal['signalId']);
    }
}