<?php

namespace Jason\Flysystem\Oss\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

class SignUrl extends AbstractPlugin
{

    /**
     * sign url.
     * @return string
     */
    public function getMethod()
    {
        return 'signUrl';
    }

    /**
     * handle.
     * @param $path
     * @param $timeout
     * @return mixed
     */
    public function handle($path, $timeout, array $options = [])
    {
        return $this->filesystem->getAdapter()->signUrl($path, $timeout, $options);
    }

}
