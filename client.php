<?php
/**
 * Created by PhpStorm.
 * User: tongh
 * Date: 2017/7/26
 * Time: 下午3:38
 */
$client = new swoole_client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);
//$client->connect('127.0.0.1', 9509, 0.5);
$client->connect('127.0.0.1', 9509, -1);
//$client->send('select * from goods where goods_name=\'444\'');
$client->send('1');
echo $client->recv();