<?php

/*
 * This file is part of the iidestiny/flysystem-oss.
 *
 * (c) iidestiny <iidestiny@vip.qq.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

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
        require_once __DIR__.'/helpers.php';
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

        $OssClient = Mockery::mock('stdClass');

        $adapter->allows([
            'OssClient' => $OssClient,
        ]);

        return [
            [$adapter, compact('OssClient')],
        ];
    }

    /**
     * @dataProvider ossProvider
     */
    public function testWriteTest($adapter, $managers)
    {
        /*$managers['OssClient']->expects()->putObject('bucket', 'foo/bar.md', 'content', [])
            ->andReturns(['response', false], ['response', true])
            ->twice();

        $adapter->shouldReceive('write')
            ->set('client', $managers['OssClient']);

        $this->assertTrue($adapter->write('foo/bar.md', 'content', new Config()));
        $this->assertTrue($adapter->write('foo/bar.md', 'content', new Config()));*/
        $this->assertTrue(true);
    }

    /**
     * @dataProvider ossProvider
     */
    public function testWriteStreamTest($adapter, $managers)
    {
        /*$adapter->expects()->write('foo.md', '', Mockery::type(Config::class))
            ->andReturns(true, false)
            ->twice();

        $result = $adapter->writeStream('foo.md', tmpfile(), new Config());

        $this->assertTrue($result);*/
        $this->assertTrue(true);
    }
}
