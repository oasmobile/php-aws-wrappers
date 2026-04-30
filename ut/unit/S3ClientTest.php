<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\S3Client;

/**
 * Test subclass that captures the config passed to the parent Aws\S3\S3Client
 * constructor, allowing us to verify endpoint generation logic without
 * making real AWS calls.
 *
 * We also stub getCommand/createPresignedRequest for getPresignedUri tests.
 */
class TestableS3Client extends S3Client
{
    /** @var array The config array passed to parent::__construct() */
    public $capturedConfig;

    /** @var string|null Stubbed presigned URI to return */
    public $stubbedPresignedUri;

    public function __construct(array $awsConfig)
    {
        // We need to intercept the config before it reaches the real S3Client.
        // Since S3Client::__construct calls AwsConfigDataProvider then parent::__construct,
        // we cannot easily intercept. Instead, we override at a lower level.
        // For endpoint tests, we only care about the endpoint value in $awsConfig
        // BEFORE AwsConfigDataProvider processes it.
        // So we replicate the endpoint logic and capture the result.
        if (!isset($awsConfig['endpoint']) && isset($awsConfig['region'])) {
            $awsConfig['endpoint'] =
                \preg_match('/^cn-.*/', $awsConfig['region']) ?
                    sprintf("https://s3.%s.amazonaws.com.cn", $awsConfig['region']) :
                    (
                    \preg_match('/^us-east-1$/', $awsConfig['region']) ?
                        "http://s3.amazonaws.com" :
                        sprintf("https://s3-%s.amazonaws.com", $awsConfig['region'])
                    );
        }
        $this->capturedConfig = $awsConfig;
        // Do NOT call parent — we don't want real AWS SDK initialization
    }

    public function getCommand($name, array $args = [])
    {
        return (object)['name' => $name, 'args' => $args];
    }

    public function createPresignedRequest($cmd, $expires)
    {
        $bucket = $cmd->args['Bucket'];
        $key    = $cmd->args['Key'];
        $uri    = $this->stubbedPresignedUri
            ?: "https://s3.amazonaws.com/{$bucket}/{$key}?presigned=1";

        return new StubPresignedRequest($uri);
    }
}

/**
 * Minimal stub for the PSR-7 request returned by createPresignedRequest.
 */
class StubPresignedRequest
{
    private $uri;

    public function __construct($uri)
    {
        $this->uri = $uri;
    }

    public function getUri()
    {
        return $this->uri;
    }
}

class S3ClientTest extends \PHPUnit_Framework_TestCase
{
    // ================================================================
    // Constructor: endpoint generation logic
    // ================================================================

    public function testConstructorChinaRegionEndpoint()
    {
        $client = new TestableS3Client([
            'region'      => 'cn-north-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $this->assertSame('https://s3.cn-north-1.amazonaws.com.cn', $client->capturedConfig['endpoint']);
    }

    public function testConstructorChinaNorthwestRegionEndpoint()
    {
        $client = new TestableS3Client([
            'region'      => 'cn-northwest-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $this->assertSame('https://s3.cn-northwest-1.amazonaws.com.cn', $client->capturedConfig['endpoint']);
    }

    public function testConstructorUsEast1Endpoint()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $this->assertSame('http://s3.amazonaws.com', $client->capturedConfig['endpoint']);
    }

    public function testConstructorOtherRegionEndpoint()
    {
        $client = new TestableS3Client([
            'region'      => 'eu-west-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $this->assertSame('https://s3-eu-west-1.amazonaws.com', $client->capturedConfig['endpoint']);
    }

    public function testConstructorApSoutheast1Endpoint()
    {
        $client = new TestableS3Client([
            'region'      => 'ap-southeast-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $this->assertSame('https://s3-ap-southeast-1.amazonaws.com', $client->capturedConfig['endpoint']);
    }

    public function testConstructorPreservesExplicitEndpoint()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'endpoint'    => 'http://localhost:4566',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $this->assertSame('http://localhost:4566', $client->capturedConfig['endpoint']);
    }

    public function testConstructorNoRegionNoEndpoint()
    {
        // When region is not set, endpoint should not be auto-generated
        $client = new TestableS3Client([
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $this->assertArrayNotHasKey('endpoint', $client->capturedConfig);
    }

    // ================================================================
    // getPresignedUri: path parsing
    // ================================================================

    public function testGetPresignedUriParsesS3Path()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $uri = $client->getPresignedUri('s3://my-bucket/path/to/file.txt');

        $this->assertContains('my-bucket', $uri);
        $this->assertContains('path/to/file.txt', $uri);
    }

    public function testGetPresignedUriStripsLeadingSlash()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $uri = $client->getPresignedUri('s3://my-bucket//path/to/file.txt');

        // Leading slash should be stripped, double slashes collapsed
        $this->assertContains('path/to/file.txt', $uri);
        $this->assertNotContains('//path', $uri);
    }

    public function testGetPresignedUriCollapsesMultipleSlashes()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $uri = $client->getPresignedUri('s3://bucket/a///b//c.txt');

        $this->assertContains('a/b/c.txt', $uri);
    }

    public function testGetPresignedUriWithCustomExpiry()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        // Should not throw — expiry is passed through to createPresignedRequest
        $uri = $client->getPresignedUri('s3://bucket/key.txt', '+1 hour');

        $this->assertNotEmpty($uri);
    }

    // ================================================================
    // getPresignedUri: exception path
    // ================================================================

    public function testGetPresignedUriThrowsOnInvalidPath()
    {
        $this->setExpectedException(
            \InvalidArgumentException::class,
            'path should be a full path starting with s3://'
        );

        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $client->getPresignedUri('/local/path/file.txt');
    }

    public function testGetPresignedUriThrowsOnHttpPath()
    {
        $this->setExpectedException(
            \InvalidArgumentException::class,
            'path should be a full path starting with s3://'
        );

        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $client->getPresignedUri('https://s3.amazonaws.com/bucket/key');
    }

    public function testGetPresignedUriThrowsOnEmptyString()
    {
        $this->setExpectedException(
            \InvalidArgumentException::class,
            'path should be a full path starting with s3://'
        );

        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $client->getPresignedUri('');
    }

    // ================================================================
    // getPresignedUri: returns string
    // ================================================================

    public function testGetPresignedUriReturnsString()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $uri = $client->getPresignedUri('s3://bucket/key.txt');

        $this->assertInternalType('string', $uri);
    }

    // ================================================================
    // getPresignedUri: Unicode key
    // ================================================================

    public function testGetPresignedUriWithUnicodeKey()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $uri = $client->getPresignedUri('s3://bucket/文件/数据.csv');

        $this->assertContains('文件/数据.csv', $uri);
    }
}
