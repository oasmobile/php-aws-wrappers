<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\StsClient;
use Oasis\Mlib\AwsWrappers\TemporaryCredential;
use PHPUnit\Framework\TestCase;

/**
 * Test subclass that bypasses AwsConfigDataProvider + real STS client
 * and allows injecting stubbed behavior directly.
 */
class TestableStsClient extends StsClient
{
    /** @var array Queued results for execute() calls */
    public $executeResults = [];

    /** @var array Recorded getCommand calls */
    public $getCommandCalls = [];

    private $executeIndex = 0;

    public function __construct()
    {
        // Skip parent constructor entirely — no AWS SDK initialization
    }

    public function getCommand($name, array $args = [])
    {
        $this->getCommandCalls[] = ['name' => $name, 'args' => $args];

        return (object)['_index' => count($this->getCommandCalls) - 1];
    }

    public function execute($cmd)
    {
        $idx = $this->executeIndex++;

        return $this->executeResults[$idx];
    }
}

class StsClientTest extends TestCase
{
    // ================================================================
    // getTemporaryCredential: basic behavior
    // ================================================================

    public function testGetTemporaryCredentialReturnsTemporaryCredential()
    {
        $client = new TestableStsClient();
        $client->executeResults = [
            new \Aws\Result([
                'Credentials' => [
                    'SessionToken'    => 'session-token-abc',
                    'AccessKeyId'     => 'AKIAEXAMPLE',
                    'SecretAccessKey' => 'secret-key-xyz',
                    'Expiration'      => '2025-12-31T23:59:59Z',
                ],
            ]),
        ];

        $credential = $client->getTemporaryCredential();

        $this->assertInstanceOf(TemporaryCredential::class, $credential);
        $this->assertSame('session-token-abc', $credential->sessionToken);
        $this->assertSame('AKIAEXAMPLE', $credential->accessKeyId);
        $this->assertSame('secret-key-xyz', $credential->secretAccessKey);
        $this->assertSame('2025-12-31T23:59:59Z', $credential->expireDateTime);
    }

    // ================================================================
    // getTemporaryCredential: default duration
    // ================================================================

    public function testGetTemporaryCredentialDefaultDuration()
    {
        $client = new TestableStsClient();
        $client->executeResults = [
            new \Aws\Result([
                'Credentials' => [
                    'SessionToken'    => 'tok',
                    'AccessKeyId'     => 'key',
                    'SecretAccessKey' => 'secret',
                    'Expiration'      => '2025-01-01T00:00:00Z',
                ],
            ]),
        ];

        $client->getTemporaryCredential();

        $this->assertCount(1, $client->getCommandCalls);
        $this->assertSame('GetSessionToken', $client->getCommandCalls[0]['name']);
        $this->assertSame(43200, $client->getCommandCalls[0]['args']['DurationSeconds']);
    }

    // ================================================================
    // getTemporaryCredential: custom duration
    // ================================================================

    public function testGetTemporaryCredentialCustomDuration()
    {
        $client = new TestableStsClient();
        $client->executeResults = [
            new \Aws\Result([
                'Credentials' => [
                    'SessionToken'    => 'tok',
                    'AccessKeyId'     => 'key',
                    'SecretAccessKey' => 'secret',
                    'Expiration'      => '2025-01-01T00:00:00Z',
                ],
            ]),
        ];

        $client->getTemporaryCredential(3600);

        $this->assertSame(3600, $client->getCommandCalls[0]['args']['DurationSeconds']);
    }

    // ================================================================
    // getTemporaryCredential: expireAt is set correctly
    // ================================================================

    public function testGetTemporaryCredentialSetsExpireAt()
    {
        $client = new TestableStsClient();
        $client->executeResults = [
            new \Aws\Result([
                'Credentials' => [
                    'SessionToken'    => 'tok',
                    'AccessKeyId'     => 'key',
                    'SecretAccessKey' => 'secret',
                    'Expiration'      => '2025-01-01T00:00:00Z',
                ],
            ]),
        ];

        $before     = time();
        $credential = $client->getTemporaryCredential(7200);
        $after      = time();

        // expireAt should be approximately now + 7200
        $this->assertGreaterThanOrEqual($before + 7200, $credential->expireAt);
        $this->assertLessThanOrEqual($after + 7200, $credential->expireAt);
    }

    // ================================================================
    // getTemporaryCredential: uses GetSessionToken command
    // ================================================================

    public function testGetTemporaryCredentialUsesGetSessionTokenCommand()
    {
        $client = new TestableStsClient();
        $client->executeResults = [
            new \Aws\Result([
                'Credentials' => [
                    'SessionToken'    => 'tok',
                    'AccessKeyId'     => 'key',
                    'SecretAccessKey' => 'secret',
                    'Expiration'      => '2025-01-01T00:00:00Z',
                ],
            ]),
        ];

        $client->getTemporaryCredential();

        $this->assertSame('GetSessionToken', $client->getCommandCalls[0]['name']);
    }

    // ================================================================
    // StsClient implements CredentialProviderInterface
    // ================================================================

    public function testImplementsCredentialProviderInterface()
    {
        $client = new TestableStsClient();

        $this->assertInstanceOf(
            \Oasis\Mlib\AwsWrappers\CredentialProviderInterface::class,
            $client
        );
    }
}
