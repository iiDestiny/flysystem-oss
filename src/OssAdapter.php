<?php

/*
 * This file is part of the iidestiny/flysystem-oss.
 *
 * (c) iidestiny <iidestiny@vip.qq.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Iidestiny\Flysystem\Oss;

use Carbon\Carbon;
use Iidestiny\Flysystem\Oss\Traits\SignatureTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use OSS\Core\OssException;
use OSS\OssClient;

/**
 * Class OssAdapter.
 *
 * @author iidestiny <iidestiny@vip.qq.com>
 */
class OssAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;
    use SignatureTrait;

    // 系统参数
    const SYSTEM_FIELD = [
        'bucket' => '${bucket}',
        'etag' => '${etag}',
        'filename' => '${object}',
        'size' => '${size}',
        'mimeType' => '${mimeType}',
        'height' => '${imageInfo.height}',
        'width' => '${imageInfo.width}',
        'format' => '${imageInfo.format}',
    ];

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

    /**
     * @var array
     */
    protected $buckets;

    /**
     * @var OssClient
     */
    protected $client;

    /**
     * @var array|mixed[]
     */
    protected $params;

    /**
     * @var bool
     */
    protected $useSSL = false;

    /**
     * OssAdapter constructor.
     *
     * @param       $accessKeyId
     * @param       $accessKeySecret
     * @param       $endpoint
     * @param       $bucket
     * @param bool  $isCName
     * @param       $prefix
     * @param array $buckets
     * @param mixed ...$params
     *
     * @throws OssException
     */
    public function __construct($accessKeyId, $accessKeySecret, $endpoint, $bucket, $isCName = false, $prefix = '', $buckets = [], ...$params)
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->endpoint = $endpoint;
        $this->bucket = $bucket;
        $this->isCName = $isCName;
        $this->setPathPrefix($prefix);
        $this->buckets = $buckets;
        $this->params = $params;
        $this->initClient();
        $this->checkEndpoint();
    }

    /**
     * 调用不同的桶配置.
     *
     * @param $bucket
     *
     * @return $this
     *
     * @throws OssException
     */
    public function bucket($bucket)
    {
        if (!isset($this->buckets[$bucket])) {
            throw new \Exception('bucket is not exist.');
        }
        $bucketConfig = $this->buckets[$bucket];

        $this->accessKeyId = $bucketConfig['access_key'];
        $this->accessKeySecret = $bucketConfig['secret_key'];
        $this->endpoint = $bucketConfig['endpoint'];
        $this->bucket = $bucketConfig['bucket'];
        $this->isCName = $bucketConfig['isCName'];

        $this->initClient();
        $this->checkEndpoint();

        return $this;
    }

    /**
     * init oss client.
     *
     * @throws OssException
     */
    protected function initClient()
    {
        $this->client = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint, $this->isCName, ...$this->params);
    }

    /**
     * get ali sdk kernel class.
     *
     * @return OssClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * oss 直传配置.
     *
     * @param string $prefix
     * @param null   $callBackUrl
     * @param array  $customData
     * @param int    $expire
     * @param int    $contentLengthRangeValue
     * @param array  $systemData
     *
     * @return false|string
     *
     * @throws \Exception
     */
    public function signatureConfig($prefix = '', $callBackUrl = null, $customData = [], $expire = 30, $contentLengthRangeValue = 1048576000, $systemData = [])
    {
        if (!empty($prefix)) {
            $prefix = ltrim($prefix, '/');
        }

        // 系统参数
        $system = [];
        if (empty($systemData)) {
            $system = self::SYSTEM_FIELD;
        } else {
            foreach ($systemData as $key => $value) {
                if (!in_array($value, self::SYSTEM_FIELD)) {
                    throw new \InvalidArgumentException("Invalid oss system filed: ${value}");
                }
                $system[$key] = $value;
            }
        }

        // 自定义参数
        $callbackVar = [];
        $data = [];
        if (!empty($customData)) {
            foreach ($customData as $key => $value) {
                $callbackVar['x:'.$key] = $value;
                $data[$key] = '${x:'.$key.'}';
            }
        }

        $callbackParam = [
            'callbackUrl' => $callBackUrl,
            'callbackBody' => urldecode(http_build_query(array_merge($system, $data))),
            'callbackBodyType' => 'application/x-www-form-urlencoded',
        ];
        $callbackString = json_encode($callbackParam);
        $base64CallbackBody = base64_encode($callbackString);

        $now = time();
        $end = $now + $expire;
        $expiration = $this->gmt_iso8601($end);

        // 最大文件大小.用户可以自己设置
        $condition = [
            0 => 'content-length-range',
            1 => 0,
            2 => $contentLengthRangeValue,
        ];
        $conditions[] = $condition;

        $start = [
            0 => 'starts-with',
            1 => '$key',
            2 => $prefix,
        ];
        $conditions[] = $start;

        $arr = [
            'expiration' => $expiration,
            'conditions' => $conditions,
        ];
        $policy = json_encode($arr);
        $base64Policy = base64_encode($policy);
        $stringToSign = $base64Policy;
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret, true));

        $response = [];
        $response['accessid'] = $this->accessKeyId;
        $response['host'] = $this->normalizeHost();
        $response['policy'] = $base64Policy;
        $response['signature'] = $signature;
        $response['expire'] = $end;
        $response['callback'] = $base64CallbackBody;
        $response['callback-var'] = $callbackVar;
        $response['dir'] = $prefix;  // 这个参数是设置用户上传文件时指定的前缀。

        return json_encode($response);
    }

    /**
     * sign url.
     *
     * @param $path
     * @param $timeout
     *
     * @return bool|string
     */
    public function signUrl($path, $timeout, array $options = [], $method = OssClient::OSS_HTTP_GET)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $path = $this->client->signUrl($this->bucket, $path, $timeout, $method, $options);
        } catch (OssException $exception) {
            return false;
        }

        return $path;
    }

    /**
     * temporary file url.
     *
     * @param $path
     * @param $expiration
     *
     * @return bool|string
     */
    public function getTemporaryUrl($path, $expiration, array $options = [], $method = OssClient::OSS_HTTP_GET)
    {
        return $this->signUrl($path, Carbon::now()->diffInSeconds($expiration), $options, $method);
    }

    /**
     * write a file.
     *
     * @param string $path
     * @param string $contents
     *
     * @return array|bool|false
     */
    public function write($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);

        $options = [];

        if ($config->has('options')) {
            $options = $config->get('options');
        }

        $this->client->putObject($this->bucket, $path, $contents, $options);

        return true;
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     *
     * @return array|bool|false
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
     *
     * @return array|bool|false
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     *
     * @return array|bool|false
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     *
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
     * copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        try {
            $this->client->copyObject($this->bucket, $path, $this->bucket, $newpath);
        } catch (OssException $exception) {
            return false;
        }

        return true;
    }

    /**
     * delete a file.
     *
     * @param string $path
     *
     * @return bool
     *
     * @throws OssException
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $this->client->deleteObject($this->bucket, $path);
        } catch (OssException $ossException) {
            return false;
        }

        return !$this->has($path);
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     *
     * @throws OssException
     */
    public function deleteDir($dirname)
    {
        $fileList = $this->listContents($dirname, true);
        foreach ($fileList as $file) {
            $this->delete($file['path']);
        }

        return !$this->has($dirname);
    }

    /**
     * create a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function createDir($dirname, Config $config)
    {
        $defaultFile = trim($dirname, '/').'/oss.txt';

        return $this->write($defaultFile, '当虚拟目录下有其他文件时，可删除此文件~', $config);
    }

    /**
     * visibility.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|bool|false
     */
    public function setVisibility($path, $visibility)
    {
        $object = $this->applyPathPrefix($path);
        $acl = (AdapterInterface::VISIBILITY_PUBLIC === $visibility) ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;

        try {
            $this->client->putObjectAcl($this->bucket, $object, $acl);
        } catch (OssException $exception) {
            return false;
        }

        return compact('visibility');
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->client->doesObjectExist($this->bucket, $path);
    }

    /**
     * Get resource url.
     *
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->normalizeHost().ltrim($path, '/');
    }

    /**
     * read a file.
     *
     * @param string $path
     *
     * @return array|bool|false
     */
    public function read($path)
    {
        try {
            $contents = $this->getObject($path);
        } catch (OssException $exception) {
            return false;
        }

        return compact('contents', 'path');
    }

    /**
     * read a file stream.
     *
     * @param string $path
     *
     * @return array|bool|false
     */
    public function readStream($path)
    {
        try {
            $stream = $this->getObject($path);
        } catch (OssException $exception) {
            return false;
        }

        return compact('stream', 'path');
    }

    /**
     * Lists all files in the directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     *
     * @throws OssException
     */
    public function listContents($directory = '', $recursive = false)
    {
        $list = [];
        $directory = '/' == substr($directory, -1) ? $directory : $directory.'/';
        $result = $this->listDirObjects($directory, $recursive);

        if (!empty($result['objects'])) {
            foreach ($result['objects'] as $files) {
                if ('oss.txt' == substr($files['Key'], -7) || !$fileInfo = $this->normalizeFileInfo($files)) {
                    continue;
                }
                $list[] = $fileInfo;
            }
        }

        // prefix
        if (!empty($result['prefix'])) {
            foreach ($result['prefix'] as $dir) {
                $list[] = [
                    'type' => 'dir',
                    'path' => $dir,
                ];
            }
        }

        return $list;
    }

    /**
     * get meta data.
     *
     * @param string $path
     *
     * @return array|bool|false
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $metadata = $this->client->getObjectMeta($this->bucket, $path);
        } catch (OssException $exception) {
            return false;
        }

        return $metadata;
    }

    /**
     * get the size of file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->normalizeFileInfo(['Key' => $path]);
    }

    /**
     * get mime type.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        return $this->normalizeFileInfo(['Key' => $path]);
    }

    /**
     * get timestamp.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->normalizeFileInfo(['Key' => $path]);
    }

    /**
     * normalize Host.
     *
     * @return string
     */
    protected function normalizeHost()
    {
        if ($this->isCName) {
            $domain = $this->endpoint;
        } else {
            $domain = $this->bucket.'.'.$this->endpoint;
        }

        if ($this->useSSL) {
            $domain = "https://{$domain}";
        } else {
            $domain = "http://{$domain}";
        }

        return rtrim($domain, '/').'/';
    }

    /**
     * Check the endpoint to see if SSL can be used.
     */
    protected function checkEndpoint()
    {
        if (0 === strpos($this->endpoint, 'http://')) {
            $this->endpoint = substr($this->endpoint, strlen('http://'));
            $this->useSSL = false;
        } elseif (0 === strpos($this->endpoint, 'https://')) {
            $this->endpoint = substr($this->endpoint, strlen('https://'));
            $this->useSSL = true;
        }
    }

    /**
     * Read an object from the OssClient.
     *
     * @param $path
     *
     * @return string
     */
    protected function getObject($path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->client->getObject($this->bucket, $path);
    }

    /**
     * File list core method.
     *
     * @param string $dirname
     * @param bool   $recursive
     *
     * @return array
     *
     * @throws OssException
     */
    public function listDirObjects($dirname = '', $recursive = false)
    {
        $delimiter = '/';
        $nextMarker = '';
        $maxkeys = 1000;

        $result = [];

        while (true) {
            $options = [
                'delimiter' => $delimiter,
                'prefix' => $dirname,
                'max-keys' => $maxkeys,
                'marker' => $nextMarker,
            ];

            try {
                $listObjectInfo = $this->client->listObjects($this->bucket, $options);
            } catch (OssException $exception) {
                throw $exception;
            }

            $nextMarker = $listObjectInfo->getNextMarker();
            $objectList = $listObjectInfo->getObjectList();
            $prefixList = $listObjectInfo->getPrefixList();

            if (!empty($objectList)) {
                foreach ($objectList as $objectInfo) {
                    $object['Prefix'] = $dirname;
                    $object['Key'] = $objectInfo->getKey();
                    $object['LastModified'] = $objectInfo->getLastModified();
                    $object['eTag'] = $objectInfo->getETag();
                    $object['Type'] = $objectInfo->getType();
                    $object['Size'] = $objectInfo->getSize();
                    $object['StorageClass'] = $objectInfo->getStorageClass();
                    $result['objects'][] = $object;
                }
            } else {
                $result['objects'] = [];
            }

            if (!empty($prefixList)) {
                foreach ($prefixList as $prefixInfo) {
                    $result['prefix'][] = $prefixInfo->getPrefix();
                }
            } else {
                $result['prefix'] = [];
            }

            // Recursive directory
            if ($recursive) {
                foreach ($result['prefix'] as $prefix) {
                    $next = $this->listDirObjects($prefix, $recursive);
                    $result['objects'] = array_merge($result['objects'], $next['objects']);
                }
            }

            if ('' === $nextMarker) {
                break;
            }
        }

        return $result;
    }

    /**
     * normalize file info.
     *
     * @return array
     */
    protected function normalizeFileInfo(array $stats)
    {
        $filePath = ltrim($stats['Key'], '/');

        $meta = $this->getMetadata($filePath) ?? [];

        if (empty($meta)) {
            return [];
        }

        return [
            'type' => 'file',
            'mimetype' => $meta['content-type'],
            'path' => $filePath,
            'timestamp' => $meta['info']['filetime'],
            'size' => $meta['content-length'],
        ];
    }
}
