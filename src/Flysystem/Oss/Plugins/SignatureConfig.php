<?php

namespace Jason\Flysystem\Oss\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

class SignatureConfig extends AbstractPlugin
{

    /**
     * sign url.
     * @return string
     */
    public function getMethod()
    {
        return 'signatureConfig';
    }

    /**
     * handle.
     * @param string $prefix
     * @param null   $callBackUrl
     * @param array  $customData
     * @param int    $expire
     * @param int    $contentLengthRangeValue
     * @param array  $systemData
     * @return mixed
     */
    public function handle($prefix = '', $callBackUrl = null, $customData = [], $expire = 30, $contentLengthRangeValue = 1048576000, $systemData = [])
    {
        return $this->filesystem->getAdapter()
                                ->signatureConfig($prefix, $callBackUrl, $customData, $expire, $contentLengthRangeValue, $systemData);
    }

}
