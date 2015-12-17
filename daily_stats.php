#!/usr/bin/php
<?php
include 'common.php';
echo date('Y-m-d H:i:s').' Beginning Daily Report processing...'.PHP_EOL;

/* should run at the very start of every day */


$main = Currencies::getMain();
$cryptos = Currencies::getCryptos();

// pay dividends
$sql = 'SELECT * FROM shares WHERE id = 1';
$result = db_query_array($sql);
$shares_info = $result[0];
if ($shares_info['cycle_close_day'] == date('j')) {
	$sql = 'SELECT site_users.id, site_users.shares_owned, site_users.shares_earned, site_users.shares_num_payouts, site_users.default_currency, site_users.last_lang, site_users.email, site_users_balances.balance FROM site_users LEFT JOIN site_users_balances ON (site_users.id = site_users_balances.site_user AND site_users.default_currency = site_users_balances.currency) WHERE shares_enabled = "Y"';
	$result = db_query_array($sql);
	if ($result) {
		foreach ($result as $dividend) {
			$payout = round((round($dividend['shares_owned'] / $shares_info['total_shares'],3,PHP_ROUND_HALF_UP) * $shares_info['total_profit_usd']) / $CFG->currencies[$dividend['default_currency']]['usd_ask'],2,PHP_ROUND_HALF_UP);
			User::updateBalances($dividend['id'],array($CFG->currencies[$dividend['default_currency']]['currency']=>$payout),true);
			db_insert('dividends',array('date'=>date('Y-m-d H:i:s'),'site_user'=>$dividend['id'],'shares_owned'=>$dividend['shares_owned'],'percentage_owned'=>round(($dividend['shares_owned'] / $shares_info['total_shares']) * 100,3,PHP_ROUND_HALF_UP),'dividend'=>$payout,'currency'=>$CFG->currencies[$dividend['default_currency']]['id']));
			db_insert('shares_log',array('date'=>date('Y-m-d H:i:s'),'history_action'=>$CFG->history_dividends_id,'site_user'=>$dividend['id'],'total'=>($payout * -1),'currency'=>$CFG->currencies[$dividend['default_currency']]['id'],'currency_usd'=>$CFG->currencies[$dividend['default_currency']]['usd_ask']));
			db_insert('history',array('date'=>date('Y-m-d H:i:s'),'history_action'=>$CFG->history_dividends_id,'site_user'=>$dividend['id'],'balance_before'=>$dividend['balance'],'balance_after'=>($dividend['balance'] + $payout)));
			
			$CFG->language = ($dividend['last_lang']) ? $dividend['last_lang'] : 'en';
			$email = SiteEmail::getRecord('dividend_payed');
			$info['dividend'] = $payout;
			$info['currency'] = $CFG->currencies[$dividend['default_currency']]['currency'];
			Email::send($CFG->form_email,$dividend['email'],$email['title'],$CFG->form_email_from,false,$email['content'],$info);
		}
	}
}

// check imbalances from shares system
$sql = 'SELECT DATE(`date` + INTERVAL 1 DAY) AS last_date FROM dividends ORDER BY id DESC LIMIT 0,1';
$result = db_query_array($sql);
if ($result[0]['last_date'] == date('Y-m-d')) {
	$sql = 'SELECT * FROM shares_imbalances';
	$result = db_query_array($sql);
	if ($result) {
		foreach ($result as $row) {
			$sql = 'SELECT id FROM conversions WHERE currency = '.$row['currency'].' AND is_active != "Y"';
			$result = db_query_array($sql);
			if (!$result)
				db_insert('conversions',array('amount'=>$row['imbalance'],'currency'=>$row['currency'],'date'=>date('Y-m-d')));
			else {
				$sql = 'UPDATE conversions SET amount = amount + ('.$row['imbalance'].') WHERE currency = '.$row['currency'].' AND is_active != "Y"';
				db_query($sql);
			}
		}
		
		$sql = 'UPDATE shares_imbalances SET imbalance = 0';
		$result = db_query($sql);
	}
	
	// compile shares report
	$sql = 'SELECT SUM(IF(shares_log.history_action = '.$CFG->history_dividends_id.',ABS(shares_log.total) * currencies.usd_ask,0)) AS dividends_usd, SUM(IF(shares_log.history_action = '.$CFG->history_buy_shares_id.',ABS(shares_log.total) * currencies.usd_ask,0)) AS buy_usd, SUM(IF(shares_log.history_action = '.$CFG->history_sell_shares_id.',ABS(shares_log.total) * currencies.usd_ask,0)) AS sell_usd FROM shares_log LEFT JOIN currencies ON (shares_log.currency = currencies.id) WHERE shares_log.factored = "N"';
	$result = db_query_array($sql);
	if ($result) {
		db_insert('shares_summary',array('date'=>date('Y-m-d'),'shares_held'=>$shares_info['shares_held'],'buy_total_usd'=>round($result[0]['buy_usd'] / $CFG->currencies[$main['fiat']]['usd_ask'],2,PHP_ROUND_HALF_UP),'sell_total_usd'=>round($result[0]['sell_usd'] / $CFG->currencies[$main['fiat']]['usd_ask'],2,PHP_ROUND_HALF_UP),'dividends_usd'=>round($result[0]['dividends_usd'] / $CFG->currencies[$main['fiat']]['usd_ask'],2,PHP_ROUND_HALF_UP),'status_id'=>1));
		$sql = 'UPDATE shares_log SET factored = "Y" WHERE factored = "N"';
		db_query($sql);
	}
}

// compile historical data
$sql = "INSERT INTO historical_data (`date`,usd) (SELECT '".(date('Y-m-d',strtotime('-1 day')))."',(transactions.btc_price * currencies.usd_ask) AS btc_price FROM transactions LEFT JOIN currencies ON (transactions.currency = currencies.id) WHERE transactions.date <= (CURDATE()) ORDER BY transactions.date DESC LIMIT 0,1) ";
$result = db_query($sql);

// get total of each currency
$sql = 'SELECT COUNT(DISTINCT site_users.id) AS total_users, SUM(IF(site_users_balances.currency IN ('.implode(',',$cryptos).'),site_users_balances.balance * currencies.usd_ask,0)) AS btc, SUM(IF(site_users_balances.currency NOT IN ('.implode(',',$cryptos).'),site_users_balances.balance * currencies.usd_ask,0)) AS usd FROM site_users LEFT JOIN site_users_balances ON (site_users.id = site_users_balances.site_user) LEFT JOIN currencies ON (currencies.id = site_users_balances.currency)';
$result = db_query_array($sql);
if ($result) {
	$total_users = $result[0]['total_users'];
	$total_btc = round($result[0]['btc'] / $CFG->currencies[$main['crypto']]['usd_ask'],2,PHP_ROUND_HALF_UP);
	$total_usd = round($result[0]['usd'] / $CFG->currencies[$main['fiat']]['usd_ask'],2,PHP_ROUND_HALF_UP);
	$btc_per_user = $total_btc / $total_users;
	$usd_per_user = $total_usd / $total_users;
}

// get open orders BTC
$sql = 'SELECT SUM(orders.btc * currencies.usd_ask) AS btc FROM orders LEFT JOIN currencies ON (orders.c_currency = currencies.id)';
$result = db_query_array($sql);
$open_orders_btc = round($result[0]['btc'] / $CFG->currencies[$main['crypto']]['usd_ask'],2,PHP_ROUND_HALF_UP);

// get total transactions for the day
$sql = 'SELECT SUM(transactions.btc * c_currencies.usd_ask) AS total_btc, AVG(transactions.btc * c_currencies.usd_ask) AS avg_btc, SUM((transactions.fee + transactions.fee1)  * transactions.btc_price * currencies.usd_ask) AS total_fees FROM transactions LEFT JOIN currencies ON (transactions.currency = currencies.id) LEFT JOIN currencies c_currencies ON (orders.c_currency = c_currencies.id) WHERE DATE(transactions.date) = (CURDATE() - INTERVAL 1 DAY)';
$result = db_query_array($sql);
$transactions_btc = round($result[0]['total_btc'] / $CFG->currencies[$main['crypto']]['usd_ask'],2,PHP_ROUND_HALF_UP);
$avg_transaction = round($result[0]['avg_btc'] / $CFG->currencies[$main['crypto']]['usd_ask'],2,PHP_ROUND_HALF_UP);
$trans_per_user = $transactions_btc / $total_users;
$total_fees = round($result[0]['total_fees'] / $CFG->currencies[$main['fiat']]['usd_ask'],2,PHP_ROUND_HALF_UP);
$fees_per_user = $total_fees / $total_users;

// get fees incurred from crypto networks for internal movements
$sql = 'SELECT SUM(fees.fee*currencies.usd_ask) AS fees_incurred FROM fees LEFT JOIN currencies ON (currencies.id = fees.c_currency) WHERE DATE(fees.date) = (CURDATE() - INTERVAL 1 DAY)';
$result = db_query_array($sql);
$gross_profit = $total_fees - round($result[0]['fees_incurred'] / $CFG->currencies[$main['fiat']]['usd_ask'],2,PHP_ROUND_HALF_UP);

db_insert('daily_reports',array('date'=>date('Y-m-d',strtotime('-1 day')),'total_btc'=>$total_btc,'total_fiat_usd'=>$total_usd,'btc_per_user'=>$btc_per_user,'usd_per_user'=>$usd_per_user,'open_orders_btc'=>$open_orders_btc,'transactions_btc'=>$transactions_btc,'avg_transaction_size_btc'=>$avg_transaction,'transaction_volume_per_user'=>$trans_per_user,'total_fees_btc'=>$total_fees,'fees_per_user_btc'=>$fees_per_user,'gross_profit_btc'=>$gross_profit));

db_update('status',1,array('cron_daily_stats'=>date('Y-m-d H:i:s')));
echo 'done'.PHP_EOL;
