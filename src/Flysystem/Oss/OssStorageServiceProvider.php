<?php

namespace Jason\Flysystem\Oss;

use Illuminate\Support\ServiceProvider;
use Jason\Flysystem\Oss\Plugins\FileUrl;
use Jason\Flysystem\Oss\Plugins\Kernel;
use Jason\Flysystem\Oss\Plugins\SetBucket;
use Jason\Flysystem\Oss\Plugins\SignatureConfig;
use Jason\Flysystem\Oss\Plugins\SignUrl;
use Jason\Flysystem\Oss\Plugins\TemporaryUrl;
use Jason\Flysystem\Oss\Plugins\Verify;
use League\Flysystem\Filesystem;

/**
 * Class OssStorageServiceProvider
 * @author iidestiny <iidestiny@vip.qq.com>
 */
class OssStorageServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     * @return void
     */
    public function boot()
    {
        app('filesystem')->extend('oss', function ($app, $config) {
            $root    = $config['root'] ?? null;
            $buckets = isset($config['buckets']) ? $config['buckets'] : [];
            $cdnHost = isset($config['cdnHost']) ? $config['cdnHost'] : null;
            $adapter = new OssAdapter(
                $config['access_key'],
                $config['secret_key'],
                $config['endpoint'],
                $config['bucket'],
                $config['isCName'],
                $root,
                $buckets,
                $cdnHost
            );

            $filesystem = new Filesystem($adapter);

            $filesystem->addPlugin(new FileUrl());
            $filesystem->addPlugin(new SignUrl());
            $filesystem->addPlugin(new TemporaryUrl());
            $filesystem->addPlugin(new SignatureConfig());
            $filesystem->addPlugin(new SetBucket());
            $filesystem->addPlugin(new Verify());
            $filesystem->addPlugin(new Kernel());

            return $filesystem;
        });
    }

    /**
     * Register the application services.
     * @return void
     */
    public function register()
    {
        //
    }

}
