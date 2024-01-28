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


use Firebase\JWT\JWT;
use GuzzleHttp\Client;

class TradeApiClient
{
    /**
     * Monolog service.
     *
     * @var \Monolog
     */
    private $monolog;

    private $tradeApiURL = FEAPI_URL;

    private $jwtSecret;

    public function __construct(Monolog $Monolog, $tradeApiURL = false, $jwtSecret = null)
    {
        if (!empty($tradeApiURL)) {
            $this->tradeApiURL = $tradeApiURL;
        }

        $this->monolog = $Monolog;
        $this->jwtSecret = $jwtSecret;
    }

    /**
     * Call endpoint with certain parameters and return the response
     *
     * @param string $method
     * @param array $parameters
     * @param string $parametersContentType,
     * @param bool $verify
     * @return bool|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function apiCall(string $method, $parameters = [], $parametersContentType = 'query', $verify = true)
    {
        //Todo: Include retry.
        try {
            $config = [
                'base_uri' => $this->tradeApiURL,
                'verify' => $verify,
            ];
            $headers = $parameters['headers'] ?? [];
            unset($parameters['headers']);
            $client = new Client($config);
            $response = $client->request($method, '', [
                $parametersContentType => $parameters,
                'connect_timeout' => 3.14,
                'headers' => $headers
            ]);
        } catch(Exception $e) {
            $this->monolog->sendEntry('error', "apiCall failed: " .$e->getMessage(), $parameters);
            return false;
        }

        if ($response->getStatusCode() != 200 && $response->getStatusCode() != 201) {
            $output = $this->getArrayFromJsonResponse($response->getBody()->getContents());
            if (!is_array($output)) {
                $output = [$output];
            }
            $this->monolog->sendEntry('critical', 'HTTP Code non 200', $output);
            return false;
        }

        return $response->getBody()->getContents();
    }

    /**
     * Convert json response to array
     *
     * @param string $response
     * @return array
     */
    private function getArrayFromJsonResponse($response)
    {
        return json_decode($response, true);
    }

    /**
     * Get the markets data for a given exchange from our trade api.
     *
     * @param string $exchangeName
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getExchangeMarketData(string $exchangeName)
    {
        $parameters = [
            'action' => 'getMarketDataExchangeSymbols',
            'exchangeName' => $exchangeName,
        ];

        $markets = $this->apiCall('GET', $parameters);

        if (!$markets || empty($markets)) {
            return [];
        }

        $returnMarkets = $this->getArrayFromJsonResponse($markets);
        if (!is_array($returnMarkets)) {
            return [];
        }

        return $returnMarkets;
    }

}
