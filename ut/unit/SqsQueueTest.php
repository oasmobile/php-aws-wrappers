<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Aws\Result;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Promise\FulfilledPromise;
use Oasis\Mlib\AwsWrappers\SqsQueue;
use Oasis\Mlib\AwsWrappers\SqsReceivedMessage;

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

class SqsQueueTest extends \PHPUnit_Framework_TestCase
{
    /** @var SqsClient|\PHPUnit_Framework_MockObject_MockObject */
    private $mockClient;

    /** @var TestableSqsQueue */
    private $queue;

    protected function setUp()
    {
        $this->mockClient = $this->getMockBuilder(SqsClient::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'createQueue',
                'deleteQueue',
                'purgeQueue',
                'getQueueUrl',
                'getQueueAttributes',
                'setQueueAttributes',
                'sendMessageBatchAsync',
                'receiveMessage',
                'deleteMessage',
                'deleteMessageBatch',
            ])
            ->getMock();

        $this->queue = new TestableSqsQueue($this->mockClient, 'test-queue');
    }

    // ================================================================
    // Helper: stub getQueueUrl to return a fixed URL
    // ================================================================

    private function stubGetQueueUrl($url = 'https://sqs.us-east-1.amazonaws.com/123456789/test-queue')
    {
        $this->mockClient
            ->method('getQueueUrl')
            ->willReturn(new Result(['QueueUrl' => $url]));
    }

    // ================================================================
    // Helper: build a minimal valid received message array
    // ================================================================

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
        $this->mockClient->expects($this->once())
            ->method('getQueueUrl')
            ->with(['QueueName' => 'test-queue'])
            ->willReturn(new Result(['QueueUrl' => 'https://sqs.example.com/test-queue']));

        $url = $this->queue->getQueueUrl();
        $this->assertSame('https://sqs.example.com/test-queue', $url);

        // Second call should use cached value (getQueueUrl not called again)
        $url2 = $this->queue->getQueueUrl();
        $this->assertSame('https://sqs.example.com/test-queue', $url2);
    }

    public function testGetQueueUrlThrowsWhenUrlEmpty()
    {
        $this->setExpectedException(\RuntimeException::class, 'Cannot find queue url');

        $this->mockClient->expects($this->once())
            ->method('getQueueUrl')
            ->willReturn(new Result(['QueueUrl' => null]));

        $this->queue->getQueueUrl();
    }

    // ================================================================
    // 3. createQueue()
    // ================================================================

    public function testCreateQueueWithNoAttributes()
    {
        $this->mockClient->expects($this->once())
            ->method('createQueue')
            ->with($this->callback(function ($args) {
                return $args['QueueName'] === 'test-queue'
                    && !isset($args['Attributes']);
            }))
            ->willReturn(new Result(['QueueUrl' => 'https://sqs.example.com/test-queue']));

        $this->queue->createQueue();
    }

    public function testCreateQueueWithValidAttributes()
    {
        $this->mockClient->expects($this->once())
            ->method('createQueue')
            ->with($this->callback(function ($args) {
                return $args['QueueName'] === 'test-queue'
                    && $args['Attributes'][SqsQueue::VISIBILITY_TIMEOUT] === '30'
                    && $args['Attributes'][SqsQueue::DELAY_SECONDS] === '5';
            }))
            ->willReturn(new Result(['QueueUrl' => 'https://sqs.example.com/test-queue']));

        $this->queue->createQueue([
            SqsQueue::VISIBILITY_TIMEOUT => '30',
            SqsQueue::DELAY_SECONDS      => '5',
        ]);
    }

    public function testCreateQueueThrowsOnInvalidAttribute()
    {
        $this->setExpectedException(
            \InvalidArgumentException::class,
            'Unknown attribute'
        );

        $this->queue->createQueue(['InvalidAttr' => 'value']);
    }

    public function testCreateQueueSetsUrlFromResult()
    {
        $this->mockClient->expects($this->once())
            ->method('createQueue')
            ->willReturn(new Result(['QueueUrl' => 'https://sqs.example.com/created-queue']));

        $this->queue->createQueue();

        // getQueueUrl should return the URL set by createQueue without calling getQueueUrl on client
        $this->mockClient->expects($this->never())
            ->method('getQueueUrl');

        $this->assertSame('https://sqs.example.com/created-queue', $this->queue->getQueueUrl());
    }

    // ================================================================
    // 4. deleteQueue()
    // ================================================================

    public function testDeleteQueue()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->expects($this->once())
            ->method('deleteQueue')
            ->with($this->callback(function ($args) {
                return isset($args['QueueUrl']);
            }));

        $this->queue->deleteQueue();
    }

    // ================================================================
    // 5. purge()
    // ================================================================

    public function testPurge()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->expects($this->once())
            ->method('purgeQueue')
            ->with($this->callback(function ($args) {
                return isset($args['QueueUrl']);
            }));

        $this->queue->purge();
    }

    // ================================================================
    // 6. sendMessage(): string payload
    // ================================================================

    public function testSendMessageWithStringPayload()
    {
        $this->stubGetQueueUrl();

        $body = 'hello world';
        $md5  = md5($body);

        $this->mockClient->expects($this->once())
            ->method('sendMessageBatchAsync')
            ->with($this->callback(function ($args) use ($body) {
                return $args['Entries'][0]['MessageBody'] === $body
                    && !isset($args['Entries'][0]['DelaySeconds']);
            }))
            ->willReturn(new FulfilledPromise(new Result([
                'Successful' => [
                    [
                        'Id'               => '0',
                        'MessageId'        => 'msg-001',
                        'MD5OfMessageBody' => $md5,
                    ],
                ],
            ])));

        $result = $this->queue->sendMessage($body);

        $this->assertNotFalse($result);
        $this->assertSame('msg-001', $result->getMessageId());
        $this->assertSame($md5, $result->getMd5OfBody());
    }

    // ================================================================
    // 7. sendMessage(): non-string payload (auto-serialization)
    // ================================================================

    public function testSendMessageWithNonStringPayloadAutoSerializes()
    {
        $this->stubGetQueueUrl();

        $payload    = ['key' => 'value', 'num' => 42];
        $serialized = base64_encode(serialize($payload));
        $md5        = md5($serialized);

        $this->mockClient->expects($this->once())
            ->method('sendMessageBatchAsync')
            ->with($this->callback(function ($args) use ($serialized) {
                $entry = $args['Entries'][0];
                // Body should be base64-serialized
                if ($entry['MessageBody'] !== $serialized) {
                    return false;
                }
                // Should have _serialization attribute
                if (!isset($entry['MessageAttributes'][SqsQueue::SERIALIZATION_FLAG])) {
                    return false;
                }
                $attr = $entry['MessageAttributes'][SqsQueue::SERIALIZATION_FLAG];

                return $attr['StringValue'] === 'base64_serialize'
                    && $attr['DataType'] === 'String';
            }))
            ->willReturn(new FulfilledPromise(new Result([
                'Successful' => [
                    [
                        'Id'               => '0',
                        'MessageId'        => 'msg-002',
                        'MD5OfMessageBody' => $md5,
                    ],
                ],
            ])));

        $result = $this->queue->sendMessage($payload);

        $this->assertNotFalse($result);
        $this->assertSame('msg-002', $result->getMessageId());
    }

    // ================================================================
    // 8. sendMessage(): with delay
    // ================================================================

    public function testSendMessageWithDelay()
    {
        $this->stubGetQueueUrl();

        $body = 'delayed msg';
        $md5  = md5($body);

        $this->mockClient->expects($this->once())
            ->method('sendMessageBatchAsync')
            ->with($this->callback(function ($args) {
                return $args['Entries'][0]['DelaySeconds'] === 10;
            }))
            ->willReturn(new FulfilledPromise(new Result([
                'Successful' => [
                    [
                        'Id'               => '0',
                        'MessageId'        => 'msg-003',
                        'MD5OfMessageBody' => $md5,
                    ],
                ],
            ])));

        $this->queue->sendMessage($body, 10);
    }

    // ================================================================
    // 9. sendMessage(): with string attributes
    // ================================================================

    public function testSendMessageWithAttributes()
    {
        $this->stubGetQueueUrl();

        $body = 'attributed msg';
        $md5  = md5($body);

        $this->mockClient->expects($this->once())
            ->method('sendMessageBatchAsync')
            ->with($this->callback(function ($args) {
                $entry = $args['Entries'][0];

                return isset($entry['MessageAttributes']['user'])
                    && $entry['MessageAttributes']['user']['DataType'] === 'String'
                    && $entry['MessageAttributes']['user']['StringValue'] === 'minhao';
            }))
            ->willReturn(new FulfilledPromise(new Result([
                'Successful' => [
                    [
                        'Id'               => '0',
                        'MessageId'        => 'msg-004',
                        'MD5OfMessageBody' => $md5,
                    ],
                ],
            ])));

        $this->queue->sendMessage($body, 0, ['user' => 'minhao']);
    }

    // ================================================================
    // 10. sendMessage(): returns false when batch fails
    // ================================================================

    public function testSendMessageReturnsFalseWhenBatchFails()
    {
        $this->stubGetQueueUrl();

        $body = 'fail msg';

        $this->mockClient->expects($this->once())
            ->method('sendMessageBatchAsync')
            ->willReturn(new FulfilledPromise(new Result([
                'Failed' => [
                    [
                        'Id'          => '0',
                        'Code'        => 'InvalidInput',
                        'Message'     => 'Invalid binary character',
                        'SenderFault' => true,
                    ],
                ],
            ])));

        $result = $this->queue->sendMessage($body);

        $this->assertFalse($result);
    }

    // ================================================================
    // 11. sendMessages(): batching (>10 messages)
    // ================================================================

    public function testSendMessagesBatchesInGroupsOfTen()
    {
        $this->stubGetQueueUrl();

        $payrolls = [];
        for ($i = 0; $i < 12; $i++) {
            $payrolls[] = "msg-$i";
        }

        // Should produce 2 batch calls: 10 + 2
        $callCount = 0;
        $this->mockClient->expects($this->exactly(2))
            ->method('sendMessageBatchAsync')
            ->willReturnCallback(function ($args) use (&$callCount) {
                $callCount++;
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
    }

    // ================================================================
    // 12. sendMessages(): attribute list size mismatch → throws
    // ================================================================

    public function testSendMessagesThrowsOnAttributeListSizeMismatch()
    {
        $this->setExpectedException(
            \UnexpectedValueException::class,
            'Attribute list size is different'
        );

        $this->queue->sendMessages(
            ['msg1', 'msg2'],
            0,
            [['attr' => 'val']] // only 1 attribute set for 2 payrolls
        );
    }

    // ================================================================
    // 13. sendMessages(): non-string attribute value → throws
    // ================================================================

    public function testSendMessagesThrowsOnNonStringAttributeValue()
    {
        $this->stubGetQueueUrl();

        // The InvalidArgumentException is thrown inside getSendMessageBatchAsyncPromise
        // when mapping attributes with non-string values, before sendMessageBatchAsync is called.
        $this->setExpectedException(\InvalidArgumentException::class, 'Only string attribute is supported');

        $this->queue->sendMessages(
            ['msg1'],
            0,
            [['count' => 42]] // non-string attribute value
        );
    }

    // ================================================================
    // 14. getSendFailureMessages()
    // ================================================================

    public function testGetSendFailureMessagesAfterPartialFailure()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->expects($this->once())
            ->method('sendMessageBatchAsync')
            ->willReturn(new FulfilledPromise(new Result([
                'Successful' => [
                    [
                        'Id'               => '1',
                        'MessageId'        => 'msg-ok',
                        'MD5OfMessageBody' => md5('good'),
                    ],
                ],
                'Failed' => [
                    [
                        'Id'          => '0',
                        'Code'        => 'InvalidInput',
                        'Message'     => 'Bad message',
                        'SenderFault' => true,
                    ],
                ],
            ])));

        $result = $this->queue->sendMessages(['bad', 'good']);

        $failures = $this->queue->getSendFailureMessages();
        $this->assertArrayHasKey('0', $failures);
        $this->assertSame('Bad message', $failures['0']);
    }

    // ================================================================
    // 15. receiveMessage(): returns message
    // ================================================================

    public function testReceiveMessageReturnsSqsReceivedMessage()
    {
        $this->stubGetQueueUrl();

        $body = 'received body';
        $this->mockClient->expects($this->once())
            ->method('receiveMessage')
            ->with($this->callback(function ($args) {
                return $args['MaxNumberOfMessages'] === 1
                    && isset($args['QueueUrl']);
            }))
            ->willReturn(new Result([
                'Messages' => [
                    $this->buildReceivedMessageArray('msg-r1', $body, 'receipt-1'),
                ],
            ]));

        $msg = $this->queue->receiveMessage();

        $this->assertInstanceOf(SqsReceivedMessage::class, $msg);
        $this->assertSame($body, $msg->getBody());
    }

    // ================================================================
    // 16. receiveMessage(): returns null when no messages
    // ================================================================

    public function testReceiveMessageReturnsNullWhenEmpty()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->expects($this->once())
            ->method('receiveMessage')
            ->willReturn(new Result(['Messages' => null]));

        $result = $this->queue->receiveMessage();

        $this->assertNull($result);
    }

    // ================================================================
    // 17. receiveMessage(): with wait and visibility timeout
    // ================================================================

    public function testReceiveMessageWithWaitAndVisibilityTimeout()
    {
        $this->stubGetQueueUrl();

        $body = 'timed msg';
        $this->mockClient->expects($this->once())
            ->method('receiveMessage')
            ->with($this->callback(function ($args) {
                return $args['WaitTimeSeconds'] === 5
                    && $args['VisibilityTimeout'] === 30;
            }))
            ->willReturn(new Result([
                'Messages' => [
                    $this->buildReceivedMessageArray('msg-r2', $body, 'receipt-2'),
                ],
            ]));

        $this->queue->receiveMessage(5, 30);
    }

    // ================================================================
    // 18. receiveMessage(): with metas (AttributeNames)
    // ================================================================

    public function testReceiveMessageWithMetas()
    {
        $this->stubGetQueueUrl();

        $body = 'meta msg';
        $this->mockClient->expects($this->once())
            ->method('receiveMessage')
            ->with($this->callback(function ($args) {
                return in_array('ApproximateReceiveCount', $args['AttributeNames']);
            }))
            ->willReturn(new Result([
                'Messages' => [
                    $this->buildReceivedMessageArray('msg-r3', $body, 'receipt-3'),
                ],
            ]));

        $this->queue->receiveMessage(null, null, ['ApproximateReceiveCount']);
    }

    // ================================================================
    // 19. receiveMessages(): returns multiple messages
    // ================================================================

    public function testReceiveMessagesReturnsMultiple()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->expects($this->once())
            ->method('receiveMessage')
            ->willReturn(new Result([
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

    // ================================================================
    // 20. receiveMessages(): returns empty array when max_count <= 0
    // ================================================================

    public function testReceiveMessagesReturnsEmptyWhenMaxCountZero()
    {
        $result = $this->queue->receiveMessages(0);
        $this->assertSame([], $result);
    }

    public function testReceiveMessagesReturnsEmptyWhenMaxCountNegative()
    {
        $result = $this->queue->receiveMessages(-1);
        $this->assertSame([], $result);
    }

    // ================================================================
    // 21. deleteMessage()
    // ================================================================

    public function testDeleteMessage()
    {
        $this->stubGetQueueUrl();

        $msgData = $this->buildReceivedMessageArray('msg-d1', 'body', 'receipt-del');
        $msg     = new SqsReceivedMessage($msgData);

        $this->mockClient->expects($this->once())
            ->method('deleteMessage')
            ->with($this->callback(function ($args) {
                return $args['ReceiptHandle'] === 'receipt-del'
                    && isset($args['QueueUrl']);
            }));

        $this->queue->deleteMessage($msg);
    }

    // ================================================================
    // 22. deleteMessages(): batches in groups of 10
    // ================================================================

    public function testDeleteMessagesBatchesInGroupsOfTen()
    {
        $this->stubGetQueueUrl();

        $messages = [];
        for ($i = 0; $i < 12; $i++) {
            $body       = "body-$i";
            $messages[] = new SqsReceivedMessage(
                $this->buildReceivedMessageArray("msg-$i", $body, "receipt-$i")
            );
        }

        // 12 messages → 2 batch calls: 10 + 2
        $this->mockClient->expects($this->exactly(2))
            ->method('deleteMessageBatch')
            ->willReturn(new Result(['Failed' => null]));

        $this->queue->deleteMessages($messages);
    }

    // ================================================================
    // 23. deleteMessages(): empty array does nothing
    // ================================================================

    public function testDeleteMessagesWithEmptyArrayDoesNothing()
    {
        $this->mockClient->expects($this->never())
            ->method('deleteMessageBatch');

        $this->queue->deleteMessages([]);
    }

    // ================================================================
    // 24. getAttribute()
    // ================================================================

    public function testGetAttribute()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->expects($this->once())
            ->method('getQueueAttributes')
            ->with($this->callback(function ($args) {
                return in_array(SqsQueue::APPROXIMATE_NUMBER_OF_MESSAGES, $args['AttributeNames']);
            }))
            ->willReturn(new Result([
                'Attributes' => [
                    SqsQueue::APPROXIMATE_NUMBER_OF_MESSAGES => '42',
                ],
            ]));

        $value = $this->queue->getAttribute(SqsQueue::APPROXIMATE_NUMBER_OF_MESSAGES);

        $this->assertSame('42', $value);
    }

    // ================================================================
    // 25. getAttributes()
    // ================================================================

    public function testGetAttributes()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->expects($this->once())
            ->method('getQueueAttributes')
            ->willReturn(new Result([
                'Attributes' => [
                    SqsQueue::VISIBILITY_TIMEOUT => '30',
                    SqsQueue::DELAY_SECONDS      => '0',
                ],
            ]));

        $attrs = $this->queue->getAttributes([
            SqsQueue::VISIBILITY_TIMEOUT,
            SqsQueue::DELAY_SECONDS,
        ]);

        $this->assertSame('30', $attrs[SqsQueue::VISIBILITY_TIMEOUT]);
        $this->assertSame('0', $attrs[SqsQueue::DELAY_SECONDS]);
    }

    public function testGetAttributesThrowsOnInvalidAttributeName()
    {
        $this->stubGetQueueUrl();

        $this->setExpectedException(
            \InvalidArgumentException::class,
            'Unknown attribute'
        );

        $this->queue->getAttributes(['InvalidAttr']);
    }

    public function testGetAttributesThrowsOnEmptyArray()
    {
        $this->stubGetQueueUrl();

        $this->setExpectedException(
            \InvalidArgumentException::class,
            'You must specify some attributes'
        );

        $this->queue->getAttributes([]);
    }

    // ================================================================
    // 26. setAttributes()
    // ================================================================

    public function testSetAttributes()
    {
        $this->stubGetQueueUrl();

        $this->mockClient->expects($this->once())
            ->method('setQueueAttributes')
            ->with($this->callback(function ($args) {
                return $args['Attributes'][SqsQueue::VISIBILITY_TIMEOUT] === '60'
                    && isset($args['QueueUrl']);
            }));

        $this->queue->setAttributes([
            SqsQueue::VISIBILITY_TIMEOUT => '60',
        ]);
    }

    public function testSetAttributesThrowsOnInvalidAttribute()
    {
        $this->stubGetQueueUrl();

        $this->setExpectedException(
            \InvalidArgumentException::class,
            'Unknown attribute'
        );

        $this->queue->setAttributes(['InvalidAttr' => 'value']);
    }

    public function testSetAttributesThrowsOnEmptyArray()
    {
        $this->stubGetQueueUrl();

        $this->setExpectedException(
            \InvalidArgumentException::class,
            'You must specify some attributes'
        );

        $this->queue->setAttributes([]);
    }

    // ================================================================
    // 27. receiveMessageBatch: maxCount validation
    // ================================================================

    public function testReceiveMessageBatchThrowsWhenMaxCountExceedsTen()
    {
        $this->stubGetQueueUrl();

        $this->setExpectedException(
            \InvalidArgumentException::class,
            'Max count for SQS message receiving is 10'
        );

        // receiveMessages with max_count > 10 calls receiveMessageBatch with min(10, max_count)
        // but receiveMessageBatch itself validates maxCount. We need to trigger it directly.
        // receiveMessages(15) will call receiveMessageBatch(10, ...) which is valid.
        // To trigger the validation, we use receiveMessage which calls receiveMessageBatch(1, ...).
        // Actually, the validation is maxCount > 10 || maxCount < 1.
        // receiveMessages caps at 10 per batch, so we can't trigger >10 through public API easily.
        // But we can test the <1 case through receiveMessages(0) which returns early.
        // Let's test via reflection or accept that this is an internal guard.

        // Actually, receiveMessages calls receiveMessageBatch with one_batch = min(10, max_count).
        // So one_batch is always <= 10. The >10 guard is for direct calls to receiveMessageBatch.
        // Since receiveMessageBatch is protected, we test it via a subclass.
        $queue = new SqsQueueTestExposer($this->mockClient, 'test-queue');
        $queue->callReceiveMessageBatch(11);
    }

    // ================================================================
    // 28. SERIALIZATION_FLAG is appended to message attribute names
    // ================================================================

    public function testReceiveMessageIncludesSerializationFlagInAttributeNames()
    {
        $this->stubGetQueueUrl();

        $body = 'test';
        $this->mockClient->expects($this->once())
            ->method('receiveMessage')
            ->with($this->callback(function ($args) {
                return in_array(SqsQueue::SERIALIZATION_FLAG, $args['MessageAttributeNames']);
            }))
            ->willReturn(new Result([
                'Messages' => [
                    $this->buildReceivedMessageArray('msg-sf', $body, 'receipt-sf'),
                ],
            ]));

        $this->queue->receiveMessage();
    }

    // ================================================================
    // 29. sendMessages(): MD5 mismatch detection
    // ================================================================

    public function testSendMessagesMd5MismatchExcludesFromResult()
    {
        $this->stubGetQueueUrl();

        $body = 'test body';

        $this->mockClient->expects($this->once())
            ->method('sendMessageBatchAsync')
            ->willReturn(new FulfilledPromise(new Result([
                'Successful' => [
                    [
                        'Id'               => '0',
                        'MessageId'        => 'msg-mismatch',
                        'MD5OfMessageBody' => 'wrong-md5',
                    ],
                ],
            ])));

        $result = $this->queue->sendMessages([$body]);

        // MD5 mismatch → message not included in successful results
        $this->assertEmpty($result);
    }

    // ================================================================
    // 30. receiveMessageWithAttributes delegates to receiveMessage
    // ================================================================

    public function testReceiveMessageWithAttributesDelegatesToReceiveMessage()
    {
        $this->stubGetQueueUrl();

        $body = 'attr msg';
        $this->mockClient->expects($this->once())
            ->method('receiveMessage')
            ->with($this->callback(function ($args) {
                // Should include both the requested attribute and SERIALIZATION_FLAG
                return in_array('user', $args['MessageAttributeNames'])
                    && in_array(SqsQueue::SERIALIZATION_FLAG, $args['MessageAttributeNames']);
            }))
            ->willReturn(new Result([
                'Messages' => [
                    $this->buildReceivedMessageArray('msg-wa', $body, 'receipt-wa'),
                ],
            ]));

        $msg = $this->queue->receiveMessageWithAttributes(['user']);

        $this->assertInstanceOf(SqsReceivedMessage::class, $msg);
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
