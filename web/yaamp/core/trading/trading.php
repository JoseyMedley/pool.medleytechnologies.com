<?php
require_once('binance_trading.php');
require_once('kraken_trading.php');
require_once('yobit_trading.php');
require_once('hitbtc_trading.php');
require_once('kucoin_trading.php');

function cancelExchangeOrder($order=false)
{
	if ($order)
		switch ($order->market)
		{
			case 'yobit':
				doYobitCancelOrder($order->uuid);
				break;
			case 'binance':
				doBinanceCancelOrder($order->uuid);
				break;
			case 'hitbtc':
				doHitBTCCancelOrder($order->uuid);
				break;
			case 'kucoin':
				doKuCoinCancelOrder($order->uuid);
				break;
		}
}

function runExchange($exchangeName=false)
{
	if (!empty($exchangeName))
	{
		switch($exchangeName)
		{
			case 'binance':
				doBinanceTrading(true);
				updateBinanceMarkets();
				break;

			case 'yobit':
				doYobitTrading(true);
				updateYobitMarkets();
				break;

			case 'hitbtc':
				doHitBTCTrading(true);
				updateHitBTCMarkets();
				break;

			case 'kraken':
				doKrakenTrading(true);
				updateKrakenMarkets();
				break;

			case 'kucoin':
				doKuCoinTrading(true);
				updateKucoinMarkets();
				break;
			default:
				debuglog(__FUNCTION__.' '.$exchangeName.' not implemented');
		}
	}
}
