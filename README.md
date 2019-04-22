<h1 align="center">flysystem-oss </h1>

<p align="center">:floppy_disk:  Flysystem adapter for the oss storage.</p>

<p align="center">
<a href="https://github.com/iiDestiny/flysystem-oss"><img src="https://travis-ci.org/iiDestiny/flysystem-oss.svg?branch=master"></a>
<a href="https://github.com/iiDestiny/flysystem-oss"><img src="https://github.styleci.io/repos/163501119/shield"></a>
<a href="https://github.com/iiDestiny/flysystem-oss"><img src="https://poser.pugx.org/iidestiny/flysystem-oss/v/stable.svg"></a>
<a href="https://github.com/iiDestiny/flysystem-oss"><img src="https://poser.pugx.org/iidestiny/flysystem-oss/v/unstable.svg"></a>
<a href="https://github.com/iiDestiny/flysystem-oss"><img src="https://poser.pugx.org/iidestiny/flysystem-oss/downloads"></a>
<a href="https://scrutinizer-ci.com/g/iiDestiny/flysystem-oss/?branch=master"><img src="https://scrutinizer-ci.com/g/iiDestiny/flysystem-oss/badges/quality-score.png?b=master"></a>
<a href="https://github.com/iiDestiny/dependency-injection"><img src="https://badges.frapsoft.com/os/v1/open-source.svg?v=103"></a>
<a href="https://github.com/iiDestiny/flysystem-oss"><img src="https://poser.pugx.org/iidestiny/flysystem-oss/license"></a>
</p>

## Requirement

-   PHP >= 7.0

## Installation

```shell
$ composer require "iidestiny/flysystem-oss" -vvv
```

## Usage

```php
use League\Flysystem\Filesystem;
use Iidestiny\Flysystem\Oss\OssAdapter;
use Iidestiny\Flysystem\Oss\Plugins\FileUrl;

$accessKeyId = 'xxxxxx';
$accessKeySecret = 'xxxxxx';
$endpoint= 'oss.iidestiny.com'; // ssl：https://iidestiny.com
$bucket = 'bucket';
$isCName = true; // 如果 isCname 为 false，endpoint 应配置 oss 提供的域名如：`oss-cn-beijing.aliyuncs.com`，cname 或 cdn 请自行到阿里 oss 后台配置并绑定 bucket

$adapter = new OssAdapter($accessKeyId, $accessKeySecret, $endpoint, $bucket, $isCName);

$flysystem = new Filesystem($adapter);

```

## API

```php
bool $flysystem->write('file.md', 'contents');

bool $flysystem->write('file.md', 'http://httpbin.org/robots.txt', ['options' => ['xxxxx' => 'application/redirect302']]);

bool $flysystem->writeStream('file.md', fopen('path/to/your/local/file.jpg', 'r'));

bool $flysystem->update('file.md', 'new contents');

bool $flysystem->updateStream('file.md', fopen('path/to/your/local/file.jpg', 'r'));

bool $flysystem->rename('foo.md', 'bar.md');

bool $flysystem->copy('foo.md', 'foo2.md');

bool $flysystem->delete('file.md');

bool $flysystem->has('file.md');

string|false $flysystem->read('file.md');

array $flysystem->listContents();

array $flysystem->getMetadata('file.md');

int $flysystem->getSize('file.md');

string $flysystem->getAdapter()->getUrl('file.md');

string $flysystem->getMimetype('file.md');

int $flysystem->getTimestamp('file.md');
```

## Plugins

```php
use Iidestiny\Flysystem\Oss\Plugins\FileUrl
use Iidestiny\Flysystem\Oss\Plugins\SignUrl
use Iidestiny\Flysystem\Oss\Plugins\SignatureConfig

$flysystem->addPlugin(new FileUrl());

// Get oss file visit url
string $flysystem->getUrl('file.md');

$flysystem->addPlugin(new SignUrl());

// Access control sign url & image handle
 string $flysystem->signUrl('file.md', $timeout, ['x-oss-process' => 'image/circle,r_100']);
```

## 前端 web 直传配置

oss 直传有三种方式，当前扩展包使用的是最完整的 [服务端签名直传并设置上传回调](https://help.aliyun.com/document_detail/31927.html?spm=a2c4g.11186623.2.10.5602668eApjlz3#concept-qp2-g4y-5db) 方式，扩展包只生成前端页面上传所需的签名参数，前端上传实现可参考 [官方文档中的实例](https://help.aliyun.com/document_detail/31927.html?spm=a2c4g.11186623.2.10.5602668eApjlz3#concept-qp2-g4y-5db) 或自行搜索

```php
use Iidestiny\Flysystem\Oss\Plugins\SignatureConfig

$flysystem->addPlugin(new SignatureConfig());

object $flysystem->signatureConfig($prefix, $callBackUrl, $expire);
```

## Integration

-   Laravel 5：[iidestiny/laravel-filesystem-oss](https://github.com/iiDestiny/laravel-filesystem-oss)

## reference

-   [overtrue/flysystem-qiniu](https://github.com/overtrue/flysystem-qiniu)

## License

MIT
