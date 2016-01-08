#!/usr/bin/php
<?php
echo "Beginning Get Global Market Stats processing...".PHP_EOL;

include 'common.php';

$main = Currencies::getMain();
$wallets = Wallets::get();
if (!$wallets)
	exit;

// GET CRYPTO GLOBAL STATS
$ch = curl_init();
curl_setopt($ch,CURLOPT_URL,'http://coinmarketcap.northpole.ro/api/v5/all.json');
curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
curl_setopt($ch,CURLOPT_TIMEOUT,10);
curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
$data1 = curl_exec($ch);
curl_close($ch);
$data = json_decode($data1,true);

foreach ($wallets as $wallet) {
	$market_data = array();
	foreach ($data['markets'] as $market) {
		if ($market['symbol'] == $CFG->currencies[$wallet['c_currency']]['currency']) {
			$market_data = $market;
			break;
		}
	}
	
	if (count($market_data) == 0)
		continue;
	
	if (!($market_data['volume24']['usd'] > 0)) {
		$ch = curl_init();
		curl_setopt($ch,CURLOPT_URL,'http://coinmarketcap-nexuist.rhcloud.com/api/'.strtolower($CFG->currencies[$wallet['c_currency']]['currency']));
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
		curl_setopt($ch,CURLOPT_TIMEOUT,10);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
		$data1 = curl_exec($ch);
		curl_close($ch);
		$data2 = json_decode($data1,true);
		
		if ($data2 && $data2['volume']['usd'] > 0)
			$market_data['volume24']['usd'] = $data2['volume']['usd'];
	}
	
	$update_data = array('global_btc'=>$market_data['availableSupplyNumber'],'market_cap'=>($market_data['marketCap']['usd']/$CFG->currencies[$main['fiat']]['usd_ask']));
	if (!empty($market_data['volume24']['usd']) && $market_data['volume24']['usd'] > 0)
		$update_data['trade_volume'] = $market_data['volume24']['usd']/$CFG->currencies[$main['fiat']]['usd_ask'];
	
	db_update('wallets',$wallet['id'],$update_data);
}

// GET FIAT EXCHANGE RATES
if ($CFG->currencies) {
	foreach ($CFG->currencies as $currency) {
		if ($currency['is_crypto'] == 'Y' || $currency == 'USD')
			continue;
		
		$currencies[] = $currency['currency'].'USD';
	}
	
	$currency_string = urlencode(implode(',',$currencies));
	$ch = curl_init();
	curl_setopt($ch,CURLOPT_URL,'http://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20yahoo.finance.xchange%20where%20pair%3D%22'.$currency_string.'%22&format=json&diagnostics=true&env=store%3A%2F%2Fdatatables.org%2Falltableswithkeys');
	curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
	curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
	curl_setopt($ch,CURLOPT_TIMEOUT,10);
	curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
	$data1 = curl_exec($ch);
	curl_close($ch);
	
	$data = json_decode($data1,true);
	
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


