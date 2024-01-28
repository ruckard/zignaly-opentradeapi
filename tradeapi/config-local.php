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
define('MONGODB_MAIN_HOSTS', ['mongodb:27017']);
define('MONGODB_MAIN_URI', 'mongodb://'.implode(',', MONGODB_MAIN_HOSTS).'/');
define('MONGODB_MAIN_OPTIONS', [
    'username' => 'guest',
    'password' => 'guest',
    //'replicaSet' => 'zignaly',
    'authSource' => 'admin',
    'w' => 1,
]);

define('MONGODB_HISTORY_NAME', 'zignaly-history');
define('MONGODB_HISTORY_HOSTS', ['mongodb:27017']);
define('MONGODB_HISTORY_URI', 'mongodb://'.implode(',', MONGODB_HISTORY_HOSTS).'/');
define('MONGODB_HISTORY_OPTIONS', [
    'username' => 'guest',
    'password' => 'guest',
    'authSource' => 'admin',
    'w' => 1,
]);
define('MONGODB_HISTORY_OPTIONS2', [
    'username' => 'guest',
    'password' => 'guest',
    'authSource' => 'admin',
    'w' => 1,
]);

define('MONGODB_LOG_NAME', 'zignaly-logging');
define('MONGODB_LOG_HOSTS', ['mongodb:27017']);
define('MONGODB_LOG_URI', 'mongodb://'.implode(',', MONGODB_LOG_HOSTS).'/');
define('MONGODB_LOG_OPTIONS', [
    'username' => 'guest',
    'password' => 'guest',
    'authSource' => 'admin',
    'w' => 1,
]);

define('RABBIT_USER', 'admin');
define('RABBIT_PASS', 'admin2020');
define('RABBIT_HOST', 'rabbit');
define('RABBIT_PORT', '5672');
define('RABBIT_VHOST', '/');

define('REDIS_HOST1_ZO', 'redis');
define('REDIS_HOST2_ZO', 'redis');
define('REDIS_PORT_ZO', '6379');
define('REDIS_PASS_ZO', '');
define('REDIS_HOST1_ZLP', 'redis');
define('REDIS_HOST2_ZLP', 'redis');
define('REDIS_PORT_ZLP', '6379');
define('REDIS_PASS_ZLP', '');
define('REDIS_HOST1_ZUS', 'redis');
define('REDIS_HOST2_ZUS', 'redis');
define('REDIS_PORT_ZUS', '6379');
define('REDIS_PASS_ZUS', '');
define('REDIS_HOST1_ZD', 'redis');
define('REDIS_HOST2_ZD', 'redis');
define('REDIS_PORT_ZD', '6379');
define('REDIS_PASS_ZD', '');
define('REDIS_HOST1_ZL3DP', 'redis');
define('REDIS_HOST2_ZL3DP', 'redis');
define('REDIS_PORT_ZL3DP', '6379');
define('REDIS_PASS_ZL3DP', '');
define('REDIS_HOST1_ZPC', 'redis');
define('REDIS_HOST2_ZPC', 'redis');
define('REDIS_PORT_ZPC', '6379');
define('REDIS_PASS_ZPC', '');
define('REDIS_HOST1_ZOPC', 'redis');
define('REDIS_HOST2_ZOPC', 'redis');
define('REDIS_PORT_ZOPC', '6379');
define('REDIS_PASS_ZOPC', '');
define('REDIS_HOST1_ZPU', 'redis');
define('REDIS_HOST2_ZPU', 'redis');
define('REDIS_PORT_ZPU', '6379');
define('REDIS_PASS_ZPU', '');
define('REDIS_HOST1_ZQ', 'redis');
define('REDIS_HOST2_ZQ', 'redis');
define('REDIS_PORT_ZQ', '6379');
define('REDIS_PASS_ZQ', '');
define('REDIS_HOST1_ZQT', 'redis');
define('REDIS_HOST2_ZQT', 'redis');
define('REDIS_PORT_ZQT', '6379');
define('REDIS_PASS_ZQT', '');

define('REDIS_HOST1_RTW', 'redis');
define('REDIS_HOST2_RTW', 'redis');
define('REDIS_PORT_RTW', '6379');
define('REDIS_PASS_RTW', '');
define('REDIS_HOST1_ASU', 'redis');
define('REDIS_HOST2_ASU', 'redis');
define('REDIS_PORT_ASU', '6379');
define('REDIS_PASS_ASU', '');
define('REDIS_HOST1_CP', 'redis');
define('REDIS_HOST2_CP', 'redis');
define('REDIS_PORT_CP', '6379');
define('REDIS_PASS_CP', '');
define('REDIS_HOST1_ZL', 'redis');
define('REDIS_HOST2_ZL', 'redis');
define('REDIS_PORT_ZL', '6379');
define('REDIS_PASS_ZL', '');

define('MASTER_KEY', 'mastercadabra');
define('MASTER_2FA_SECRET', 'a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a123=');
define('SUPPORT_KEY', 'supportcadabra');
define('SUPPORT_2FA_SECRET', 'b1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a1234BCDE56789a123=');
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
define('CREATE_PROVIDER_ID', 'a123b456789c12345d1234e1');
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

define('FEAPI_URL', 'http://api.zignaly.lndo.site/fe/api.php');


define('PEM_FILE', __DIR__.'/../zignaly_');

define('COINRAY_KEY', '');
define('COINRAY_SECRET', '');

define('IMAGEKIT_PUB', '=');
define('IMAGEKIT_PRI', '/1ksV1xZXtrloA=');

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
    'host' => 'postgres',
    'port' => 5432,
    'dbname' => 'zignaly',
    'schema' => 'last_trades',
    'user' => 'postgres',
    'password' => 'postgresloc',
);

// On local use, reCaptcha test keys:
// https://developers.google.com/recaptcha/docs/faq
$reCaptchaCredentials = [
    'sitekey' => '6LeIxAca123456789b123456789c123456789d12',
    'secret' => '6LeIxAca123456789b123456789c123456789d13',
];

$ccxtExchangesGlobalConfig = [
    "common" => [
        'timeout' => 30000,
        'enableRateLimit' => true,
        'adjustForTimeDifference' => true,
    ],
    "exchanges" => [
        "kucoin" => [
            "partnerid" => 'Zignaly',
            "partnerkey" => '2abc1345-abc4-5678-d123-e123456789e1'
        ]
    ]
];

define('DEV_TEAM_SECRET_KEY', "0abcde-1fabcd-e12345-f12345-a12345");
define('TRUSTED_IPS', '');
define('TRUSTED_USER_AGENTS', '');
define('RECAPTCHA_NOTIFICATIONS_USERNAME', '');
define('RECAPTCHA_NOTIFICATIONS_CHANNEL', '');

define('BINANCE_MOCKSERVER', 'https://mockserver:1080');
define('JWT_SECRET', 'b1234abcde12345A');
define('ITAPI_URL', '');

define('CASH_BACK_PROGRAM_SUBACCOUNT_ID_FUTURES', '');
define('CASH_BACK_PROGRAM_SUBACCOUNT_ID_SPOT', '');

define('SEGMENT_KEY', '');
define('NEW_API_URL', '');

define('CLOUDFLARE_API_URL', 'https://test.example.net');
define('CLOUDFLARE_API_PATH', '/cf-worker/push-kv/');
define('CLOUDFLARE_INTERNAL_SECRET', 'j6abcde12345abcde12345abcdefg123');

define('MYSQL_TRADES_SERVER', '');
define('MYSQL_TRADES_PORT', '3306');
define('MYSQL_TRADES_USER', '');
define('MYSQL_TRADES_PASSWORD', '');
define('MYSQL_TRADES_DATABASE', '');

define('SQL_DSN', 'mysql:dbname='.MYSQL_TRADES_DATABASE.';port='.MYSQL_TRADES_PORT.';host='.MYSQL_TRADES_SERVER);

$WallBlackList = <<<EOF
3commas
aivia
aluna
atani
avatrade
bit-copy
coinmatics
coinmetro
copyme
cryptohopper
etoro
haasonline
naga
octafx
roboforex
share4you
tradelize
tradesanta
upbots
wunderbit
yanda
zulutrade
EOF;

define('WALL_BLACKLIST_WORDS', $WallBlackList);

$zigrefPartners = [
    [
        'url' => 'https://example.net/testRefURL',
        'refCode' => 'TestRefURL',
        'name' => 'TestRef',
    ]
];
define('ZIGREF_PARTNERS', $zigrefPartners);
define('ZIGREF_DEFAULT_AMOUNT', 10);
define('ZIGREF_DEFAULT_ASSET', 'USDT');
define('ZIGREF_DEFAULT_REWARD_ASSET', 'ZIG');
define('ZIG_CONVERSION_URL', 'https://example.net/new_api/zig/convert-preview');
define('ZIG_BALANCE_API_URL', '');
define('ZIG_TRANSFER_URL', '');
define('ZIG_TRANSFER_API_CODE', '');
define('ZIG_TRANSFER_SECRET', '');
define('ZIG_REWARD_VAULT_USER_ID', '');
define('ZIG_REWARD_VAULT_BASKET_SUB_ACCOUNT_ID', '');
define('ZIG_SUCCESS_FEE_USER_ID', '');
define('ZIG_SUCCESS_FEE_BASKET_SUB_ACCOUNT_ID', '');
define('ZIG_REWARD_DEPOSIT_REWARD', '');
define('ZIG_REWARD_DEPOSIT_MIN', '');
define('ZIG_REWARD_LIFETIME_COMMISSION', '');
define('ZIG_TRADING_FEE_CASHBACK_DISCOUNT', '');







