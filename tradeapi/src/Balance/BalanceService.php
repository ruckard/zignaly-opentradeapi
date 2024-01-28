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


namespace Zignaly\Balance;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;
use Zignaly\exchange\ZignalyExchangeCodes;
use Zignaly\Prices\PriceConversionException;
use Zignaly\Prices\PriceConverterService;
use Zignaly\Positions\PositionsService;
use Zignaly\utils\NumberFormatUtils;

/**
 * Class BalanceService
 * @package Zignaly\Balance
 */
class BalanceService
{
    /**
     * @var \ExchangeCalls
     */
    private $exchangeCalls;

    /**
     * @var \newUser
     */
    private $userModel;

    /**
     * @var DailyBalance
     */
    private $dailyBalanceModel;

    /**
     * @var PositionsService
     */
    private $positionsService;

    /**
     * @var PriceConverterService
     */
    private $priceConverterService;

    /***
     * BalanceService constructor.
     * @param \ExchangeCalls $exchangeCalls
     * @param \newUser $newUser
     * @param DailyBalance $dailyBalanceModel
     * @param PositionsService $positionsService
     * @param PriceConverterService $priceConverterService
     */
    public function __construct(
        \ExchangeCalls $exchangeCalls,
        \newUser $newUser,
        DailyBalance $dailyBalanceModel,
        PositionsService $positionsService,
        PriceConverterService $priceConverterService
    ) {
        $this->exchangeCalls = $exchangeCalls;
        $this->userModel = $newUser;
        $this->dailyBalanceModel = $dailyBalanceModel;
        $this->positionsService = $positionsService;
        $this->priceConverterService = $priceConverterService;
    }

    /**
     * Get the balance from the exchange directly.
     *
     * @param BSONDocument $user
     * @param string $internalExchangeId
     * @return array
     */
    public function updateBalance(BSONDocument $user, string $internalExchangeId): array
    {
        $exchange = $this->findUserExchange($user, $internalExchangeId);
        $error = null;
        $data = null;
        if ($exchange) {
            if (!$this->isUserExchangeActivated($exchange)) {
                return [];
            }

            $isTestnet = $exchange->isTestnet ?? false;

            if ($this->exchangeCalls->setCurrentExchange(
                $exchange->name,
                $this->getExchangeType($exchange),
                $isTestnet
            )) {
                $balanceData = $this->exchangeCalls->getBalance($user, $internalExchangeId, 'all', 'all', true);

                if ($balanceData && \is_array($balanceData)) {
                    try {
                        $exchangeType = $this->getExchangeType($exchange);
                        $data = $this->parseBalanceData($balanceData, $exchange);
                        $data['exchangeInternalId'] = $internalExchangeId;
                        $data['isTestnet'] = $isTestnet;
                        $data['isPaperTrading'] = !empty($exchange->paperTrading);
                        $data['exchangeType'] = $exchangeType;
                        $data['lastUpdateAt'] = new UTCDateTime();

                        if ('futures' === $exchangeType) {
                            //Calculate daily profit
                            $pnlUSD = $this->calculateDailyProfitAndLoss($user->_id, $internalExchangeId);
                            //ToDo: How we get transfers from futures look discontinued. We need to see if we really need this.
                            /*$netTransfer = ZignalyExchangeCodes::isBitmex($exchange->name)
                                ? 0.0 : $this->calculateDailyNetTransfer();*/
                            $netTransfer = 0.0;
                            $data['total']['pnlUSD'] = (float)($pnlUSD);
                            $data['total']['netTransfer'] = (float)($netTransfer);
                        }

                        $this->dailyBalanceModel->updateDailyBalanceForUserFromExchange(
                            $user->_id,
                            $internalExchangeId,
                            $data
                        );

                        if (empty($exchange->balanceSynced)) {
                            $this->userModel->updateInternalExchangeBalanceSynced(
                                $user->_id,
                                $internalExchangeId,
                                true
                            );
                        }
                    } catch (\Exception $exception) {
                        $error = $exception->getMessage();
                    }
                } else {
                    $error = 'Error updating balance';
                }
            } else {
                $error = 'Can\'t connect to exchange';
            }
        } else {
            $error = 'Exchange not found';
        }

        if ($error) {
            throw new BalanceUpdateException($error);
        }

        return $data;
    }

    /**
     * @param BSONDocument $exchange
     * @param \ExchangeCalls $exchangeCalls
     * @return array
     */
    public function getExchangeAssets(BSONDocument $exchange, \ExchangeCalls $exchangeCalls): array
    {
        $result = $exchangeCalls->getUserTransactionInfo();
        if (\is_array($result) && isset($result['error'])) {
            return $result;
        }

        $lastCustomBnbBtcPrice = $this->priceConverterService->getLastPrice($exchange, 'BTC', 'BNB');

        $ret = [];
        /** @var array $coinsBalance */
        $coinsBalance = $exchangeCalls->getAllPairsBalance();
        foreach ($result->getCoins() as $coin) {
            $networks = [];
            // Get Networks
            foreach ($result->getCoinNetworksForCoin($coin) as $networkInfo) {
                $network = [];
                $network['name'] = $networkInfo->getName();
                $network['network'] = $networkInfo->getNetwork();
                $network['coin'] = $networkInfo->getCoin();
                $network['addressRegex'] = $networkInfo->getAddressRegex();
                $network['depositDesc'] = $networkInfo->getDepositDesc();
                $network['depositEnable'] = $networkInfo->isDepositEnabled();
                $network['isDefault'] = $networkInfo->isDefault();
                $network['memoRegex'] = $networkInfo->getMemoRegEx();
                $network['resetAddressStatus'] = $networkInfo->isResetAddressStatus();
                $network['specialTips'] = $networkInfo->getSpecialTips();
                $network['withdrawDesc'] = $networkInfo->getWithdrawDesc();
                $network['withdrawEnable'] = $networkInfo->isWithdrawEnabled();
                $network['withdrawFee'] = $networkInfo->getWithdrawFee();
                $network['withdrawMin'] = $networkInfo->getWithdrawMin();
                $network['integerMultiple'] = $networkInfo->getWithdrawIntegerMultiple();

                $networks[] = $network;
            }

            // Get asset balances
            $balanceFree = 0.0;
            $balanceLocked = 0.0;
            $balanceTotal = 0.0;
            $balanceFreeBTC = 0.0;
            $balanceFreeUSDT = 0.0;
            $balanceLockedBTC = 0.0;
            $balanceLockedUSDT = 0.0;
            $maxWithdraw = 0.0;

            if (\is_array($coinsBalance) && isset($coinsBalance[$coin])) {
                $balanceFree = $coinsBalance[$coin]['free'];
                $balanceLocked = $coinsBalance[$coin]['used'];
                $balanceTotal = $coinsBalance[$coin]['total'];
                $maxWithdraw = $balanceFree;
                if (isset($coinsBalance[$coin]['max_withdraw_amount'])) {
                    $maxWithdraw = $coinsBalance[$coin]['max_withdraw_amount'];
                }
            }

            // Get BTC Balances
            try {
                $freeConverted = $this->priceConverterService->convert($exchange, $coin, (float)$balanceFree);
                $balanceFreeBTC = $freeConverted->amountInBTC;
                $balanceFreeUSDT = $freeConverted->amountInUsdt;

                $lockedConverted = $this->priceConverterService->convert($exchange, $coin, (float)$balanceLocked);
                $balanceLockedBTC = $lockedConverted->amountInBTC;
                $balanceLockedUSDT = $lockedConverted->amountInUsdt;
            } catch (\Exception $exception) {
            }

            $balanceTotalBTC = $balanceFreeBTC+$balanceLockedBTC;
            $balanceTotalUSDT = $balanceFreeUSDT+$balanceLockedUSDT;

            $balanceTotalCustom = $lastCustomBnbBtcPrice? $balanceTotalBTC / $lastCustomBnbBtcPrice: 0.0;

            $ret[$coin] = [
                'name' => $result->getNameForCoin($coin),
                'balanceFree' => NumberFormatUtils::formatPriceZignalyPrecision($balanceFree),
                'balanceLocked' => NumberFormatUtils::formatPriceZignalyPrecision($balanceLocked),
                'balanceTotal' => NumberFormatUtils::formatPriceZignalyPrecision($balanceTotal),
                'balanceFreeBTC' => NumberFormatUtils::formatPriceZignalyPrecision($balanceFreeBTC),
                'balanceLockedBTC' => NumberFormatUtils::formatPriceZignalyPrecision($balanceLockedBTC),
                'balanceTotalBTC' => NumberFormatUtils::formatPriceZignalyPrecision($balanceTotalBTC),
                'balanceFreeUSDT' => NumberFormatUtils::formatPriceZignalyPrecision($balanceFreeUSDT),
                'balanceLockedUSDT' => NumberFormatUtils::formatPriceZignalyPrecision($balanceLockedUSDT),
                'balanceTotalUSDT' => NumberFormatUtils::formatPriceZignalyPrecision($balanceTotalUSDT),
                'balanceTotalExchCoin' => NumberFormatUtils::formatPriceZignalyPrecision($balanceTotalCustom),
                'maxWithdrawAmount' => NumberFormatUtils::formatPriceZignalyPrecision($maxWithdraw),
                'exchCoin' => 'BNB',
                'networks' => $networks,
            ];
        }

        return $ret;
    }

    /**
     * @param BSONDocument $user
     * @param string $internalExchangeId
     * @return BSONDocument|null
     */
    private function findUserExchange(BSONDocument $user, string $internalExchangeId): ?BSONDocument
    {
        $found = null;

        if ($user->exchanges) {
            foreach ($user->exchanges as $exchange) {
                if ($exchange->internalId === $internalExchangeId) {
                    $found = $exchange;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @param array $balanceData
     * @param BSONDocument $exchange
     * @return array
     */
    private function parseBalanceData(array $balanceData, BSONDocument $exchange): array
    {
        $exchangeAccountType = $this->getExchangeType($exchange);

        $fields = ['free', 'locked', 'total'];
        if ('futures' === $exchangeAccountType) {
            $fields = array_merge($fields, ['current_margin', 'wallet', 'margin', 'unrealized_profit']);
        }

        $btcs = array_fill_keys($fields, 0);
        $usds = array_fill_keys($fields, 0);

        $parsedData = ['total' => []];

        $this->priceConverterService->flushCaches();

        foreach ($balanceData as $coin => $data) {
            try {
                $total = $data['total'] ?? 0;
                if (!empty($total)) {
                    $parsedData[$coin] = [
                        'asset' => $coin
                    ];

                    foreach ($fields as $field) {
                        $precision = 'free' === $field ? 8 : 12;
                        if ('locked' === $field) {
                            $free = round($data['free'], 8);
                            $value = $total > 0 ? $total - $free : $data['used'];
                            $value = round($value, $precision);
                        } else {
                            $value = round($data[$field] ?? 0, $precision);
                        }

                        $conversion = $this->priceConverterService->convert($exchange, $coin, $value);
                        $btcs[$field] += $conversion->amountInBTC;
                        $usds[$field] += $conversion->amountInUsdt;

                        if ('total' === $field) {
                            $parsedData[$coin]['totalBTC'] = (float)($conversion->amountInBTC);
                            $parsedData[$coin]['totalUSD'] = (float)($conversion->amountInUsdt);
                        }

                        $fieldName = $this->toCamelCase($field);
                        $parsedData[$coin][$fieldName] = (float)($value);
                    }
                }
            } catch (PriceConversionException $exception) {
                //Ignore by now
            }
        }

        foreach ($fields as $field) {
            $fieldName = $this->toCamelCase($field);
            $parsedData['total']["{$fieldName}BTC"] = (float)($btcs[$field] ?? 0.0);
            $parsedData['total']["{$fieldName}USD"] = (float)($usds[$field] ?? 0.0);
        }

        return $parsedData;
    }

    /**
     * @param $exchange
     * @return string
     */
    private function getExchangeType($exchange): string
    {
        return empty($exchange->exchangeType) ? 'spot' : strtolower($exchange->exchangeType);
    }

    /**
     * @param $str
     * @return string
     */
    private function toCamelCase($str): string
    {
        return preg_replace_callback(
            '/_([a-z])/',
            static function ($m) {
                return strtoupper($m[1]);
            },
            $str
        );
    }

    /**
     * @param $userId
     * @param $exchangeId
     * @return float
     * @throws \Exception
     */
    private function calculateDailyProfitAndLoss($userId, $exchangeId): float
    {
        return $this->positionsService->getProfitFromDate($userId, $exchangeId);
    }

    private function isUserExchangeActivated(BSONDocument $exchange): bool
    {
        return empty($exchange->isBrokerAccount) || !empty($exchange->subAccountId);
    }

    /**
     * @return float
     */
    private function calculateDailyNetTransfer(): float
    {
        $from = strtotime('today midnight')*1000;
        $transfers = $this->exchangeCalls->getFuturesTransfer($from);

        $result = 0.0;
        foreach ($transfers as $transfer) {
            $amount = $transfer->getAmount();

            if ($transfer->isDeposit()) {
                $result += abs($amount);
            } else {
                $result -= abs($amount);
            }
        }

        return $result;
    }
}
