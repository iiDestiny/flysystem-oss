<?php

namespace Iidestiny\Flysystem\Oss\Plugins;


use League\Flysystem\Plugin\AbstractPlugin;

class SignUrl extends AbstractPlugin
{
    /**
     * sign url
     *
     * @return string
     */
    public function getMethod()
    {
        return 'signUrl';
    }

    /**
     * handle
     *
     * @param $path
     * @param $timeout
     *
     * @return mixed
     */
    public function handle($path, $timeout)
    {
        return $this->filesystem->getAdapter()->signUrl($path, $timeout);
    }
}