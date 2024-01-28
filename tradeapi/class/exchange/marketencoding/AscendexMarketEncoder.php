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


namespace Zignaly\exchange\marketencoding;

use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\service\entity\ZignalyMarketData;

class AscendexMarketEncoder extends BaseMarketEncoder
{
    public function createMarketFromCcxtMarket($market)
    {
        $m = parent::createMarketFromCcxtMarket($market);

        if (str_contains($market['id'], '/')) {
            $m['zignalyId'] = strtoupper(str_replace('/', '', $market['id']));
        }

        if (str_contains($market['id'], '-')) {
            $m['zignalyId'] = strtoupper(str_replace('-', '', $market['id']));
        }

        return $m;
    }

    public function toCcxt($symbol)
    {
        $ccxtSymbol = $this->symbolFromZig($symbol);

        if (null === $ccxtSymbol) {
            throw new \Exception('Zignaly symbol '.$symbol. ' not found in ' .ZignalyExchangeCodes::ZignalyVcce);
        }

        return $ccxtSymbol;
    }

    public function getExchangeName(): string
    {
        return ZignalyExchangeCodes::ZignalyAscendex;
    }

    public function coinrayExchange()
    {
        return null;
    }

    /**
     * get zignaly symbol from ccxt symbol
     *
     * @param string $symbol
     * @param array $ccxtMarketData
     * @return string
     */
    public function fromCcxt($symbol, $ccxtMarket = null)
    {
        if ($ccxtMarket != null) {
            return $this->getZignalySymbol($ccxtMarket['id']);
        }

        $zigSymbol = $this->symbolFromCcxt($symbol);

        if ($zigSymbol == null) {
            throw new \Exception("Ccxt symbol ".$symbol. " in ".ZignalyExchangeCodes::ZignalyAscendex);
        }

        return $zigSymbol;
    }

    /**
     * @inheritDoc
     */
    public function getReferenceSymbol4Zignaly($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return null;
        }
        return $market->getReferenceSymbol();
    }
    /**
     * @inheritDoc
     */
    public function getZignalySymbolFromZignalyId($zignalyId)
    {
        $market = $this->marketFromZig($zignalyId);
        if ($market == null) {
            return null;
        }

        return $market->getId();
    }

    /**
     * @inheritDoc
     */
    public function getBaseFromMarketData(ZignalyMarketData $marketData)
    {
        return $marketData->getBaseId();
    }

    /**
     * @inheritDoc
     */
    public function getQuoteFromMarketData(ZignalyMarketData $marketData)
    {
        return $marketData->getQuoteId();
    }

    /**
     * @inheritDoc
     */
    public function getZignalySymbolFromMarketData(ZignalyMarketData $marketData)
    {
        return $this->getZignalySymbol($marketData->getId());
    }

    private function getZignalySymbol($id)
    {
        return strtoupper(str_replace('-', '', str_replace('/', '', $id)));
    }
}
