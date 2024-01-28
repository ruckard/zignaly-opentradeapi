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

if ('cli' === php_sapi_name()) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, "sigHandler");
}

use MongoDB\Client;
use Zignaly\Process\DIContainer;

require_once __DIR__.'/vendor/autoload.php';

require_once __DIR__.'/class/ExchangeOrderAccessor.php';
require_once __DIR__.'/class/ExchangeFillsAccessor.php';

require_once __DIR__.'/config.php';
require_once __DIR__.'/class/Security.php';
$Security = new Security();

require_once __DIR__.'/class/Monolog.php';

require_once __DIR__.'/class/TelegramCodes.php';
require_once __DIR__.'/class/TelegramBot.php';

require_once __DIR__.'/class/Notification.php';
$Notification = new Notification();

require_once __DIR__ . '/class/RabbitMQRedisProxy.php';
require_once __DIR__ . '/class/RabbitMQRedisProxyMessage.php';
if (!isset($excludeRabbit)) {
    $RabbitMQ = new RabbitMQ();
}

if (!isset($excludeMongo)) {
    $mongoDBOptionsOverride = MONGODB_MAIN_OPTIONS;
    // Overrides that instruct read operations preferences that should balance.
    if (!empty($secondaryDBNode)) {
        $mongoDBOptionsOverride['readPreference'] = 'secondaryPreferred';
        $mongoDBOptionsOverride['maxStalenessSeconds'] = 120;
    }

    if (isset($retryServerSelection) && $retryServerSelection) {
        $mongoDBOptionsOverride['serverSelectionTryOnce'] = false;
        $mongoDBOptionsOverride['serverSelectionTimeoutMS'] = 30000;
    }

    if (isset($socketTimeOut) && $socketTimeOut) {
        $mongoDBOptionsOverride['socketTimeoutMS'] = 2400000;
    }

    $mongoConnection = new Client(MONGODB_MAIN_URI, $mongoDBOptionsOverride);
    $mongoDBLink = $mongoConnection->selectDatabase(MONGODB_MAIN_NAME);
}

if (!empty($connectPGSQL)) {
    $pg_host = PGSQL_HOST;
    $pg_dbname = PGSQL_DBNAME;
    $pg_user = PGSQL_USER;
    $pg_password = PGSQL_PASSWORD;
    $pg_connection = pg_connect("host=$pg_host dbname=$pg_dbname user=$pg_user password=$pg_password)");

    /**
     * Check if the given userId is considered active.
     *
     * @param string $userId
     * @return bool
     */
    function checkIfUserIsActive(string $userId): bool
    {
        global $pg_connection;
        $result = pg_query($pg_connection, "SELECT active::int FROM active_users WHERE id_user=$userId");

        $rs = pg_fetch_assoc($result);
        if (!$rs) {
            return false;
        } else {
            return $rs['active'];
        }
    }
}

if (!empty($createSecondaryDBLink)) {
    $mongoDBOptionsOverride = MONGODB_MAIN_OPTIONS;
    $mongoDBOptionsOverride['readPreference'] = 'secondaryPreferred';
    $mongoDBOptionsOverride['maxStalenessSeconds'] = 120;

    $mongoConnectionRO = new Client(MONGODB_MAIN_URI, $mongoDBOptionsOverride);
    $mongoDBLinkRO = $mongoConnectionRO->selectDatabase(MONGODB_MAIN_NAME);
}

require_once __DIR__ . '/class/RedisHandler.php';
require_once __DIR__ . '/class/DepositHistory.php';

require_once __DIR__ . '/class/BinanceStatus.php';
$BinanceStatus = new BinanceStatus();

require_once __DIR__ . '/class/Accounting.php';
$Accounting = new Accounting();

require_once __DIR__.'/class/Exchange.php';
$Exchange = new Exchange();

require_once __DIR__ . '/class/User.php';
$User = new User();

require_once __DIR__.'/class/MarketConversion.php';
$MarketConversion = new MarketConversion();

require_once __DIR__ . '/class/Status.php';
$Status = new Status();

require_once __DIR__ . '/controller/CheckOrdersCCXT.php';
require_once __DIR__ . '/controller/PriceWatcher.php';
require_once __DIR__ . '/controller/ProfileNotifications.php';
require_once __DIR__ . '/controller/ExchangeCalls.php';
require_once __DIR__ . '/controller/PositionCacheGenerator.php';
require_once __DIR__ . '/controller/RedisLockController.php';
require_once __DIR__ . '/controller/RedisTriggersWatcher.php';

require_once __DIR__ . '/class/Position.php';

require_once __DIR__ . '/class/SendGridMailer.php';
$SendGridMailer = new SendGridMailer();

require_once __DIR__ . '/class/Signal.php';
$Signal = new Signal();


require_once __DIR__ . '/class/newUser.php';
$newUser = new newUser();

require_once __DIR__ . '/class/SignalStats.php';
$SignalStats = new SignalStats();

require_once __DIR__ . '/class/CopyTraderStats.php';
$CopyTraderStats = new CopyTraderStats();

require_once __DIR__ . '/class/HistoryDB.php';
require_once __DIR__ . '/class/HistoryDB2.php';
require_once __DIR__ . '/class/PS2DB.php';

require_once __DIR__ . '/class/ProfitSharingBalance.php';

// Initialize Dependency Injector Container.
$container = DIContainer::init();
require_once __DIR__ . '/class/Provider.php';
$Provider = new Provider();

require_once __DIR__ . '/class/newPositionCCXT.php';
$newPositionCCXT = new newPositionCCXT();
require_once __DIR__ . '/class/Order.php';
require_once __DIR__ . '/class/TradingFeeTracker.php';

$Position = $container->get('position.model');

require_once __DIR__ . '/class/WorkerRestart.php';
$WorkerRestart = new WorkerRestart();

require_once __DIR__ . '/controller/RestartWorker.php';
$RestartWorker = new RestartWorker();

require_once __DIR__ . '/class/TradeApiClient.php';
require_once __DIR__ . '/class/Transactions.php';
require_once __DIR__ . '/class/CashBackPayments.php';
require_once __DIR__ . '/class/Formula.php';
require_once __DIR__ . '/class/InternalTransfer.php';
require_once __DIR__ . '/class/Analytics.php';
require_once __DIR__ . '/class/PostBE.php';
require_once __DIR__ . '/controller/SendAnalyticsEvents.php';
require_once __DIR__ . '/controller/ZIGHelper.php';
require_once __DIR__ . '/class/GlobalBlackList.php';
require_once __DIR__ . '/controller/ExitPosition.php';

$continueLoop = true;

/**
 * Avoid the supervisor daemon to kill the process in the middle of the execution.
 * @param int $signo
 */
function sigHandler(int $signo)
{
    global $continueLoop;

    if (SIGTERM === $signo) {
        $continueLoop = false;
    }
}
