<?php

namespace Iidestiny\Flysystem\Oss\Traits;

use DateTime;

trait SignatureTrait
{
    /**
     * gmt
     *
     * @param $time
     *
     * @return string
     * @throws \Exception
     */
    function gmt_iso8601($time)
    {
        $dtStr      = date("c", $time);
        $myDatetime = new DateTime($dtStr);
        $expiration = $myDatetime->format(DateTime::ISO8601);
        $pos        = strpos($expiration, '+');
        $expiration = substr($expiration, 0, $pos);

        return $expiration . "Z";
    }
}