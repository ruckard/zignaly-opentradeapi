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

namespace Zignaly\exchange\ccxtwrap;

use Zignaly\exchange\ExchangeTrade;
use Zignaly\exchange\ExchangeTradeTakerOrMaker;
use Zignaly\exchange\ExchangeOrderType;
use Zignaly\exchange\ExchangeOrderSide;
use Zignaly\exchange\ExchangeTradeFee;
use Zignaly\exchange\ExchangeTradeMakerOrTaker;

class ExchangeTradeFeeCcxt implements ExchangeTradeFee {
    /** @var float */
    private $feeCost;
    /** @var string */
    private $feeCurrency;
    /** @var float|null */
    private $feeRate;
    /**
     * Constructor
     *
     * @param float $feeCost
     * @param string $feeCurrency
     * @param float|null $feeRate
     */
    public function __construct ($feeCost, $feeCurrency, $feeRate) {
        $this->feeCost = $feeCost;
        $this->feeCurrency = $feeCurrency;
        $this->feeRate = $feeRate;
    }
   /**
     * fee cost
     *
     * @return float
     */
    public function getFeeCost () {
        return $this->feeCost;
    }
    /**
     * fee currency
     *
     * @return string
     */
    public function getFeeCurrency () {
        return $this->feeCurrency;
    }
    /**
     * fee rate
     *
     * @return float|null
     */
    public function getFeeRate () {
        return $this->feeRate;
    }
}