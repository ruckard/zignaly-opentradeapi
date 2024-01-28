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

namespace Zignaly\exchange\exceptions;
/**
 * Base exchange exception
 */
class ExchangeException extends \Exception {
    public static $printPrevious = true;

    public function __construct($message, $code = 0, \Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }

    public function __toString() {
        $result   = array();
        $result[] = sprintf("Exception '%s' with message '(%s) %s' in %s:%d", get_class($this), $this->code, $this->message, $this->file, $this->line);
        $result[] = '---[Stack trace]:';
        $result[] = $this->getTraceAsString();

        if (self::$printPrevious) {
            $previous = $this->getPrevious();
            if ($previous) {
                do {
                    $result[] = '---[Previous exception]:';
                    $result[] = sprintf("Exception '%s' with message '(%s) %s' in %s:%d", get_class($previous), $previous->getCode(), $previous->getMessage(), $previous->getFile(), $previous->getLine());
                    $result[] = '---[Stack trace]:';
                    $result[] = $previous->getTraceAsString();
                } while(method_exists($previous, 'getPrevious') && ($previous = $previous->getPrevious()));
            }
        }

        return implode("\r\n", $result);
    }
}
