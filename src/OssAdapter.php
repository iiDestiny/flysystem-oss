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

use Iidestiny\Flysystem\Oss\Traits\SignatureTrait;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\PathPrefixer;
use League\Flysystem\Visibility;
use OSS\Core\OssException;
use OSS\Credentials\StaticCredentialsProvider;
use OSS\OssClient;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;

/**
 * Class OssAdapter.
 *
 * @author iidestiny <iidestiny@vip.qq.com>
 */
class OssAdapter implements FilesystemAdapter
{
    use SignatureTrait;

    // 系统参数

    public const SYSTEM_FIELD = [
        'bucket'   => '${bucket}',
        'etag'     => '${etag}',
        'filename' => '${object}',
        'size'     => '${size}',
        'mimeType' => '${mimeType}',
        'height'   => '${imageInfo.height}',
        'width'    => '${imageInfo.width}',
        'format'   => '${imageInfo.format}',
    ];

    protected $accessKeyId;

    protected $accessKeySecret;

    protected $endpoint;

    protected string $bucketName = '';

    protected $isCName;

    /**
     * @var array<string, array>
     */
    protected array $buckets = [];

    /**
     * @var array<string, OssAdapter>
     */
    protected array $bucketAdapters = [];

    /**
     * @var OssClient
     */
    protected $client;

    /**
     * @var array
     */
    protected $params;

    /**
     * @var bool
     */
    protected $useSSL = false;

    /**
     * @var string|null
     */
    protected ?string $cdnUrl = null;

    /**
     * @var PathPrefixer
     */
    protected $prefixer;

    public function __construct($accessKeyId, $accessKeySecret, $endpoint, $bucket, bool $isCName = false, string $prefix = '', array $buckets = [], ...$params)
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->endpoint = $endpoint;
        $this->bucketName = $bucket;
        $this->isCName = $isCName;
        $this->prefixer = new PathPrefixer($prefix, DIRECTORY_SEPARATOR);
        $this->buckets = $buckets;
        $this->params = $params;
        $this->initDefaultBucketAdapter();
    }

    public function getBucketName(): string
    {
        return $this->bucketName;
    }

    /**
     * init default bucket adapter.
     *
     * @return \Iidestiny\Flysystem\Oss\OssAdapter
     */
    protected function initDefaultBucketAdapter(): OssAdapter
    {
        $this->initClient()
            ->checkEndpoint()
            ->bucketAdapters[$this->bucketName] = $this;

        return $this;
    }

    /**
     * set cdn url.
     *
     * @param string|null $url
     * @return $this
     */
    public function setCdnUrl(?string $url): OssAdapter
    {
        $this->cdnUrl = $url;

        return $this;
    }

    public function ossKernel(): OssClient
    {
        return $this->getClient();
    }

    /**
     * get bucket adapter by bucket name.
     *
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function bucket($bucket): OssAdapter
    {
        return $this->bucketAdapters[$bucket] ?? ($this->bucketAdapters[$bucket] = $this->createBucketAdapter($bucket));
    }

    /**
     * create bucket adapter by bucket name.
     *
     * @param string $bucket
     * @return \Iidestiny\Flysystem\Oss\OssAdapter
     * @throws \InvalidArgumentException
     */
    protected function createBucketAdapter(string $bucket): OssAdapter
    {
        if (!isset($this->buckets[$bucket])) {
            throw new \InvalidArgumentException(sprintf('Bucket "%s" does not exist.', $bucket));
        }

        $config = $this->buckets[$bucket];
        $extra = array_merge($this->params, $this->extraConfig($config));

        // new bucket adapter
        $adapter = new self(
            $config['access_key'] ?? $this->accessKeyId,
            $config['secret_key'] ?? $this->accessKeySecret,
            $config['endpoint'] ?? $this->endpoint,
            $config['bucket'],
            $config['isCName'] ?? false,
            $config['root'] ?? '',
            [],
            ...$extra);

        return $adapter->setCdnUrl($config['url'] ?? null)->initDefaultBucketAdapter();
    }

    /**
     * extract extra config.
     *
     * @param array $config
     * @return array
     */
    protected function extraConfig(array $config): array
    {
        return array_diff_key($config, array_flip(['driver', 'root', 'buckets', 'access_key', 'secret_key',
            'endpoint', 'bucket', 'isCName', 'url']));
    }

    /**
     * init oss client.
     *
     * @return $this
     */
    protected function initClient(): OssAdapter
    {
        $provider = new StaticCredentialsProvider($this->accessKeyId, $this->accessKeySecret, $this->params['securityToken'] ?? null);

        $this->client = new OssClient([
            'endpoint' => rtrim($this->endpoint, '/'),
            'cname'    => $this->isCName,
            'provider' => $provider,
            ...$this->params,
        ]);

        return $this;
    }

    /**
     * get ali sdk kernel class.
     */
    public function getClient(): OssClient
    {
        return $this->client;
    }

    /**
     * 验签.
     */
    public function verify(): array
    {
        // oss 前面 header、公钥 header
        $authorizationBase64 = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $pubKeyUrlBase64 = $_SERVER['HTTP_X_OSS_PUB_KEY_URL'] ?? '';
        // 验证失败
        if (empty($authorizationBase64) || empty($pubKeyUrlBase64)) {
            return [false, ['CallbackFailed' => 'authorization or pubKeyUrl is null']];
        }

        // 获取OSS的签名
        $authorization = base64_decode($authorizationBase64);
        // 获取公钥
        $pubKeyUrl = base64_decode($pubKeyUrlBase64);
        // 请求验证
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $pubKeyUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        if (!$pubKey = curl_exec($ch)) {
            return [false, ['CallbackFailed' => 'curl is fail']];
        }

        // 获取回调 body
        $body = file_get_contents('php://input');
        // 拼接待签名字符串
        $path = $_SERVER['REQUEST_URI'];
        $pos = strpos($path, '?');
        if (false === $pos) {
            $authStr = urldecode($path)."\n".$body;
        } else {
            $authStr = urldecode(substr($path, 0, $pos)).substr($path, $pos, strlen($path) - $pos)."\n".$body;
        }
        // 验证签名
        $ok = openssl_verify($authStr, $authorization, $pubKey, OPENSSL_ALGO_MD5);

        if (1 !== $ok) {
            curl_close($ch);

            return [false, ['CallbackFailed' => 'verify is fail, Illegal data']];
        }

        parse_str($body, $data);
        curl_close($ch);

        return [true, $data];
    }

    /**
     * oss 直传配置.
     *
     * @param string $prefix 目录前缀
     * @param null $callBackUrl 回调地址
     * @param array $customData 自定义参数
     * @param int $expire 过期时间（秒）
     * @param array $systemData 系统接收参数，回调时会返回
     * @param array $policyData 自定义 policy 参数
     *                          see: https://help.aliyun.com/zh/oss/developer-reference/postobject#section-d5z-1ww-wdb
     * @return string
     * @throws \JsonException|\InvalidArgumentException|\DateMalformedStringException
     * @see https://help.aliyun.com/zh/oss/use-cases/overview-20
     */
    public function signatureConfig(string $prefix = '', $callBackUrl = null, array $customData = [], int $expire = 30, array $systemData = [], array $policyData = []): string
    {
        $prefix = $this->prefixer->prefixPath($prefix);

        // 系统参数
        $system = [];
        if (empty($systemData)) {
            $system = self::SYSTEM_FIELD;
        } else {
            foreach ($systemData as $key => $value) {
                if (!in_array($value, self::SYSTEM_FIELD, true)) {
                    throw new \InvalidArgumentException("Invalid oss system filed: {$value}");
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
        $callbackString = json_encode($callbackParam, JSON_THROW_ON_ERROR);
        $base64CallbackBody = base64_encode($callbackString);

        $now = time();
        $end = $now + $expire;
        $expiration = $this->gmt_iso8601($end);

        // 如果用户没有设置文件大小，需要设置默认值
        $hasContentLengthRange = false;
        $contentLengthRangeKey = 'content-length-range';
        foreach ($policyData as $item) {
            if (isset($item[0]) && $item[0] === $contentLengthRangeKey) {
                $hasContentLengthRange = true;
                break;
            }
        }
        if (!$hasContentLengthRange) {
            $condition = [
                0 => $contentLengthRangeKey,
                1 => 0, // min: 0
                2 => 1048576000, // max: 1GB
            ];
            $conditions[] = $condition;
        }
        $conditions[] = [
            0 => 'starts-with',
            1 => '$key',
            2 => $prefix,
        ];

        $arr = [
            'expiration' => $expiration,
            'conditions' => array_merge($conditions, $policyData), // 将自定义policy参数一起合并
        ];
        $policy = json_encode($arr, JSON_THROW_ON_ERROR);
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

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    /**
     * sign url.
     *
     * @return false|string
     */
    public function getTemporaryUrl(string $path, int $timeout, array $options = [], string $method = OssClient::OSS_HTTP_GET): bool|string
    {
        $path = $this->prefixer->prefixPath($path);

        try {
            $path = $this->client->signUrl($this->bucketName, $path, $timeout, $method, $options);
        } catch (OssException) {
            return false;
        }

        return $path;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $path = $this->prefixer->prefixPath($path);
        $options = $config->get('options', []);

        try {
            $this->client->putObject($this->bucketName, $path, $contents, $options);
        } catch (\Exception $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage());
        }
    }

    /**
     * Write a new file using a stream.
     *
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $path = $this->prefixer->prefixPath($path);
        $options = $config->get('options', []);

        try {
            $this->client->uploadStream($this->bucketName, $path, $contents, $options);
        } catch (OssException $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getErrorCode(), $exception);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (\Exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $path = $this->prefixer->prefixPath($source);
        $newPath = $this->prefixer->prefixPath($destination);

        try {
            $this->client->copyObject($this->bucketName, $path, $this->bucketName, $newPath);
        } catch (OssException) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * delete a file.
     *
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function delete(string $path): void
    {
        $path = $this->prefixer->prefixPath($path);

        try {
            $this->client->deleteObject($this->bucketName, $path);
        } catch (OssException) {
            throw UnableToDeleteFile::atLocation($path);
        }
    }

    /**
     * @throws OssException|\OSS\Http\RequestCore_Exception
     * @throws \Exception
     */
    public function deleteDirectory(string $path): void
    {
        try {
            $contents = $this->listContents($path, false);
            $files = [];
            foreach ($contents as $i => $content) {
                if ($content instanceof DirectoryAttributes) {
                    $this->deleteDirectory($content->path());
                    continue;
                }
                $files[] = $this->prefixer->prefixPath($content->path());
                if ($i && 0 === $i % 100) {
                    $this->client->deleteObjects($this->bucketName, $files);
                    $files = [];
                }
            }
            !empty($files) && $this->client->deleteObjects($this->bucketName, $files);
            $this->client->deleteObject($this->bucketName, $this->prefixer->prefixDirectoryPath($path));
        } catch (OssException $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getErrorCode(), $exception);
        }
    }

    /**
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->client->createObjectDir($this->bucketName, $this->prefixer->prefixPath($path));
        } catch (OssException $exception) {
            throw UnableToCreateDirectory::dueToFailure($path, $exception);
        }
    }

    /**
     * visibility.
     *
     * @param string $path
     * @param string $visibility
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $object = $this->prefixer->prefixPath($path);

        $acl = Visibility::PUBLIC === $visibility ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;

        try {
            $this->client->putObjectAcl($this->bucketName, $object, $acl);
        } catch (OssException $exception) {
            throw UnableToSetVisibility::atLocation($path, $exception->getMessage());
        }
    }

    /**
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function visibility(string $path): FileAttributes
    {
        try {
            $acl = $this->client->getObjectAcl($this->bucketName, $this->prefixer->prefixPath($path), []);
        } catch (OssException $exception) {
            throw UnableToRetrieveMetadata::visibility($path, $exception->getMessage());
        }

        return new FileAttributes($path, null, OssClient::OSS_ACL_TYPE_PRIVATE === $acl ? Visibility::PRIVATE : Visibility::PUBLIC);
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     * @return bool
     * @throws \OSS\Core\OssException
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function fileExists(string $path): bool
    {
        $path = $this->prefixer->prefixPath($path);

        return $this->client->doesObjectExist($this->bucketName, $path);
    }

    /**
     * @throws \OSS\Core\OssException
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function directoryExists(string $path): bool
    {
        return $this->client->doesObjectExist($this->bucketName, $this->prefixer->prefixDirectoryPath($path));
    }

    /**
     * Get resource url.
     */
    public function getUrl(string $path): string
    {
        $path = $this->prefixer->prefixPath($path);

        if (!is_null($this->cdnUrl)) {
            return rtrim($this->cdnUrl, '/').'/'.ltrim($path, '/');
        }

        return $this->normalizeHost().ltrim($path, '/');
    }

    /**
     * read a file.
     */
    public function read(string $path): string
    {
        $path = $this->prefixer->prefixPath($path);

        try {
            return $this->client->getObject($this->bucketName, $path);
        } catch (\Exception $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage());
        }
    }

    /**
     * read a file stream.
     *
     * @return array|bool|false
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');

        try {
            fwrite($stream, $this->client->getObject($this->bucketName, $path, [OssClient::OSS_FILE_DOWNLOAD => $stream]));
        } catch (OssException $exception) {
            fclose($stream);
            throw UnableToReadFile::fromLocation($path, $exception->getMessage());
        }
        rewind($stream);

        return $stream;
    }

    /**
     * @throws \Exception
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $directory = $this->prefixer->prefixDirectoryPath($path);
        $nextMarker = '';
        while (true) {
            $options = [
                OssClient::OSS_PREFIX => $directory,
                OssClient::OSS_MARKER => $nextMarker,
            ];

            try {
                $listObjectInfo = $this->client->listObjects($this->bucketName, $options);
                $nextMarker = $listObjectInfo->getNextMarker();
            } catch (OssException $exception) {
                throw new \Exception($exception->getErrorMessage(), 0, $exception);
            }

            $prefixList = $listObjectInfo->getPrefixList();
            foreach ($prefixList as $prefixInfo) {
                $subPath = $this->prefixer->stripDirectoryPrefix($prefixInfo->getPrefix());
                if ($subPath == $path) {
                    continue;
                }
                yield new DirectoryAttributes($subPath);
                if ($deep) {
                    $contents = $this->listContents($subPath, true);
                    foreach ($contents as $content) {
                        yield $content;
                    }
                }
            }

            $listObject = $listObjectInfo->getObjectList();
            if (!empty($listObject)) {
                foreach ($listObject as $objectInfo) {
                    $objectPath = $this->prefixer->stripPrefix($objectInfo->getKey());
                    $objectLastModified = strtotime($objectInfo->getLastModified());
                    if ('/' == substr($objectPath, -1, 1)) {
                        continue;
                    }
                    yield new FileAttributes($objectPath, $objectInfo->getSize(), null, $objectLastModified);
                }
            }

            if ('true' !== $listObjectInfo->getIsTruncated()) {
                break;
            }
        }
    }

    /**
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function getMetadata($path): FileAttributes
    {
        try {
            $result = $this->client->getObjectMeta($this->bucketName, $this->prefixer->prefixPath($path));
        } catch (OssException $exception) {
            throw UnableToRetrieveMetadata::create($path, 'metadata', $exception->getErrorCode(), $exception);
        }

        $size = (int)($result['content-length'] ?? 0);
        $timestamp = isset($result['last-modified']) ? strtotime($result['last-modified']) : 0;
        $mimetype = $result['content-type'] ?? '';

        return new FileAttributes($path, $size, null, $timestamp, $mimetype);
    }

    /**
     * get the size of file.
     *
     * @param string $path
     * @return \League\Flysystem\FileAttributes
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function fileSize(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if (null === $meta->fileSize()) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return $meta;
    }

    /**
     * get mime type.
     *
     * @param string $path
     * @return \League\Flysystem\FileAttributes
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function mimeType(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if (null === $meta->mimeType()) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return $meta;
    }

    /**
     * get timestamp.
     *
     * @param string $path
     * @return \League\Flysystem\FileAttributes
     * @throws \OSS\Http\RequestCore_Exception
     */
    public function lastModified(string $path): FileAttributes
    {
        $meta = $this->getMetadata($path);
        if (null === $meta->lastModified()) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return $meta;
    }

    /**
     * normalize Host.
     */
    protected function normalizeHost(): string
    {
        if ($this->isCName) {
            $domain = $this->endpoint;
        } else {
            $domain = $this->bucketName.'.'.$this->endpoint;
        }

        if ($this->useSSL) {
            $scheme = 'https';
        } else {
            $scheme = 'http';
        }

        return rtrim($scheme.'://'.$domain, '/').'/';
    }

    /**
     * Check the endpoint to see if SSL can be used.
     *
     * @return $this
     */
    protected function checkEndpoint(): OssAdapter
    {
        if (str_starts_with($this->endpoint, 'http://')) {
            $this->endpoint = substr($this->endpoint, strlen('http://'));
            $this->useSSL = false;
        } elseif (str_starts_with($this->endpoint, 'https://')) {
            $this->endpoint = substr($this->endpoint, strlen('https://'));
            $this->useSSL = true;
        }

        return $this;
    }
}
