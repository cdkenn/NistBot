<?php
/**
 * Copyright: Chip Wasson and Collin Kennedy
 * Date: 2/6/2017
 * Time: 2:01 PM
 */
require_once __DIR__."/core.php";

$body = file_get_contents('php://input');
//file_put_contents("log/".time()."message.json",$body);
$bot = new \NistBot\Bot();
var_dump(json_decode($body));
$bot->handleMessage(json_decode($body));