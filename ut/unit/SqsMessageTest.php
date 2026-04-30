<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\SqsMessage;

class SqsMessageTest extends \PHPUnit_Framework_TestCase
{
    // ── Constructor: happy path ──────────────────────────────────

    public function testConstructorSetsMessageId()
    {
        $msg = new SqsMessage([
            'MessageId' => 'msg-001',
        ]);

        $this->assertSame('msg-001', $msg->getMessageId());
    }

    public function testConstructorWithUuidMessageId()
    {
        $msg = new SqsMessage([
            'MessageId' => '6a0f35b7-1406-4eb7-8be9-5b6eccea9f38',
        ]);

        $this->assertSame('6a0f35b7-1406-4eb7-8be9-5b6eccea9f38', $msg->getMessageId());
    }

    // ── MD5 fields default to null ──────────────────────────────

    public function testMd5OfBodyDefaultsToNull()
    {
        $msg = new SqsMessage([
            'MessageId' => 'msg-001',
        ]);

        $this->assertNull($msg->getMd5OfBody());
    }

    public function testMd5OfAttributesDefaultsToNull()
    {
        $msg = new SqsMessage([
            'MessageId' => 'msg-001',
        ]);

        $this->assertNull($msg->getMd5OfAttributes());
    }

    // ── Constructor: missing MessageId ───────────────────────────

    public function testConstructorThrowsWhenMessageIdMissing()
    {
        $this->setExpectedException(\Exception::class);

        new SqsMessage([]);
    }

    // ── Constructor: Unicode MessageId ───────────────────────────

    public function testConstructorWithUnicodeMessageId()
    {
        $msg = new SqsMessage([
            'MessageId' => '消息-001',
        ]);

        $this->assertSame('消息-001', $msg->getMessageId());
    }
}
