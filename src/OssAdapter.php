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
     * @var string|null
     */
    protected $cdnUrl = null;

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
     * 设置cdn的url.
     *
     * @param string|null $url
     */
    public function setCdnUrl($url)
    {
        $this->cdnUrl = $url;
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
                $callbackVar['x:' . $key] = $value;
                $data[$key] = '${x:' . $key . '}';
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
        $files = [];
        if (!$this->copy($path, $newpath)) {
            return false;
        }
        if ($this->client->doesObjectExist($this->bucket, rtrim($path, '/') . '/')) {
            $path = rtrim($path, '/') . '/';
            $newpath = rtrim($newpath, '/') . '/';
            $files = $this->listContents($path, true);
        }
        if (count($files) > 0) {
            foreach ($files as $file) {
                $this->delete($file['path']);
            }
            $this->delete($path);
        } else {
            $this->delete($path);
        }
        return true;
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
        $files = [];
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);
        if ($this->client->doesObjectExist($this->bucket, rtrim($path, '/') . '/')) {
            $path = rtrim($path, '/') . '/';
            $newpath = rtrim($newpath, '/') . '/';
            $files = $this->listContents($path, true);
        }
        try {
            if (count($files) > 0) {
                foreach ($files as $file) {
                    $this->client->copyObject($this->bucket, $file['path'], $this->bucket, str_replace($path, $newpath, $file['path']));
                }
                $this->client->copyObject($this->bucket, $path, $this->bucket, $newpath);
            } else {
                $this->client->copyObject($this->bucket, $path, $this->bucket, $newpath);
            }
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
        if ($this->client->doesObjectExist($this->bucket, rtrim($path, '/') . '/')) {
            $path = rtrim($path, '/') . '/';
        }
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
     */
    public function deleteDir($dirname)
    {
        if ($this->client->doesObjectExist($this->bucket, rtrim($dirname, '/') . '/')) {
            $dirname = rtrim($dirname, '/') . '/';
            $files = $this->listContents($dirname, true);
        }
        if (count($files) > 0) {
            foreach ($files as $file) {
                $this->delete($file['path']);
            }
            $this->delete($dirname);
        }

        return true;
    }

    /**
     * create a directory.
     *
     * @param string $dirname
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $this->client->createObjectDir($this->bucket, $dirname, null);

        return ['path' => $dirname, 'type' => 'dir'];
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
        if ($this->client->doesObjectExist($this->bucket, rtrim($path, '/') . '/')) {
            return true;
        }
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

        if (!is_null($this->cdnUrl)) {
            return rtrim($this->cdnUrl, '/') . '/' . ltrim($path, '/');
        }

        return $this->normalizeHost() . ltrim($path, '/');
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
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $this->getObject($path));
            rewind($stream);
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
        if ($directory != '') {
            $directory = rtrim($directory, '/') . '/';
        }
        $result = $this->listDirObjects($directory, $recursive);

        if (!empty($result['objects'])) {
            foreach ($result['objects'] as $files) {
                if (!$fileInfo = $this->normalizeFileInfo($files, false)) {
                    continue;
                }
                if ($fileInfo['mimetype'] == 'application/octet-stream' && $fileInfo['size'] == 0) {
                    $fileInfo['type'] = "dir";
                }
                $list[] = $fileInfo;
            }
        }
        if (!empty($result['prefix'])) {
            foreach ($result['prefix'] as $folder) {
                $list[] = ["type" => 'dir', "mimetype" => "application/octet-stream", "size" => 0, "path" => $folder, "timestamp" => Carbon::now()->timestamp];
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
            $extraMeta = [
                'type' => data_get($metadata, 'content-length', 0) == 0 && data_get($metadata, 'content-type', 'application/octet-stream') == 'application/octet-stream' ? 'dir' : 'file',
                'mimetype' => $metadata['content-type'],
                'path' => $path,
                'timestamp' => $metadata['info']['filetime'],
                'size' => $metadata['content-length'],
            ];
            $metadata = array_merge($metadata, $extraMeta);
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
        // Recursive directory
        if ($recursive) {
            $delimiter = '';
        }
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
            $objectList = collect($objectList)->filter(function ($object) use ($dirname) {
                return $object->getKey() != $dirname;
            })->toArray();
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
     * @param array $stats
     *
     * @return array
     */
    protected function normalizeFileInfo(array $stats, $isFetchApi = true)
    {
        $filePath = ltrim($stats['Key'], '/');

        if ($isFetchApi) {
            $meta = $this->getMetadata($filePath) ?? [];
            if (empty($meta)) {
                return [];
            } else {
                return [
                    'type' => 'file',
                    'mimetype' => $meta['content-type'],
                    'path' => $filePath,
                    'timestamp' => $meta['info']['filetime'],
                    'size' => $meta['content-length'],
                ];
            }
        }
        return [
            'type' => 'file',
            'mimetype' => $this->getMime(data_get(pathinfo($filePath), 'extension')),
            'path' => $filePath,
            'timestamp' => Carbon::create(data_get($stats, 'LastModified'))->timestamp,
            'size' => data_get($stats, 'Size'),
        ];
    }
    protected function getMime($ext)
    {
        $ext = strtolower($ext);
        if (!(strpos($ext, '.') !== false)) {
            $ext = '.' . $ext;
        }

        switch ($ext) {
            case '.aac':
                $mime = 'audio/aac';
                break; // AAC audio
            case '.abw':
                $mime = 'application/x-abiword';
                break; // AbiWord document
            case '.arc':
                $mime = 'application/octet-stream';
                break; // Archive document (multiple files embedded)
            case '.avi':
                $mime = 'video/x-msvideo';
                break; // AVI: Audio Video Interleave
            case '.azw':
                $mime = 'application/vnd.amazon.ebook';
                break; // Amazon Kindle eBook format
            case '.bin':
                $mime = 'application/octet-stream';
                break; // Any kind of binary data
            case '.bmp':
                $mime = 'image/bmp';
                break; // Windows OS/2 Bitmap Graphics
            case '.bz':
                $mime = 'application/x-bzip';
                break; // BZip archive
            case '.bz2':
                $mime = 'application/x-bzip2';
                break; // BZip2 archive
            case '.csh':
                $mime = 'application/x-csh';
                break; // C-Shell script
            case '.css':
                $mime = 'text/css';
                break; // Cascading Style Sheets (CSS)
            case '.csv':
                $mime = 'text/csv';
                break; // Comma-separated values (CSV)
            case '.doc':
                $mime = 'application/msword';
                break; // Microsoft Word
            case '.docx':
                $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                break; // Microsoft Word (OpenXML)
            case '.eot':
                $mime = 'application/vnd.ms-fontobject';
                break; // MS Embedded OpenType fonts
            case '.epub':
                $mime = 'application/epub+zip';
                break; // Electronic publication (EPUB)
            case '.gif':
                $mime = 'image/gif';
                break; // Graphics Interchange Format (GIF)
            case '.htm':
                $mime = 'text/html';
                break; // HyperText Markup Language (HTML)
            case '.html':
                $mime = 'text/html';
                break; // HyperText Markup Language (HTML)
            case '.ico':
                $mime = 'image/x-icon';
                break; // Icon format
            case '.ics':
                $mime = 'text/calendar';
                break; // iCalendar format
            case '.jar':
                $mime = 'application/java-archive';
                break; // Java Archive (JAR)
            case '.jpeg':
                $mime = 'image/jpeg';
                break; // JPEG images
            case '.jpg':
                $mime = 'image/jpeg';
                break; // JPEG images
            case '.js':
                $mime = 'application/javascript';
                break; // JavaScript (IANA Specification) (RFC 4329 Section 8.2)
            case '.json':
                $mime = 'application/json';
                break; // JSON format
            case '.mid':
                $mime = 'audio/midi audio/x-midi';
                break; // Musical Instrument Digital Interface (MIDI)
            case '.midi':
                $mime = 'audio/midi audio/x-midi';
                break; // Musical Instrument Digital Interface (MIDI)
            case '.mpeg':
                $mime = 'video/mpeg';
                break; // MPEG Video
            case '.mpkg':
                $mime = 'application/vnd.apple.installer+xml';
                break; // Apple Installer Package
            case '.odp':
                $mime = 'application/vnd.oasis.opendocument.presentation';
                break; // OpenDocument presentation document
            case '.ods':
                $mime = 'application/vnd.oasis.opendocument.spreadsheet';
                break; // OpenDocument spreadsheet document
            case '.odt':
                $mime = 'application/vnd.oasis.opendocument.text';
                break; // OpenDocument text document
            case '.oga':
                $mime = 'audio/ogg';
                break; // OGG audio
            case '.ogv':
                $mime = 'video/ogg';
                break; // OGG video
            case '.ogx':
                $mime = 'application/ogg';
                break; // OGG
            case '.otf':
                $mime = 'font/otf';
                break; // OpenType font
            case '.png':
                $mime = 'image/png';
                break; // Portable Network Graphics
            case '.pdf':
                $mime = 'application/pdf';
                break; // Adobe Portable Document Format (PDF)
            case '.ppt':
                $mime = 'application/vnd.ms-powerpoint';
                break; // Microsoft PowerPoint
            case '.pptx':
                $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                break; // Microsoft PowerPoint (OpenXML)
            case '.rar':
                $mime = 'application/x-rar-compressed';
                break; // RAR archive
            case '.rtf':
                $mime = 'application/rtf';
                break; // Rich Text Format (RTF)
            case '.sh':
                $mime = 'application/x-sh';
                break; // Bourne shell script
            case '.svg':
                $mime = 'image/svg+xml';
                break; // Scalable Vector Graphics (SVG)
            case '.swf':
                $mime = 'application/x-shockwave-flash';
                break; // Small web format (SWF) or Adobe Flash document
            case '.tar':
                $mime = 'application/x-tar';
                break; // Tape Archive (TAR)
            case '.tif':
                $mime = 'image/tiff';
                break; // Tagged Image File Format (TIFF)
            case '.tiff':
                $mime = 'image/tiff';
                break; // Tagged Image File Format (TIFF)
            case '.ts':
                $mime = 'application/typescript';
                break; // Typescript file
            case '.ttf':
                $mime = 'font/ttf';
                break; // TrueType Font
            case '.txt':
                $mime = 'text/plain';
                break; // Text, (generally ASCII or ISO 8859-n)
            case '.vsd':
                $mime = 'application/vnd.visio';
                break; // Microsoft Visio
            case '.wav':
                $mime = 'audio/wav';
                break; // Waveform Audio Format
            case '.weba':
                $mime = 'audio/webm';
                break; // WEBM audio
            case '.webm':
                $mime = 'video/webm';
                break; // WEBM video
            case '.webp':
                $mime = 'image/webp';
                break; // WEBP image
            case '.woff':
                $mime = 'font/woff';
                break; // Web Open Font Format (WOFF)
            case '.woff2':
                $mime = 'font/woff2';
                break; // Web Open Font Format (WOFF)
            case '.xhtml':
                $mime = 'application/xhtml+xml';
                break; // XHTML
            case '.xls':
                $mime = 'application/vnd.ms-excel';
                break; // Microsoft Excel
            case '.xlsx':
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                break; // Microsoft Excel (OpenXML)
            case '.xml':
                $mime = 'application/xml';
                break; // XML
            case '.xul':
                $mime = 'application/vnd.mozilla.xul+xml';
                break; // XUL
            case '.zip':
                $mime = 'application/zip';
                break; // ZIP archive
            case '.3gp':
                $mime = 'video/3gpp';
                break; // 3GPP audio/video container
            case '.3g2':
                $mime = 'video/3gpp2';
                break; // 3GPP2 audio/video container
            case '.7z':
                $mime = 'application/x-7z-compressed';
                break; // 7-zip archive
            default:
                $mime = 'application/octet-stream'; // general purpose MIME-type
        }
        return $mime;
    }
}
