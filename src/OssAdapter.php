<?php

namespace Iidestiny\Flysystem\Oss;


use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use OSS\Core\OssException;
use OSS\OssClient;

class OssAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var
     */
    protected $accessKeyId;

    /**
     * @var
     */
    protected $accessKeySecret;

    /**
     * @var
     */
    protected $endpoint;

    /**
     * @var
     */
    protected $bucket;

    /**
     * @var
     */
    protected $isCName;

    protected $domain;

    /**
     * OssAdapter constructor.
     *
     * @param $accessKeyId
     * @param $accessKeySecret
     * @param $endpoint
     * @param $bucket
     * @param bool $isCName
     * @param null $domain
     */
    public function __construct($accessKeyId, $accessKeySecret, $endpoint, $bucket, $isCName = false, $domain = null)
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->endpoint = $endpoint;
        $this->bucket = $bucket;
        $this->isCName = $isCName;
        $this->domain = $domain;
    }


    /**
     * create oss client
     *
     * @return OssClient
     * @throws \OSS\Core\OssException
     */
    protected function client()
    {
        $client = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint, $this->isCName);

        return $client;
    }

    /**
     * write a file
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return array|false|null
     * @throws \OSS\Core\OssException
     */
    public function write($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);

        $options = [];

        if ($config->has('options')) {
            $options = $config->get('options');
        }

        return $this->client()->putObject($this->bucket, $path, $contents, $options);
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config
     * @return array|false|null
     * @throws \OSS\Core\OssException
     */
    public function writeStream($path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);

        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config
     * @return array|false|null
     * @throws \OSS\Core\OssException
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config
     * @return array|false|null
     * @throws \OSS\Core\OssException
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * rename a file
     *
     * @param string $path
     * @param string $newpath
     * @return bool
     * @throws OssException
     */
    public function rename($path, $newpath)
    {
        if (!$this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * copy a file
     *
     * @param string $path
     * @param string $newpath
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        try {
            $this->client()->copyObject($this->bucket, $path, $this->bucket, $newpath);
        } catch (OssException $exception) {
            return false;
        }

        return true;
    }

    /**
     * delete a file
     *
     * @param string $path
     * @return bool
     * @throws OssException
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $this->client()->deleteObject($this->bucket, $path);
        } catch (OssException $ossException) {
            return false;
        }

        return !$this->has($path);
    }

    /**
     * Delete a directory
     *
     * @param string $dirname
     * @return bool
     */
    public function deleteDir($dirname)
    {
        return true;
    }

    /**
     * create a directory
     *
     * @param string $dirname
     * @param Config $config
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     * @return array|bool|null
     * @throws \OSS\Core\OssException
     */
    public function has($path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->client()->doesObjectExist($this->bucket, $path);
    }

    public function read($path)
    {
        // TODO: Implement read() method.
    }

    public function readStream($path)
    {
        // TODO: Implement readStream() method.
    }

    public function listContents($directory = '', $recursive = false)
    {
        // TODO: Implement listContents() method.
    }

    public function getMetadata($path)
    {
        // TODO: Implement getMetadata() method.
    }

    public function getSize($path)
    {
        // TODO: Implement getSize() method.
    }

    public function getMimetype($path)
    {
        // TODO: Implement getMimetype() method.
    }

    public function getTimestamp($path)
    {
        // TODO: Implement getTimestamp() method.
    }
}