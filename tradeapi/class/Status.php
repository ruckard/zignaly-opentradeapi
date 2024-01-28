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


class Status
{
    public $positionStatuses = '';

    function __construct()
    {
        $this->positionStatuses = $this->getPositionStatuses();
    }

    public function getPositionStatusText($status)
    {
        return $this->positionStatuses[$status];
    }

    public function getPositionStatuses()
    {
        $statuses = [
             0 => 'Position Created In DB.',
             1 => 'Buy Created On Exchange.',
             2 => 'Buy Creation On Exchange Failed.',
             3 => 'Buy Not Place Because TTL.',
             4 => 'Buy Removed From Exchange Because TTL.',
             5 => 'Current Price Below Min Price.',
             6 => 'Price Too Low.',
             7 => 'Order would trigger immediately.',
             8 => 'Unknown Error.',
             9 => 'Buy Performed.',
            10 => 'Unknown Error.',
            11 => 'Canceled by User.',
            12 => 'No Minimal Amount.',
            13 => 'Stop Loss Placed.',
            14 => 'Take Profit Placed.',
            15 => 'Trailing Stop Placed.',
            16 => 'Stop Loss Ended.',
            17 => 'Take Profit Ended.',
            18 => 'Trailing Stop Ended.',
            19 => 'Unknown Stop Ended.',
            20 => 'Stop Loss Price would be under minimum amount.',
            21 => 'Market Order Placed.',
            22 => 'Market Order Ended.',
            23 => 'Updating Position.',
            24 => 'Account has insufficient balance.',
            25 => 'Buy Performed with Stop Loss disabled.',
            26 => 'Buy only position. Buy performed successfully.',
            27 => 'Selling by TTL with Stop Loss Placed.',
            28 => 'Waiting for Take Profit Trigger.',
            29 => 'Selling by TTL without Stop Loss Placed.',
            30 => 'Sold by TTL.',
            31 => 'Waiting for Sell by TTL Signal.',
            32 => 'Wrong Key/Secret pair for Exchange.',
            33 => 'Error placing the Take Profit order.',
            34 => 'Missing buy Order in position.',
            35 => 'Sold Manually.',
            36 => 'Order partially filled but notional value is below minimal order value.',
            37 => 'The selling balance is lower than registered.',
            38 => 'The buying notional value is below minimal order value.',
            39 => 'The selling target amount would be less than the allowed minimum order value.',
            40 => 'Sold by Signal.',
            41 => 'Buy order canceled manually.',
            42 => 'A stop-Limit buy order needs a stop price.',
            43 => 'Maximum concurrent positions reached for this provider.',
            44 => 'Daily volume for this market is below your allowed for this provider.',
            45 => 'The number of positions for this market has reached your limit for this provider.',
            46 => 'The provided signalId has already been used and your configuration doesn\'t allow duplicate Ids.',
            47 => 'There is an active position for this exchange with the given signalId.',
            48 => 'You need to upgrade your membership in order to enjoying this service.',
            49 => 'The signal was sent for a term that you don\'t allow.',
            50 => 'You need to pay the provider in order to use its signals.',
            51 => 'The signal risk level is above your setting value.',
            52 => 'Base currency not supported by your configuration.',
            53 => 'Blacklisted pair for this provider.',
            54 => 'Manually Canceled.',
            55 => 'The position size doesn\'t cover your number of take profit targets.',
            56 => 'Price above or below allowed by the exchange.',
            57 => 'No settings for this provider.',
            58 => 'Global maximum concurrent positions reached.',
            59 => 'Daily volume for this market is below your allowed from your global settings.',
            60 => 'The number of positions for this market has reached your global limit.',
            61 => 'Global blacklisted pair.',
            62 => 'The pair isn\'t in your whitelist for this provider.',
            63 => 'The pair isn\'t in your global whitelist.',
            64 => 'Delisted or marked for delist coin.',
            65 => 'You need to accept the provider\'s disclaimer.',
            66 => 'Order expired.',
            67 => 'Your current tier for this provider doesn\'t allow this position size.',
            68 => 'Position sold by panic sell signal.',
            69 => 'Import failed creating fake order or trade.',
            70 => 'The success rate from the signal is below your allowed configuration.',
            71 => 'No keys when trying to open the position',
            72 => 'Remaining amount below minimum for selling.',
            73 => 'Position partially filled below the minimum allowed for selling.',
            74 => 'The position size is bigger than the remaining allocated balance',
            75 => 'You need to set your balance before using this copy trading provider.',
            76 => 'Just for testing.',
            77 => 'Buy not placed after certain amount of time',
            78 => 'positionSizeQuote doesn\'t match the copy trading provider quote.',
            79 => 'Your API key/secret pair doesn\'t have permissions for this action.',
            80 => 'QTY is over the symbol\'s maximum QTY.',
            81 => 'The configured exchange is not a Futures exchange.',
            82 => 'The symbol from this signal doesn\'t exist in the exchange that you have configured.',
            83 => 'A position already exists for this market in this exchange account.',
            84 => 'Margin is insufficient.',
            85 => 'Your subscription to this service has been suspended by the service\'s provider.',
            86 => 'The exchange responded that the position was no longer opened, so it couldn\'t be reduced.',
            87 => 'Leverage tokens are not enabled in your account.',
            88 => 'The configured exchange and or exchange type, does not match the one from the signal.',
            89 => 'The current service does not allow clones.',
            90 => 'The exchange attached to this position is no longer connected to your account.',
            91 => 'The balance between the position and the contract does not match.',
            92 => 'Hedge mode not supported',
            93 => 'KuCoin Exchange temporary suspended.',
            94 => 'The exchange did not return an orderID.',
            95 => 'Exceeded the maximum allowable position at current leverage',
            96 => 'Hedge mode not supported yet.',
            97 => 'Position reduced to zero',
            98 => 'This action disabled is on this account, check the exchange for more info.',
            99 => 'Unknown Error, Pending Review.',
            100 => 'Amount over maximum allowed by the Exchange',
            101 => 'Position liquidated',
            102 => 'Your API keys do not have the right permissions, please choose "order" instead of "order cancel"',
            103 => 'There is an existing contract for the given market, please review it before opening a new one',
            104 => 'Position side (LONG|SHORT) is not allowed in your configuration',
            105 => 'Canceled according to the order type\'s rules',
            106 => 'Market expired',
            107 => 'Demo trading has been disabled, it will be re-enabled soon',
            108 => 'Service liquidated',
            109 => 'Position Exited',
        ];

        return $statuses;
    }

    public function getPositionStatusFromError($error)
    {
        $error = strtolower($error);

        $knownErrors = [
            ['msg' => 'Account has insufficient balance for requested action', 'status' => 24],
            ['msg' => 'Balance insufficient', 'status' => 24],
            ['msg' => 'Order would trigger immediately', 'status' => 7],
            ['msg' => 'API-key format invalid', 'status' => 32],
            ['msg' => 'Invalid API key/secret pair', 'status' => 32],
            ['msg' => 'Invalid API-key, IP, or permissions for action', 'status' => 32],
            ['msg' => 'Filter failure: MIN_NOTIONAL', 'status' => 38],
            ['msg' => 'Filter failure: PERCENT_PRICE', 'status' => 56],
            ['msg' => 'Access denied, require more permission', 'status' => 79],
            ['msg' => 'QTY is over the symbol\'s maximum QTY.', 'status' => 80],
            ['msg' => 'Margin is insufficient', 'status' => 84],
            ['msg' => 'ReduceOnly Order is rejected', 'status' => 86],
            ['msg' => 'this action disabled is on this account', 'status' => 87],
            ['msg' => 'Order ID not returned', 'status' => 94],
            ['msg' => 'Exceeded the maximum allowable position at current leverage', 'status' => 95],
            ['msg' => 'Order\'s position side does not match user\'s setting', 'status' => 96],
            ['msg' => 'Access Denied', 'status' => 102]
        ];

        foreach ($knownErrors as $knownError) {
            if (strpos($error, strtolower($knownError['msg'])) !== false) {
                return $knownError['status'];
            }
        }

        return 99;
    }

    public function getPositionStatusFromErrorCode($code, $buyPerformed = false)
    {
        switch ($code['msg']) {
            case 'Account has insufficient balance for requested action.':
                return $buyPerformed ? 37 : 24;
                break;
            case 'Order would trigger immediately.':
                return 7;
                break;
            case 'API-key format invalid.':
            case 'Invalid API-key, IP, or permissions for action.':
                return 32;
                break;
            case 'Filter failure: MIN_NOTIONAL':
                return $buyPerformed ? 36 : 38;
                break;
            case 'Filter failure: PERCENT_PRICE':
                return 56;
                break;
            default:
                return 99;
        }
    }

    public function getProviderStatuses()
    {
        $statuses = [
            1 => 'Enabled',
            2 => 'Disabled',
        ];

        return $statuses;
    }

    public function getExchangeStatuses()
    {
        $statuses = [
            1 => 'Enabled',
            2 => 'Disabled',
        ];

        return $statuses;
    }


    public function getCheckOrderStatus()
    {
        $statuses = [
            0 => 'Never checked',
            1 => 'Checking',
            2 => 'Checked',
        ];

        return $statuses;
    }

}