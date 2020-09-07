<?php

namespace Jason\Flysystem\Oss\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

class SetBucket extends AbstractPlugin
{

    /**
     * sign url.
     * @return string
     */
    public function getMethod()
    {
        return 'bucket';
    }

    /**
     * handle.
     * @param $bucket
     * @return mixed
     */
    public function handle($bucket)
    {
        return $this->filesystem->getAdapter()->bucket($bucket);
    }

}
