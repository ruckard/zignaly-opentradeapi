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

namespace Zignaly\exchange\ccxtwrap\exchanges;

use Zignaly\exchange\ccxtwrap\ExchangePositionCcxt;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\exchange\ExchangeFuturesTransfer;
use Zignaly\exchange\ExchangeOptions;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\exchange\ExchangePosition;
use Zignaly\exchange\exceptions;

class Binancefutures extends Binance {
    public function __construct (ExchangeOptions $options) {
        if (!array_key_exists("options", $options->ccxtOptions)){
            $options->ccxtOptions['options'] = array();
        }
        $options->ccxtOptions['options']['defaultType'] = "future";
        $options->ccxtOptions['options']['wsconf'] = array(
            "conx-tpls" =>  array(
                "default" => array (
                    "type" => "ws-s",
                    "baseurl" => "wss://fstream.binance.com/stream?streams="
                )
            )
        );
        /*
        $options->ccxtOptions['urls'] = [
            'testFapiPublic'=> 'https://testnet.binancefuture.com/fapi/v1', // ←------  fapi prefix here
            'testFapiPrivate'=> 'https://testnet.binancefuture.com/fapi/v1', // ←------  fapi prefix here
        ];
        */
        parent::__construct ($options);

        // Binance futures production endpoints override, not supported yet by CCXT.
        $this->exchange->urls['api']['fapiPublic'] = 'https://fapi.binance.com/fapi/v1';
        $this->exchange->urls['api']['fapiPrivate'] = 'https://fapi.binance.com/fapi/v1';
    }

    public function useTestEndpoint() {
        /*
        if (array_key_exists('testFapiPublic', $this->exchange->urls)) {
            $this->exchange->urls['api']['fapiPublic'] = $this->exchange->urls['testFapiPublic'];
            $this->exchange->urls['api']['fapiPrivate'] = $this->exchange->urls['testFapiPrivate'];
            */
        if (array_key_exists('test', $this->exchange->urls)) {
            foreach($this->exchange->urls['test'] as $key => $value){
                $this->exchange->urls['api'][$key] = $value;
            }
        } else {
            throw new exceptions\ExchangeTestEndpointNotAvailException ("This exchange not provide test endpoint");
        }
    }

    /**
     * get exchange if (zignaly internal code)
     *
     * @return void
     */
    public function getId(){
        return ZignalyExchangeCodes::ZignalyBinanceFutures;
    }

    /**
     * Prepare ccxt params from ExchangeExtraParams
     *
     * @param string $orderType
     * @param ExchangeExtraParams $params
     * @return array
     */
    protected function prepareCreateOrderCcxtParams(string $orderType, ExchangeExtraParams $params = null)
    {
        $ps = parent::prepareCreateOrderCcxtParams($orderType, $params);
        if (($params != null) && ($params->getReduceOnly() != null)) {
            $ps["reduceOnly"] = $params->getReduceOnly();
        }
        if ((($orderType == ExchangeOrderType::Limit)
            || ($orderType == ExchangeOrderType::Stop)
            || ($orderType == ExchangeOrderType::StopLimit))
            && ($params != null)
        ) {
            if ($params->getTimeInForce() != null) {
                $ps["timeInForce"] = $params->getTimeInForce();
            } else if (true === $params->getPostOnly()) {
                $ps["timeInForce"] = ExchangeExtraParams::TIME_IN_FORCE_GTX;
            }
        }

        if (null !== $params && !empty($params->getPositionSide())) {
            $ps["positionSide"] = $params->getPositionSide();
        }
        
        return $ps;
    }

    /**
     * translate ccxt to expected to binance order type
     *
     * @param string $ccxtType
     *
     * @return string
     */
    protected function translateOrderType2Binance(string $ccxtType)
    {

        switch ($ccxtType) {
            case ExchangeOrderType::CcxtMarket:
                return "MARKET";
            case ExchangeOrderType::CcxtLimit:
                return "LIMIT";
            case ExchangeOrderType::CcxtStop:
                return "STOP_MARKET";
            case ExchangeOrderType::CcxtStopLimit:
                return "STOP";
            case ExchangeOrderType::CcxtStopLossLimit:
                return "STOP";
            default:
                return $ccxtType;
        }
    }
    
    /**
     * translate binance order type (ccxt does not translate it) to our ccxt list
     *
     * @param string $binanceType
     *
     * @return string
     */
    protected function translateOrderTypeFromBinance(string $binanceType)
    {
        switch (strtolower($binanceType)) {
            case "limit":
                return ExchangeOrderType::CcxtLimit;
            case "market":
                return ExchangeOrderType::CcxtMarket;
            case "stop_market":
                return ExchangeOrderType::CcxtStop;
            case "stop":
                return ExchangeOrderType::CcxtStopLimit;
            //case "TAKE_PROFIT":
            //case "TAKE_PROFIT_LIMIT":
            //case "LIMIT_MAKER":
            default:
                return ($binanceType == null) ? $binanceType : strtolower($binanceType);
        }
    }

    /**
     * Get position for associated account
     *
     * @return ExchangePosition[]
     */ 
    public function getPosition()
    {
    
        try {
            $ret = array();
            $positions = $this->exchange->fapiPrivateGetPositionRisk(array());
            foreach ($positions as $position) {
                // parse position
                $symbol = null;
                $marketId = $this->exchange->safe_string($position, 'symbol');
                if (is_array($this->exchange->markets_by_id) && array_key_exists($marketId, $this->exchange->markets_by_id)) {
                    $market = $this->exchange->markets_by_id[$marketId];
                }
                if ($market !== null) {
                    $symbol = $market['symbol'];
                }
                $pos = array(
                    'symbol' => $symbol,
                    'amount' => $position['positionAmt'],
                    'entryprice' => $position['entryPrice'],
                    'markprice' => $position['markPrice'],
                    'liquidationprice' => $position['liquidationPrice'],
                    'leverage' => $position['leverage'],
                    'margin' => $position['marginType'],
                    'isolated' => 'cross' !== $position['marginType'],
                    'side' => strtolower($position['positionSide']),
                    'info' => $position
                );

                $ret[] = new ExchangePositionCcxt($pos);
            }
            return $ret;
        } catch (\ccxt\BaseError $ex) {
            throw $this->parseCcxtException($ex, $ex->getMessage());
        }
    }

    /**
     * @param int $from
     * @param string|null $asset
     * @return ExchangeFuturesTransfer[]
     */
    public function getFuturesTransfers(int $from, ?string $asset = null): array
    {
        $result = [];
        $response = $this->exchange->getFuturesTransfers($from, $asset ?? 'USDT');
        $total = $response['total'] ?? 0;

        if ($total > 0) {
            $rows = $response['rows'] ?? [];
            foreach ($rows as $row) {
                if ('CONFIRMED' === $row['status']) {
                    $transfer = new ExchangeFuturesTransfer();
                    $transfer->setAmount($row['amount'] ?? 0.0);
                    $transfer->setTimestamp($row['timestamp'] ?? 0);
                    $transfer->setTransferId($row['tranId'] ?? '');

                    $type = $row['type'] ?? 0;
                    $transfer->setType(1 === (int)$type | 3 === (int)$type? ExchangeFuturesTransfer::TYPE_DEPOSIT
                        : ExchangeFuturesTransfer::TYPE_WITHDRAWAL);

                    $result[] = $transfer;
                }
            }
        }

        return $result;
    }

        /**
     * Get zignaly broker id for futures
     *
     * @return string
     */
    protected function getZignalyBrokerId(): string
    {
        return 'x-dMwd6wKj'; // old id 'x-ZBx2yvyk';
    }
}
