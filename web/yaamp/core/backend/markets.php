<?php

function BackendPricesUpdate()
{
	debuglog(__FUNCTION__);

	settings_prefetch_all();
	
	$exchanges = getdbolist('db_balances');
	foreach ($exchanges as $exchange) {
		BackendPricesUpdateExchange($exchange->name);
	}

	updateOtherMarkets();

	$list2 = getdbolist('db_coins', "installed AND IFNULL(symbol2,'') != ''");
	foreach($list2 as $coin2)
	{
		$coin = getdbosql('db_coins', "symbol='$coin2->symbol2'");
		if(!$coin) continue;

		$list = getdbolist('db_markets', "coinid=$coin->id");
		foreach($list as $market)
		{
			$market2 = getdbosql('db_markets', "coinid=$coin2->id and name='$market->name'");
			if(!$market2) continue;

			$market2->price = $market->price;
			$market2->price2 = $market->price2;
			$market2->deposit_address = $market->deposit_address;
			$market2->pricetime = $market->pricetime;

			$market2->save();
		}
	}

	$coins = getdbolist('db_coins', "installed and id in (select distinct coinid from markets)");
	foreach($coins as $coin)
	{
		if($coin->symbol=='BTC') {
			$coin->price = 1;
			$coin->price2 = 1;
			$coin->save();
			continue;
		}

		$market = getBestMarket($coin);
		if($market)
		{
			$coin->price = $market->price*(1-YAAMP_FEES_EXCHANGE/100);
			$coin->price2 = $market->price2;

			$base_coin = !empty($market->base_coin)? getdbosql('db_coins', "symbol='{$market->base_coin}'"): null;
			if($base_coin)
			{
				$coin->price *= $base_coin->price;
				$coin->price2 *= $base_coin->price;
			}
		}
		else {
			$coin->price = 0;
			$coin->price2 = 0;
		}

		$coin->save();
		dborun("UPDATE earnings SET price={$coin->price} WHERE status!=2 AND coinid={$coin->id}");
		dborun("UPDATE markets SET message=NULL WHERE disabled=0 AND message='disabled from settings'");
	}
	debuglog("==== END BackendPricesUpdate ====");
}

function BackendPricesUpdateExchange($exchangename)
{
	debuglog(__METHOD__);
	debuglog("==== Start Sync Market Price $exchangename ====");
	switch ($exchangename) {
		case 'yobit':
			updateYobitMarkets();
		break;
		case 'coinex':
			updateCoinExMarkets();
		break;
		case 'p2pb2b':
			updateP2PB2BMarkets();
		break;
		case 'bitmesh':
			updateBitMeshMarkets();
		break;
		case 'nova':
			updateNovaMarkets();
		break;
		case 'btc-alpha':
			updateBTCAlphaMarkets();
		break;
		case 'tradeogre':
			updateTradeOgreMarkets();
		break;
		case 'binance':
			updateBinanceMarkets();
		break;
	}
	debuglog("==== End Sync Market Price ====");
}

function BackendWatchMarkets($marketname=NULL)
{
	// temporary to fill new coin 'watch' field
	if (defined('YIIMP_WATCH_CURRENCIES')) {
		$watched = explode(',', YIIMP_WATCH_CURRENCIES);
		foreach ($watched as $symbol) {
			dborun("UPDATE coins SET watch=1 WHERE symbol=:sym", array(':sym'=>$symbol));
		}
	}

	$coins = new db_coins;
	$coins = $coins->findAllByAttributes(array('watch'=>1));
	foreach ($coins as $coin)
	{
		// track btc/usd for history analysis
		if ($coin->symbol == 'BTC') {
			if ($marketname) continue;
			$mh = new db_market_history;
			$mh->time = time();
			$mh->idcoin = $coin->id;
			$mh->idmarket = NULL;
			$mh->price = dboscalar("SELECT usdbtc FROM mining LIMIT 1");
			if (YIIMP_FIAT_ALTERNATIVE == 'EUR')
				$mh->price2 = kraken_btceur();
			$mh->balance = dboscalar("SELECT SUM(balance) AS btc FROM balances");
			$mh->save();
			continue;
		} else if ($coin->installed) {
			// "yiimp" prices and balance history
			$mh = new db_market_history;
			$mh->time = time();
			$mh->idcoin = $coin->id;
			$mh->idmarket = NULL;
			$mh->price = $coin->price;
			$mh->price2 = $coin->price2;
			$mh->balance = $coin->balance;
			$mh->save();
		}

		if ($coin->rpcencoding == 'DCR') {
			// hack to store the locked balance history as a "stake" market
			$remote = new WalletRPC($coin);
			$stake = 0.; //(double) $remote->getbalance('*',0,'locked');
			$balances = $remote->getbalance('*',0);
			if (isset($balances["balances"])) {
				foreach ($balances["balances"] as $accb) {
					$stake += (double) arraySafeVal($accb, 'lockedbytickets', 0);
				}
			}
			$info = $remote->getstakeinfo();
			if (empty($remote->error) && isset($info['difficulty']))
			dborun("UPDATE markets SET balance=0, ontrade=:stake, balancetime=:time,
				price=:ticketprice, price2=:live, pricetime=NULL WHERE coinid=:id AND name='stake'", array(
				':ticketprice'=>$info['difficulty'], ':live'=>$info['live'], ':stake'=>$stake,
				':id'=>$coin->id, ':time'=>time()
			));
		}

		// user watched currencies
		$markets = getdbolist('db_markets', "coinid={$coin->id} AND NOT disabled");
		foreach($markets as $market) {
			if ($marketname && $market->name != $marketname) continue;
			if (!empty($market->base_coin)) continue; // todo ?
			if (empty($market->price)) continue;
			$mh = new db_market_history;
			$mh->time = time(); // max(intval($market->balancetime), intval($market->pricetime));
			$mh->idcoin = $coin->id;
			$mh->idmarket = $market->id;
			$mh->price = $market->price;
			$mh->price2 = $market->price2;
			$mh->balance = (double) ($market->balance) + (double) ($market->ontrade);
			$mh->save();
		}
	}
}

function getBestMarket($coin)
{
	$market = NULL;
	if ($coin->symbol == 'BTC')
		return NULL;

	if (!empty($coin->symbol2)) {
		$alt = getdbosql('db_coins', "symbol=:symbol", array(':symbol'=>$coin->symbol2));
		if ($alt && $alt->symbol2 != $coin->symbol2)
			return getBestMarket($alt);
	}

	if (!empty($coin->market)) {
		// get coin market first (if set)
		if ($coin->market != 'BEST' && $coin->market != 'unknown')
			$market = getdbosql('db_markets', "coinid={$coin->id} AND price!=0 AND NOT deleted AND
				NOT disabled AND IFNULL(deposit_address,'') != '' AND name=:name",
				array(':name'=>$coin->market));
		else
		// else take one of the big exchanges...
			$market = getdbosql('db_markets', "coinid={$coin->id} AND price!=0 AND NOT deleted AND
				NOT disabled AND IFNULL(deposit_address,'') != '' AND
				name IN ('poloniex','bittrex') ORDER BY priority DESC, price DESC");
	}

	if(!$market) {
		$market = getdbosql('db_markets', "coinid={$coin->id} AND price!=0 AND NOT deleted AND
			NOT disabled AND IFNULL(deposit_address,'') != '' ORDER BY priority DESC, price DESC");
	}

	if (!$market && empty($coin->market)) {
		debuglog("best market for {$coin->symbol} is unknown");
		$coin->market = 'unknown';
		$coin->save();
	}

	return $market;
}

function AverageIncrement($value1, $value2)
{
	$percent = 80;
	$value = ($value1*(100-$percent) + $value2*$percent) / 100;

	return $value;
}

///////////////////////////////////////////////////////////////////////////////////////////////////
function updateP2PB2BMarkets()
{
	debuglog(__FUNCTION__);
	debuglog ("====== P2PB2B =======");
	$exchange = 'p2pb2b';
	if (exchange_get($exchange, 'disabled')) return;
	$list = p2pb2b_api_query('tickers');
	if(empty($list) || !is_object($list)) return;
	foreach($list->result as $name=>$ticker) {
		$data = $ticker->ticker;
		$e = explode('_', $name);
		$symbol = strtoupper($e[0]); $base = strtoupper($e[1]);
		if($base != 'BTC') continue;
		debuglog("$symbol : $base");

		$coin = getdbosql('db_coins', "symbol=:sym", array(':sym'=>$symbol));
		if(!$coin) continue;
		$market = getdbosql('db_markets', "coinid={$coin->id} AND name='$exchange' AND IFNULL(base_coin,'') IN ('','BTC')");
		$symbol = $coin->getOfficialSymbol();
		if(!$market)
		{
			//debuglog ("$symbol not found in db");
			$market = new db_markets;
			$market->coinid = $coin->id;
			#$market->disabled = 0;
			$market->deleted = 0;
			$market->name = $exchange;
			//continue;	
		}
		else
		{
			//debuglog ("$symbol found in db");
			$symbol = $coin->getOfficialSymbol();
			if (market_get($exchange, $symbol, "disabled")) {
				#$market->disabled = 1;
				$market->message = 'disabled from settings';
				$market->save();
				continue;
			}
		}
		debuglog(json_encode($data));
		$market->disabled = ($data->bid == 0);
		$price2 = ((double)$data->ask + (double)$data->bid)/2;
		//debuglog($symbol. ' : ' .$price2);
		$market->price2 = AverageIncrement($market->price2, $price2);
		$market->price = AverageIncrement($market->price, (double)$data->bid);
		$market->priority = -1; // not ready for trading
		$market->txfee = 0.2;
		debuglog("$exchange: $symbol price set to ".bitcoinvaluetoa($market->price));
		$market->pricetime = time(); // $m->updated_time;
		$market->save();
		if (!empty($market->price2))
		{
			if ((empty($coin->price2))||($coin->price2==0)) {
				$coin->price = $market->price;
				$coin->price2 = $market->price2;
				$coin->save();
			}
		}
	}
}

function updateBitMeshMarkets($force = false)
{
	debuglog(__FUNCTION__);
	$exchange = 'bitmesh';
	if (exchange_get($exchange, 'disabled')) return;

	$count = (int) dboscalar("SELECT count(id) FROM markets WHERE name LIKE '$exchange%'");
	if ($count == 0) return;

	$result = bitmesh_api_query('market.ticker');
	if(!is_object($result)) return;

	foreach($result->data as $ticker)
	{
		if (is_null(objSafeVal($ticker,'name'))) continue;
		$pairs = explode('_', $ticker->name);

		$base = reset($pairs); $symbol = end($pairs);
		$base = strtoupper($base);
		$symbol = strtoupper($symbol);
		if($symbol == 'BTC' || $base != 'BTC') continue;

		if (market_get($exchange, $symbol, "disabled")) {
			$market->disabled = 1;
			$market->message = 'disabled from settings';
		}
		
		$coin = getdbosql('db_coins', "symbol='{$symbol}'");
		if(!$coin) continue;
		if(!$coin->installed && !$coin->watch) continue;

		$market = getdbosql('db_markets', "coinid={$coin->id} and name='{$exchange}'");
		if(!$market) continue;
		debuglog(json_encode($ticker));
		$price2 = ($ticker->price + $ticker->price)/2;
		$market->price2 = AverageIncrement($market->price2, $price2);
		$market->price = AverageIncrement($market->price, $ticker->price);
		$market->pricetime = time();
		$market->priority = -1;
		$market->txfee = 0.2; // trade pct
		$market->save();

		debuglog("$exchange: update $symbol: {$market->price} {$market->price2}");
		if ((empty($coin->price))||(empty($coin->price2))) {
			$coin->price = $market->price;
			$coin->price2 = $market->price2;
			$coin->market = $exchange;
			$coin->save();
		}
	}
}

function updateBTCAlphaMarkets($force = false)
{
	debuglog(__FUNCTION__);
	$exchange = 'btc-alpha';
	if (exchange_get($exchange, 'disabled')) return;

	$count = (int) dboscalar("SELECT count(id) FROM markets WHERE name LIKE '$exchange%'");
	if ($count == 0) return;

	$result = btcalpha_api_query('ticker');
	if(!is_array($result)) return;

	foreach($result as $ticker)
	{
		if (is_null(objSafeVal($ticker,'pair'))) continue;
		$pairs = explode('_', $ticker->pair);
		$symbol = reset($pairs); $base = end($pairs);
		if($symbol == 'BTC' || $base != 'BTC') continue;

		if (market_get($exchange, $symbol, "disabled")) {
			$market->disabled = 1;
			$market->message = 'disabled from settings';
		}

		$coin = getdbosql('db_coins', "symbol='{$symbol}'");
		if(!$coin) continue;
		if(!$coin->installed && !$coin->watch) continue;

		$market = getdbosql('db_markets', "coinid={$coin->id} and name='{$exchange}'");
		if(!$market) continue;

		$price2 = ($ticker->buy + $ticker->sell)/2;
		$market->price2 = AverageIncrement($market->price2, $price2);
		$market->price = AverageIncrement($market->price, $ticker->buy);
		$market->pricetime = time();
		$market->priority = -1;
		$market->txfee = 0.2; // trade pct
		$market->save();

		debuglog("$exchange: update $symbol: {$market->price} {$market->price2}");
		if ((empty($coin->price))||(empty($coin->price2))) {
			$coin->price = $market->price;
			$coin->price2 = $market->price2;
			$coin->market = $exchange;
			$coin->save();
		}
	}
}


/////////////////////////////////////////////////////////////////////////////////////////////

function updateGateioMarkets($force = false)
{
	$exchange = 'gateio';
	if (exchange_get($exchange, 'disabled')) return;

	$list = getdbolist('db_markets', "name LIKE '$exchange%'");
	if (empty($list)) return;

	$markets = gateio_api_query('tickers');
	if(!is_array($markets)) return;

	foreach($list as $market)
	{
		$coin = getdbo('db_coins', $market->coinid);
		if(!$coin) continue;

		$symbol = $coin->getOfficialSymbol();
		if (market_get($exchange, $symbol, "disabled")) {
			$market->disabled = 1;
			$market->message = 'disabled from settings';
			$market->save();
			continue;
		}

		$dbpair = strtolower($symbol).'_btc';
		foreach ($markets as $pair => $ticker) {
			if ($pair != $dbpair) continue;
			$price2 = (doubleval($ticker['highestBid']) + doubleval($ticker['lowestAsk'])) / 2;
			$market->price = AverageIncrement($market->price, doubleval($ticker['highestBid']));
			$market->price2 = AverageIncrement($market->price2, $price2);
			$market->pricetime = time();
			$market->priority = -1;
			$market->txfee = 0.2; // trade pct
			$market->save();

			if (empty($coin->price2)) {
				$coin->price = $market->price;
				$coin->price2 = $market->price2;
				$coin->market = $exchange;
				$coin->save();
			}
		}
	}
}


/////////////////////////////////////////////////////////////////////////////////////////////

function updateKrakenMarkets($force = false)
{
	$exchange = 'kraken';
	if (exchange_get($exchange, 'disabled')) return;

	$count = (int) dboscalar("SELECT count(id) FROM markets WHERE name LIKE '$exchange%'");
	if ($count == 0) return;

	$result = kraken_api_query('AssetPairs');
	if(!is_array($result)) return;

	foreach($result as $pair => $data)
	{
		$pairs = explode('-', $pair);
		$base = reset($pairs); $symbol = end($pairs);
		if($symbol == 'BTC' || $base != 'BTC') continue;
		if(in_array($symbol, array('GBP','CAD','EUR','USD','JPY'))) continue;
		if(strpos($symbol,'.d') !== false) continue;

		$coin = getdbosql('db_coins', "symbol='{$symbol}'");
		if(!$coin) continue;
		if(!$coin->installed && !$coin->watch) continue;

		$fees = reset($data['fees']);
		$feepct = is_array($fees) ? end($fees) : null;
		$market = getdbosql('db_markets', "coinid={$coin->id} and name='{$exchange}'");
		if(!$market) continue;

		$market->txfee = $feepct;

		if (market_get($exchange, $symbol, "disabled")) {
			$market->disabled = 1;
			$market->message = 'disabled from settings';
		}

		$market->save();
		if($market->disabled || $market->deleted) continue;

		sleep(1);
		$ticker = kraken_api_query('Ticker', $symbol);
		if(!is_array($ticker) || !isset($ticker[$pair])) continue;

		$ticker = arraySafeVal($ticker, $pair);
		if(!is_array($ticker) || !isset($ticker['b'])) continue;

		$price1 = (double) $ticker['a'][0]; // a = ask
		$price2 = (double) $ticker['b'][0]; // b = bid, c = last

		// Alt markets on kraken (LTC/DOGE/NMC) are "reversed" against BTC (1/x)
		if ($price2 > $price1) {
			$price = $price2 ? 1 / $price2 : 0;
			$price2 = $price1 ? 1 / $price1 : 0;
		} else {
			$price = $price1 ? 1 / $price1 : 0;
			$price2 = $price2 ? 1 / $price2 : 0;
		}

		$market->price = AverageIncrement($market->price, $price);
		$market->price2 = AverageIncrement($market->price2, $price2);
		$market->pricetime = time();

		$market->save();
	}
}

////////////////////////////////////////////////////////////////////////////////////

function updateYobitMarkets()
{
	$exchange = 'yobit';
	if (exchange_get($exchange, 'disabled')) return;

	$count = (int) dboscalar("SELECT count(id) FROM markets WHERE name LIKE '$exchange%'");
	if ($count == 0) return;

	$res = yobit_api_query('info');
	if(!is_object($res)) return;

	foreach($res->pairs as $i=>$item)
	{
		$e = explode('_', $i);
		$symbol = strtoupper($e[0]);
		$base_symbol = strtoupper($e[1]);

		if($symbol == 'BTC') continue;

		$coin = getdbosql('db_coins', "symbol=:symbol", array(':symbol'=>$symbol));
		if(!$coin) continue;

		$sqlFilter = "AND IFNULL(base_coin,'')=''";
		if ($base_symbol != 'BTC') {
			// Only track ALT markets (ETH, DOGE) if the market record exists in the DB, sample market name "yobit DOGE"
			$in_db = (int) dboscalar("SELECT count(M.id) FROM markets M INNER JOIN coins C ON C.id=M.coinid ".
				" WHERE C.installed AND C.symbol=:sym AND M.name LIKE '$exchange %' AND M.base_coin=:base",
				array(':sym'=>$symbol,':base'=>$base_symbol)
			);
			if (!$in_db) continue;
			$sqlFilter = "AND base_coin='$base_symbol'";
		}

		$market = getdbosql('db_markets', "coinid={$coin->id} AND name LIKE '$exchange%' $sqlFilter");
		if(!$market) continue;

		$market->txfee = objSafeVal($item,'fee',0.2);
		if ($market->disabled < 9) $market->disabled = arraySafeVal($item,'hidden',0);
		if (time() - $market->pricetime > 6*3600) $market->price = 0;

		if (market_get($exchange, $symbol, "disabled")) {
			$market->disabled = 1;
			$market->message = 'disabled from settings';
		}

		$market->save();

		if ($market->deleted || $market->disabled) continue;
		if (!$coin->installed && !$coin->watch) continue;

		$symbol = $coin->getOfficialSymbol();
		$pair = strtolower($symbol.'_'.$base_symbol);

		$ticker = yobit_api_query("ticker/$pair");
		if(!$ticker || objSafeVal($ticker,$pair) === NULL) continue;
		if(objSafeVal($ticker->$pair,'buy') === NULL) {
			debuglog("$exchange: invalid data received for $pair ticker");
			continue;
		}

		$price2 = ($ticker->$pair->buy + $ticker->$pair->sell) / 2;
		$market->price2 = AverageIncrement($market->price2, $price2);
		$market->price = AverageIncrement($market->price, $ticker->$pair->buy);
		if ($ticker->$pair->buy < $market->price) $market->price = $ticker->$pair->buy;
		$market->pricetime = time();
		$market->save();

		if(!empty(EXCH_YOBIT_KEY))
		{
			$last_checked = cache()->get($exchange.'-deposit_address-check-'.$symbol);
			if ($last_checked) continue;

			sleep(1); // for the api nonce
			$address = yobit_api_query2('GetDepositAddress', array("coinName"=>$symbol));
			if (!empty($address) && isset($address['return']) && $address['success']) {
				$addr = $address['return']['address'];
				if (!empty($addr) && $addr != $market->deposit_address) {
					$market->deposit_address = $addr;
					debuglog("$exchange: deposit address for {$symbol} updated");
					$market->save();
				}
			}
			cache()->set($exchange.'-deposit_address-check-'.$symbol, time(), 24*3600);
		}
	}
}

function updateHitBTCMarkets()
{
	$exchange = 'hitbtc';
	if (exchange_get($exchange, 'disabled')) return;

	$markets = getdbolist('db_markets', "name LIKE '$exchange%'"); // allow "hitbtc LTC"
	if(empty($markets)) return;

	$data = hitbtc_api_query('ticker','','array');
	if(!is_array($data) || empty($data)) return;

	foreach($markets as $market)
	{
		$coin = getdbo('db_coins', $market->coinid);
		if(!$coin) continue;

		$base = 'BTC';
		$symbol = $coin->getOfficialSymbol();
		$pair = strtoupper($symbol).$base;

		$sqlFilter = '';
		if (!empty($market->base_coin)) {
			$base = $market->base_coin;
			$pair = strtoupper($market->base_coin.$symbol);
			$sqlFilter = "AND base_coin='{$market->base_coin}'";
		}

		if (market_get($exchange, $symbol, "disabled", false, $base)) {
			$market->disabled = 1;
			$market->message = 'disabled from settings';
			$market->save();
			continue;
		}

		foreach ($data as $p => $ticker)
		{
			if ($p === $pair) {
				$price2 = ((double)$ticker['bid'] + (double)$ticker['ask'])/2;
				$market->price = AverageIncrement($market->price, (double)$ticker['bid']);
				$market->price2 = AverageIncrement($market->price2, $price2);
				$market->pricetime = time(); // $ticker->timestamp
				$market->priority = -1;
				$market->save();

				if (empty($coin->price2) && strpos($pair,'BTC') !== false) {
					$coin->price = $market->price;
					$coin->price2 = $market->price2;
					$coin->save();
				}
				debuglog("$exchange: $pair $market->price ".bitcoinvaluetoa($market->price2));
				// break;
			}
		}

		if(!empty(EXCH_HITBTC_KEY))
		{
			$last_checked = cache()->get($exchange.'-deposit_address-check-'.$symbol);
			if($coin->installed && !$last_checked && empty($market->deposit_address))
			{
				sleep(1);
				$res = hitbtc_api_user('payment/address/'.$symbol); // GET method
				if(is_object($res) && isset($res->address)) {
					if (!empty($res->address)) {
						$market->deposit_address = $res->address;
						debuglog("$exchange: deposit address for {$symbol} updated");
						$market->save();
						if ($symbol == 'WAVES' || $symbol == 'LSK') // Wallet address + Public key
							debuglog("$exchange: $symbol deposit address data: ".json_encode($res));
					}
				}
				cache()->set($exchange.'-deposit_address-check-'.$symbol, time(), 24*3600);
			}
		}
	}
}

function updateNovaMarkets()
{
	$exchange = 'nova';
	if (exchange_get($exchange, 'disabled')) return;

	$markets = getdbolist('db_markets', "name LIKE '$exchange%'"); // allow "nova LTC"
	if(empty($markets)) return;

	$data = nova_api_query('markets');
	if(!is_object($data) || $data->status != 'success' || !is_array($data->markets)) return;

	foreach($markets as $market)
	{
		$coin = getdbo('db_coins', $market->coinid);
		if(!$coin) continue;

		$base = 'BTC';
		$symbol = $coin->getOfficialSymbol();
		$pair = $base.'_'.strtoupper($symbol);

		$sqlFilter = '';
		if (!empty($market->base_coin)) {
			$base = $market->base_coin;
			$pair = strtoupper($market->base_coin.'_'.$symbol);
			$sqlFilter = "AND base_coin='{$market->base_coin}'";
		}

		if (market_get($exchange, $symbol, "disabled", false, $base)) {
			$market->disabled = 1;
			$market->message = 'disabled from settings';
			$market->save();
			continue;
		}

		foreach ($data->markets as $ticker) {
			if ($ticker->marketname === $pair) {

				$market->marketid = $ticker->marketid;

				if ($market->disabled < 9) {
					$nbm = (int) dboscalar("SELECT COUNT(id) FROM markets WHERE coinid={$coin->id} $sqlFilter");
					$market->disabled = (floatval($ticker->volume24h) <= 0.005) && $nbm > 1; // in btc
				}

				if (!$market->disabled) {
					$market->price = AverageIncrement($market->price, $ticker->bid);
					$market->price2 = AverageIncrement($market->price2, $ticker->last_price);
					$market->pricetime = time();
					$market->save();

					if (empty($coin->price2) && strpos($pair,'BTC') !== false) {
						$coin->price = $market->price;
						$coin->price2 = $market->price2;
						$coin->save();
					}
				}
				break;
			}
		}

		if(!empty(EXCH_NOVA_KEY))
		{
			$last_checked = cache()->get($exchange.'-deposit_address-check-'.$symbol);
			if(empty($market->deposit_address) && !$last_checked)
			{
				sleep(1);
				$res = nova_api_user('getdepositaddress/'.$symbol);
				if(objSafeVal($res,'status') == 'success') {
					$addr = objSafeVal($res, 'address');
					if (!empty($addr)) {
						$market->deposit_address = $addr;
						// delimiter "::" for memo / payment id
						$market->message = null;
						debuglog("$exchange: deposit address for {$symbol} updated");
						$market->save();
					} else {
						debuglog("$exchange: Failed to update $symbol deposit address, ".json_encode($res));
					}
				}
				cache()->set($exchange.'-deposit_address-check-'.$symbol, time(), 24*3600);
			}
		}
	}
}

function updateBinanceMarkets()
{
	$exchange = 'binance';
	if (exchange_get($exchange, 'disabled')) return;

	$list = getdbolist('db_markets', "name LIKE '$exchange%'");
	if (empty($list)) return;

	$tickers = binance_api_query('ticker/allBookTickers');
	if(!is_array($tickers)) return;

	foreach($list as $market)
	{
		$coin = getdbo('db_coins', $market->coinid);
		if(!$coin) continue;

		$symbol = $coin->getOfficialSymbol();
		if (market_get($exchange, $symbol, "disabled")) {
			$market->disabled = 1;
			$market->message = 'disabled from settings';
			$market->save();
			continue;
		}

		$pair = $symbol.'BTC';
		foreach ($tickers as $ticker) {
			if ($pair != $ticker->symbol) continue;

			$price2 = ($ticker->bidPrice+$ticker->askPrice)/2;
			$market->price = AverageIncrement($market->price, $ticker->bidPrice);
			$market->price2 = AverageIncrement($market->price2, $price2);
			$market->pricetime = time();
			if ($market->disabled < 9) $market->disabled = (floatval($ticker->bidQty) < 0.01);
			$market->save();

			if (empty($coin->price2)) {
				$coin->price = $market->price;
				$coin->price2 = $market->price2;
				$coin->save();
			}
		}
	}
}

function updateKuCoinMarkets()
{
	$exchange = 'kucoin';
	if (exchange_get($exchange, 'disabled')) return;

	$list = getdbolist('db_markets', "name LIKE '$exchange%'");
	if (empty($list)) return;

	$symbols = kucoin_api_query('symbols','market=BTC');
	if(!kucoin_result_valid($symbols) || empty($symbols->data)) return;

	usleep(500);
	$markets = kucoin_api_query('market/allTickers');
	if(!kucoin_result_valid($markets) || empty($markets->data)) return;
	if(!isset($markets->data->ticker) || !is_array($markets->data->ticker)) return;
	$tickers = $markets->data->ticker;

	foreach($list as $market)
	{
		$coin = getdbo('db_coins', $market->coinid);
		if(!$coin) continue;

		$symbol = $coin->getOfficialSymbol();
		if (market_get($exchange, $symbol, "disabled")) {
			$market->disabled = 1;
			$market->message = 'disabled from settings';
			$market->save();
			continue;
		}

		$pair = strtoupper($symbol).'-BTC';

		$enableTrading = false;
		foreach ($symbols->data as $sym) {
			if (objSafeVal($sym,'symbol') != $pair) continue;
			$enableTrading = objSafeVal($sym,'enableTrading',false);
			break;
		}

		if ($market->disabled == $enableTrading) {
			$market->disabled = (int) (!$enableTrading);
			$market->save();
			if ($market->disabled) continue;
		}

		foreach ($tickers as $ticker) {
			if ($ticker->symbol != $pair) continue;
			if (objSafeVal($ticker,'buy',-1) == -1) continue;

			$market->price = AverageIncrement($market->price, $ticker->buy);
			$market->price2 = AverageIncrement($market->price2, objSafeVal($ticker,'sell',$ticker->buy));
			$market->priority = -1;
			$market->pricetime = time();

			if (floatval($ticker->vol) > 0.01)
				$market->save();

			if (empty($coin->price2)) {
				$coin->price = $market->price;
				$coin->price2 = $market->price2;
				$coin->save();
			}
		}
	}
}


// todo: store min/max txs limits

// update other installed coins price from cryptonator
function updateOtherMarkets()
{
	$coins = getdbolist('db_coins', "installed AND IFNULL(price,0.0) = 0.0");
	foreach($coins as $coin)
	{
		$symbol = $coin->getOfficialSymbol();
		if (market_get("cryptonator", $coin->symbol, "disabled")) {
			continue;
		}

		$json = @ file_get_contents("https://www.cryptonator.com/api/full/".strtolower($symbol)."-btc");
		$object = json_decode($json);
		if (empty($object)) continue;

		if (is_object($object) && isset($object->ticker)) {
			$ticker = $object->ticker;
			if ($ticker->target == 'BTC' && $ticker->volume > 1) {
				$coin->price2 = $ticker->price;
				$coin->price  = AverageIncrement((float)$coin->price, (float)$coin->price2);
				if ($coin->save()) {
					debuglog("cryptonator: $symbol price set to ".bitcoinvaluetoa($coin->price));
				}
			}
		}
	}
}
