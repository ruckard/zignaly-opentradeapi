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



namespace Zignaly\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Zignaly\Process\DIContainer;

class MarketDataController
{
    /**
     * Market data service.
     *
     * @var \Zignaly\redis\ZignalyMarketDataRedisService
     */
    private $marketData;

    /**
     * Monolog service.
     *
     * @var \Monolog
     */
    private $monolog;

    public function __construct()
    {
        $container = DIContainer::getContainer();
        if (!$container->has('monolog')) {
            $container->set('monolog', new Monolog('MarketDataController'));
        }
        $this->monolog = $container->get('monolog');
        $this->marketData = $container->get('marketData');
    }

    /**
     * Provides exchange name IDs list supported by Zignaly.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getExchanges(Request $request, array $payload): JsonResponse
    {
        $exchanges = $this->marketData->getExchanges();
        sort($exchanges);

        return new JsonResponse($exchanges);
    }

    /**
     * Provides Zignaly supported exchange symbols metadata collection.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param array $payload Request POST payload.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getSymbolsMetadata(Request $request, array $payload): JsonResponse
    {
        $symbolsMetadataDigested = [];

        if (!isset($payload['exchangeName'])) {
            sendHttpResponse(['error' => ['code' => 21]]);
        }

        $exchangeName = filter_var($payload['exchangeName'], FILTER_SANITIZE_STRING);
        $symbolsMetadata = $this->marketData->getMarkets($exchangeName);

        foreach ($symbolsMetadata as $symbolMetadata) {
            $symbolsMetadataDigested[] = $symbolMetadata->asArray();
        }

        return new JsonResponse($symbolsMetadataDigested);
    }
}