<?php

namespace Iidestiny\Flysystem\Oss\Plugins;


use League\Flysystem\Plugin\AbstractPlugin;

class FileUrl extends AbstractPlugin
{
    /**
     * get file url
     *
     * @return string
     */
    public function getMethod()
    {
        return 'getUrl';
    }

    /**
     * handle
     *
     * @param null $path
     * @return mixed
     */
    public function handle($path = null)
    {
        return $this->filesystem->getAdapter()->getUrl($path);
    }
}