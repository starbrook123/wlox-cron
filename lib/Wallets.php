<?php 
class Wallets {
	public static function get() {
		global $CFG;
	
		if (!($user_id > 0))
			return false;
	
		$sql = 'SELECT wallets.* FROM wallets ';
		$result = db_query_array($sql);
		if (!$result)
			return false;
	
		if (!empty($currencies)) {
			$sorted = array();
			foreach ($result as $row) {
				$sorted[$CFG->currencies[$row['c_currency']]['currency']] = $row;
			}
			return $sorted;
		}
		else
			return $result;
	}
	
	public static function sumFields($wallet_id,$fields) {
		global $CFG;
	
		if (!is_array($fields) || empty($fields || !$wallet_id))
			return false;
	
		$set = array();
		foreach ($fields as $field => $sum_amount) {
			if (!is_numeric($sum_amount))
				continue;
				
			$set[] = $field.' = '.$field.' + ('.$sum_amount.')';
		}
	
		$sql = 'UPDATE wallets SET '.implode(',',$set).' WHERE id = '.$wallet_id;
		return db_query($sql);
	}
	
	public static function getReserveSurplus($wallet_id) {
		global $CFG;
		
		if (!$wallet_id)
			return false;
	
		$reserve_ratio = ($CFG->bitcoin_reserve_ratio) ? $CFG->bitcoin_reserve_ratio : '0';
		$sql = 'SELECT (hot_wallet_btc - ((total_btc * '.$reserve_ratio.') + pending_withdrawals) - bitcoin_sending_fee) AS surplus, hot_wallet_btc FROM wallets WHERE id = '.$wallet_id;
		$result = db_query_array($sql);
		if (!$result)
			return 0;
	
		return $result[0];
	}
}
?>