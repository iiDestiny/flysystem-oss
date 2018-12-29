<?php

/*
 * This file is part of the iidestiny/flysystem-oss.
 *
 * (c) iidestiny <iidestiny@vip.qq.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\Flysystem\Qiniu;

use Iidestiny\Flysystem\Oss\OssAdapter;
use League\Flysystem\Config;
use PHPUnit\Framework\TestCase;
use Mockery;

class OssAdapterTest extends TestCase
{
    /**
     * set up.
     */
    public function setUp()
    {
        require_once __DIR__ . '/helpers.php';
    }

    /**
     * oss provider.
     *
     * @return array
     */
    public function ossProvider()
    {
        $adapter = Mockery::mock(OssAdapter::class, ['accessKeyId', 'accessKeySecret', 'endpoint', 'bucket'])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $client = Mockery::mock('stdClass');

        $adapter->allows([
            'client' => $client,
        ]);

        return [
            [$adapter, compact('client')],
        ];
    }

    /**
     * @dataProvider ossProvider
     */
    public function testWriteTest($adapter, $managers)
    {
        $managers['client']->expects()->putObject('bucket', 'foo/bar.md', 'content', [])
            ->andReturns(['response', false], ['response', true])
            ->twice();

        $this->assertNull($adapter->write('foo/bar.md', 'content', new Config()));
        $this->assertNull($adapter->write('foo/bar.md', 'content', new Config()));
    }

    /**
     * @dataProvider ossProvider
     */
    public function testWriteStreamTest($adapter, $managers)
    {
        $adapter->expects()->write('foo.md', '', Mockery::type(Config::class))
            ->andReturns(true, false)
            ->twice();

        $result = $adapter->writeStream('foo.md', tmpfile(), new Config());

        $this->assertNull($result);
    }
}
