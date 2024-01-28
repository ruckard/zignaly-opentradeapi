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

/**
 * Class RemoveNullMonologProcessor
 * @package Zignaly
 * @author Miguel Ángel Garzón <miguel@zignaly.com>
 */
class RemoveNullMonologProcessor
{
    /**
     * @param array $record
     * @return array
     */
    public function __invoke(array $record): array
    {
        $context = $record['context'] ?? null;

        if ($context) {
            $this->removeNulls($context);
        }

        $record['context'] = $context;

        return $record;
    }

    /**
     * @param array $values
     */
    public function removeNulls(array &$values): void
    {
        foreach ($values as $key => $value) {
            //Remove all null and false values
            if (null === $value || false === $value) {
                unset($values[$key]);
            } elseif (\is_array($value)) {
                $this->removeNulls($value);
                $values[$key] = $value;
            }
        }
    }
}
