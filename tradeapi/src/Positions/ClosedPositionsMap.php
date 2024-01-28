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


namespace Zignaly\Positions;

/**
 * Class ClosedPositionsMap
 * @package Zignaly\Positions
 */
class ClosedPositionsMap
{
    public const FIELDS = [
        'accounting' => 'a',
        'amount' => 'am',
        'base' => 'b',
        'buyPrice' => 'bp',
        'buyTTL' => 'bt',
        'checkStop' => 'cs',
        'closeDate' => 'cd',
        'closed' => 'c',
        'close_order' => 'close_order',
        'closeTrigger' => 'ct',
        'copyTraderId' => 'cti',
        'currentAllocatedBalance' => 'ca',
        'exchange' => 'e',
        'exchangeInternalName' => 'en',
        'exchangeType' => 'et',
        'fees' => 'f',
        'fundingFees' => 'ff',
        'internalExchangeId' => 'ie',
        'invested' => 'i',
        'investedQuote' => 'iq',
        'isCopyTrader' => 'ic',
        'isCopyTrading' => 'ict',
        'leverage' => 'l',
        'logoUrl' => 'lg',
        'netProfit' => 'n',
        'netProfitPercentage' => 'np',
        'openDateBackup' => 'odb',
        'openDate' => 'od',
        'open_order' => 'open_order',
        'openTrigger' => 'ot',
        'orders' => 'o',
        'pair' => 'p',
        'paperTrading' => 'pt',
        'positionId' => 'pi',
        'positionSizePercentage' => 'psp',
        'positionSize' => 'ps',
        'positionSizeQuote' => 'psq',
        'profitPercentage' => 'pp',
        'profit' => 'pf',
        'providerId' => 'pri',
        'providerName' => 'pn',
        'provider' => 'pr',
        'providerOwnerUserId' => 'poui',
        'quoteAsset' => 'qa',
        'quote' => 'q',
        'realInvestment' => 'ri',
        'reBuyTargetsCountFail' => 'rbtcf',
        'reBuyTargetsCountPending' => 'rbtcp',
        'reBuyTargetsCountSuccess' => 'rbtcs',
        'reBuyTargets' => 'rbt',
        'remainAmount' => 'ra',
        'sellByTTL' => 'sbt',
        'sellPlaceOrderAt' => 'spo',
        'sellPrice' => 'sp',
        'short' => 'sh',
        'side' => 'sd',
        'signalId' => 'si',
        'signalMetadata' => 'sm',
        'signalTerm' => 'st',
        'statusDesc' => 'std',
        'status' => 'sts',
        'stopLossPercentage' => 'slp',
        'stopLossPrice' => 'sl',
        'stopLossPriority' => 'sly',
        'symbol' => 'sy',
        'takeProfit' => 'tp',
        'takeProfitTargetsCountFail' => 'tpcf',
        'takeProfitTargetsCountPending' => 'tpcp',
        'takeProfitTargetsCountSuccess' => 'tpcs',
        'takeProfitTargets' => 'tpt',
        'tradeViewSymbol' => 'tv',
        'trailingStopPercentage' => 'trsp',
        'trailingStopTriggered' => 'trst',
        'trailingStopTriggerPercentage' => 'trstp',
        'trailingStopTriggerPrice' => 'trstr',
        'trailingStopTriggerPriority' => 'trsty',
        'type' => 't',
        'unitsAmount' => 'ua',
        'unitsInvestment' => 'ui',
        'updating' => 'u',
        'userId' => 'uid',
    ];
}