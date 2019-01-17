<?php

/*
 * This file is part of the iidestiny/flysystem-oss.
 *
 * (c) iidestiny <iidestiny@vip.qq.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

function file_get_contents($path)
{
    return "contents of {$path}";
}

function fopen($path, $mode)
{
    return "resource of {$path} with mode {$mode}";
}

$GLOBALS['result_of_ini_get'] = true;

function ini_get()
{
    return $GLOBALS['result_of_ini_get'];
}
