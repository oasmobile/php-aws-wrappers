<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\SqsSentMessage;

class SqsSentMessageTest extends \PHPUnit_Framework_TestCase
{
    // ── Constructor: happy path ──────────────────────────────────

    public function testConstructorSetsMessageIdAndMd5OfBody()
    {
        $msg = new SqsSentMessage([
            'MessageId'        => 'sent-001',
            'MD5OfMessageBody' => 'abc123def456',
        ]);

        $this->assertSame('sent-001', $msg->getMessageId());
        $this->assertSame('abc123def456', $msg->getMd5OfBody());
    }

    // ── MD5OfMessageAttributes: optional ─────────────────────────

    public function testMd5OfAttributesDefaultsToEmptyString()
    {
        $msg = new SqsSentMessage([
            'MessageId'        => 'sent-002',
            'MD5OfMessageBody' => 'abc123',
        ]);

        $this->assertSame('', $msg->getMd5OfAttributes());
    }

    public function testMd5OfAttributesWhenProvided()
    {
        $msg = new SqsSentMessage([
            'MessageId'              => 'sent-003',
            'MD5OfMessageBody'       => 'abc123',
            'MD5OfMessageAttributes' => 'attr-md5-hash',
        ]);

        $this->assertSame('attr-md5-hash', $msg->getMd5OfAttributes());
    }

    // ── Constructor: missing mandatory fields ────────────────────

    public function testConstructorThrowsWhenMessageIdMissing()
    {
        $this->setExpectedException(\Exception::class);

        new SqsSentMessage([
            'MD5OfMessageBody' => 'abc123',
        ]);
    }

    public function testConstructorThrowsWhenMd5OfMessageBodyMissing()
    {
        $this->setExpectedException(\Exception::class);

        new SqsSentMessage([
            'MessageId' => 'sent-004',
        ]);
    }

    // ── Inherits SqsMessage: getMessageId ────────────────────────

    public function testInheritsGetMessageId()
    {
        $msg = new SqsSentMessage([
            'MessageId'        => 'inherited-id',
            'MD5OfMessageBody' => 'hash',
        ]);

        $this->assertSame('inherited-id', $msg->getMessageId());
    }
}
