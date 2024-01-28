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


class Signal
{
    private $mongoDBLink;
    private $Monolog;

    public function __construct()
    {
        global $mongoDBLink;

        $this->mongoDBLink = $mongoDBLink;
    }

    public function configureLogging(Monolog $Monolog)
    {
        $this->Monolog = $Monolog;
    }

    /**
     * Get the given number and return it's factor.
     *
     * @param bool|float}int$value
     * @return bool|float|int
     */
    public function composeFactor($value)
    {
        if (!$value || !is_numeric($value)) {
            return false;
        }
        $factor = 1 + $value / 100;

        return $factor;
    }


    /**
     * Get a signal and the type of targets and prepare the parameters for the next process factorizing them and
     * creating the targets array.
     *
     * @param array $signal
     * @param string $targetsType
     * @return bool|array
     */
    public function composeTargetsFromUpdateSignal(array $signal, string $targetsType)
    {
        $targets = false;

        if ('takeProfitTargets' === $targetsType) {
            $rawOptions = ['takeProfitPercentage', 'takeProfitPrice', 'takeProfitAmountPercentage', 'takeProfitPostOnly'];
            $pricePriority = !empty($signal['takeProfitPriority']) && 'price' === $signal['takeProfitPriority'] ? 'price' : 'percentage';
        } else {
            $rawOptions = ['DCATargetPercentage', 'DCATargetPrice', 'DCAAmountPercentage', 'DCAPostOnly'];
            $pricePriority = !empty($signal['DCAPriority']) && 'price' === $signal['DCAPriority'] ? 'price' : 'percentage';
        }

        foreach ($signal as $key => $value) {
            $parseOption =  preg_replace("/[0-9]+/", "", $key);
            if (in_array($parseOption, $rawOptions)) {
                $targetId = (int)preg_replace("/[^0-9]/", "", $key);

                if ($targetId == '' || isset($targets[$targetId])) {
                    continue;
                }
                $amountPercentage = isset($signal[$rawOptions[2].$targetId]) && $signal[$rawOptions[2].$targetId] > 0
                    ? $signal[$rawOptions[2].$targetId] / 100 : false;

                if (!$amountPercentage) {
                    continue;
                }
                $priceTargetPercentage = isset($signal[$rawOptions[0] . $targetId])
                    ? $this->composeFactor($signal[$rawOptions[0] . $targetId]) : false;

                $priceTarget = isset($signal[$rawOptions[1].$targetId]) ? $signal[$rawOptions[1].$targetId] : false;

                if (!$priceTargetPercentage && !$priceTarget) {
                    continue;
                }
                $postOnly = isset($signal[$rawOptions[3].$targetId])
                    ? (true === $signal[$rawOptions[3].$targetId])
                    || 'true' === $signal[$rawOptions[3].$targetId]
                    : false;

                $targets[$targetId] = [
                    'targetId' => $targetId,
                    'priceTargetPercentage' => $priceTargetPercentage,
                    'priceTarget' => $priceTarget,
                    'amountPercentage' => $amountPercentage,
                    'postOnly' => $postOnly,
                    'pricePriority' => $pricePriority,
                ];
            }
        }

        return $targets;
    }

    /**
     * Count the total number of signals for a given provider key.
     *
     * @param $providerKey
     * @return int
     */
    public function countSignalsFromProvider($providerKey)
    {
        $find = [
            'key' => $providerKey,
        ];

        return $this->mongoDBLink->selectCollection('signal')->count($find);
    }

    /**
     * Return the signal document if any.
     * @param \MongoDB\BSON\ObjectId $id
     * @return array|object|null
     */
    public function getSignalFromId(\MongoDB\BSON\ObjectId $id)
    {
        $find = [
            '_id' => $id,
        ];

        return $this->mongoDBLink->selectCollection('signal')->findOne($find);
    }

    /**
     * Compose an entry signal from a reverse signal and send it.
     * @param RabbitMQ $RabbitMQ
     * @param string $reverseSignalId
     */
    public function composeEntrySignalFromReverse(RabbitMQ $RabbitMQ, string $reverseSignalId)
    {
        $signal = $this->getSignalFromId(new \MongoDB\BSON\ObjectId($reverseSignalId));
        unset($signal->_id);
        $signal->datetime = $signal->datetime->__toString();
        if (isset($signal->price)) {
            $signal->price = is_object($signal->price) ? $signal->price->__toString() : $signal->price;
        }
        if (isset($signal->volume)) {
            $signal->volume = is_object($signal->volume) ? $signal->volume->__toString() : $signal->volume;
        }
        $signal->reverseId = $reverseSignalId;
        $messageForJson = iterator_to_array($signal);
        $messageForJson['type'] = 'entry';
        $parseParameters = [];
        foreach ($messageForJson as $parameter => $value) {
            if (false !== strpos($parameter, 'exit')) {
                continue;
            } elseif ('entrypoint' !== strtolower($parameter) && false !== strpos($parameter, 'entry')) {
                $option = lcfirst(str_replace('entry', '', $parameter));
                $parseParameters[$option] = $value;
            } else {
                $parseParameters[$parameter] = $value;
            }
        }
        $message = json_encode($parseParameters, JSON_PRESERVE_ZERO_FRACTION);
        $RabbitMQ->publishMsg('signals', $message);
    }

    /**
     * @param array $signal
     * @param string $side
     * @return array
     */
    public function composeEntrySignalFromReverseV2(array $signal, string $side) : array
    {
        $signal['reverseId'] = $signal['_id'];
        $signal['type'] = 'entry';
        unset($signal['_id']);
        $parseParameters = [];
        foreach ($signal as $parameter => $value) {
            if (str_contains($parameter, 'exit')) {
                continue;
            } elseif (str_contains($parameter, 'entry')) {
                $option = lcfirst(str_replace('entry', '', $parameter));
                $parseParameters[$option] = $value;
            } else {
                $parseParameters[$parameter] = $value;
            }
        }

        if (empty($parseParameters['side'])) {
            $parseParameters['side'] = 'LONG' === $side ? 'SHORT' : 'LONG';
        }

        return $parseParameters;
    }

    /**
     * @param array $signal
     * @return void
     */
    public function convertToFactor(array &$signal) : void
    {
        if (isset($signal['takeProfitPercentage1']) || isset($signal['takeProfitPrice1'])) {
            $signal['takeProfitTargets'] = $this->composeTakeProfitsFromSignal($signal);
        }

        if ((isset($signal['DCAAmountPercentage1']) || isset($signal['DCAPositionSize1']))
            && (isset($signal['DCATargetPrice1']) || isset($signal['DCATargetPercentage1']))) {
            $signal['reBuyTargets'] = $this->composeDCATargetsFromSignal($signal);
        }

        if (isset($signal['trailingStopTriggerPercentage'])) {
            $signal['trailingStopTriggerPercentage'] = $this->composeMultiplicationMultiplier(
                $signal['trailingStopTriggerPercentage']
            );
        }

        if (isset($signal['trailingStopDistancePercentage'])) {
            $signal['trailingStopDistancePercentage'] = $this->composeDivisionMultiplier(
                $signal['trailingStopDistancePercentage']
            );
        }

        if (isset($signal['stopLossPercentage'])) {
            $signal['stopLossPercentage'] = $this->composeDivisionMultiplier($signal['stopLossPercentage']);
        }

        if (isset($signal['reduceAvailablePercentage'])) {
            $signal['reduceAvailablePercentage'] = $signal['reduceAvailablePercentage'] / 100;
        }

        if (isset($signal['reduceTargetPercentage'])) {
            $signal['reduceTargetPercentage'] = 1 + $signal['reduceTargetPercentage'] / 100;
        }
    }

    /**
     * @param $signal
     * @return array
     */
    private function composeDCATargetsFromSignal($signal): array
    {
        $targetId = 1;
        $reBuyTargets = [];

        while ((isset($signal['DCAAmountPercentage'.$targetId]) || isset($signal['DCAPositionSize'.$targetId]))
            && (isset($signal['DCATargetPrice'.$targetId]) || isset($signal['DCATargetPercentage'.$targetId]))) {
            $priceTargetPercentage = isset($signal['DCATargetPercentage'.$targetId])
                ? $this->composeDivisionMultiplier($signal['DCATargetPercentage'.$targetId]) : false;

            if (!empty($signal['DCATargetPrice'.$targetId]) && (!$priceTargetPercentage || (!empty($signal['DCAPriority']) && 'price' === $signal['DCAPriority']))) {
                $priceTarget = $signal['DCATargetPrice' . $targetId];
                $priceTargetPercentage = false;
            } else {
                $priceTarget = false;
            }

            $amountPercentageMultiplier = isset($signal['DCAAmountPercentage'.$targetId]) ? $signal['DCAAmountPercentage'.$targetId] / 100 : null;

            $postOnly = isset($signal['DCAPostOnly'.$targetId])
                ? (true === $signal['DCAPostOnly'.$targetId])
                || ('true' ===  $signal['DCAPostOnly'.$targetId])
                : false;

            $newInvestment = isset($signal['DCAPositionSize'.$targetId]) ? $signal['DCAPositionSize'.$targetId] : null;
            $target = [
                'targetId' => $targetId,
                'priceTargetPercentage' => $priceTargetPercentage,
                'priceTarget' => $priceTarget,
                'pricePriority' => empty($signal['DCAPriority']) ? 'percentage' : strtolower($signal['DCAPriority']),
                'amountPercentage' => $amountPercentageMultiplier,
                'newInvestment' => $newInvestment,
                'postOnly' => $postOnly,
            ];
            $reBuyTargets[] = $target;
            $targetId++;
        }

        return $reBuyTargets;
    }

    /**
     * @param $value
     * @return float|null
     */
    private function composeDivisionMultiplier($value) : ?float
    {
        if (!$value || $value == 0) {
            return null;
        }

        return (float)(1 - abs($value) / 100);
    }

    /**
     * @param array $signal
     * @return array
     */
    private function composeTakeProfitsFromSignal(array $signal): array
    {
        $targets = $this->countTargets($signal, 'takeProfitPercentage', 'takeProfitPrice');
        $amountFirst = $this->getAmountForTargets($targets, true);
        $amountNext = $this->getAmountForTargets($targets, false);
        $targetId = 1;
        $takeProfits = [];
        while (isset($signal['takeProfitPercentage'.$targetId]) || isset($signal['takeProfitPrice'.$targetId])) {
            $priceTargetPercentage = isset($signal['takeProfitPercentage'.$targetId])
                ? $this->composeMultiplicationMultiplier($signal['takeProfitPercentage'.$targetId])
                : false;

            if (!empty($signal['takeProfitPrice'.$targetId]) &&
                (!$priceTargetPercentage || (!empty($signal['takeProfitPriority']) && 'price' === $signal['takeProfitPriority']))
            ) {
                $priceTarget = $signal['takeProfitPrice' . $targetId];
                $priceTargetPercentage = false;
            } else {
                $priceTarget = false;
            }

            $currentAmount = $targetId == 1 ? $amountFirst : $amountNext;
            $amountPercentage = isset($signal['takeProfitAmountPercentage'.$targetId])
            && $signal['takeProfitAmountPercentage'.$targetId] > 0 ? $signal['takeProfitAmountPercentage'.$targetId]
                : $currentAmount;

            $amountPercentageMultiplier = $amountPercentage / 100;

            $postOnly = isset($signal['takeProfitPostOnly'.$targetId])
                ? (true === $signal['takeProfitPostOnly'.$targetId])
                || ('true' === $signal['takeProfitPostOnly'.$targetId])
                : false;

            $target = [
                'targetId' => $targetId,
                'priceTargetPercentage' => $priceTargetPercentage,
                'priceTarget' => $priceTarget,
                'pricePriority' => empty($signal['takeProfitPriority']) ? 'percentage' : strtolower($signal['takeProfitPriority']),
                'amountPercentage' => $amountPercentageMultiplier,
                'postOnly' => $postOnly,
            ];
            $takeProfits[] = $target;
            $targetId++;
        }

        return $takeProfits;
    }

    /**
     * @param $value
     * @return float|null
     */
    private function composeMultiplicationMultiplier($value) : ?float
    {
        if (!$value || !is_numeric($value)) {
            return null;
        }

        return (float)(1 + (abs($value) / 100));
    }

    /**
     * @param int $targets
     * @param bool $isFirst
     * @return float
     */
    private function getAmountForTargets(int $targets, bool $isFirst) : float
    {
        if (100 % $targets == 0) {
            return (float)(100 / $targets);
        }

        $amount = floor(100 / $targets);

        return $isFirst ? $amount + 1 : $amount;
    }

    /**
     * @param array $signal
     * @param string $target1
     * @param string $target2
     * @return int
     */
    private function countTargets(array $signal, string $target1, string $target2): int
    {
        $targets = 0;
        $targetId = 1;
        while (isset($signal[$target1.$targetId]) || isset($signal[$target2.$targetId])) {
            $targets++;
            $targetId++;
        }

        return $targets;
    }

    /**
     * Check if a position belong to the user who sent it.
     * @param \MongoDB\Model\BSONDocument $position
     * @return bool
     */
    public function checkIfUserIsServiceOwner(\MongoDB\Model\BSONDocument $position)
    {
        if ("1" === $position->provider->_id) {
            if (empty($position->signal->userId)) {
                return false;
            }

            return $position->user->_id->__toString() === $position->signal->userId;
        }

        if (empty($position->provider->userId)) {
            return false;
        }

        return $position->provider->userId === $position->user->_id->__toString();
    }

    public function getLastSignalsFromProviderKey($key, $limit = false, $sort = -1)
    {
        $find = [
            'key' => $key,
            'statsDone' => false,
        ];

        $options = [
            'sort' => [
                '_id' => $sort,
            ],
        ];

        if ($limit)
            $options['limit'] = $limit;

        return $this->mongoDBLink->selectCollection('signal')->find($find, $options);
    }

    /**
     * Return list of signals from a provider key.
     *
     * @param string $key
     * @param bool $fromDate
     * @param bool $signalId
     * @param bool|string $getAllSignals
     * @return \MongoDB\Driver\Cursor
     */
    public function getSignalsFromProviderKey(string $key, $fromDate = false, $signalId = false, $getAllSignals = false)
    {
        $find = [
            'key' => $key,
            '$or' => [
                ['type' => 'buy'],
                ['type' => 'entry'],
            ]
        ];

        if ($signalId)
            $find['_id'] = is_object($signalId) ? $signalId : new \MongoDB\BSON\ObjectId($signalId);

        if ($fromDate)
            $find['datetime'] = [
                '$gte' => is_object($fromDate) ? $fromDate : new \MongoDB\BSON\UTCDateTime($fromDate),
            ];

        if (!$getAllSignals)
            $find['statsDone'] = false;

        $options = [
            //'noCursorTimeout' => true,
        ];

        return $this->mongoDBLink->selectCollection('signal')->find($find, $options);
    }

    /**
     * @param $signal
     * @param bool $statsDone
     * @return string
     */
    public function storeSignal($signal, $statsDone = false)
    {
        $signal = $this->composeMetaData($signal);
        $signal['statsDone'] = $statsDone;

        $providerKey = isset($signal['key']) ? $signal['key'] : false;

        $mailId = isset($signal['mailId']) ? $signal['mailId'] : false;
        if ($mailId && $this->checkIfValueIdAlreadyExists($mailId, $providerKey, 'mailId')) {
            return false;
        }

        if (isset($signal['price']) && is_numeric($signal['price'])) {
            $signal['price'] = (float)preg_replace("/[^0-9.]/", "", $signal['price']);
        }
        if (isset($signal['volume']) && is_numeric($signal['volume'])) {
            $signal['volume'] = (float)preg_replace("/[^0-9.]/", "", $signal['volume']);
        }
        if (!isset($signal['datetime'])) {
            $signal['datetime'] = time() * 1000;
        }

        $signal['datetime'] = new \MongoDB\BSON\UTCDateTime($signal['datetime']);

        return $this->mongoDBLink->selectCollection('signal')->insertOne($this->replaceKeys($signal))->getInsertedId()->__toString();
    }

    public function composeMetaData($signal)
    {
        foreach ($signal as $key => $value) {
            if (substr($key, 0, 2) == 'MD') {
                unset($signal[$key]);
                $newKey = substr($key, 2);
                $signal['metadata'][$newKey] = $value;
            }
        }

        return $signal;
    }

    public function checkIfValueIdAlreadyExists($valueId, $providerKey, $field)
    {
        if (!$valueId || empty($valueId))
            return false;

        $find = [
            'key' => $providerKey,
            $field => $valueId,
        ];

        $signalFound = $this->mongoDBLink->selectCollection('signal')->findOne($find);

        return isset($signalFound->exchange) ? true : false;
    }

    /**
     * Parsing parameters from signal to a valid format.
     *
     * @param array $options
     * @return bool|array
     */
    public function composeSignalFromRequest(array $options)
    {
        // Support alternative key name for automated tests.
        if (isset($options['providerKey'])) {
            $options['key'] = $options['providerKey'];
        }

        foreach ($options as $key => $value) {
            $tmpOption = $this->checkOption($key);
            if (!$tmpOption)
                continue;

            $signal[$tmpOption] = $this->validateValue($value, $tmpOption);
        }

        return isset($signal) ? $signal : false;
    }

    /**
     * Parsing parameters from signal to a valid format.
     *
     * @param array $options
     * @return array
     */
    public function composeCompleteSignalFromRequest(array $options)
    {
        foreach ($options as $key => $value) {
            $tmpOption = $this->checkOption($key);
            if (!$tmpOption)
                continue;

            $signal[$tmpOption] = $this->validateValue($value, $tmpOption);
        }

        $signal['datetime'] = microtime(true) * 1000;

        if (empty($signal['entryPoint'])) {
            //ToDo: Look for the way to detect if it's from TradingView. Could be:    "userAgent" : "Go-http-client/1.1",
            $signal['entryPoint'] = 'endpoint';
        }

        if (!isset($signal['type'])) {
            $signal['type'] = 'entry';
        }

        if ($signal['type'] == 'update') {
            $signal['takeProfitTargets'] = $this->composeTargetsFromUpdateSignal($signal, 'takeProfitTargets');
            if (!$signal['takeProfitTargets'])
                unset($signal['takeProfitTargets']);
            $signal['reBuyTargets'] =  $this->composeTargetsFromUpdateSignal($signal, 'reBuysTargets');
            if (!$signal['reBuyTargets'])
                unset($signal['reBuyTargets']);
        }

        $signal['userAgent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Not defined';
        $signal['version'] = 2;

        return $signal;
    }

    public function composeSignalFromMail($message)
    {
        if (strpos($message, '||') !== false) {
            $options = explode("||", $message);
        } else {
            $options = explode("\n", $message);
        }
        foreach ($options as $option) {
            $elements = explode('=', $option);
            if (!isset($elements[1])) {
                continue;
            }
            $tmpOption = $this->checkOption($elements[0]);
            if (!$tmpOption)
                continue;

            $signal[$tmpOption] = $this->validateValue($elements[1], $tmpOption);
        }

        return isset($signal) ? $signal : false;
    }

    public function updateSignal($signalId, $set)
    {
        $find = [
            '_id' => $signalId,
        ];

        $update = [
            '$set' => $set,
        ];

        return $this->mongoDBLink->selectCollection('signal')->updateOne($find, $update);
    }

    /**
     * Checking if the exchange is in the list of supported exchanges.
     * Exchanges are hardcoded here, to avoid calls
     * to the DB so the signal is processed as fast as possible.
     *
     * @param string $exchange exchange name
     * 
     * @return bool
     */
    public function checkIfExchangeIsSupported(string $exchange)
    {
        $exch = new \Exchange();
        return $exch->checkIfExchangeIsSupported($exchange);
        /*
        $find = [
            'name' => new \MongoDB\BSON\Regex($exchange, 'i'),
        ];
        $exchange = $this->mongoDBLink->selectCollection('exchange')
            ->findOne($find);
        return isset($exchange->name) && isset($exchange->enabled)
            && $exchange->enabled;
        */
    }

    public function checkRequiredFields($signal)
    {
        if (!isset($signal['key'])) {
            $this->Monolog->sendEntry('warning', ": No key provided: ", $signal);
            header('HTTP/1.1 401 Unauthorized');
            return false;
        }

        if (!isset($signal['exchange'])) {
            $this->Monolog->sendEntry('warning', ": No exchange provided: ", $signal);
            header('HTTP/1.1 400 Bad Request');
            return false;
        }

        if (!isset($signal['type'])) {
            $this->Monolog->sendEntry('warning', "No type provided", $signal);
            header('HTTP/1.1 400 Bad Request');
            return false;
        }

        return true;
    }

    /**
     * Check if side is LONG or SHORT.
     *
     * @param array $signal
     * @return bool
     */
    private function checkSide(array $signal)
    {
        $sides = ['long', 'short'];

        if (!isset($signal['side'])) {
            return true;
        }

        $side = strtolower($signal['side']);

        return (in_array($side, $sides));
    }

    private function checkSignalType(array $signal)
    {
        $types = [
            'buy',
            'entry',
            'sell',
            'exit',
            'rebuy',
            'dca',
            'reentry',
            'stop',
            'start',
            'disablemarket',
            'enablemarket',
            'panicsell',
            'update',
            'cancelentry',
            'reverse'
        ];

        $type = strtolower($signal['type']);

        return (in_array($type, $types));
    }

    /**
     * Check if signal is good for processing.
     *
     * @param array $signal
     * @return bool
     */
    public function generalChecks(array $signal)
    {
        if (!$this->checkRequiredFields($signal)) {
            return false;
        }

        if (!$this->checkIfExchangeIsSupported($signal['exchange'])) {
            $this->Monolog->sendEntry('warning', "Exchange not supported: ", $signal);
            header('HTTP/1.1 406 Not Acceptable');
            return false;
        }

        if (!$this->checkSide($signal)) {
            $this->Monolog->sendEntry('warning', "Wrong side", $signal);
            header('HTTP/1.1 406 Not Acceptable');
            return false;
        }

        if (!$this->checkSignalType($signal)) {
            $this->Monolog->sendEntry('warning', "Wrong type", $signal);
            header('HTTP/1.1 406 Not Acceptable');
            return false;
        }

        return true;
    }

    private function validateValue($value, $key)
    {
        if (substr($key, 0, 2) == 'MD')
            return $value;

        if ($key == 'removeReduceOrder') {
            $targets = [];
            foreach ($value as $item) {
                $targets[] = preg_replace("/[^0-9]/", '', $item);
            }

            return $targets;
        }

        $value = preg_replace("/[^A-Za-z0-9\.\-\_]/", '', $value);
        $value = trim($value);
        $value = $key == 'key' ? $value : strtolower($value);
        if ($key == 'exchange')
            $value = ucfirst($value);

        if ($key == 'positionSizeQuote')
            $value = strtoupper($value);

        if ($key == 'pair') {
            $value = strtoupper($value);
            $value = str_replace('.P', '', $value);
            $value = preg_replace("/[^A-Za-z0-9 ]/", '', $value);
            $value = str_replace('PERP', '', $value);
        }

        return empty($value) ? false : $value;
    }

    /**
     * Converting option, if exists to the proper format.
     *
     * @param string $option
     * @return bool|string
     */
    private function checkOption(string $option)
    {
        if (substr($option, 0, 2) == 'MD') {
            return str_replace('.', '-', $option);
        }

        $options = [
            'amount' => 'amount',
            'buytype' => 'orderType',
            'buystopprice' => 'buyStopPrice',
            'key' => 'key',
            'bot_licencekey' => 'key',
            'pair' => 'pair',
            'market' => 'pair',
            'exchange' => 'exchange',
            'type' => 'type',
            'signalmode' => 'type',
            'signalid' => 'signalId',
            'signal_id' => 'signalId',
            'price' => 'price',
            'limitprice' => 'limitPrice',
            'leverage' => 'leverage',
            'positionsize' => 'positionSize',
            'positionsizepercentage' => 'positionSizePercentage',
            'positionsizequote' => 'positionSizeQuote',
            'positionsizepercentagefromquoteavailable' => 'positionSizePercentageFromQuoteAvailable',
            'positionsizepercentagefromquotetotal' => 'positionSizePercentageFromQuoteTotal',
            'positionsizepercentagefromaccountavailable' => 'positionSizePercentageFromAccountAvailable',
            'positionsizepercentagefromaccounttotal' => 'positionSizePercentageFromAccountTotal',
            'skipexitingaftertp' => 'skipExitingAfterTP',
            'ordertype' => 'orderType',
            'stoplosspercentage' => 'stopLossPercentage',
            'stoploss' => 'stopLossPrice',
            'stoplossprice' => 'stopLossPrice',
            'stoplossfollowstakeprofit' => 'stopLossFollowsTakeProfit',
            'stoplosstobreakeven' => 'stopLossToBreakEven',
            'stoplosspriority' => 'stopLossPriority',
            'stoplossforce' => 'stopLossForce',
            'successrate' => 'successRate',
            'trailingstoptriggerpercentage' => 'trailingStopTriggerPercentage',
            'trailingstoptriggerprice' => 'trailingStopTriggerPrice',
            'trailingstoptriggerpriority' => 'trailingStopTriggerPriority',
            'trailingstopdistancepercentage' => 'trailingStopDistancePercentage',
            'takeprofitpriority' => 'takeProfitPriority',
            'dcapriority' => 'DCAPriority',
            'dcaplaceall' => 'DCAPlaceAll',
            'dcafrombeginning' => 'DCAFromBeginning',
            'buyttl' => 'buyTTL',
            'sellttl' => 'sellTTL',
            'sellbyttl' => 'sellTTL',
            'term' => 'term',
            'volume' => 'volume',
            'basevol' => 'volume',
            'risk' => 'risk',
            'panicbase' => 'panicBase',
            'panicquote' => 'panicQuote',
            'exchangeaccounttype' => 'exchangeAccountType',
            'side' => 'side',
            'limittype' => 'limitType',
            'limittypetif' => 'limitTypeTIF',
            'reduceonly' => 'reduceOnly',
            'marginmode' => 'marginMode',
            'realinvestment' => 'realInvestment',
            'reduceordertype' => 'reduceOrderType',
            'reducetargetpercentage' => 'reduceTargetPercentage',
            'reducetargetprice' => 'reduceTargetPrice',
            'reducetargetpriority' => 'reduceTargetPriority',
            'reducetargetamount' => 'reduceTargetAmount',
            'reduceavailablepercentage' => 'reduceAvailablePercentage',
            'reducerecurring' => 'reduceRecurring',
            'reducepersistent' => 'reducePersistent',
            'removeallreduceorders' => 'removeAllReduceOrders',
            'removereduceorder' => 'removeReduceOrder',
            'removereducerecurringpersistent' => 'removeReduceRecurringPersistent',
            'removedcas' => 'removeDCAs',
            'removetakeprofits' => 'removeTakeProfits',
            'postonly' => 'postOnly',
            'reducepostonly' => 'reducePostOnly',
            'entryPoint' => 'entrypoint',
            'hedgemode' => 'hedgeMode',
            'ignoreopencontractcheck' => 'ignoreOpenContractCheck',
            'increasepsamount' => 'increasePSAmount',
            'increasepsprice' => 'increasePSPrice',
            'increasepspricepercentagefromoriginalentry' => 'increasePSPricePercentageFromOriginalEntry',
            'increasepspricepercentagefromaverageentry' => 'increasePSPricePercentageFromAverageEntry',
            'increasepscost' => 'increasePSCost',
            'increasepscostpercentagefromremaining' => 'increasePSCostPercentageFromRemaining',
            'increasepscostpercentagefromtotal' => 'increasePSCostPercentageFromTotal',
            'increasepspercentagefromtotalaccountbalance' => 'increasePSPercentageFromTotalAccountBalance',
            'multiplybyleverage' => 'multiplyByLeverage',
            'increasepsstopprice' => 'increasePSStopPrice',
            'increasepsordertype' => 'increasePSOrderType',
            'cancelbuyat' => 'cancelBuyAt',
            'exitbyttlat' => 'exitByTTLAt',
            'forceexitbyttl' => 'forceExitByTTL',
        ];

        $option = trim(strtolower($option));
        if ('skipexitingaftertp' !== $option && 'exitbyttlat' !== $option && 'forceexitbyttl' !== $option && str_contains($option, 'exit')) {
            $option = str_replace('exit', '', $option);
            $prefix = 'exit';
        } elseif ('increasepspricepercentagefromoriginalentry' !== $option
            && 'increasepspricepercentagefromaverageentry' !== $option
            && 'entrypoint' !== $option && str_contains($option, 'entry')) {
            $option = str_replace('entry', '', $option);
            $prefix = 'entry';
        } else {
            $prefix = '';
        }

        if (isset($options[$option])) {
            if (empty($prefix)) {
                return $options[$option];
            } else {
                return $prefix . ucfirst($options[$option]);
            }
        }

        if (false !== strpos($option, '_long')) {
            $option = str_replace('_long', '', $option);
            $suffix = '_long';
        } elseif (false !== strpos($option, '_short')) {
            $option = str_replace('_short', '', $option);
            $suffix = '_short';
        } else {
            $suffix = '';
        }

        if (isset($options[$option])) {
            if (empty($suffix)) {
                return $options[$option];
            } else {
                return $options[$option] . $suffix;
            }
        }

        $prefix = ''; //We unset prefix because next options are sub-parameters only available for entry, so no need of entry prefix.
        $unlimitOptions = [
            'takeprofitpercentage' => 'takeProfitPercentage',
            'takeprofitprice' => 'takeProfitPrice',
            'target' => 'takeProfitPrice',
            'takeprofitamountpercentage' => 'takeProfitAmountPercentage',
            'takeprofitpostonly' => 'takeProfitPostOnly',
            'dcapercentage' => 'DCATargetPercentage',
            'dcapostonly' => 'DCAPostOnly',
            'dcatargetpercentage' => 'DCATargetPercentage',
            'dcaprice' => 'DCATargetPrice',
            'dcatargetprice' => 'DCATargetPrice',
            'dcaamountpercentage' => 'DCAAmountPercentage',
            'dcapositionsize' => 'DCAPositionSize',
        ];

        $targetId = (int)preg_replace("/[^0-9]/", "", $option);
        if (is_numeric($targetId)) {
            $option = preg_replace("/[0-9]+/", "", $option);
            if (isset($unlimitOptions[$option])) {
                if (empty($prefix)) {
                    return $unlimitOptions[$option] . $targetId;
                } else {
                    return $prefix . ucfirst($unlimitOptions[$option]) . $targetId;
                }
            }
        }

        return false;
    }

    private function replaceKeys($input)
    {
        $return = [];
        foreach ($input as $key => $value) {
            $key = str_replace('.', '-', $key);

            if (strpos($key, '$') === 0)
                $key = str_replace('$', '-$', $key);

            if (is_array($value))
                $value = $this->replaceKeys($value);

            $return[$key] = $value;
        }

        return $return;
    }
}