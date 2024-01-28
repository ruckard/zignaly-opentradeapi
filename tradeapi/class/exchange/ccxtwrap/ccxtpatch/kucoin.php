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

namespace Zignaly\exchange\ccxtwrap\ccxtpatch;

use Zignaly\exchange\exceptions;
use ccxt;
use Zignaly\exchange\zignalyratelimiter\RateLimitTrait;

class kucoin extends ccxt\kucoin {
    use RateLimitTrait;
    
    private $partnerId;
    private $partnerKey;
    public function __construct($options = array()) {
        parent::__construct($options);
        if (isset($options['partnerid'])){
            $this->partnerId = $options['partnerid'];
        } else {
            $this->partnerId = null;
        }
        if (isset($options['partnerkey'])){
            $this->partnerKey = $options['partnerkey'];
        } else {
            $this->partnerKey = null;
        }
    }

    public function sign ($path, $api = 'public', $method = 'GET', $params = array (), $headers = null, $body = null) {
        /**
         * array['url']
         * array['method']
         * array['body']
         * array['headers']
         **/
        $ret = parent::sign($path, $api, $method, $params, $headers, $body);
        // not private request
        if (!array_key_exists("KC-API-KEY",$ret['headers'])) return $ret;
        // if properties not provided raise exception
        
        // if properties not provided, send original request
        //if (($this->partnerKey == null) || ($this->partnerId == null)) return $ret;
        
        if (($this->partnerKey == null) || ($this->partnerId == null)) throw new exceptions\ExchangeAuthException("KuCoin exchange needs partnerKey and partnerId options in ccxt configuration");
        // generate third party platform auth
        // recover data from headers
        $timestamp = $ret['headers']['KC-API-TIMESTAMP'];
        $partner = $this->partnerId;
        $apiKey = $ret['headers']['KC-API-KEY'];
        $payload = $timestamp . $partner . $apiKey;
        $signature = $this->hmac ($this->encode ($payload), $this->encode ($this->partnerKey), 'sha256', 'base64');
        $ret['headers']['KC-API-PARTNER'] = $partner;
        $ret['headers']['KC-API-PARTNER-SIGN'] = $this->decode ($signature);
        return $ret;
    }
}
