<?php

namespace Jason\Flysystem\Oss;

use Carbon\Carbon;
use Iidestiny\Flysystem\Oss\Traits\SignatureTrait;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use OSS\Core\OssException;
use OSS\OssClient;

/**
 * Class OssAdapter.
 * @author iidestiny <iidestiny@vip.qq.com>
 */
class OssAdapter extends AbstractAdapter
{

    use NotSupportingVisibilityTrait;
    use SignatureTrait;

    // 系统参数
    const SYSTEM_FIELD = [
        'bucket'   => '${bucket}',
        'etag'     => '${etag}',
        'filename' => '${object}',
        'size'     => '${size}',
        'mimeType' => '${mimeType}',
        'height'   => '${imageInfo.height}',
        'width'    => '${imageInfo.width}',
        'format'   => '${imageInfo.format}',
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
     * @var string
     */
    protected $cdnHost;

    /**
     * @var array
     */
    protected $buckets;

    /**
     * @var OssClient
     */
    protected $client;

    /**
     * @var bool
     */
    protected $useSSL = false;

    /**
     * OssAdapter constructor.
     * @param string      $accessKeyId
     * @param string      $accessKeySecret
     * @param string      $endpoint
     * @param string      $bucket
     * @param bool        $isCName
     * @param string      $prefix
     * @param array       $buckets
     * @param string|null $cdnHost
     * @throws \OSS\Core\OssException
     */
    public function __construct(string $accessKeyId, string $accessKeySecret, string $endpoint, string $bucket, bool $isCName = false, string $prefix = '', array $buckets = [], string $cdnHost = null)
    {
        $this->accessKeyId     = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->endpoint        = $endpoint;
        $this->bucket          = $bucket;
        $this->isCName         = $isCName;
        $this->cdnHost         = $cdnHost;
        $this->setPathPrefix($prefix);
        $this->buckets = $buckets;
        $this->initClient();
        $this->checkEndpoint();
    }

    /**
     * 调用不同的桶配置.
     * @param $bucket
     * @return $this
     * @throws OssException|\Exception
     */
    public function bucket($bucket)
    {
        if (!isset($this->buckets[$bucket])) {
            throw new \Exception('bucket is not exist.');
        }
        $bucketConfig = $this->buckets[$bucket];

        $this->accessKeyId     = $bucketConfig['access_key'];
        $this->accessKeySecret = $bucketConfig['secret_key'];
        $this->endpoint        = $bucketConfig['endpoint'];
        $this->bucket          = $bucketConfig['bucket'];
        $this->isCName         = $bucketConfig['isCName'];

        $this->initClient();
        $this->checkEndpoint();

        return $this;
    }

    /**
     * init oss client.
     * @throws OssException
     */
    protected function initClient(): void
    {
        $this->client = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint, $this->isCName);
    }

    /**
     * get ali sdk kernel class.
     * @return OssClient
     */
    public function getClient(): OssClient
    {
        return $this->client;
    }

    /**
     * oss 直传配置.
     * @param string $prefix
     * @param null   $callBackUrl
     * @param array  $customData
     * @param int    $expire
     * @param int    $contentLengthRangeValue
     * @param array  $systemData
     * @return false|string
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
        $data        = [];
        if (!empty($customData)) {
            foreach ($customData as $key => $value) {
                $callbackVar['x:' . $key] = $value;
                $data[$key]               = '${x:' . $key . '}';
            }
        }

        $callbackParam      = [
            'callbackUrl'      => $callBackUrl,
            'callbackBody'     => urldecode(http_build_query(array_merge($system, $data))),
            'callbackBodyType' => 'application/x-www-form-urlencoded',
        ];
        $callbackString     = json_encode($callbackParam);
        $base64CallbackBody = base64_encode($callbackString);

        $now        = time();
        $end        = $now + $expire;
        $expiration = $this->gmt_iso8601($end);

        // 最大文件大小.用户可以自己设置
        $condition    = [
            0 => 'content-length-range',
            1 => 0,
            2 => $contentLengthRangeValue,
        ];
        $conditions[] = $condition;

        $start        = [
            0 => 'starts-with',
            1 => '$key',
            2 => $prefix,
        ];
        $conditions[] = $start;

        $arr          = [
            'expiration' => $expiration,
            'conditions' => $conditions,
        ];
        $policy       = json_encode($arr);
        $base64Policy = base64_encode($policy);
        $stringToSign = $base64Policy;
        $signature    = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret, true));

        $response                 = [];
        $response['accessid']     = $this->accessKeyId;
        $response['host']         = $this->normalizeHost();
        $response['policy']       = $base64Policy;
        $response['signature']    = $signature;
        $response['expire']       = $end;
        $response['callback']     = $base64CallbackBody;
        $response['callback-var'] = $callbackVar;
        $response['dir']          = $prefix;  // 这个参数是设置用户上传文件时指定的前缀。

        return json_encode($response);
    }

    /**
     * sign url.
     * @param string $path
     * @param int    $timeout
     * @param array  $options
     * @return bool|string
     */
    public function signUrl(string $path, int $timeout, array $options = [])
    {
        $path = $this->applyPathPrefix($path);

        try {
            $path = $this->client->signUrl($this->bucket, $path, $timeout, OssClient::OSS_HTTP_GET, $options);
        } catch (OssException $exception) {
            return false;
        }

        return $path;
    }

    /**
     * temporary file url.
     * @param string $path
     * @param int    $expiration
     * @param array  $options
     * @return bool|string
     */
    public function getTemporaryUrl(string $path, int $expiration, array $options = [])
    {
        return $this->signUrl($path, Carbon::now()->diffInSeconds($expiration), $options);
    }

    /**
     * write a file.
     * @param string                   $path
     * @param string                   $contents
     * @param \League\Flysystem\Config $config
     * @return array|bool
     */
    public function write(string $path, string $contents, Config $config)
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
     * @param string                   $path
     * @param resource                 $resource
     * @param \League\Flysystem\Config $config
     * @return array|bool|false
     */
    public function writeStream(string $path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);

        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file.
     * @param string                   $path
     * @param string                   $contents
     * @param \League\Flysystem\Config $config
     * @return array|bool
     */
    public function update(string $path, string $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     * @param string                   $path
     * @param resource                 $resource
     * @param \League\Flysystem\Config $config
     * @return array|bool
     */
    public function updateStream(string $path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * rename a file.
     * @param string $path
     * @param string $newPath
     * @return bool
     * @throws \OSS\Core\OssException
     */
    public function rename(string $path, string $newPath)
    {
        if (!$this->copy($path, $newPath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * copy a file.
     * @param string $path
     * @param string $newPath
     * @return bool
     */
    public function copy(string $path, string $newPath)
    {
        $path    = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newPath);

        try {
            $this->client->copyObject($this->bucket, $path, $this->bucket, $newpath);
        } catch (OssException $exception) {
            return false;
        }

        return true;
    }

    /**
     * delete a file.
     * @param string $path
     * @return bool
     * @throws \OSS\Core\OssException
     */
    public function delete(string $path)
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
     * @param string $dirname
     * @return bool
     * @throws \OSS\Core\OssException
     */
    public function deleteDir(string $dirname)
    {
        $fileList = $this->listContents($dirname, true);
        foreach ($fileList as $file) {
            $this->delete($file['path']);
        }

        return !$this->has($dirname);
    }

    /**
     * create a directory.
     * @param string                   $dirname
     * @param \League\Flysystem\Config $config
     * @return bool
     */
    public function createDir(string $dirname, Config $config)
    {
        $defaultFile = trim($dirname, '/') . '/oss.txt';

        return $this->write($defaultFile, '当虚拟目录下有其他文件时，可删除此文件~', $config);
    }

    /**
     * visibility.
     * @param string $path
     * @param string $visibility
     * @return array|bool|false
     */
    public function setVisibility(string $path, string $visibility)
    {
        $object = $this->applyPathPrefix($path);
        $acl    = (AdapterInterface::VISIBILITY_PUBLIC === $visibility) ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;

        try {
            $this->client->putObjectAcl($this->bucket, $object, $acl);
        } catch (OssException $exception) {
            return false;
        }

        return compact('visibility');
    }

    /**
     * Check whether a file exists.
     * @param string $path
     * @return array|bool|null
     */
    public function has(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->client->doesObjectExist($this->bucket, $path);
    }

    /**
     * Get resource url.
     * @param string $path
     * @return string
     */
    public function getUrl(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->normalizeHost() . ltrim($path, '/');
    }

    /**
     * read a file.
     * @param string $path
     * @return array|bool|false
     */
    public function read(string $path)
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
     * @param string $path
     * @return array|bool|false
     */
    public function readStream(string $path)
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
     * @param string $directory
     * @param bool   $recursive
     * @return array
     * @throws OssException
     */
    public function listContents(string $directory = '', bool $recursive = false)
    {
        $list = [];

        $result = $this->listDirObjects($directory, true);

        if (!empty($result['objects'])) {
            foreach ($result['objects'] as $files) {
                if (!$fileInfo = $this->normalizeFileInfo($files)) {
                    continue;
                }

                $list[] = $fileInfo;
            }
        }

        return $list;
    }

    /**
     * get meta data.
     * @param string $path
     * @return array|bool|false
     */
    public function getMetadata(string $path)
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
     * @param string $path
     * @return array|false
     */
    public function getSize(string $path)
    {
        return $this->normalizeFileInfo(['Key' => $path]);
    }

    /**
     * get mime type.
     * @param string $path
     * @return array|false
     */
    public function getMimetype(string $path)
    {
        return $this->normalizeFileInfo(['Key' => $path]);
    }

    /**
     * get timestamp.
     * @param string $path
     * @return array|false
     */
    public function getTimestamp(string $path)
    {
        return $this->normalizeFileInfo(['Key' => $path]);
    }

    /**
     * normalize Host.
     * @return string
     */
    protected function normalizeHost()
    {
        if ($this->isCName) {
            if (!empty($this->cdnHost)) {
                $domain = $this->cdnHost;
            } else {
                $domain = $this->endpoint;
            }
        } else {
            $domain = $this->bucket . '.' . $this->endpoint;
        }

        if ($this->useSSL) {
            $domain = "https://{$domain}";
        } else {
            $domain = "http://{$domain}";
        }

        return rtrim($domain, '/') . '/';
    }

    /**
     * Check the endpoint to see if SSL can be used.
     */
    protected function checkEndpoint()
    {
        if (0 === strpos($this->endpoint, 'http://')) {
            $this->endpoint = substr($this->endpoint, strlen('http://'));
            $this->useSSL   = false;
        } elseif (0 === strpos($this->endpoint, 'https://')) {
            $this->endpoint = substr($this->endpoint, strlen('https://'));
            $this->useSSL   = true;
        }
    }

    /**
     * Read an object from the OssClient.
     * @param string $path
     * @return string
     */
    protected function getObject(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->client->getObject($this->bucket, $path);
    }

    /**
     * File list core method.
     * @param string $dirname
     * @param bool   $recursive
     * @return array
     * @throws OssException
     */
    public function listDirObjects(string $dirname = '', bool $recursive = false)
    {
        $delimiter  = '/';
        $nextMarker = '';
        $maxkeys    = 1000;

        $result = [];

        while (true) {
            $options = [
                'delimiter' => $delimiter,
                'prefix'    => $dirname,
                'max-keys'  => $maxkeys,
                'marker'    => $nextMarker,
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
                    $object['Prefix']       = $dirname;
                    $object['Key']          = $objectInfo->getKey();
                    $object['LastModified'] = $objectInfo->getLastModified();
                    $object['eTag']         = $objectInfo->getETag();
                    $object['Type']         = $objectInfo->getType();
                    $object['Size']         = $objectInfo->getSize();
                    $object['StorageClass'] = $objectInfo->getStorageClass();
                    $result['objects'][]    = $object;
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
                    $next              = $this->listDirObjects($prefix, $recursive);
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
     * Notes: normalize file info.
     * @Author: <C.Jason>
     * @Date  : 2020/9/7 10:48 上午
     * @param array $stats
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
            'type'      => 'file',
            'mimetype'  => $meta['content-type'],
            'path'      => $filePath,
            'timestamp' => $meta['info']['filetime'],
            'size'      => $meta['content-length'],
        ];
    }

}
