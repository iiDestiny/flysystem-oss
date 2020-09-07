<?php

namespace Jason\Flysystem\Oss\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

class TemporaryUrl extends AbstractPlugin
{

    /**
     * getTemporaryUrl.
     * @return string
     */
    public function getMethod()
    {
        return 'getTemporaryUrl';
    }

    /**
     * handle.
     * @param $path
     * @param $expiration
     * @return mixed
     */
    public function handle($path, $expiration, array $options = [])
    {
        return $this->filesystem->getAdapter()->getTemporaryUrl($path, $expiration, $options);
    }

}
