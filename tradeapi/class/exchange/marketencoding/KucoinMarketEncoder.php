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

use Zignaly\exchange\ccxtwrap\exchanges\Kucoin;
use Zignaly\exchange\ZignalyExchangeCodes;

class KucoinMarketEncoder extends BaseMarketEncoder {

    public function __construct() {
        parent::__construct();
    }
     /**
     * exchange code for coinray
     *
     * @return void
     */
    public function coinrayExchange() {
        return "KUCN";
    }
    /**
     * @inheritdoc
     */
    public function toCcxt($symbol) {
        // replace BCHABC -> BCH
        $symbol = str_replace('BCHABC', 'BCH', $symbol);
        $ccxtSymbol = $this->symbolFromZig($symbol);

        if ($ccxtSymbol == null){
            throw new \Exception("Zignaly symbol ".$symbol. " not found in ".ZignalyExchangeCodes::ZignalyKucoin);
        }

        return $ccxtSymbol;
    }

    /**
     * @inheritDoc
     */
    public function getExchangeName(){
        return ZignalyExchangeCodes::ZignalyKucoin;
    }
}
