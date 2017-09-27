<?php
/**
 * Created by PhpStorm.
 * User: gordon
 * Date: 2017/9/26
 * Time: 下午3:55
 */
ini_set('error_reporting', 'E_ALL');

function cc($name, $params)
{
    $params = array_merge(explode(' ', $name), $params);
    $command = '*' . count($params) . "\r\n";
    foreach ($params as $arg) {
        $command .= '$' . mb_strlen($arg, '8bit') . "\r\n" . $arg . "\r\n";
    }
    return $command;
}

function execute($sock, $command)
{
    if (is_array($command)) {
        $len = count($command);
        $command = implode("\r\n", $command);
        fwrite($sock, $command);

        $d = [];
        for ($i = 0; $i < $len; $i ++) {
            $d[] = get($sock, $command);
        }
        return $d;
    }
    fwrite($sock, $command);

    return get($sock, $command);
}

function get($sock, $command)
{
    $line = fgets($sock);
    $type = $line[0];
    $line = mb_substr($line, 1, -2, '8bit');
    switch ($type) {
        case '+': // Status reply
            if ($line === 'OK' || $line === 'PONG') {
                return true;
            } else {
                return $line;
            }
        case '-': // Error reply
            throw new Exception("Redis error: " . $line . "\nRedis command was: " . $command);
        case ':': // Integer reply
            // no cast to int as it is in the range of a signed 64 bit integer
            return $line;
        case '$': // Bulk replies
            if ($line == '-1') {
                return null;
            }

            $length = (int)$line + 2;
            $data = '';
            while ($length > 0) {
                if (($block = fread($sock, $length)) === false) {
                    throw new Exception("Failed to read from socket.\nRedis command was: " . $command);
                }
                $data .= $block;
                $length -= mb_strlen($block, '8bit');
            }
            return mb_substr($data, 0, -2, '8bit');
        case '*': // Multi-bulk replies
            $count = (int)$line;
            $data = [];
            for ($i = 0; $i < $count; $i++) {
                $data[] = get($sock, $command);
            }

            return $data;
        default:
            throw new Exception('Received illegal data from redis: ' . $line . "\nRedis command was: " . $command);
    }
}
//$sock = fsockopen('127.0.0.1', '6379');
$sock = stream_socket_client('tcp://10.10.10.132:6379');
//$c = cc('AUTH', ['74946443']);
$c = cc('SELECT', ['0']);
execute($sock, $c);

$times = 10;
$exe = cc('lrange', ['push_job', 0, -1]);
//TODO start

// 管道
$t = microtime(true);
$commands = [];
for ($i = 0; $i < $times; $i++) {
    $commands[] = $exe;
}
$a = execute($sock, $commands);
$t1 = microtime(true);
echo $t1 - $t.PHP_EOL;

// 事务；
$t = microtime(true);
$commands = [];
$c = cc('multi', []);
execute($sock, $c);
for ($i = 0; $i < $times; $i++) {
    execute($sock, $exe);
}
$c = cc('exec', []);
$a = execute($sock, $c);
$t1 = microtime(true);
echo $t1 - $t.PHP_EOL;

// 直接执行；10.1.1.10
$t = microtime(true);
for ($i = 0; $i < $times; $i++) {
    execute($sock, $exe);
}
$t1 = microtime(true);
echo $t1 - $t.PHP_EOL;
