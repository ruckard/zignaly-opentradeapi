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

namespace Zignaly\exchange\zignalyratelimiter;

use Aws\Lambda\LambdaClient;
use \ccxt\ExchangeError;

trait RateLimitTrait
{
    /** @var LambdaClient */
    private static $lambdaClient = null;

    private static $zignalyContainerChecked = false;
    private static $zignalyMonolog = null;

    private function zignalyLog($message, $context)
    {
        if (!self::$zignalyContainerChecked && null == self::$zignalyMonolog) {
            if (class_exists('Zignaly\\Process\\DIContainer', true)) {
                try {
                    $container = \Zignaly\Process\DIContainer::getContainer();
                    self::$zignalyMonolog = $container->get('monolog');
                    self::$zignalyContainerChecked = true;
                } catch (\Exception $ex) {
                }
            } else {
                self::$zignalyContainerChecked = true;
            }
        }

        if (null !== self::$zignalyMonolog) {
            self::$zignalyMonolog->sendEntry('debug', $message, $context);
        } else {
            if ($this->verbose) {
                echo($message."\n");
                var_dump($context);
            }
        }
    }

    public function zignalyEncodeUrlAndAddApiKey($url, &$headers, $apiKey)
    {
        $headers['X-API-KEY'] = $apiKey;
        return urlencode($url);
    }
    /**
     * Generate proxy and api keys for the next request in Ireland lambdas
     *
     * @return string
     */
    public function zignalyGetProxyIreland()
    {
        $minIndex = $this->zignalyRateLimitFirstIndexServer ?? 1;
        $maxIndex = $this->zignalyRateLimitLastIndexServer ?? 50;
        $zignalyApiKeyTemplate = $this->zignalyRateLimitApiKeyTemplate ?? 'ENBDrM00RW5uCDmkVU6JR8Z548auIYYV3wBzHKK';
        $lambdaIndex = random_int($minIndex, $maxIndex);
        $keys = "XABCD";
        $random = sprintf('%03d', $lambdaIndex);
        
        $this->zignalyRateLimitApiKey = $zignalyApiKeyTemplate . substr($keys, ($lambdaIndex-1)/10, 1);
        
        return "https://cors-proxy-0{$random}.zignaly.cloud/test/proxy?url=";
    }
    /**
     * Generate proxy and pai keys for the next request in Japan lambdas
     *
     * @return string
     */
    public function zignalyGetProxy()
    {
        if (isset($this->zignalyRateLimitProxies)) {
            $proxiesCount = 0;
            foreach ($this->zignalyRateLimitProxies as $proxy) {
                $proxiesCount += ($proxy['max'] - $proxy['min'] + 1);
            }

            $proxyIndex = random_int(0, $proxiesCount - 1);
            $lambdaIndex = 0;
            $urlTemplate = '';
            $apiKeyTemplate = '';
            foreach ($this->zignalyRateLimitProxies as $proxy) {
                $proxiesInThisChunk = $proxy['max'] - $proxy['min'] + 1;
                if ($proxiesInThisChunk > $proxyIndex) {
                    $urlTemplate = $proxy['urltpl'];
                    $apiKeyTemplate = $proxy['apikeytpl'];
                    $lambdaIndex = $proxy['min'] + $proxyIndex;
                    break;
                }
                $proxyIndex -= $proxiesInThisChunk;
            }

            $this->zignalyRateLimitApiKey = sprintf($apiKeyTemplate, $lambdaIndex);
            return sprintf($urlTemplate, $lambdaIndex);
        } else {
            $minIndex = $this->zignalyRateLimitFirstIndexServer ?? 1;
            $maxIndex = $this->zignalyRateLimitLastIndexServer ?? 50;
            $zignalyApiKeyTemplate = $this->zignalyRateLimitApiKeyTemplate ?? '';
            $lambdaIndex = random_int($minIndex, $maxIndex);
            $this->zignalyRateLimitApiKey = sprintf($zignalyApiKeyTemplate, $lambdaIndex);
            return sprintf('https://api-%d.zignaly.cloud/prod/proxy?url=', $lambdaIndex);
        }
    }

    /**
     * create fetch using lambda function
     *
     * Lamdba response when returned an error
     * {
     *      "errorType":"Error",
     *      "errorMessage":"[object Object]",
     *      "trace":[
     *          "Error: [object Object]",
     *          "    at /var/task/handler.js:27:14",
     *          "    at new Promise (<anonymous>)",
     *          "    at Runtime.module.exports.corsProxy [as handler] (/var/task/handler.js:15:10)",
     *          "    at Runtime.handleOnce (/var/runtime/Runtime.js:66:25)"
     *      ]
     * }
     *
     * @param string $lambdaFuncNme
     * @param string $url
     * @param string $method
     * @param array $headers
     * @param string $body
     * @return void
     */
    protected function fetchAwsLambda($lambdaFuncName, $url, $method = 'GET', $headers = null, $body = null)
    {
        $this->zignalyLog('Lambda Info', [
            'lambda' => $lambdaFuncName,
            'request' => [
                'url' => $url,
                'method' => $method,
                'headers' => $headers,
                'body' => $body
            ]
        ]);
        $queryString = [];
        /*$queryStringParameters = parse_url($url, PHP_URL_QUERY);
        if (!empty($queryStringParameters)) {
            parse_str($queryStringParameters, $queryString);
        }*/

        if ('binance' == $this->id) {
            $this->setSecondTry($url, $body, $headers, true);
        }

        $realUrl = urldecode(rtrim(str_replace($queryString, '', $url), '?'));
        $queryString['url'] = $realUrl;
        $payload = [
            'httpMethod' => $method,
            'headers' => new \ArrayObject($headers?? []),
            'queryStringParameters' => $queryString,
            'url' => $realUrl,
            'body' => $body?? "",
            'isBase64Encoded' => false,
            //"resource" => "Resource path",
            //"path" => "Path parameter",
            //"pathParameters"=> [],
            //"stageVariables" => [],
            //"requestContext" => []
        ];

        if (self::$lambdaClient == null) {
            self::$lambdaClient = LambdaClient::factory([
                //'profile' => 'zignaly',
                'version' => 'latest',
                // The region where you have created your Lambda
                'region'  => 'ap-northeast-1',
            ]);
        }
        $resp = self::$lambdaClient->invoke([
            // The name your created Lamda function
            'FunctionName' => $lambdaFuncName,// 'zignaly-core-prod-249',
            'Payload' => json_encode($payload)
        ]);

        if (isset($resp['errorType'])) {
            throw new ExchangeError(implode(' ', array($url, $method, $resp['errorType'], $resp['errorMessage'] ?? '')));
        }

        $response = json_decode($resp->get('Payload')->getContents(), true);
        if (!isset($response['statusCode']) ||
            !isset($response['headers']) ||
            !isset($response['body'])
        ) {
            $dumpResponse = var_export($resp, true);
            echo($dumpResponse);
            throw new ExchangeError(implode(' ', array($url, $method, $dumpResponse)));
        }

        $http_status_code = $response['statusCode'];
        $response_headers = $response['headers'];
        $result = $response['body'];
        $http_status_text = '';
        $curl_error = 0;

        $responseHeaders = array_change_key_case($response_headers, CASE_UPPER);
        if (array_key_exists('ZIGNALY-RETRIES', $responseHeaders)) {
            $this->zignalyLog('Request retried in lambda', [
                'request' => [
                    'lambda' => $lambdaFuncName,
                    'url' => $url,
                    'method' => $method,
                    'headers' => $headers,
                    'body' => $body
                ],
                'response' => [
                    'response' => $result,
                    'headers' => $response_headers
                ]
            ]);
        }

        $result = $this->on_rest_response($http_status_code, $http_status_text, $url, $method, $response_headers, $result, $headers, $body);

        $this->lastRestRequestTimestamp = $this->milliseconds();

        if ($this->enableLastHttpResponse) {
            $this->last_http_response = $result;
        }

        if ($this->enableLastResponseHeaders) {
            $this->last_response_headers = $response_headers;
        }

        $json_response = null;
        $is_json_encoded_response = $this->is_json_encoded_object($result);

        if ($is_json_encoded_response) {
            $json_response = $this->parse_json($result);
            if ($this->enableLastJsonResponse) {
                $this->last_json_response = $json_response;
            }
        }

        if ($this->verbose) {
            print_r(array('Response:', $lambdaFuncName, $method, $url, $http_status_code, $curl_error, $response_headers, $result));
        }
/*
        if ($result === false) {
            if ($curl_errno == 28) { // CURLE_OPERATION_TIMEDOUT
                throw new RequestTimeout(implode(' ', array($url, $method, $curl_errno, $curl_error)));
            }

            // all sorts of SSL problems, accessibility
            throw new ExchangeNotAvailable(implode(' ', array($url, $method, $curl_errno, $curl_error)));
        }
*/
        $this->handle_errors($http_status_code, $http_status_text, $url, $method, $response_headers, $result ? $result : null, $json_response, $headers, $body);
        $this->handle_http_status_code($http_status_code, $http_status_text, $url, $method, $result);

        return isset($json_response) ? $json_response : $result;
    }

    protected function getRandomLambdaFunction()
    {
        $funcsCount = 0;
        foreach ($this->zignalyRateLimitAwsLambdaFuncs as $func) {
            $funcsCount += ($func['max'] - $func['min'] + 1);
        }

        $funcIndex = random_int(0, $funcsCount - 1);
        $lambdaIndex = 0;
        $funcTemplate = '';
        foreach ($this->zignalyRateLimitAwsLambdaFuncs as $func) {
            $funcsInThisChunk = $func['max'] - $func['min'] + 1;
            if ($funcsInThisChunk > $funcIndex) {
                $funcTemplate = $func['functpl'];
                $lambdaIndex = $func['min'] + $funcIndex;
                break;
            }
            $funcIndex -= $funcsInThisChunk;
        }

        return sprintf($funcTemplate, $lambdaIndex);
    }

    private function generateNewTryForBinance($query, $delay, $recvWindow)
    {
        $pairs = explode('&', $query);
        $newPairs = [];
        foreach ($pairs as $i) {
            list($name,$value) = explode('=', $i, 2);
            if ('signature' == $name) {
                continue;
            }

            if ('timestamp' == $name) {
                $value = $this->nonce() + $delay;
            } else if ('recvWindow' == $name) {
                $value = $recvWindow;
            }

            $newPairs[] = $name.'='.$value;
        }

        $newQuery = implode('&', $newPairs);
        $signature = $this->hmac($this->encode($newQuery), $this->encode($this->secret));
        $newQuery .= '&' . 'signature=' . $signature;
        return $newQuery;
    }

    public function fetch($url, $method = 'GET', $headers = null, $body = null)
    {
        /*if (('bitmex' == $this->id || 'ascendex' == $this->id) && 'DELETE' === strtoupper($method)) {
            return parent::fetch($url, $method, $headers, $body);
        }*/

        if (isset($this->zignalyRateLimitAwsLambda) && $this->zignalyRateLimitAwsLambda) {
            return $this->fetchAwsLambda($this->getRandomLambdaFunction(), $url, $method, $headers, $body);
        } else {
            if (isset($this->zignalyRateLimit) && $this->zignalyRateLimit) {
                //$url = $this->zignalyEncodeUrlAndAddApiKey($url, $headers, $this->zignalyRateLimitApiKey);
                if ('binance' != $this->id) {
                    $url = $this->zignalyEncodeUrlAndAddApiKey($url, $headers, $this->zignalyRateLimitApiKey);
                } else {
                    $this->zignalyEncodeUrlAndAddApiKey($url, $headers, $this->zignalyRateLimitApiKey);
                    // ensure response headers are stored
                    $this->enableLastResponseHeaders = true;
                    $this->enableLastHttpResponse = true;
                    // hack ot send a second request to the lambda function
                    // check if body is present
                    $this->setSecondTry($url, $body, $headers, true);
                    try {
                        $response = parent::fetch($url, $method, $headers, $body);
                        return $response;
                    } finally {
                        $responseHeaders = array_change_key_case($this->last_response_headers, CASE_UPPER);
                        if (array_key_exists('ZIGNALY-RETRIES', $responseHeaders)) {
                            $this->zignalyLog('Request retried in lambda', [
                                'request' => [
                                    'url' => $url,
                                    'method' => $method,
                                    'headers' => $headers,
                                    'body' => $body
                                ],
                                'response' => [
                                    'response' => $this->last_http_response,
                                    'headers' => $this->last_response_headers
                                ]
                            ]);
                        }
                    }
                }
            }
            return parent::fetch($url, $method, $headers, $body);
        }
    }
      
    public function throttle($cost = null)
    {
        if (isset($this->zignalyRateLimitAwsLambda) && $this->zignalyRateLimitAwsLambda) {
            // maybe we could remove the original throttle from ccxt if we have so many lambdas
            parent::throttle($cost);
        } else {
            if (isset($this->zignalyRateLimit) && $this->zignalyRateLimit) {
                $this->proxy = $this->zignalyGetProxy();
            } else {
                parent::throttle($cost);
            }
        }
    }

    private function setSecondTry(&$url, &$body, &$headers, $encodeUrl = true)
    {
        $recvWindow1 = $this->zignalyRateLimitFirstRecvWindow ?? 10000;
        $timestampDelay2 = $this->zignalyRateLimitSecondTimestampDelay ?? 9999;
        $recvWindow2 = $this->zignalyRateLimitSecondRecvWindow ?? 20000;
        if ($body != null) {
            // parse body expected application/x-www-form-urlencoded
            $body = $this->generateNewTryForBinance(
                $body,
                0,
                $recvWindow1
            );

            $headers['ZIGNALY_SECOND_BODY'] =
                $this->generateNewTryForBinance(
                    $body,
                    $timestampDelay2,
                    $recvWindow2
                );
        } else {
            // params in the url
            $parts = explode('?', $url);
            $newUrl1 = $url;
            $newUrl2 = $url;
            if (count($parts) > 1) {
                $parts[1] = $this->generateNewTryForBinance(
                    $parts[1],
                    0,
                    $recvWindow1
                );

                $newUrl1 = implode('?', $parts);

                $parts[1] = $this->generateNewTryForBinance(
                    $parts[1],
                    $timestampDelay2,
                    $recvWindow2
                );

                $newUrl2 = implode('?', $parts);
            }
            $url = $encodeUrl ? urlencode($newUrl1) : $newUrl1;
            $headers['ZIGNALY_SECOND_URL'] = $newUrl2;
        }
    }
}
