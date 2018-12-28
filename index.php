<?php

require_once 'vendor/autoload.php';

$adapter = new \Iidestiny\Flysystem\Oss\OssAdapter(
    'LTAILa74wqVbraGP',
    '6oKxxRC79iarBJXX7Mr1HXUs4Cqs4V',
    'http://oss-cn-beijing.aliyuncs.com',
    'oss-adapter'
);

$flysystem = new \League\Flysystem\Filesystem($adapter);

//$r = $flysystem->updateStream('test.png', fopen('https://iocaffcdn.phphub.org/uploads/avatars/27822_1541754919.jpg!/both/200x200', 'r'));
//$r = $flysystem->updateStream('aas.txt', 'sdfasdfsdfadfasdfssssss');
$r = $flysystem->delete('cc.png');

dd($r);