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


namespace Zignaly\Provider;

use MongoDB\BSON\ObjectId;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Class ProviderService
 * @package Zignaly\Provider
 */
class ProviderService
{
    /**
     * Monolog service.
     *
     * @var \Monolog
     */
    private $monolog;
    
    /**
     * Process memory cache.
     *
     * @var ArrayAdapter
     */
    private $arrayCache;

    /**
     * ProviderService constructor.
     */
    public function __construct(\Monolog $monolog, ArrayAdapter $arrayCache)
    {
        global $mongoDBLink;
        $this->monolog = $monolog;
        $this->arrayCache = $arrayCache;
        $this->mongoDBLink = $mongoDBLink;
   }

   /**
     * Returns a provider document or null if the provider is missing.
     * 
     * @param string $userId
     * @param string $providerId
     * @return BSONDocument|null
     */
    public function getProvider($userId, $providerId)
    {
        try {
            $providerId = is_object($providerId) ? $providerId : new ObjectId($providerId);
            $userId = is_object($userId) ? $userId : new ObjectId($userId);

            $find = [
                '_id' => $providerId,
                '$or' => [
                    ['userId' => $userId],
                    ['public' => true],
                ]
            ];
            $provider = $this->mongoDBLink->selectCollection('provider')->findOne($find);
        } catch (\Exception $e) {
            return null;
        }

        if (!isset($provider->name))
            return null;

        return $provider;
    }

    /**
     * Returns provider logo path or a default image.
     * 
     * @param string $providerId
     * @param string $userId
     * @return mixed|string
     */
    public function getProviderLogoUrl(string $providerId, string $userId)
    {
        try {
            $key = 'providerLogo_' . $providerId;
            $cache = $this->arrayCache->getItem($key);

            if ($cache->isHit()) {
                return $cache->get();
            }

            if ($providerId != 1) {
                $provider = $this->getProvider($userId, $providerId);
                $logoUrl = empty($provider->logoUrl) ? '' : $provider->logoUrl;
            }

            if (empty($logoUrl)) {
                $logoUrl = 'images/providersLogo/default.png';
            }

            $cache->set($logoUrl);
            $this->arrayCache->save($cache);

            return $logoUrl;
        } catch (\Exception $e) {
            $this->monolog->sendEntry('ERROR', "Getting logo url for $providerId: " . $e->getMessage());

            return 'images/providersLogo/default.png';
        }
    }
}