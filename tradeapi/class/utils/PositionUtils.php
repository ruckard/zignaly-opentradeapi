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

namespace Zignaly\utils;

use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangeExtraParams;
use Zignaly\Mediator\PositionMediator;

/**
 * Class PositionUtils
 * @package Zignaly\utils
 */
class PositionUtils
{
    /**
     * Generate more common options
     *
     * @param PositionMediator $positionMediator position mediator
     * @param $target
     * @return array
     */
    public static function extractOptionsForOrder(PositionMediator $positionMediator, $target = null): array
    {
        $position = $positionMediator->getPositionEntity();
        $options = self::extractOptionsDefaults($position, $target);

        if (!$positionMediator->checkIfHedgeMode() && $positionMediator->getExchangeType() === BaseExchange::TYPE_FUTURE) {
            $options['reduceOnly'] = true;
        }

        return $options;
    }

    /**
     * Generate options for rebuys order
     *
     * @param PositionMediator $positionMediator position mediator
     * @param $buyStopPrice
     * @param $target
     * @return array
     */
    public static function extractOptionsForRebuysOrder(
        PositionMediator $positionMediator,
        $buyStopPrice,
        $target
    ): array {
        $position = $positionMediator->getPositionEntity();
        $options = self::extractOptionsDefaults($position, $target);

        if ($buyStopPrice) {
            $options['buyStopPrice'] = $buyStopPrice;
        }

        return $options;
    }

    /**
     * Extract options from position to create order options
     *
     * @param $position
     * @param string $firstOrSecond first or second
     *
     * @return array
     */
    public static function extractOptionsToCreateOrder($position, string $firstOrSecond): array
    {
        $options = self::extractOptionsDefaults($position);

        $positionMediator = PositionMediator::fromMongoPosition($position);

        $multiOrder = 'first' === $firstOrSecond ? 'multiFirstData' : 'multiSecondData';
        if (!empty($position->$multiOrder->buyStopPrice)) {
            $options['buyStopPrice'] = is_object($position->$multiOrder->buyStopPrice)
                ? $position->$multiOrder->buyStopPrice->__toString() : $position->$multiOrder->buyStopPrice;
        }
        if (!empty($position->$multiOrder->limitType)) {
            $options['limitType'] = $position->$multiOrder->limitType;
        }
        if (!empty($position->$multiOrder->limitTypeTIF)) {
            $options['limitTypeTIF'] = $position->$multiOrder->limitTypeTIF;
        }
        if (!empty($position->$multiOrder->reduceOnly)) {
            $options['reduceOnly'] = $position->$multiOrder->reduceOnly;
        }
        // if ((empty($position->exchange->exchangeType) || 'spot' === $position->exchange->exchangeType)
        if (($positionMediator->getExchangeType() === 'spot')
            && 'MARKET' === $position->$multiOrder->orderType) {
            $options['quoteOrderQty'] = $position->$multiOrder->positionSize;
        }

        if (isset($position->signal->postOnly) && $position->signal->postOnly) {
            $options['postOnly'] = true;
        }

        $options['marginMode'] = $position->marginMode ?? null;

        return $options;
    }

    /**
     * Convert internal order options array to ccxt wrappers methods
     *
     * @param array $options options
     *
     * @return ExchangeExtraParams
     */
    public static function generateExchangeExtraParamsFromOptions(array $options): ExchangeExtraParams
    {
        $params = new ExchangeExtraParams();
        if (isset($options['buyStopPrice']) && $options['buyStopPrice']) {
            $params->setStopPrice($options['buyStopPrice']);
        }

        if (isset($options['stopLossPrice']) && $options['stopLossPrice']) {
            $params->setStopLossPrice($options['stopLossPrice']);
        }

        // add reduceOnly parameter (only valid for binance futures)
        if (isset($options['reduceOnly']) && $options['reduceOnly']) {
            $params->setReduceOnly(true);
        }
        if (isset($options['limitType'])) {
            if ('postOnly' === $options['limitType']) {
                $params->setTimeInForce(ExchangeExtraParams::TIME_IN_FORCE_GTX);
            } elseif ('TIF' === $options['limitType']) {
                $params->setTimeInForce(ExchangeExtraParams::TIME_IN_FORCE_GTC);
                if (isset($options['limitTypeTIF'])) {
                    switch ($options['limitTypeTIF']) {
                        case 'GTC':
                            $params->setTimeInForce(ExchangeExtraParams::TIME_IN_FORCE_GTC);
                            break;
                        case 'IOC':
                            $params->setTimeInForce(ExchangeExtraParams::TIME_IN_FORCE_IOC);
                            break;
                        case 'FOK':
                            $params->setTimeInForce(ExchangeExtraParams::TIME_IN_FORCE_FOK);
                            break;
                        default:
                            break;
                    }
                }
            }
        }
        
        if (isset($options['quoteOrderQty'])) {
            $params->setQuoteOrderQty($options['quoteOrderQty']);
        }

        if (isset($options['zignalyPositionId'])) {
            $params->setZignalyPositionId($options['zignalyPositionId']);
        }

        if (isset($options['postOnly']) && $options['postOnly']) {
            $params->setPostOnly(true);
        }

        if (!empty($options['positionSide'])) {
            $params->setPositionSide($options['positionSide']);
        }

        return $params;
    }

    /**
     * Default options
     *
     * @param $position
     * @param $target
     * @return array
     */
    private static function extractOptionsDefaults($position, $target = null): array
    {
        $options = [];
        if (!isset($position->exchange->paperTrading)
            || !$position->exchange->paperTrading
        ) {
            $options['zignalyPositionId'] = $position->_id->__toString();
        }

        if ($target && isset($target->postOnly) && $target->postOnly) {
            $options['postOnly'] = true;
        }

        //With ignore, we avoid to change the marginMode
        $options['marginMode'] = 'ignore';

        if (!empty($position->signal->hedgeMode)) {
            $options['positionSide'] = $position->side;
        } else {
            $options['positionSide'] = 'BOTH';
        }

        return $options;
    }
}
