<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\TemporaryCredential;
use PHPUnit\Framework\TestCase;

class TemporaryCredentialTest extends TestCase
{
    // ── Property assignment and retrieval ─────────────────────────

    public function testAccessKeyIdAssignmentAndRetrieval()
    {
        $tc = new TemporaryCredential();
        $tc->accessKeyId = 'AKIAIOSFODNN7EXAMPLE';

        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $tc->accessKeyId);
    }

    public function testSecretAccessKeyAssignmentAndRetrieval()
    {
        $tc = new TemporaryCredential();
        $tc->secretAccessKey = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

        $this->assertSame('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $tc->secretAccessKey);
    }

    public function testSessionTokenAssignmentAndRetrieval()
    {
        $tc = new TemporaryCredential();
        $tc->sessionToken = 'FwoGZXIvYXdzEBYaDH...truncated...';

        $this->assertSame('FwoGZXIvYXdzEBYaDH...truncated...', $tc->sessionToken);
    }

    public function testExpireAtAssignmentAndRetrieval()
    {
        $tc = new TemporaryCredential();
        $tc->expireAt = 1700000000;

        $this->assertSame(1700000000, $tc->expireAt);
    }

    public function testExpireDateTimeAssignmentAndRetrieval()
    {
        $tc = new TemporaryCredential();
        $dateTime = new \Aws\Api\DateTimeResult('2025-01-01T00:00:00Z');
        $tc->expireDateTime = $dateTime;

        $this->assertSame($dateTime, $tc->expireDateTime);
    }

    // ── Default values ───────────────────────────────────────────

    public function testPropertiesDefaultToNull()
    {
        $tc = new TemporaryCredential();

        $this->assertNull($tc->accessKeyId);
        $this->assertNull($tc->secretAccessKey);
        $this->assertNull($tc->sessionToken);
        $this->assertNull($tc->expireDateTime);
        $this->assertNull($tc->expireAt);
    }

    // ── Full credential round-trip ───────────────────────────────

    public function testFullCredentialAssignment()
    {
        $tc = new TemporaryCredential();
        $tc->accessKeyId     = 'AKID';
        $tc->secretAccessKey = 'SECRET';
        $tc->sessionToken    = 'TOKEN';
        $tc->expireAt        = 9999999999;
        $tc->expireDateTime  = new \Aws\Api\DateTimeResult('2030-12-31T23:59:59Z');

        $this->assertSame('AKID', $tc->accessKeyId);
        $this->assertSame('SECRET', $tc->secretAccessKey);
        $this->assertSame('TOKEN', $tc->sessionToken);
        $this->assertSame(9999999999, $tc->expireAt);
        $this->assertInstanceOf(\Aws\Api\DateTimeResult::class, $tc->expireDateTime);
    }

    // ── Unicode values ───────────────────────────────────────────

    public function testUnicodeSessionToken()
    {
        $tc = new TemporaryCredential();
        $tc->sessionToken = '令牌-token-🔑';

        $this->assertSame('令牌-token-🔑', $tc->sessionToken);
    }
}
