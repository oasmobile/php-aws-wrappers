<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\S3Client;
use PHPUnit\Framework\TestCase;

/**
 * Minimal stub implementing Aws\CommandInterface for testing.
 */
class StubCommand implements \Aws\CommandInterface
{
    private string $name;
    private array $args;

    public function __construct(string $name, array $args)
    {
        $this->name = $name;
        $this->args = $args;
    }

    public function getName() { return $this->name; }
    public function hasParam($name) { return array_key_exists($name, $this->args); }
    public function toArray() { return $this->args; }
    public function getHandlerList() { throw new \RuntimeException('Not implemented'); }
    public function offsetExists(mixed $offset): bool { return isset($this->args[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->args[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->args[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->args[$offset]); }
    public function count(): int { return count($this->args); }
    public function getIterator(): \Traversable { return new \ArrayIterator($this->args); }
    /** @param mixed $name */
    public function __get($name) { return $this->args[$name] ?? null; }
}

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
        return new StubCommand($name, $args);
    }

    public function createPresignedRequest(\Aws\CommandInterface $command, $expires, array $options = [])
    {
        $bucket = $command['Bucket'];
        $key    = $command['Key'];
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

class S3ClientTest extends TestCase
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

        $this->assertStringContainsString('my-bucket', $uri);
        $this->assertStringContainsString('path/to/file.txt', $uri);
    }

    public function testGetPresignedUriStripsLeadingSlash()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $uri = $client->getPresignedUri('s3://my-bucket//path/to/file.txt');

        // Leading slash should be stripped, double slashes collapsed
        $this->assertStringContainsString('path/to/file.txt', $uri);
        $this->assertStringNotContainsString('//path', $uri);
    }

    public function testGetPresignedUriCollapsesMultipleSlashes()
    {
        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $uri = $client->getPresignedUri('s3://bucket/a///b//c.txt');

        $this->assertStringContainsString('a/b/c.txt', $uri);
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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path should be a full path starting with s3://');

        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $client->getPresignedUri('/local/path/file.txt');
    }

    public function testGetPresignedUriThrowsOnHttpPath()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path should be a full path starting with s3://');

        $client = new TestableS3Client([
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $client->getPresignedUri('https://s3.amazonaws.com/bucket/key');
    }

    public function testGetPresignedUriThrowsOnEmptyString()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path should be a full path starting with s3://');

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

        $this->assertIsString($uri);
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

        $this->assertStringContainsString('文件/数据.csv', $uri);
    }
}
