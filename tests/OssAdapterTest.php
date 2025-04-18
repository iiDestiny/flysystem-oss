<?php

/*
 * This file is part of the iidestiny/flysystem-oss.
 *
 * (c) iidestiny <iidestiny@vip.qq.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Tests;

use Iidestiny\Flysystem\Oss\OssAdapter;
use OSS\OssClient;
use PHPUnit\Framework\TestCase;

class OssAdapterTest extends TestCase
{
    public function testWriteTest()
    {
        $this->assertTrue(true);
    }

    public function testWriteStreamTest()
    {
        $this->assertTrue(true);
    }

    public function testNewAdapter(): void
    {
        $extra = [
            'signatureVersion' => OssClient::OSS_SIGNATURE_VERSION_V4,
            'region'           => 'cn-beijing',
        ];
        $adapter = new OssAdapter(
            '<accessKeyId>',
            '<accessKeySecret>',
            'https://oss-cn-beijing.aliyuncs.com',
            '<bucket>',
            false,
            '<prefix>',
            [
                'test' => [
                    'access_key' => '<test-accessKey>',
                    'secret_key' => '<test-secretKey>',
                    'bucket'     => '<test-bucket>',
                    'endpoint'   => 'https://oss-cn-beijing.aliyuncs.com',
                    'isCName'    => false,
                ],
            ],
            ...$extra,
        );
        $this->assertInstanceOf(OssAdapter::class, $adapter);
        $this->assertEquals('<bucket>', $adapter->getBucketName());
        $this->assertInstanceOf(OssAdapter::class, $adapter->bucket('test'));
        $this->assertEquals('<test-bucket>', $adapter->bucket('test')->getBucketName());
    }


    public function testPolicyTokenSignatureV4(): void
    {
        $extra = [
            'signatureVersion' => OssClient::OSS_SIGNATURE_VERSION_V4,
            'region'           => 'cn-hangzhou',
        ];
        $adapter = new OssAdapter(
            '<accessKeyId>',
            '<accessKeySecret>',
            'https://oss-cn-beijing.aliyuncs.com',
            '<bucket>',
            false,
            '<prefix>',
            [],
            ...$extra,
        );

       $result = $adapter->policyTokenSignatureV4([
            'expire' => 30*60, // 30 minutes
            'conditions' => [
                [
                    'eq', '$success_action_status', '200',
                ],
                [
                    'content-length-range', 1, 1048576000, // file size between 1 byte and 1GB
                ]
            ]
        ]);

       print_r($result);

       $this->assertNotEmpty($result['policy_token']);
       $this->assertNotEmpty($result['policy_token_json']);
    }
}
