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


$generalBinanceKey = '';
$generalBinanceSecret = '';

$generalBinanceKey2 = '';
$generalBinanceSecret2 = '';

define('MONGODB_MAIN_NAME', 'zignaly');
define('MONGODB_MAIN_HOSTS', []);
define('MONGODB_MAIN_URI', 'mongodb://'.implode(',', MONGODB_MAIN_HOSTS).'/');
define('MONGODB_MAIN_OPTIONS', [
    // Options.
]);

define('MONGODB_HISTORY_NAME', 'zignaly-history');
define('MONGODB_HISTORY_HOSTS', []);
define('MONGODB_HISTORY_URI', 'mongodb://'.implode(',', MONGODB_HISTORY_HOSTS).'/');
define('MONGODB_HISTORY_OPTIONS', [
    // Options.
]);

define('MONGODB_LOG_NAME', 'zignaly-logging');
define('MONGODB_LOG_HOSTS', []);
define('MONGODB_LOG_URI', 'mongodb://'.implode(',', MONGODB_LOG_HOSTS).'/');
define('MONGODB_LOG_OPTIONS', [
    // Options.
]);

define('PGSQL_HOST', '');
define('PGSQL_DBNAME', '');
define('PGSQL_USER', '');
define('PGSQL_PASSWORD', '');

define('RABBIT_USER', '');
define('RABBIT_PASS', '');
define('RABBIT_HOST', '');
define('RABBIT_PORT', '');
define('RABBIT_VHOST', '');

define('REDIS_HOST1_ZO', '');
define('REDIS_HOST2_ZO', '');
define('REDIS_PORT_ZO', '');
define('REDIS_PASS_ZO', '');
define('REDIS_HOST1_ZLP', '');
define('REDIS_HOST2_ZLP', '');
define('REDIS_PORT_ZLP', '');
define('REDIS_PASS_ZLP', '');
define('REDIS_HOST1_ZUS', '');
define('REDIS_HOST2_ZUS', '');
define('REDIS_PORT_ZUS', '');
define('REDIS_PASS_ZUS', '');
define('REDIS_HOST1_ZLP_BITMEX', '');
define('REDIS_HOST2_ZLP_BITMEX', '');
define('REDIS_PORT_ZLP_BITMEX', '');
define('REDIS_PASS_ZLP_BITMEX', '');
define('REDIS_HOST1_ZQ', '');
define('REDIS_HOST2_ZQ', '');
define('REDIS_PORT_ZQ', '');
define('REDIS_PASS_ZQ', '');
define('REDIS_HOST1_ZQT', '');
define('REDIS_HOST2_ZQT', '');
define('REDIS_PORT_ZQT', '');
define('REDIS_PASS_ZQT', '');
define('REDIS_HOST1_ZD', '');
define('REDIS_HOST2_ZD', '');
define('REDIS_PORT_ZD', '');
define('REDIS_PASS_ZD', '');
define('REDIS_HOST1_ZL3DP', '');
define('REDIS_HOST2_ZL3DP', '');
define('REDIS_PORT_ZL3DP', '');
define('REDIS_PASS_ZL3DP', '');
define('REDIS_HOST1_ZPC', '');
define('REDIS_HOST2_ZPC', '');
define('REDIS_PORT_ZPC', '');
define('REDIS_PASS_ZPC', '');
define('REDIS_HOST1_RTW', '');
define('REDIS_HOST2_RTW', '');
define('REDIS_PORT_RTW', '');
define('REDIS_PASS_RTW', '');
define('REDIS_HOST1_ZL', '');
define('REDIS_HOST2_ZL', '');
define('REDIS_PORT_ZL', '');
define('REDIS_PASS_ZL', '');

define('MASTER_KEY', '');
define('SUPPORT_KEY', '');
define('SUPPORT_KEY', '');
define('SUPPORT_2FA_SECRET', '');
define('DASHLY_SECRET', '');
define('BINANCE_ID', '');
define('STATCOINMARKET_PROVIDER_ID', '');
define('STATCOINMARKET2_PROVIDER_ID', '');
define('CRYPTODAMUS_PROVIDER_ID', '');
define('CRYPTODAMUS2_PROVIDER_ID', '');
define('CRYPTOBASESCANNER_PROVIDER_ID', '');
define('MININGHAMSTER_PROVIDER_ID', '');
define('MININGHAMSTER_PROVIDER_KEY', '');
define('MININGHAMSTER_PROVIDER_BOTKEY', '');
define('CREATE_PROVIDER_ID', '');
define('CRYPTOQUALITYSIGNALS_PROVIDER_ID', '');
define('CRYPTOQUALITYSIGNALS_PROVIDER_KEY', '');
define('CRYPTOQUALITYSIGNALSFREE_PROVIDER_ID', '');
define('PALMBEACHSIGNALS_PROVIDER_ID', '');

define('GMAIL_OAUTH_CLIENTID', '');
define('GMAIL_OAUTH_SECRET', '');
define('GMAIL_REFRESH_TOKEN', '');
define('NOTIFY_EMAIL', '');
define('SENDGRID_API_KEY', '');

define('STRIPE_SECRET_KEY_TEST', '');
define('STRIPE_PUB_KEY_TEST', '');
define('STRIPE_SECRET_KEY_PROD', '');
define('STRIPE_PUB_KEY_PROD', '');
define('STRIPE_SECRET_KEY', STRIPE_SECRET_KEY_PROD);
define('STRIPE_PUB_KEY', STRIPE_PUB_KEY_PROD);

define('FIRSTPROMOTER_WID', '');
define('FIRSTPROMOTER_API_KEY', '');
define('FIRSTPROMOTER_WID_CT', '');
define('FIRSTPROMOTER_API_KEY_CT', '');

define('TELEGRAM_WEBHOOK_KEY', '');
define('TELEGRAM_TOKEN', '');

define('FEAPI_URL', '');


define('PEM_FILE', '');

define('COINRAY_KEY', '');
define('COINRAY_SECRET', '');

define('IMAGEKIT_PUB', '=');
define('IMAGEKIT_PRI', '');

/*
 * How to create the pub/key pem files:
 * $ openssl genrsa -out key.pem 1024
 * $ openssl rsa -in key.pem -text -noout
 * $ openssl rsa -in key.pem -pubout -out pub.pem
 * $ openssl rsa -in pub.pem -pubin -text -noout
 */

$ccxtExchangesGlobalConfig = array(
    "common" => array(
        'timeout' => 30000,
        'enableRateLimit' => true,
        'adjustForTimeDifference' => true,
    ),
    "exchanges" => array(
        "kucoin" => array(
            "partnerid" => null,
            "partnerkey" => null
        )
    )
);

$sqlLastTradesWriteConfig = array(
);

// On local use, reCaptcha test keys:
// https://developers.google.com/recaptcha/docs/faq

$reCaptchaCredentials = [
    'sitekey' => '',
    'secret' => ''
];

$ccxtExchangesGlobalConfig = [
];

define('DEV_TEAM_SECRET_KEY', "0abcde-1fabcd-2efabc-3defga-4bcdef");
define('TRUSTED_IPS', '');
define('TRUSTED_USER_AGENTS', '');
define('RECAPTCHA_NOTIFICATIONS_USERNAME', '');
define('RECAPTCHA_NOTIFICATIONS_CHANNEL', '');

define('BINANCE_MOCKSERVER', 'https://mockserver:1080');
define('JWT_SECRET', 'a123b456c789d');
define('ITAPI_URL', '');

define('CASH_BACK_PROGRAM_SUBACCOUNT_ID_FUTURES', '');
define('CASH_BACK_PROGRAM_SUBACCOUNT_ID_SPOT', '');

define('SEGMENT_KEY', '');
define('NEW_API_URL', '');

define('CLOUDFLARE_API_URL', 'https://test.example.net');
define('CLOUDFLARE_API_PATH', '/cf-worker/push-kv/');
define('CLOUDFLARE_INTERNAL_SECRET', 'a123b456c789d1234d);

define('MYSQL_TRADES_SERVER', '');
define('MYSQL_TRADES_PORT', '3306');
define('MYSQL_TRADES_USER', '');
define('MYSQL_TRADES_PASSWORD', '');
define('MYSQL_TRADES_DATABASE', '');
define('SQL_DSN', 'mysql:dbname='.MYSQL_TRADES_DATABASE.';port='.MYSQL_TRADES_PORT.';host='.MYSQL_TRADES_SERVER);

define('MYSQL_PS2_SERVER', '');
define('MYSQL_PS2_PORT', '3306');
define('MYSQL_PS2_USER', '');
define('MYSQL_PS2_PASSWORD', '');
define('MYSQL_PS2_DATABASE', '');
define('SQL_PS2_DSN', 'mysql:dbname='.MYSQL_PS2_DATABASE.';port='.MYSQL_PS2_PORT.';host='.MYSQL_PS2_SERVER);


$WallBlackList = <<<EOF

EOF;

define('WALL_BLACKLIST_WORDS', $WallBlackList);
