<?php

require_once 'vendor/autoload.php';

$adapter = new \Iidestiny\Flysystem\Oss\OssAdapter(
    'LTAILa74wqVbraGP',
    '6oKxxRC79iarBJXX7Mr1HXUs4Cqs4V',
    'oss.iidestiny.com',
    'oss-adapter',
    true
);

$flysystem = new \League\Flysystem\Filesystem($adapter);
$flysystem->addPlugin(new \Iidestiny\Flysystem\Oss\Plugins\FileUrl());
//$r = $flysystem->updateStream('test.png', fopen('https://iocaffcdn.phphub.org/uploads/avatars/27822_1541754919.jpg!/both/200x200', 'r'));
//$r = $flysystem->write('ccaa.txt', 'sdfasdfsdfadfasdfssssss');
$r = $flysystem->getUrl('ccaa.txt');

dd($r);