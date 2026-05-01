<?php

namespace Oasis\Mlib\AwsWrappers\Test\Integration;

use Oasis\Mlib\AwsWrappers\SqsQueue;
use Oasis\Mlib\AwsWrappers\SqsReceivedMessage;
use Oasis\Mlib\AwsWrappers\Test\UTConfig;
use Oasis\Mlib\Utils\ArrayDataProvider;
use Oasis\Mlib\Utils\DataType;
use PHPUnit\Framework\TestCase;

/**
 * Uses the shared SQS queue created by IntegrationSetup.
 */
class SqsQueueIntegrationTest extends TestCase
{
    protected SqsQueue $sqs;

    protected function setUp(): void
    {
        parent::setUp();
        IntegrationSetup::ensureSqs();
        $this->sqs = new SqsQueue(UTConfig::$awsConfig, UTConfig::$sharedQueueName);
    }

    // ── Send / Receive ──────────────────────────────────────────

    public function testBatchSend(): void
    {
        $batch    = 175;
        $payrolls = [];
        for ($i = 0; $i < $batch; ++$i) {
            $payrolls[] = json_encode(["id" => $i, "val" => md5($i)]);
        }
        $this->sqs->sendMessages($payrolls);

        $received = 0;
        while ($msgs = $this->sqs->receiveMessages($batch, 1)) {
            $received += count($msgs);
            $this->sqs->deleteMessages($msgs);
        }
        $this->assertGreaterThanOrEqual($batch, $received);
    }

    public function testAttributedMessage(): void
    {
        $this->sqs->sendMessage('hello', 0, ['user' => 'minhao']);
        $msg = $this->sqs->receiveMessage(5, null, [], ['user']);
        $this->assertInstanceOf(SqsReceivedMessage::class, $msg);
        $this->assertEquals('minhao', $msg->getAttribute('user'));
        $this->sqs->deleteMessage($msg);
    }

    public function testAutoSerialization(): void
    {
        $obj = new ArrayDataProvider(['a' => 9]);
        $this->sqs->sendMessage($obj, 0, ['b' => 'xyz']);
        $msg = $this->sqs->receiveMessage(5, null, [], ['b']);
        $this->assertInstanceOf(SqsReceivedMessage::class, $msg);
        $body = $msg->getBody();
        $this->assertInstanceOf(ArrayDataProvider::class, $body);
        $this->assertEquals(9, $body->getMandatory('a', DataType::Int));
        $this->sqs->deleteMessage($msg);
    }

    public function testSNSPublishedSerialization(): void
    {
        $obj       = new ArrayDataProvider(['a' => 9]);
        $structured = [
            'Subject' => 'base64_serialize',
            'Message' => base64_encode(serialize($obj)),
        ];
        $this->sqs->sendMessage(json_encode($structured));
        $msg = $this->sqs->receiveMessage(5);
        $this->assertInstanceOf(SqsReceivedMessage::class, $msg);
        $body = $msg->getBody();
        $this->assertInstanceOf(ArrayDataProvider::class, $body);
        $this->assertEquals(9, $body->getMandatory('a', DataType::Int));
        $this->sqs->deleteMessage($msg);
    }

    public function testFailureMessages(): void
    {
        $ret = $this->sqs->sendMessage("\x8");
        $this->assertFalse($ret);
        $this->assertStringContainsString('Invalid binary character', $this->sqs->getSendFailureMessages()[0]);

        $ret = $this->sqs->sendMessages(["x" => "\x8", "y" => "\xA"]);
        $this->assertCount(1, $ret);
        $failed = $this->sqs->getSendFailureMessages();
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('Invalid binary character', $failed["x"]);
    }

    public function testReceiveMessageWithAttributes(): void
    {
        $uniqueBody = 'attr-test-' . uniqid();
        $this->sqs->sendMessage($uniqueBody, 0, ['sender' => 'integration']);
        // Long-poll; may receive a stale message from a previous test, so retry
        $deadline = microtime(true) + 10;
        $msg = null;
        while (microtime(true) < $deadline) {
            $candidate = $this->sqs->receiveMessageWithAttributes(['sender'], 2, null, []);
            if ($candidate === null) continue;
            if ($candidate->getOriginalBody() === $uniqueBody) {
                $msg = $candidate;
                break;
            }
            // Not our message — delete and retry
            $this->sqs->deleteMessage($candidate);
        }
        $this->assertNotNull($msg, 'Did not receive the expected message within timeout');
        $this->assertEquals('integration', $msg->getAttribute('sender'));
        $this->assertIsString($msg->getMessageId());
        $this->assertIsArray($msg->getOriginalAttributes());
        $this->sqs->deleteMessage($msg);
    }

    // ── Queue metadata ──────────────────────────────────────────

    public function testExists(): void
    {
        $this->assertTrue($this->sqs->exists());
    }

    public function testGetName(): void
    {
        $this->assertEquals(UTConfig::$sharedQueueName, $this->sqs->getName());
    }

    public function testGetQueueUrl(): void
    {
        $url = $this->sqs->getQueueUrl();
        $this->assertStringContainsString(UTConfig::$sharedQueueName, $url);
    }

    public function testGetAndSetAttributes(): void
    {
        $this->sqs->setAttributes([SqsQueue::VISIBILITY_TIMEOUT => '30']);
        $this->assertEquals('30', $this->sqs->getAttribute(SqsQueue::VISIBILITY_TIMEOUT));

        $attrs = $this->sqs->getAttributes([SqsQueue::VISIBILITY_TIMEOUT, SqsQueue::DELAY_SECONDS]);
        $this->assertArrayHasKey(SqsQueue::VISIBILITY_TIMEOUT, $attrs);
        $this->assertArrayHasKey(SqsQueue::DELAY_SECONDS, $attrs);

        $this->sqs->setAttributes([SqsQueue::VISIBILITY_TIMEOUT => '20']);
    }

    public function testNonExistentQueue(): void
    {
        $q = new SqsQueue(UTConfig::$awsConfig, 'aw-ut-nonexistent-' . time());
        $this->assertFalse($q->exists());
    }

    /** This test manages its own temporary queue — independent of shared resources. */
    public function testCreateAndDeleteQueue(): void
    {
        $tempName  = UTConfig::$sqsConfig['prefix'] . 'temp-' . time();
        $tempQueue = new SqsQueue(UTConfig::$awsConfig, $tempName);
        $this->assertFalse($tempQueue->exists());

        $tempQueue->createQueue([SqsQueue::VISIBILITY_TIMEOUT => '10']);
        $this->assertTrue($tempQueue->exists());
        $tempQueue->purge();
        $tempQueue->deleteQueue();
    }
}
