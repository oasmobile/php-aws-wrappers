<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Aws\Result;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Promise\FulfilledPromise;
use Oasis\Mlib\AwsWrappers\SqsQueue;
use Oasis\Mlib\AwsWrappers\SqsReceivedMessage;
use PHPUnit\Framework\TestCase;

/**
 * Test subclass that bypasses AwsConfigDataProvider + new SqsClient()
 * and allows injecting a mock client directly.
 */
class TestableSqsQueue extends SqsQueue
{
    public function __construct($mockClient, $name)
    {
        $this->client = $mockClient;
        $this->name   = $name;
    }
}

/**
 * Stub SqsClient that records magic method calls and returns queued results.
 * PHPUnit 13 removed addMethods()/setMethods(), so we use a manual stub for
 * AWS SDK clients that rely on __call magic methods.
 */
class StubSqsClient extends SqsClient
{
    /** @var array<string, list<mixed>> Queued return values per method */
    private array $returnQueue = [];

    /** @var array<string, list<array>> Recorded calls per method */
    public array $calls = [];

    /** @var array<string, callable> Persistent callbacks per method */
    private array $callbacks = [];

    public function __construct()
    {
        // Skip parent constructor
    }

    public function queueReturn(string $method, mixed $value): self
    {
        $this->returnQueue[$method][] = $value;
        return $this;
    }

    public function onMethod(string $method, callable $callback): self
    {
        $this->callbacks[$method] = $callback;
        return $this;
    }

    public function __call($name, array $args)
    {
        $this->calls[$name][] = $args;

        if (!empty($this->returnQueue[$name])) {
            return array_shift($this->returnQueue[$name]);
        }

        if (isset($this->callbacks[$name])) {
            return ($this->callbacks[$name])(...$args);
        }

        return null;
    }
}

class SqsQueueTest extends TestCase
{
    /** @var StubSqsClient */
    private $mockClient;

    /** @var TestableSqsQueue */
    private $queue;

    protected function setUp(): void
    {
        $this->mockClient = new StubSqsClient();
        $this->queue = new TestableSqsQueue($this->mockClient, 'test-queue');
    }

    private function stubGetQueueUrl($url = 'https://sqs.us-east-1.amazonaws.com/123456789/test-queue')
    {
        $this->mockClient->queueReturn('getQueueUrl', new Result(['QueueUrl' => $url]));
    }

    private function buildReceivedMessageArray($id, $body, $receiptHandle)
    {
        return [
            'MessageId'     => $id,
            'ReceiptHandle' => $receiptHandle,
            'Body'          => $body,
            'MD5OfBody'     => md5($body),
        ];
    }

    // ================================================================
    // 1. getName()
    // ================================================================

    public function testGetName()
    {
        $this->assertSame('test-queue', $this->queue->getName());
    }

    // ================================================================
    // 2. getQueueUrl(): fetches and caches URL
    // ================================================================

    public function testGetQueueUrlFetchesFromClient()
    {
        $this->mockClient->queueReturn('getQueueUrl', new Result(['QueueUrl' => 'https://sqs.example.com/test-queue']));

        $url = $this->queue->getQueueUrl();
        $this->assertSame('https://sqs.example.com/test-queue', $url);

        // Second call should use cached value (getQueueUrl not called again)
        $url2 = $this->queue->getQueueUrl();
        $this->assertSame('https://sqs.example.com/test-queue', $url2);
        $this->assertCount(1, $this->mockClient->calls['getQueueUrl']);
    }

    public function testGetQueueUrlThrowsWhenUrlEmpty()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot find queue url');

        $this->mockClient->queueReturn('getQueueUrl', new Result(['QueueUrl' => null]));
        $this->queue->getQueueUrl();
    }

    // ================================================================
    // 3. createQueue()
    // ================================================================

    public function testCreateQueueWithNoAttributes()
    {
        $this->mockClient->queueReturn('createQueue', new Result(['QueueUrl' => 'https://sqs.example.com/test-queue']));

        $this->queue->createQueue();

        $args = $this->mockClient->calls['createQueue'][0][0];
        $this->assertSame('test-queue', $args['QueueName']);
        $this->assertArrayNotHasKey('Attributes', $args);
    }

    public function testCreateQueueWithValidAttributes()
    {
        $this->mockClient->queueReturn('createQueue', new Result(['QueueUrl' => 'https://sqs.example.com/test-queue']));

        $this->queue->createQueue([
            SqsQueue::VISIBILITY_TIMEOUT => '30',
            SqsQueue::DELAY_SECONDS      => '5',
        ]);

        $args = $this->mockClient->calls['createQueue'][0][0];
        $this->assertSame('test-queue', $args['QueueName']);
        $this->assertSame('30', $args['Attributes'][SqsQueue::VISIBILITY_TIMEOUT]);
        $this->assertSame('5', $args['Attributes'][SqsQueue::DELAY_SECONDS]);
    }

    public function testCreateQueueThrowsOnInvalidAttribute()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown attribute');

        $this->queue->createQueue(['InvalidAttr' => 'value']);
    }

    public function testCreateQueueSetsUrlFromResult()
    {
        $this->mockClient->queueReturn('createQueue', new Result(['QueueUrl' => 'https://sqs.example.com/created-queue']));

        $this->queue->createQueue();

        $this->assertSame('https://sqs.example.com/created-queue', $this->queue->getQueueUrl());
        // getQueueUrl should NOT have been called on the client
        $this->assertArrayNotHasKey('getQueueUrl', $this->mockClient->calls);
    }

    // ================================================================
    // 4. deleteQueue()
    // ================================================================

    public function testDeleteQueue()
    {
        $this->stubGetQueueUrl();
        $this->queue->deleteQueue();

        $this->assertCount(1, $this->mockClient->calls['deleteQueue']);
        $this->assertArrayHasKey('QueueUrl', $this->mockClient->calls['deleteQueue'][0][0]);
    }

    // ================================================================
    // 5. purge()
    // ================================================================

    public function testPurge()
    {
        $this->stubGetQueueUrl();
        $this->queue->purge();

        $this->assertCount(1, $this->mockClient->calls['purgeQueue']);
        $this->assertArrayHasKey('QueueUrl', $this->mockClient->calls['purgeQueue'][0][0]);
    }

    // ================================================================
    // 6-10. sendMessage()
    // ================================================================

    public function testSendMessageWithStringPayload()
    {
        $this->stubGetQueueUrl();
        $body = 'hello world';
        $md5  = md5($body);

        $this->mockClient->queueReturn('sendMessageBatchAsync', new FulfilledPromise(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'msg-001', 'MD5OfMessageBody' => $md5]],
        ])));

        $result = $this->queue->sendMessage($body);

        $this->assertNotFalse($result);
        $this->assertSame('msg-001', $result->getMessageId());
        $this->assertSame($md5, $result->getMd5OfBody());
        $args = $this->mockClient->calls['sendMessageBatchAsync'][0][0];
        $this->assertSame($body, $args['Entries'][0]['MessageBody']);
        $this->assertArrayNotHasKey('DelaySeconds', $args['Entries'][0]);
    }

    public function testSendMessageWithNonStringPayloadAutoSerializes()
    {
        $this->stubGetQueueUrl();
        $payload    = ['key' => 'value', 'num' => 42];
        $serialized = base64_encode(serialize($payload));
        $md5        = md5($serialized);

        $this->mockClient->queueReturn('sendMessageBatchAsync', new FulfilledPromise(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'msg-002', 'MD5OfMessageBody' => $md5]],
        ])));

        $result = $this->queue->sendMessage($payload);

        $this->assertNotFalse($result);
        $this->assertSame('msg-002', $result->getMessageId());
        $entry = $this->mockClient->calls['sendMessageBatchAsync'][0][0]['Entries'][0];
        $this->assertSame($serialized, $entry['MessageBody']);
        $this->assertArrayHasKey(SqsQueue::SERIALIZATION_FLAG, $entry['MessageAttributes']);
        $attr = $entry['MessageAttributes'][SqsQueue::SERIALIZATION_FLAG];
        $this->assertSame('base64_serialize', $attr['StringValue']);
        $this->assertSame('String', $attr['DataType']);
    }

    public function testSendMessageWithDelay()
    {
        $this->stubGetQueueUrl();
        $body = 'delayed msg';
        $md5  = md5($body);

        $this->mockClient->queueReturn('sendMessageBatchAsync', new FulfilledPromise(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'msg-003', 'MD5OfMessageBody' => $md5]],
        ])));

        $this->queue->sendMessage($body, 10);

        $this->assertSame(10, $this->mockClient->calls['sendMessageBatchAsync'][0][0]['Entries'][0]['DelaySeconds']);
    }

    public function testSendMessageWithAttributes()
    {
        $this->stubGetQueueUrl();
        $body = 'attributed msg';
        $md5  = md5($body);

        $this->mockClient->queueReturn('sendMessageBatchAsync', new FulfilledPromise(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'msg-004', 'MD5OfMessageBody' => $md5]],
        ])));

        $this->queue->sendMessage($body, 0, ['user' => 'minhao']);

        $entry = $this->mockClient->calls['sendMessageBatchAsync'][0][0]['Entries'][0];
        $this->assertSame('String', $entry['MessageAttributes']['user']['DataType']);
        $this->assertSame('minhao', $entry['MessageAttributes']['user']['StringValue']);
    }

    public function testSendMessageReturnsFalseWhenBatchFails()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->queueReturn('sendMessageBatchAsync', new FulfilledPromise(new Result([
            'Failed' => [['Id' => '0', 'Code' => 'InvalidInput', 'Message' => 'Invalid binary character', 'SenderFault' => true]],
        ])));

        $result = $this->queue->sendMessage('fail msg');
        $this->assertFalse($result);
    }

    // ================================================================
    // 11-13. sendMessages()
    // ================================================================

    public function testSendMessagesBatchesInGroupsOfTen()
    {
        $this->stubGetQueueUrl();
        $payrolls = [];
        for ($i = 0; $i < 12; $i++) {
            $payrolls[] = "msg-$i";
        }

        $this->mockClient->onMethod('sendMessageBatchAsync', function ($args) {
            $successful = [];
            foreach ($args['Entries'] as $entry) {
                $successful[] = [
                    'Id'               => $entry['Id'],
                    'MessageId'        => 'msg-id-' . $entry['Id'],
                    'MD5OfMessageBody' => md5($entry['MessageBody']),
                ];
            }
            return new FulfilledPromise(new Result(['Successful' => $successful]));
        });

        $result = $this->queue->sendMessages($payrolls);

        $this->assertCount(12, $result);
        $this->assertCount(2, $this->mockClient->calls['sendMessageBatchAsync']);
    }

    public function testSendMessagesThrowsOnAttributeListSizeMismatch()
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Attribute list size is different');

        $this->queue->sendMessages(['msg1', 'msg2'], 0, [['attr' => 'val']]);
    }

    public function testSendMessagesThrowsOnNonStringAttributeValue()
    {
        $this->stubGetQueueUrl();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only string attribute is supported');

        $this->queue->sendMessages(['msg1'], 0, [['count' => 42]]);
    }

    // ================================================================
    // 14. getSendFailureMessages()
    // ================================================================

    public function testGetSendFailureMessagesAfterPartialFailure()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->queueReturn('sendMessageBatchAsync', new FulfilledPromise(new Result([
            'Successful' => [['Id' => '1', 'MessageId' => 'msg-ok', 'MD5OfMessageBody' => md5('good')]],
            'Failed' => [['Id' => '0', 'Code' => 'InvalidInput', 'Message' => 'Bad message', 'SenderFault' => true]],
        ])));

        $this->queue->sendMessages(['bad', 'good']);

        $failures = $this->queue->getSendFailureMessages();
        $this->assertArrayHasKey('0', $failures);
        $this->assertSame('Bad message', $failures['0']);
    }

    // ================================================================
    // 15-18. receiveMessage()
    // ================================================================

    public function testReceiveMessageReturnsSqsReceivedMessage()
    {
        $this->stubGetQueueUrl();
        $body = 'received body';

        $this->mockClient->queueReturn('receiveMessage', new Result([
            'Messages' => [$this->buildReceivedMessageArray('msg-r1', $body, 'receipt-1')],
        ]));

        $msg = $this->queue->receiveMessage();

        $this->assertInstanceOf(SqsReceivedMessage::class, $msg);
        $this->assertSame($body, $msg->getBody());
        $args = $this->mockClient->calls['receiveMessage'][0][0];
        $this->assertSame(1, $args['MaxNumberOfMessages']);
        $this->assertArrayHasKey('QueueUrl', $args);
    }

    public function testReceiveMessageReturnsNullWhenEmpty()
    {
        $this->stubGetQueueUrl();
        $this->mockClient->queueReturn('receiveMessage', new Result(['Messages' => null]));

        $this->assertNull($this->queue->receiveMessage());
    }

    public function testReceiveMessageWithWaitAndVisibilityTimeout()
    {
        $this->stubGetQueueUrl();
        $body = 'timed msg';

        $this->mockClient->queueReturn('receiveMessage', new Result([
            'Messages' => [$this->buildReceivedMessageArray('msg-r2', $body, 'receipt-2')],
        ]));

        $this->queue->receiveMessage(5, 30);

        $args = $this->mockClient->calls['receiveMessage'][0][0];
        $this->assertSame(5, $args['WaitTimeSeconds']);
        $this->assertSame(30, $args['VisibilityTimeout']);
    }

    public function testReceiveMessageWithMetas()
    {
        $this->stubGetQueueUrl();
        $body = 'meta msg';

        $this->mockClient->queueReturn('receiveMessage', new Result([
            'Messages' => [$this->buildReceivedMessageArray('msg-r3', $body, 'receipt-3')],
        ]));

        $this->queue->receiveMessage(null, null, ['ApproximateReceiveCount']);

        $args = $this->mockClient->calls['receiveMessage'][0][0];
        $this->assertContains('ApproximateReceiveCount', $args['AttributeNames']);
    }

    // ================================================================
    // 19-20. receiveMessages()
    // ================================================================

    public function testReceiveMessagesReturnsMultiple()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->queueReturn('receiveMessage', new Result([
            'Messages' => [
                $this->buildReceivedMessageArray('msg-1', 'body1', 'r1'),
                $this->buildReceivedMessageArray('msg-2', 'body2', 'r2'),
                $this->buildReceivedMessageArray('msg-3', 'body3', 'r3'),
            ],
        ]));

        $msgs = $this->queue->receiveMessages(3);
        $this->assertCount(3, $msgs);
        $this->assertInstanceOf(SqsReceivedMessage::class, $msgs[0]);
    }

    public function testReceiveMessagesReturnsEmptyWhenMaxCountZero()
    {
        $this->assertSame([], $this->queue->receiveMessages(0));
    }

    public function testReceiveMessagesReturnsEmptyWhenMaxCountNegative()
    {
        $this->assertSame([], $this->queue->receiveMessages(-1));
    }

    // ================================================================
    // 21-23. deleteMessage / deleteMessages
    // ================================================================

    public function testDeleteMessage()
    {
        $this->stubGetQueueUrl();
        $msg = new SqsReceivedMessage($this->buildReceivedMessageArray('msg-d1', 'body', 'receipt-del'));

        $this->queue->deleteMessage($msg);

        $args = $this->mockClient->calls['deleteMessage'][0][0];
        $this->assertSame('receipt-del', $args['ReceiptHandle']);
        $this->assertArrayHasKey('QueueUrl', $args);
    }

    public function testDeleteMessagesBatchesInGroupsOfTen()
    {
        $this->stubGetQueueUrl();
        $messages = [];
        for ($i = 0; $i < 12; $i++) {
            $messages[] = new SqsReceivedMessage($this->buildReceivedMessageArray("msg-$i", "body-$i", "receipt-$i"));
        }

        $this->mockClient->onMethod('deleteMessageBatch', function () {
            return new Result(['Failed' => null]);
        });

        $this->queue->deleteMessages($messages);
        $this->assertCount(2, $this->mockClient->calls['deleteMessageBatch']);
    }

    public function testDeleteMessagesWithEmptyArrayDoesNothing()
    {
        $this->queue->deleteMessages([]);
        $this->assertArrayNotHasKey('deleteMessageBatch', $this->mockClient->calls);
    }

    // ================================================================
    // 24-25. getAttribute / getAttributes
    // ================================================================

    public function testGetAttribute()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->queueReturn('getQueueAttributes', new Result([
            'Attributes' => [SqsQueue::APPROXIMATE_NUMBER_OF_MESSAGES => '42'],
        ]));

        $value = $this->queue->getAttribute(SqsQueue::APPROXIMATE_NUMBER_OF_MESSAGES);
        $this->assertSame('42', $value);

        $args = $this->mockClient->calls['getQueueAttributes'][0][0];
        $this->assertContains(SqsQueue::APPROXIMATE_NUMBER_OF_MESSAGES, $args['AttributeNames']);
    }

    public function testGetAttributes()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->queueReturn('getQueueAttributes', new Result([
            'Attributes' => [SqsQueue::VISIBILITY_TIMEOUT => '30', SqsQueue::DELAY_SECONDS => '0'],
        ]));

        $attrs = $this->queue->getAttributes([SqsQueue::VISIBILITY_TIMEOUT, SqsQueue::DELAY_SECONDS]);
        $this->assertSame('30', $attrs[SqsQueue::VISIBILITY_TIMEOUT]);
        $this->assertSame('0', $attrs[SqsQueue::DELAY_SECONDS]);
    }

    public function testGetAttributesThrowsOnInvalidAttributeName()
    {
        $this->stubGetQueueUrl();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown attribute');
        $this->queue->getAttributes(['InvalidAttr']);
    }

    public function testGetAttributesThrowsOnEmptyArray()
    {
        $this->stubGetQueueUrl();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You must specify some attributes');
        $this->queue->getAttributes([]);
    }

    // ================================================================
    // 26. setAttributes()
    // ================================================================

    public function testSetAttributes()
    {
        $this->stubGetQueueUrl();
        $this->queue->setAttributes([SqsQueue::VISIBILITY_TIMEOUT => '60']);

        $args = $this->mockClient->calls['setQueueAttributes'][0][0];
        $this->assertSame('60', $args['Attributes'][SqsQueue::VISIBILITY_TIMEOUT]);
        $this->assertArrayHasKey('QueueUrl', $args);
    }

    public function testSetAttributesThrowsOnInvalidAttribute()
    {
        $this->stubGetQueueUrl();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown attribute');
        $this->queue->setAttributes(['InvalidAttr' => 'value']);
    }

    public function testSetAttributesThrowsOnEmptyArray()
    {
        $this->stubGetQueueUrl();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You must specify some attributes');
        $this->queue->setAttributes([]);
    }

    // ================================================================
    // 27. receiveMessageBatch: maxCount validation
    // ================================================================

    public function testReceiveMessageBatchThrowsWhenMaxCountExceedsTen()
    {
        $this->stubGetQueueUrl();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max count for SQS message receiving is 10');

        $queue = new SqsQueueTestExposer($this->mockClient, 'test-queue');
        $queue->callReceiveMessageBatch(11);
    }

    // ================================================================
    // 28. SERIALIZATION_FLAG in attribute names
    // ================================================================

    public function testReceiveMessageIncludesSerializationFlagInAttributeNames()
    {
        $this->stubGetQueueUrl();
        $body = 'test';

        $this->mockClient->queueReturn('receiveMessage', new Result([
            'Messages' => [$this->buildReceivedMessageArray('msg-sf', $body, 'receipt-sf')],
        ]));

        $this->queue->receiveMessage();

        $args = $this->mockClient->calls['receiveMessage'][0][0];
        $this->assertContains(SqsQueue::SERIALIZATION_FLAG, $args['MessageAttributeNames']);
    }

    // ================================================================
    // 29. MD5 mismatch detection
    // ================================================================

    public function testSendMessagesMd5MismatchExcludesFromResult()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->queueReturn('sendMessageBatchAsync', new FulfilledPromise(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'msg-mismatch', 'MD5OfMessageBody' => 'wrong-md5']],
        ])));

        $result = $this->queue->sendMessages(['test body']);
        $this->assertEmpty($result);
    }

    // ================================================================
    // 30. receiveMessageWithAttributes
    // ================================================================

    public function testReceiveMessageWithAttributesDelegatesToReceiveMessage()
    {
        $this->stubGetQueueUrl();
        $body = 'attr msg';

        $this->mockClient->queueReturn('receiveMessage', new Result([
            'Messages' => [$this->buildReceivedMessageArray('msg-wa', $body, 'receipt-wa')],
        ]));

        $msg = $this->queue->receiveMessageWithAttributes(['user']);

        $this->assertInstanceOf(SqsReceivedMessage::class, $msg);
        $args = $this->mockClient->calls['receiveMessage'][0][0];
        $this->assertContains('user', $args['MessageAttributeNames']);
        $this->assertContains(SqsQueue::SERIALIZATION_FLAG, $args['MessageAttributeNames']);
    }
}

/**
 * Subclass to expose protected receiveMessageBatch for testing maxCount validation.
 */
class SqsQueueTestExposer extends SqsQueue
{
    public function __construct($mockClient, $name)
    {
        $this->client = $mockClient;
        $this->name   = $name;
    }

    public function callReceiveMessageBatch($maxCount)
    {
        return $this->receiveMessageBatch($maxCount);
    }
}
