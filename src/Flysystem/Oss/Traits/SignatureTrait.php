<?php

namespace Jason\Flysystem\Oss\Traits;

trait SignatureTrait
{

    /**
     * gmt.
     * @param $time
     * @return string
     * @throws \Exception
     */
    public function gmt_iso8601($time)
    {
        return (new \DateTime(null, new \DateTimeZone('UTC')))->setTimestamp($time)->format('Y-m-d\TH:i:s\Z');
    }

}
