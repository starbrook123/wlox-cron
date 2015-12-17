#!/usr/bin/php
<?php
echo "Beginning Get Global Market Stats processing...".PHP_EOL;

include 'common.php';

$wallets = Wallets::get();
if (!$wallets)
	exit;

if (!array_key_exists('BTC',$wallets)) {
	echo 'Error: Must have BTC data series to execute this function.'.PHP_EOL;
	exit;
}

$main = Currencies::getMain();
$btc_data = array();
$btc_wallet = $wallets['BTC'];
unset($wallets['BTC']);
array_unshift($wallets,$btc_wallet);

// GET CRYPTO GLOBAL STATS
foreach ($wallets as $wallet) {
	$data1 = file_get_contents('http://coinmarketcap-nexuist.rhcloud.com/api/'.strtolower($CFG->currencies[$wallet['c_currency']]['currency']));
	$data = json_decode($data1,true);
	
	db_update('current_stats',1,array('trade_volume'=>($data['volume']['usd']/$CFG->currencies[$main['fiat']]['usd_ask']),'total_btc'=>($data['supply']['usd']/$CFG->currencies[$wallet['c_currency']]['usd_ask']),'market_cap'=>($data['market_cap']['usd']/$CFG->currencies[$main['fiat']]['usd_ask'])));
}

// GET FIAT EXCHANGE RATES
if ($CFG->currencies) {
	foreach ($CFG->currencies as $currency) {
		if ($currency['is_crypto'] == 'Y' || $currency == 'USD')
			continue;
		
		$currencies[] = $currency['currency'].'USD';
	}
	$currency_string = urlencode(implode(',',$currencies));
	$data = json_decode(file_get_contents('http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20yahoo.finance.xchange%20where%20pair%3D%22'.$currency_string.'%22&format=json&diagnostics=true&env=store%3A%2F%2Fdatatables.org%2Falltableswithkeys'),TRUE);
	
	if ($data['query']['results']['rate']) {
		$bid_str = '(CASE currency ';
		$ask_str = '(CASE currency ';
		$currency_ids = array();
		$last = false;
		
		foreach ($data['query']['results']['rate'] as $row) {
			$key = str_replace('USD','',$row['id']);
			if ($key == $last)
				continue;
			
			$ask = $row['Ask'];
			$bid = $row['Bid'];
			
			if (strlen($key) < 3 || strstr($key,'='))
				continue;
			
			if ($bid == $CFG->currencies[$key]['usd_bid'] || $ask == $CFG->currencies[$key]['usd_ask'])
				continue;
			
			$bid_str .= ' WHEN "'.$key.'" THEN '.$bid.' ';
			$ask_str .= ' WHEN "'.$key.'" THEN '.$ask.' ';
			$currency_ids[] = $CFG->currencies[$key]['id'];
			$last = $key;
		}
		
		$bid_str .= ' END)';
		$ask_str .= ' END)';
		
		$sql = 'UPDATE currencies SET usd_bid = '.$bid_str.', usd_ask = '.$ask_str.' WHERE id IN ('.implode(',',$currency_ids).')';
		$result = db_query($sql);
	}
}
db_update('status',1,array('cron_get_stats'=>date('Y-m-d H:i:s')));
echo 'done'.PHP_EOL;


