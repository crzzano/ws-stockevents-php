<?php
require_once 'vendor/autoload.php';

use PPFinances\Wealthsimple\Exceptions\LoginFailedException;
use PPFinances\Wealthsimple\Exceptions\OTPRequiredException;
use PPFinances\Wealthsimple\Sessions\WSAPISession;
use PPFinances\Wealthsimple\WealthsimpleAPI;

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$ws_accounts = !empty($_ENV['WEALTHSIMPLE_ACCOUNTS']) ? explode(",",$_ENV['WEALTHSIMPLE_ACCOUNTS']) : [];
$ws_tx_types = explode(",",$_ENV['WEALTHSIMPLE_TX_TYPES'] ?? "DIY_BUY,DIY_SELL");
$ws_activity_limit = $_ENV['WEALTHSMPLE_ACTIVITY_LIMIT'] ?? 250;

// 1. Define a function that will be called when the session is created or updated. Persist the session to a safe place
$persist_session_fct = function (WSAPISession $session) {
    $json = json_encode($session);
    // @TODO Save $json somewhere safe; it contains tokens that can be used to empty your Wealthsimple account, so treat it with respect!
    // i.e. don't store it in a Git repository, or anywhere it can be accessed by others!
    // If you are running this on your own workstation, only you have access, and your drive is encrypted, it's OK to save it to a file:
    file_put_contents(__DIR__ . '/session.json', $json);
};

// 2. If it's the first time you run this, create a new session using the username & password (and TOTP answer, if needed). Do NOT save those infos in your code!
if (!file_exists(__DIR__ . '/session.json')) {
    $totp_code = null;
        try {
            if (empty($username)) {
                $username = $_ENV['WEALTHSIMPLE_EMAIL'] ?? readline("Wealthsimple email: ");
            }
            if (empty($password)) {
                $password = $_ENV['WEALTHSIMPLE_PASSWORD'] ?? readline("Wealthsimple password: ");
            }
            $totp_code = readline("2FA App OTP Code: ");
            if($username && $password && $totp_code) {
                WealthsimpleAPI::login($username, $password, $totp_code, $persist_session_fct, 'trade.read');
            }
            // The above will throw exceptions if login failed
        } catch (OTPRequiredException $e) {
            die("2FA code required. Please run this script again!\n");
        } catch (LoginFailedException $e) {
            die("Login failed. Check your credentials.");
        }
}

// 3. Load the session object, and use it to instantiate the API object
$session = json_decode(file_get_contents(__DIR__ . '/session.json'));
$ws = WealthsimpleAPI::fromToken($session, $persist_session_fct);
// $persist_session_fct is needed here too, because the session may be updated if the access token expired, and thus this function will be called to save the new session

// if no account numbers provided in .env, request one
if(!$ws_accounts || count($ws_accounts) == 0) {
    $ws_accounts = [readline("Wealthsimple Account #: ")];
}

// Optionally define functions to cache market data, if you want transactions' descriptions and accounts balances to show the security's symbol instead of its ID
// eg. sec-s-e7947deb977341ff9f0ddcf13703e9a6 => TSX:XEQT
$sec_info_getter_fn = function (string $ws_security_id) {
    if ($market_data = @file_get_contents(sys_get_temp_dir() . "/ws-api-$ws_security_id.json")) {
        return json_decode($market_data);
    }
    return NULL;
};
$sec_info_setter_fn = function (string $ws_security_id, object $market_data) {
    file_put_contents(sys_get_temp_dir() . "/ws-api-$ws_security_id.json", json_encode($market_data));
    return $market_data;
};
$ws->setSecurityMarketDataCache($sec_info_getter_fn, $sec_info_setter_fn);

// 4. Use the API object to access your WS accounts
$accounts = $ws->getAccounts();

foreach ($accounts as $account) {
    if(!in_array($account->number,$ws_accounts)) {
        continue;
    }

    echo "Account: $account->description ($account->number)\n";
    if ($account->description === $account->unifiedAccountType) {
        // This is an "unknown" account, for which description is generic; please open an issue on https://github.com/gboudreau/ws-api-php/issues and include the following:
        echo "    Unknown account: " . json_encode($account) . "\n";
    }

    if ($account->currency === 'CAD') {
        $value = $account->financials->currentCombined->netLiquidationValue->amount;
        echo "  Net worth: $value $account->currency\n";
    }
    // Note: for USD accounts, $value is just the CAD value converted in USD, so it's not the real value of the account.
    // For USD accounts, only the balance & positions (below) are relevant.

    // Cash and positions balances
    $balances = $ws->getAccountBalances($account->id);
    $cash_balance = (float)$balances[$account->currency === 'USD' ? 'sec-c-usd' : 'sec-c-cad'] ?? 0;
    echo "  Available (cash) balance: $cash_balance $account->currency\n";
    if (count($balances) > 1) {
        echo "  Other positions:\n";
        foreach ($balances as $security => $bal) {
            if ($security === 'sec-c-cad' || $security === 'sec-c-usd') {
                continue;
            }
            echo "  - $security x $bal\n";
        }
    }

    $acts = $ws->getActivities($account->id, $ws_activity_limit);
    $csv_data="Symbol,Date,Quantity,Price,Currency";
    if ($acts) {
        foreach ($acts as $act) {
            if(!in_array($act->type, $ws_tx_types) ){
                continue;
            }

            $sign="";
            if ($act->type === 'DIY_SELL') {
                $sign = '-';
            }

            if ($act->type === 'DIY_BUY' || $act->type === 'DIY_SELL') {
                $securityNameForStockEvents = securityIdToSymbol($ws,$act->securityId);
                $price = round($act->amount / $act->assetQuantity,2);
                $csv_data .= "\n$securityNameForStockEvents," . date("Y-m-d", strtotime($act->occurredAt)) . ",$sign$act->assetQuantity,$price,$act->currency";
            }

        }
        echo "\n";
    }

    $csv_name = "stockevents-" . $account->number . '-' . date("Y-m-d") . ".csv";
    echo "\n ** Creating CSV with this output:\n\n";
    echo $csv_data;
    echo "\n\n ** Make sure all of your stocks are on your StockEvents watch list first!\n";
    echo " ** Send this CSV to your email and import it into StockEvents.\n";
    echo " ** File location: ".__DIR__."/$csv_name \n\n";
    file_put_contents(__DIR__ . "/$csv_name", $csv_data);
}

function securityIdToSymbol($ws, $security_id) {
    $security_symbol = "[$security_id]";
    $market_data = $ws->getSecurityMarketData($security_id);
    if (!empty($market_data->stock)) {
        $stock = $market_data->stock;
        $security_symbol = $stock->symbol;
        $security_symbol = str_replace(".UN","-UN",$security_symbol);
        if($stock->primaryExchange == 'TSX') {
            $security_symbol .= '.TO';
        }
    }
    return $security_symbol;
}
