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

use MongoDB\Database;
use Zignaly\db\model\ExchangeModel;
use MongoDB\BSON\ObjectId;

class ZignalyExchangeMapping {
    /**
     * Data base connection
     *
     * @var Database $mongoDBLink
     */
    private $mongoDBLink;
    /**
     * Exchange model
     *
     * @var ExchangeModel
     */
    private $model;
    /**
     * Constructor
     *
     * @param Database $mongoDBLink
     */
    public function __construct(Database $mongoDBLink)
    {
        $this->mongoDBLink = $mongoDBLink;
        $this->model = new ExchangeModel($this->mongoDBLink);
    }
    /**
     * Get exchange id list for exchanges that are derived or subordinate
     * to that one
     *
     * @param string $exchangeId
     * @return ObjectId[]
     */
    public function getSubExchanges(string $exchangeId): array
    {
        // Ignore if no exchange ID was provided.
        if (empty($exchangeId)) {
            return [];
        }

        $exchange = $this->model->getById($exchangeId);
        if (!isset($exchange->subExchanges)) return [];
        
        return $exchange->subExchanges;
    }

    public function getSubExchangesAsStringList(string $exchangeId): array
    {
        $list = $this->getSubExchanges($exchangeId);
        if (empty($list)) {
            return [];
        }

        $ret = [];
        foreach($list as $elem){
            $ret[] = $elem->__toString();
        }
        return $ret;
   }
}