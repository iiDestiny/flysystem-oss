<?php

namespace Jason\Flysystem\Oss\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

/**
 * Class Kernel.
 */
class Kernel extends AbstractPlugin
{

    /**
     * @return string
     */
    public function getMethod()
    {
        return 'kernel';
    }

    /**
     * @return mixed
     */
    public function handle()
    {
        return $this->filesystem->getAdapter()->getClient();
    }

}
