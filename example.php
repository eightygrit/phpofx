<?php
//error_reporting(-1);
	
require_once "ofx.php";

$ofx = new OFX(array(
    "uri" => "https://www.oasis.cfree.com/3001.ofxgp",
    "user_id" => "123456",
    "password" => "1234",
    "org" => "Wells Fargo",
    "fid" => "3001",
    "bank_id" => $routing_number,
));


$accounts = $ofx->fetch_accounts();
print_r($accounts);
$transactions = $ofx->fetch_transactions($accounts[0]);
?>