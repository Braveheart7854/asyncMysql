<?php
/**
 * Created by PhpStorm. 
 * swoole2.0 异步mysql的实例
 * User: tongh
 * Date: 2017/7/31
 * Time: 上午10:33
 */

class MysqlServer
{
    protected $poolSize = 20;
    protected $freePool = [];   //空闲连接池
    protected $workPool = [];   //工作连接池
    protected $waitQueue = [];  //等待的请求队列
    protected $waitQueueMax = 100; //等待队列的最大长度，超过后将拒绝新的请求

    /**
     * @var swoole_server
     */
    protected $serv;

    function run()
    {
        $serv = new swoole_server("127.0.0.1", 9509);
        $serv->set(array(
            'worker_num' => 1,
        ));

        $serv->on('WorkerStart', array($this, 'onStart'));
        $serv->on('Receive', array($this, 'onReceive'));
        $serv->start();
    }

    function onStart($serv)
    {
        $this->serv = $serv;
        for ($i = 0; $i < $this->poolSize; $i++) {

            $db = new swoole_mysql;
            $server = array(
                'host' => '127.0.0.1',
                'port' => 3306,
                'user' => 'root',
                'password' => '123456',
                'database' => 'jiuhao-shop-test',
                'charset' => 'utf8', //指定字符集
                'timeout' => 2,  // 可选：连接超时时间（非查询超时时间），默认为SW_MYSQL_CONNECT_TIMEOUT（1.0）
            );
            $db->connect($server,function (swoole_mysql $db,$r)use($i){
                if ($r === false) {
                    var_dump($db->connect_errno, $db->connect_error);
                    die;
                }else{
                    $this->freePool[] = array(
                        'mysqli' => $db,
                        'dbNum' => $i,
                        'fd' => 0,
                    );
                }
            });

        }
        echo "Server: start.Swoole version is [" . SWOOLE_VERSION . "]\n";
    }

    function onReceive($serv, $fd, $fromId, $data)
    {
        //没有空闲的数据库连接
        if (count($this->freePool) == 0) {
            //等待队列未满
            if (count($this->waitQueue) < $this->waitQueueMax) {
                $this->waitQueue[] = array(
                    'fd' => $fd,
                    'sql' => $data,
                );
            } else {
                $this->serv->send($fd, "request too many, Please try again later.");
            }
        } else {
            $this->doQuery($fd, $data);
        }
    }

    function doQuery($fd, $sql)
    {
        //从空闲池中移除
        $db = array_pop($this->freePool);
        /**
         * @var mysqli
         */
        $mysqli = $db['mysqli'];

        if ($sql==1){
            $this->transaction($mysqli, $fd, $sql, $db);
        }else{
            $mysqli->query($sql,function (swoole_mysql $mysqli, $r)use($fd,$db){
                if ($r === false) {
                    $this->serv->send($fd, sprintf("MySQLi Error: %s\n", $mysqli->errno.'  '.$mysqli->error));
                }
                elseif ($r === true ) {
                    $result = ['affected_rows'=>$mysqli->affected_rows,'insert_id'=>$mysqli->insert_id];
                    $this->serv->send($fd,json_encode($result));
                }else{
                    $this->serv->send($fd,json_encode($r));
                }

                //release mysqli object
                $this->freePool[] = $db;
                unset($this->workPool[$db['dbNum']]);
                //这里可以取出一个等待请求
                if (count($this->waitQueue) > 0) {
                    $idle_n = count($this->freePool);
                    for ($i = 0; $i < $idle_n; $i++) {
                        $req = array_shift($this->waitQueue);
                        $this->doQuery($req['fd'], $req['sql']);
                    }
                }
            });
        }

        $db['fd'] = $fd;
        //加入工作池中
        $this->workPool[$db['dbNum']] = $db;
    }

    function transaction($mysqli,$fd,$sql,$db){
//        if (empty($sql))
//            $this->serv->send($fd,'');

        $mysqli->query('START TRANSACTION',function (swoole_mysql $mysqli,$r)use($fd,$sql,$db){
            $sql1 = "update goods set quality_score=quality_score-1 where Id=1";
            $mysqli->query($sql1,function (swoole_mysql $mysqli,$r)use($fd,$db){
                if ($r === false) {
                    $mysqli->query('ROLLBACK',function (swoole_mysql $mysqli,$r)use($fd) {
                        $this->serv->send($fd, "It's failed to create order");
                    });
                }else{
                    $sql2 = "update orders set parent_order_no='23432' where Id=169";
                    $mysqli->query($sql2,function (swoole_mysql $mysqli,$r)use($fd,$db){
                        if ($r === false) {
                            $mysqli->query('ROLLBACK',function (swoole_mysql $mysqli,$r)use($fd) {
                                $this->serv->send($fd, "It's failed to create order");
                            });
                        }else{
                            $mysqli->query('COMMIT',function (swoole_mysql $mysqli,$r)use($fd) {
                                $this->serv->send($fd, 'Create order successfully!');
                            });
                        }
                    });
                }
            });

            //release mysqli object
            $this->freePool[] = $db;
            unset($this->workPool[$db['dbNum']]);
            //这里可以取出一个等待请求
            if (count($this->waitQueue) > 0) {
                $idle_n = count($this->freePool);
                for ($i = 0; $i < $idle_n; $i++) {
                    $req = array_shift($this->waitQueue);
                    $this->doQuery($req['fd'], $req['sql']);
                }
            }

        });

    }
}

$server = new MysqlServer();
$server->run();