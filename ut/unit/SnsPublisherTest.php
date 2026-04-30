<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\SnsPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Test subclass that bypasses AwsConfigDataProvider + real SnsClient
 * and allows injecting a mock client directly.
 */
class TestableSnsPublisher extends SnsPublisher
{
    public function __construct($mockClient, $topicArn)
    {
        $this->client   = $mockClient;
        $this->topicArn = $topicArn;
    }
}

/**
 * Minimal stub for SnsClient that records publish() calls.
 */
class StubSnsClient
{
    /** @var array Recorded publish calls */
    public $publishCalls = [];

    public function publish(array $args = [])
    {
        $this->publishCalls[] = $args;
    }
}

class SnsPublisherTest extends TestCase
{
    /** @var StubSnsClient */
    private $stubClient;

    /** @var TestableSnsPublisher */
    private $publisher;

    protected function setUp(): void
    {
        $this->stubClient = new StubSnsClient();
        $this->publisher  = new TestableSnsPublisher($this->stubClient, 'arn:aws:sns:us-east-1:123456789:test-topic');
    }

    // ================================================================
    // publish: basic (no channels)
    // ================================================================

    public function testPublishBasicNoChannels()
    {
        $this->publisher->publish('Test Subject', 'Hello World');

        $this->assertCount(1, $this->stubClient->publishCalls);

        $call = $this->stubClient->publishCalls[0];
        $this->assertSame('Test Subject', $call['Subject']);
        $this->assertSame('json', $call['MessageStructure']);
        $this->assertSame('arn:aws:sns:us-east-1:123456789:test-topic', $call['TopicArn']);

        $message = json_decode($call['Message'], true);
        $this->assertSame('Hello World', $message['default']);
    }

    // ================================================================
    // publish: single channel (string, not array)
    // ================================================================

    public function testPublishWithSingleChannelAsString()
    {
        $this->publisher->publish('Subject', 'Body', SnsPublisher::CHANNEL_EMAIL);

        $call    = $this->stubClient->publishCalls[0];
        $message = json_decode($call['Message'], true);

        $this->assertSame('Body', $message['default']);
        $this->assertSame('Body', $message[SnsPublisher::CHANNEL_EMAIL]);
    }

    // ================================================================
    // publish: Email channel (pass-through body)
    // ================================================================

    public function testPublishEmailChannel()
    {
        $this->publisher->publish('Subject', 'Email body', [SnsPublisher::CHANNEL_EMAIL]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $this->assertSame('Email body', $message[SnsPublisher::CHANNEL_EMAIL]);
    }

    // ================================================================
    // publish: SQS channel (pass-through body)
    // ================================================================

    public function testPublishSqsChannel()
    {
        $this->publisher->publish('Subject', 'SQS body', [SnsPublisher::CHANNEL_SQS]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $this->assertSame('SQS body', $message[SnsPublisher::CHANNEL_SQS]);
    }

    // ================================================================
    // publish: Lambda channel (pass-through body)
    // ================================================================

    public function testPublishLambdaChannel()
    {
        $this->publisher->publish('Subject', 'Lambda body', [SnsPublisher::CHANNEL_LAMBDA]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $this->assertSame('Lambda body', $message[SnsPublisher::CHANNEL_LAMBDA]);
    }

    // ================================================================
    // publish: APNS channel (structured body with aps.alert)
    // ================================================================

    public function testPublishApnsChannel()
    {
        $this->publisher->publish('Subject', 'Push notification', [SnsPublisher::CHANNEL_APNS]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $expected = ['aps' => ['alert' => 'Push notification']];
        $this->assertSame($expected, $message[SnsPublisher::CHANNEL_APNS]);
    }

    public function testPublishApnsSandboxChannel()
    {
        $this->publisher->publish('Subject', 'Sandbox push', [SnsPublisher::CHANNEL_APNS_SANDBOX]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $expected = ['aps' => ['alert' => 'Sandbox push']];
        $this->assertSame($expected, $message[SnsPublisher::CHANNEL_APNS_SANDBOX]);
    }

    public function testPublishApnsVoipChannel()
    {
        $this->publisher->publish('Subject', 'VOIP push', [SnsPublisher::CHANNEL_APNS_VOIP]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $expected = ['aps' => ['alert' => 'VOIP push']];
        $this->assertSame($expected, $message[SnsPublisher::CHANNEL_APNS_VOIP]);
    }

    public function testPublishMacosChannel()
    {
        $this->publisher->publish('Subject', 'macOS push', [SnsPublisher::CHANNEL_MACOS]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $expected = ['aps' => ['alert' => 'macOS push']];
        $this->assertSame($expected, $message[SnsPublisher::CHANNEL_MACOS]);
    }

    // ================================================================
    // publish: GCM channel (structured body with data.message)
    // ================================================================

    public function testPublishGcmChannel()
    {
        $this->publisher->publish('Subject', 'GCM message', [SnsPublisher::CHANNEL_GCM]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $expected = ['data' => ['message' => 'GCM message']];
        $this->assertSame($expected, $message[SnsPublisher::CHANNEL_GCM]);
    }

    public function testPublishAdmChannel()
    {
        $this->publisher->publish('Subject', 'ADM message', [SnsPublisher::CHANNEL_ADM]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $expected = ['data' => ['message' => 'ADM message']];
        $this->assertSame($expected, $message[SnsPublisher::CHANNEL_ADM]);
    }

    // ================================================================
    // publish: Baidu channel (title + description)
    // ================================================================

    public function testPublishBaiduChannel()
    {
        $this->publisher->publish('Subject', 'Baidu msg', [SnsPublisher::CHANNEL_BAIDU]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $expected = ['title' => 'Baidu msg', 'description' => 'Baidu msg'];
        $this->assertSame($expected, $message[SnsPublisher::CHANNEL_BAIDU]);
    }

    // ================================================================
    // publish: MPNS channel (XML structure)
    // ================================================================

    public function testPublishMpnsChannel()
    {
        $this->publisher->publish('Subject', 'MPNS body', [SnsPublisher::CHANNEL_MPNS]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $this->assertStringContainsString('<wp:Title>MPNS body</wp:Title>', $message[SnsPublisher::CHANNEL_MPNS]);
        $this->assertStringContainsString('<wp:Count>1</wp:Count>', $message[SnsPublisher::CHANNEL_MPNS]);
        $this->assertStringContainsString('<?xml version="1.0"', $message[SnsPublisher::CHANNEL_MPNS]);
    }

    public function testPublishMpnsChannelEscapesXmlEntities()
    {
        $this->publisher->publish('Subject', 'A & B <C>', [SnsPublisher::CHANNEL_MPNS]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        // htmlentities with ENT_XML1 should escape & and < >
        $this->assertStringContainsString('A &amp; B &lt;C&gt;', $message[SnsPublisher::CHANNEL_MPNS]);
    }

    // ================================================================
    // publish: multiple channels at once
    // ================================================================

    public function testPublishMultipleChannels()
    {
        $this->publisher->publish('Subject', 'Multi body', [
            SnsPublisher::CHANNEL_EMAIL,
            SnsPublisher::CHANNEL_APNS,
            SnsPublisher::CHANNEL_GCM,
        ]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        // default
        $this->assertSame('Multi body', $message['default']);
        // email: pass-through
        $this->assertSame('Multi body', $message[SnsPublisher::CHANNEL_EMAIL]);
        // APNS: structured
        $this->assertSame(['aps' => ['alert' => 'Multi body']], $message[SnsPublisher::CHANNEL_APNS]);
        // GCM: structured
        $this->assertSame(['data' => ['message' => 'Multi body']], $message[SnsPublisher::CHANNEL_GCM]);
    }

    // ================================================================
    // publish: unsupported channel is skipped (triggers mwarning)
    // ================================================================

    public function testPublishUnsupportedChannelIsSkipped()
    {
        // The source code has a known issue: mwarning() receives $channels (array)
        // instead of $channel (string), causing an "Array to string conversion" notice.
        // Suppress the notice to test the functional behavior.
        $oldLevel = error_reporting(error_reporting() & ~E_NOTICE);

        try {
            $this->publisher->publish('Subject', 'Body', ['UNSUPPORTED_CHANNEL']);

            $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

            $this->assertSame('Body', $message['default']);
            $this->assertArrayNotHasKey('UNSUPPORTED_CHANNEL', $message);
        } finally {
            error_reporting($oldLevel);
        }
    }

    // ================================================================
    // publishToSubscribedSQS: serialization
    // ================================================================

    public function testPublishToSubscribedSqsSerializesPayload()
    {
        $payload = ['key' => 'value', 'num' => 42];

        $this->publisher->publishToSubscribedSQS($payload);

        $this->assertCount(1, $this->stubClient->publishCalls);

        $call = $this->stubClient->publishCalls[0];
        $this->assertSame('base64_serialize', $call['Subject']);

        $message = json_decode($call['Message'], true);

        // The body should be base64-encoded serialized payload
        $expectedBody = base64_encode(serialize($payload));
        $this->assertSame($expectedBody, $message['default']);
        // SQS channel should also have the same body
        $this->assertSame($expectedBody, $message[SnsPublisher::CHANNEL_SQS]);
    }

    public function testPublishToSubscribedSqsWithStringPayload()
    {
        $this->publisher->publishToSubscribedSQS('simple string');

        $call    = $this->stubClient->publishCalls[0];
        $message = json_decode($call['Message'], true);

        $expectedBody = base64_encode(serialize('simple string'));
        $this->assertSame($expectedBody, $message['default']);
    }

    // ================================================================
    // topicArn getter/setter
    // ================================================================

    public function testGetTopicArn()
    {
        $this->assertSame('arn:aws:sns:us-east-1:123456789:test-topic', $this->publisher->getTopicArn());
    }

    public function testSetTopicArn()
    {
        $this->publisher->setTopicArn('arn:aws:sns:eu-west-1:999:new-topic');

        $this->assertSame('arn:aws:sns:eu-west-1:999:new-topic', $this->publisher->getTopicArn());
    }

    public function testSetTopicArnAffectsSubsequentPublish()
    {
        $this->publisher->setTopicArn('arn:aws:sns:eu-west-1:999:new-topic');
        $this->publisher->publish('Subject', 'Body');

        $this->assertSame('arn:aws:sns:eu-west-1:999:new-topic', $this->stubClient->publishCalls[0]['TopicArn']);
    }

    // ================================================================
    // publish: Unicode body
    // ================================================================

    public function testPublishWithUnicodeBody()
    {
        $this->publisher->publish('主题', '你好世界 🌍', [SnsPublisher::CHANNEL_EMAIL]);

        $message = json_decode($this->stubClient->publishCalls[0]['Message'], true);

        $this->assertSame('你好世界 🌍', $message['default']);
        $this->assertSame('你好世界 🌍', $message[SnsPublisher::CHANNEL_EMAIL]);
    }
}
