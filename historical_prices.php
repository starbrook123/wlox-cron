#!/usr/bin/php
<?php
include 'common.php';

// WARNING
// This file is only to be run to pull price data from other exchanges before the exchange has it's own operations!
// Once the exchange is operating, it will generate it's own price data

echo date('Y-m-d H:i:s').' Beginning Historical Data processing...'.PHP_EOL;

$wallets = Wallets::get();
if (!$wallets) {
	echo 'Error: no wallets to process.'.PHP_EOL;
	exit;
}

if (!array_key_exists('BTC',$wallets)) {
	echo 'Error: Must have BTC data series to execute this function.'.PHP_EOL;
	exit;
}

$btc_data = array();
$btc_wallet = $wallets['BTC'];
unset($wallets['BTC']);
array_unshift($wallets,$btc_wallet);

// QUANDL HISTORICAL DATA
foreach ($wallets as $wallets) {
	if ($CFG->currencies[$wallet['c_currency']]['currency'] == 'BTC')
		$url = 'https://www.quandl.com/api/v3/datasets/BAVERAGE/USD.csv';
	else
		$url = 'https://www.quandl.com/api/v3/datasets/CRYPTOCHART/'.$CFG->currencies[$wallet['c_currency']]['currency'].'.csv';
	
	$data = file_get_contents($url);
	$data1 = explode("\n",$data);
	if ($data1) {
		$i = 1;
		$c = count($data1);
		foreach ($data1 as $row) {
			if ($i == 1) {
				$i++;
				continue;
			}
			
			$row1 = explode(',',$row);
			
			if ($CFG->currencies[$wallet['c_currency']]['currency'] == 'BTC') {
				$btc_data[$row1[0]] = $row1[1];
				$exchange_rate = $row1[1];
			}
			else if (!empty($btc_data[$row1[0]]))
				$exchange_rate = $row1[1] * $btc_data[$row1[0]];
			else
				continue;
			
			if ($i == 2) {
				db_update('currencies',$CFG->btc_currency_id,array('usd_ask'=>$exchange_rate,'usd_bid'=>$exchange_rate));
			}
			
			$sql = "SELECT * FROM historical_data WHERE `date` = '{$row1[0]}'";
			$result = db_query_array($sql);
			
			if (!$result) {
				db_insert('historical_data',array('date'=>$row1[0],'usd'=>$exchange_rate));
			}
			else {
				db_update('historical_data',$result[0]['id'],array('usd'=>$exchange_rate));
			}
			
			$i++;
		}
	}
}

echo 'done';
?>
