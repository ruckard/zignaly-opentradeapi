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


use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Handler\SlackWebhookHandler;
use Zignaly\RemoveBsonMonologProcessor;
use Zignaly\RemoveNullMonologProcessor;

class Monolog
{
    protected $log;

    /**
     * The process name that is generating the logs.
     *
     * @var string
     */
    private $processName;

    private $slackWebhookUrl = '';
    private $slackChannel = '';
    private $slackUsername;
    private $slackUseAttachment = false;
    private $slackIconEmoji;
    private $slackUseShortAttachment = false;
    private $slackIncludeContextAndExtra = false;
    private $slackLevel = Logger::ERROR;
    private $slackBubble = true;
    private $slackExcludeFields = [];
    private $slackCriticalChannel = '';
    private $slackCriticalLevel = Logger::CRITICAL;
    private $slackAlertChannel = '';
    private $slackAlertLevel = Logger::ALERT;

    private $hostname = '';
    private $extendKeys = [];


    public function __construct($processName, $slackIconEmoji = ':bangbang', $pushDBHandler = true)
    {
        $this->processName = $processName;
        $this->slackUsername = $processName;
        $this->slackIconEmoji = $slackIconEmoji;
        $this->log = new Logger($processName);
        $isLocal = getenv('LANDO') === 'ON';
        if ($isLocal) {
            $logName = __DIR__ . '/../../logs/' . $processName . '.log';
        } else {
            $logName = "$processName.log";
        }
        $handler = new StreamHandler($logName);
        $handler->setFormatter(new LogstashFormatter($processName));
        $this->log->pushHandler($handler);
        $this->hostname = gethostname();

        $this->log->pushProcessor(new RemoveBsonMonologProcessor());
        $this->log->pushProcessor(new RemoveNullMonologProcessor());
    }

    public function addExtendedKeys($key, $value)
    {
        $this->extendKeys[$key] = $value;
    }

    public function confDBLogging()
    {
        try {
            $mongoConnection = new MongoDB\Client(MONGODB_LOG_URI, MONGODB_LOG_OPTIONS);
            $stream = new \Monolog\Handler\MongoDBHandler($mongoConnection, MONGODB_LOG_NAME, 'logs', Logger::DEBUG);
            $stream->setFormatter(new \Monolog\Formatter\MongoDBFormatter());
            $this->log->pushHandler($stream);
        } catch (Exception $e) {
            //echo "Not able to connect to the DB: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Given a method return the expiration time for the long entry
     * @param string $method
     * @return DateTime
     * @throws Exception
     */
    private function getExpirationTimeDefault(string $method)
    {
        //debug, info, notice, warning, error, critical, alert, emergency
        switch ($method) {
            case 'debug':
                $expirationDate = time() + 24 * 60 * 60 * 2;
                return new DateTime('@' . $expirationDate);
                break;
            case 'info':
                $expirationDate = time() + 24 * 60 * 60 * 5;
                return new DateTime('@' . $expirationDate);
                break;
            case 'notice':
                $expirationDate = time() + 24 * 60 * 60 * 6;
                return new DateTime('@' . $expirationDate);
                break;
            case 'warning':
                $expirationDate = time() + 24 * 60 * 60 * 7;
                return new DateTime('@' . $expirationDate);
                break;
            case 'error':
                $expirationDate = time() + 24 * 60 * 60 * 10;
                return new DateTime('@' . $expirationDate);
                break;
            case 'critical':
                $expirationDate = time() + 24 * 60 * 60 * 12;
                return new DateTime('@' . $expirationDate);
                break;
            case 'alert':
                $expirationDate = time() + 24 * 60 * 60 * 60;
                return new DateTime('@' . $expirationDate);
                break;
            case 'emergency':
                $expirationDate = time() + 24 * 60 * 60 * 60;
                return new DateTime('@' . $expirationDate);
                break;
            default:
                 $expirationDate = time() + 24 * 60 * 60 * 180;
                return new DateTime('@' . $expirationDate);
        }
    }

    public function sendEntry($method, $message, $extendMsg = [])
    {
        //Method: debug, info, notice, warning, error, critical, alert, emergency
        try {
            if (is_object($extendMsg)) {
                $extendMsg = $extendMsg->getArrayCopy();
            }
            if (!is_array($extendMsg)) {
                $extendMsg[] = $extendMsg;
            }
            $extendMsg = array_merge($extendMsg, $this->extendKeys);
            $parseExtendMsg = $this->replaceKeys($extendMsg);
            $parseExtendMsg['expireAt'] = $this->getExpirationTimeDefault($method);
            $this->log->$method($message, $parseExtendMsg);
        } catch (Exception $e) {
            /*echo "Error with logging: " . $e->getMessage() . "\n";
            var_dump($message, $extendMsg);
            echo "\n\n";*/
        }
    }

    public function trackSequence()
    {
        $this->extendKeys = [
            'hostname' => $this->hostname,
            'sequenceId' => uniqid('TI_', true),
        ];
    }

    private function replaceKeys($input)
    {
        $return = [];
        foreach ($input as $key => $value) {
            $key = str_replace('.', '-', $key);

            if (strpos($key, '$') === 0) {
                $key = str_replace('$', '-$', $key);
            }
            if (is_array($value)) {
                $value = $this->replaceKeys($value);
            }
            $return[$key] = $value;
        }

        return $return;
    }

    /**
     * Get the process name that is logging.
     *
     * @return string
     */
    public function getProcessName()
    {
        return $this->processName;
    }

}
