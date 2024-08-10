<br>

<div class="main-left-box">
<div class="main-left-title">YiiMP API</div>
<div class="main-left-inner">

<p>Simple REST API.</p>

<p><b>Wallet Status</b></p>

request:
<p class="main-left-box" style='padding: 3px; font-size: .8em; background-color: #ffffee; font-family: monospace;'>
	http://<?=YAAMP_API_URL?>/api/wallet?address=<b>WALLET_ADDRESS</b></p>

result:
<pre class="main-left-box" style='padding: 3px; font-size: .8em; background-color: #ffffee; font-family: monospace;'>
{
	"unsold": 0.00050362,
	"balance": 0.00000000,
	"unpaid": 0.00050362,
	"paid24h": 0.00000000,
	"total": 0.00050362
}
</pre>

request:
<p class="main-left-box" style='padding: 3px; font-size: .8em; background-color: #ffffee; font-family: monospace;'>
        http://<?=YAAMP_API_URL?>/api/walletEx?address=<b>WALLET_ADDRESS</b></p>

result:
<pre class="main-left-box" style='padding: 3px; font-size: .8em; background-color: #ffffee; font-family: monospace;'>
{
	"unsold": 0.00050362,
	"balance": 0.00000000,
	"unpaid": 0.00050362,
	"paid24h": 0.00000000,
	"total": 0.00050362,
	"miners":[{
		"version": "ccminer\/1.8.2",
		"password": "d=96",
		"ID": "",
		"algo": "decred",
		"difficulty": 96,
		"subscribe": 1,
		"accepted": 82463372.083,
		"rejected": 0
	}]
<?php if (YAAMP_API_PAYOUTS) : ?>
	,"payouts":[{
		"time": 1529860641,
		"amount": "0.001",
		"tx": "transaction_id_of_the_payout"
	}]
<?php endif; ?>
}
</pre>
<?php
if (YAAMP_API_PAYOUTS)
	echo "Payouts of the last ".(YAAMP_API_PAYOUTS_PERIOD / 3600)." hours are displayed, please use a block explorer to see all payouts.";
?>
<p><b>Pool Status</b></p>

request:
<p class="main-left-box" style='padding: 3px; font-size: .8em; background-color: #ffffee; font-family: monospace;'>
	http://<?=YAAMP_API_URL?>/api/status</p>

result:
<pre class="main-left-box" style='padding: 3px; font-size: .8em; background-color: #ffffee; font-family: monospace;'>
{
	"x11": {
		"name": "x11",
		"port": 3533,
		"coins": 10,
		"fees": 1,
		"hashrate": 269473938,
		"workers": 5,
		"estimate_current": "0.00053653",
		"estimate_last24h": "0.00036408",
		"actual_last24h": "0.00035620",
		"hashrate_last24h": 269473000,
		"rental_current": "3.61922463"
	},

	...
}
</pre>


request:
<p class="main-left-box" style='padding: 3px; font-size: .8em; background-color: #ffffee; font-family: monospace;'>
	http://<?=YAAMP_API_URL?>/api/currencies</p>

result:
<pre class="main-left-box" style='padding: 3px; font-size: .8em; background-color: #ffffee; font-family: monospace;'>
{
	"BTX": {
		"algo": "bitcore",
		"port": 3556,
		"name": "BitCore",
		"height": 18944,
		"workers": 181,
		"shares": 392,
		"hashrate": 7267227499,
		"24h_blocks": 329,
		"24h_btc": 0.54471295,
		"lastblock": 18945,
		"timesincelast": 67
	},

	...
}
</pre>

<br><br>

</div></div>

<br><br><br><br><br><br><br><br><br><br>
<br><br><br><br><br><br><br><br><br><br>
<br><br><br><br><br><br><br><br><br><br>
<br><br><br><br><br><br><br><br><br><br>

<script>


</script>


