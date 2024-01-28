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

namespace Zignaly\exchange;

class ExchangeExtraParams {
    /** @var float stop price*/
    protected $stopPrice = null;
    /** @var float stop loss price*/
    protected $stopLossPrice = null;
    /** @var float quote order quantity */
    protected $quoteOrderQty = null;
    /** @var bool reduce only option (for Binance futures)*/
    protected $reduceOnly = null;
    /** @var string time in force option (for Binance futures)*/
    protected $timeInForce = null;
    /** @var string zignaly position id */
    protected $zignalyPositionId = null;
    /** @var boolean post only option */
    protected $postOnly = null;
    /** @var string position side option */
    protected $positionSide = null;
    /**
     * Valid options for timeInForce property
     */
    /** Good Till Cancel */
    const TIME_IN_FORCE_GTC = "GTC";
    /** Immediate or Cancel */
    const TIME_IN_FORCE_IOC = "IOC";
    /** Fill or Kill */
    const TIME_IN_FORCE_FOK = "FOK";
    /** Good Till Crossing (Post Only) */
    const TIME_IN_FORCE_GTX = "GTX";

    public function __construct () {

    }
    /**
     * set stop price
     *
     * @param float $price
     * @return ExchangeExtraParams
     */
    public function setStopPrice (float $price)
    {
        $this->stopPrice = $price;
        return $this;
    }
    /**
     * get stopPrice
     *
     * @return float
     */
    public function getStopPrice ()
    {
        return $this->stopPrice;
    }

    /**
     * set stop loss price
     *
     * @param float $price
     * @return ExchangeExtraParams
     */
    public function setStopLossPrice(float $price)
    {
        $this->stopLossPrice = $price;
        return $this;
    }
    /**
     * get stopLossPrice
     *
     * @return float
     */
    public function getStopLossPrice ()
    {
        return $this->stopLossPrice;
    }
    /**
     * set quote order quantity
     *
     * @param float $price
     * @return ExchangeExtraParams
     */
    public function setQuoteOrderQty (float $price)
    {
        $this->quoteOrderQty = $price;
        return $this;
    }
    /**
     * get quote order quantity
     *
     * @return float
     */
    public function getQuoteOrderQty ()
    {
        return $this->quoteOrderQty;
    }
    /**
     * get reduceOnly
     *
     * @param boolean $reduceOnly
     * @return ExchangeExtraParams
     */
    public function setReduceOnly(bool $reduceOnly)
    {
        $this->reduceOnly = $reduceOnly;
        return $this;
    }
    /**
     * get reduceOnly
     * 
     * @return boolean|null
     */
    public function getReduceOnly()
    {
        return $this->reduceOnly;
    }
    /**
     * set time in force 
     *
     * TIME_IN_FORCE_GTC: Good Till Cancel
     * TIME_IN_FORCE_IOC: Immediate or Cancel
     * TIME_IN_FORCE_FOK: Fill or Kill
     * TIME_IN_FORCE_GTX: Good Till Crossing (Post Only) 
     *
     * @param string $timeInForce
     * @return void
     */
    public function setTimeInForce(string $timeInForce)
    {
        $this->timeInForce = $timeInForce;
        return $this;
    }
    public function getTimeInForce()
    {
        return $this->timeInForce;
    }
    /**
     * Set zignaly position id
     *
     * @param string $zignalyPositionId
     * @return ExchangeExtraParams
     */
    public function setZignalyPositionId(string $zignalyPositionId)
    {
        $this->zignalyPositionId = $zignalyPositionId;
        return $this;
    }
    /**
     * Get zignaly position id
     *
     * @return string
     */
    public function getZignalyPositionId()
    {
        return $this->zignalyPositionId;
    }
    /**
     * Set post only
     *
     * @param bool $postOnly
     * @return ExchangeExtraParams
     */
    public function setPostOnly(bool $postOnly)
    {
        $this->postOnly = $postOnly;
        return $this;
    }
    /**
     * Get post only option
     *
     * @return boolean
     */
    public function getPostOnly()
    {
        return $this->postOnly;
    }

    /**
     * Set position side
     *
     * @param string $positionSide
     * @return ExchangeExtraParams
     */
    public function setPositionSide(string $positionSide): ExchangeExtraParams
    {
        $this->positionSide = $positionSide;
        return $this;
    }

    /**
     * Get position side
     *
     * @return string
     */
    public function getPositionSide(): string
    {
        return $this->positionSide;
    }
}