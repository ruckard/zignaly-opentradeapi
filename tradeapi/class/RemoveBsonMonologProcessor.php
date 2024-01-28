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


namespace Zignaly;

use MongoDB\BSON\Type;

/**
 * Class RemoveBsonMonologProcessor
 * @package Zignaly
 * @author Miguel Ángel Garzón <miguel@zignaly.com>
 */
class RemoveBsonMonologProcessor
{
    /**
     * @param array $record
     * @return array
     */
    public function __invoke(array $record): array
    {
        foreach ($record as $key => $value) {
            $record[$key] = $this->normalize($value);
        }

        return $record;
    }

    /**
     * @param mixed $field
     * @return mixed
     */
    private function normalize($field)
    {
        $result = $field;

        if (\is_iterable($field)) {
            $result = [];
            foreach ($field as $key => $value) {
                $result[$key] = $this->normalize($value);
            }
        } elseif ($field instanceof Type) {
            $result = (string) $field;
        }

        return $result;
    }
}
