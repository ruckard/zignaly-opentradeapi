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


namespace Zignaly\Prices;

use MongoDB\Model\BSONDocument;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use Zignaly\exchange\BaseExchange;
use Zignaly\exchange\ExchangeFactory;
use Zignaly\exchange\marketencoding\BaseMarketEncoder;
use Zignaly\exchange\marketencoding\BitmexMarketEncoder;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\service\entity\ZignalyMarketData;
use Zignaly\service\ZignalyLastPriceService;
use Zignaly\service\ZignalyMarketDataService;

/**
 * Class PriceConverterService
 * @package Zignaly\Prices
 */
class PriceConverterService
{
    /**
     * @var ZignalyMarketDataService
     */
    private $marketData;

    /**
     * @var ArrayAdapter
     */
    private $marketCache;

    /**
     * @var ArrayAdapter
     */
    private $pricesCache;

    /**
     * @var ZignalyLastPriceService
     */
    private $pricesService;

    /**
     * PriceConverterService constructor.
     * @param ZignalyLastPriceService $pricesService
     * @param ZignalyMarketDataService $marketData
     */
    public function __construct(ZignalyLastPriceService $pricesService, ZignalyMarketDataService $marketData)
    {
        $this->pricesService = $pricesService;
        $this->marketData = $marketData;

        $this->marketCache = new ArrayAdapter(60);
        $this->pricesCache = new ArrayAdapter(10, false);
    }

    /**
     * Flush markets and price caches
     */
    public function flushCaches(): void
    {
        $this->marketCache->clear();
        $this->pricesCache->clear();
    }

    /**
     * @param BSONDocument $exchange
     * @param string $asset
     * @param float $amount
     * @return PriceConversion
     * @throws PriceConversionException
     */
    public function convert(BSONDocument $exchange, string $asset, float $amount): PriceConversion
    {
        $result = new PriceConversion($asset, $amount);

        if (0.0 !== $amount) {
            //The reference coin should be XBT or BTC, except if the market is delisted
            //or we don't have information that the reference coin is USDT
            $coin = $this->getReferenceCoin($exchange, $asset);
            $lastPrice = $this->getLastPrice($exchange, $coin, $asset);
            $usdtPrice = 'USDT' === $asset? 1.0:
                $this->getLastPrice($exchange, 'XBT' === $coin ? 'XBT' : 'BTC', 'USDT');

            if (!$lastPrice || !$usdtPrice) {
                //Flush the caches just in case
                $this->flushCaches();
                throw new PriceConversionException("Prices for $coin:$asset are not returned from the price service");
            }

            $convertedAmount = 'BTC' === $coin && $this->shouldBTCBeABase($asset)? $amount/$lastPrice
                : $amount * $lastPrice;

            if ('USDT' === $coin) {
                $result->amountInUsdt = $convertedAmount;
                $result->amountInBTC = $convertedAmount / $usdtPrice;
            } else {
                $result->amountInBTC = $convertedAmount;
                $result->amountInUsdt = 'USDT' === $asset? $amount : $convertedAmount * $usdtPrice;
            }
        }

        return $result;
    }

    /**
     * @param BSONDocument $exchange
     * @param string $coin
     * @param string $asset
     * @return float|null
     */
    public function getLastPrice(BSONDocument $exchange, string $coin, string $asset): ?float
    {
        $result = 1.0;

        if ($coin !== $asset) {
            $exchangeId = $this->getExchangeId($exchange);

            $cacheKey = "$exchangeId-$coin-$asset";

            try {
                $result = $this->pricesCache->get(
                    $cacheKey,
                    function (ItemInterface $item) use ($exchange, $coin, $asset) {
                        $asset = 'USD' === $asset ? 'USDT' : $asset;

                        //FIXME: this should to be common for any exchange, any coin
                        if ('XBT' === $coin && 'USDT' === $asset) {
                            $marketEncoder = BaseMarketEncoder::newInstance(
                                $exchange->name,
                                $this->getExchangeType($exchange)
                            );
                            $symbol = $marketEncoder->getReferenceSymbol4Zignaly('XBTUSD');
                            $realExchange = ZignalyExchangeCodes::ZignalyBitmex;
                        } else {
                            $realExchange = ZignalyExchangeCodes::getRealExchangeName(
                                $this->getRealExchange($exchange)->getId()
                            );

                            $symbol = 'BTC' === $coin && $this->shouldBTCBeABase($asset)? "$coin$asset" : "$asset$coin";
                        }

                        $currentPrice = $symbol? $this->pricesService->lastPriceStrForSymbol($realExchange, $symbol)
                            : null;

                        return $currentPrice ? (float)$currentPrice : null;
                    }
                );
            } catch (InvalidArgumentException $e) {
                $result = null;
            }
        }

        return $result;
    }

    /**
     * @param BSONDocument $exchange
     * @param string $asset
     * @return string
     */
    private function getReferenceCoin(BSONDocument $exchange, string $asset): string
    {
        $coin = $asset;

        if (\in_array($asset, ['BTC', 'USDT'])) {
            $coin = 'BTC';
        } else {
            $marketEncoder = BaseMarketEncoder::newInstance(
                $exchange->name,
                $this->getExchangeType($exchange)
            );

            //For bitmex returns asset, that should be ¿always? XBT
            if (!$marketEncoder instanceof BitmexMarketEncoder) {
                $market = $this->getMarket($exchange, $asset);

                //If the market is null, probably it's a fail in the market storage and/or a delisted market
                //(although delisted market should return an object with isActive to false), use USDT just in case
                $coin = !$market || !$market->getIsActive() ? 'USDT' : 'BTC';
            }
        }

        return $coin;
    }

    /**
     * @param BSONDocument $exchange
     * @param string $asset
     * @return ZignalyMarketData | null
     */
    private function getMarket(BSONDocument $exchange, string $asset): ?ZignalyMarketData
    {
        $exchangeId = $this->getExchangeId($exchange);

        try {
            return $this->marketCache->get(
                "$exchangeId-$asset",
                function (ItemInterface $item) use ($exchangeId, $asset) {
                    $market = $this->marketData->getMarket($exchangeId, "{$asset}BTC")
                           ?: $this->marketData->getMarket($exchangeId, "BTC{$asset}");

                    return $market?: null;
                }
            );
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * @param BSONDocument $exchange
     * @return false|string
     */
    private function getExchangeId(BSONDocument $exchange)
    {
        return ZignalyExchangeCodes::getExchangeFromCaseInsensitiveStringWithType(
            $exchange->name,
            empty($exchange->exchangeType) ? 'spot' : strtolower($exchange->exchangeType)
        );
    }

    /**
     * @param $exchange
     * @return BaseExchange
     */
    private function getRealExchange(BSONDocument $exchange): BaseExchange
    {
        $realExchanges = [];
        $exchangeName = !empty($exchange->exchangeName) ? $exchange->exchangeName : $exchange->name;
        if (!isset($realExchanges[$exchangeName])) {
            $exchangeType = !empty($exchange->exchangeType) ? $exchange->exchangeType : 'spot';
            $realExchanges[$exchangeName] = ExchangeFactory::createFromNameAndType($exchangeName, $exchangeType, []);
        }

        return $realExchanges[$exchangeName];
    }

    /**
     * Check if BTC should go as base.
     *
     * @param string $coin
     * @return bool
     */
    private function shouldBTCBeABase(string $coin): bool
    {
        return \in_array($coin, ['USDT', 'PAX', 'USDC', 'TUSD', 'USDS', 'USD', 'BUSD']);
    }

    /**
     * @param $exchange
     * @return string
     */
    private function getExchangeType($exchange): string
    {
        return empty($exchange->exchangeType) ? 'spot' : strtolower($exchange->exchangeType);
    }
}
