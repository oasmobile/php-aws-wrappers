<?php

namespace Oasis\Mlib\AwsWrappers;

use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use Oasis\Mlib\AwsWrappers\Contracts\QueueInterface;
use Oasis\Mlib\Event\EventDispatcherInterface;
use Oasis\Mlib\Event\EventDispatcherTrait;

class SqsQueue implements EventDispatcherInterface, QueueInterface
{
    use EventDispatcherTrait;
    
    const SEND_PROGRESS   = "send_progress";
    const READ_PROGRESS   = "read_progress";
    const DELETE_PROGRESS = "delete_progress";
    
    const DELAY_SECONDS                              = 'DelaySeconds';
    const MAXIMUM_MESSAGE_SIZE                       = 'MaximumMessageSize';
    const MESSAGE_RETENTION_PERIOD                   = 'MessageRetentionPeriod';
    const RECEIVE_MESSAGE_WAIT_TIME_SECONDS          = 'ReceiveMessageWaitTimeSeconds';
    const VISIBILITY_TIMEOUT                         = 'VisibilityTimeout';
    const ALL                                        = 'All';
    const APPROXIMATE_NUMBER_OF_MESSAGES             = 'ApproximateNumberOfMessages';
    const APPROXIMATE_NUMBER_OF_MESSAGES_NOT_VISIBLE = 'ApproximateNumberOfMessagesNotVisible';
    const APPROXIMATE_NUMBER_OF_MESSAGES_DELAYED     = 'ApproximateNumberOfMessagesDelayed';
    const CREATED_TIMESTAMP                          = 'CreatedTimestamp';
    const LAST_MODIFIED_TIMESTAMP                    = 'LastModifiedTimestamp';
    const QUEUE_ARN                                  = 'QueueArn';
    
    const MUTABLE_ATTRIBUTES = [
        self::DELAY_SECONDS,
        self::MAXIMUM_MESSAGE_SIZE,
        self::MESSAGE_RETENTION_PERIOD,
        self::RECEIVE_MESSAGE_WAIT_TIME_SECONDS,
        self::VISIBILITY_TIMEOUT,
    ];
    const ALL_ATTRIBUTES     = [
        self::DELAY_SECONDS,
        self::MAXIMUM_MESSAGE_SIZE,
        self::MESSAGE_RETENTION_PERIOD,
        self::RECEIVE_MESSAGE_WAIT_TIME_SECONDS,
        self::VISIBILITY_TIMEOUT,
        self::ALL,
        self::APPROXIMATE_NUMBER_OF_MESSAGES,
        self::APPROXIMATE_NUMBER_OF_MESSAGES_NOT_VISIBLE,
        self::APPROXIMATE_NUMBER_OF_MESSAGES_DELAYED,
        self::CREATED_TIMESTAMP,
        self::LAST_MODIFIED_TIMESTAMP,
        self::QUEUE_ARN,
    ];
    const SERIALIZATION_FLAG = '_serialization';
    
    protected SqsClient $client;
    protected array $config = [];
    protected ?string $url = null;
    protected readonly string $name;
    protected array $sendFailureMessages = [];
    
    public function __construct(mixed $awsConfig, string $name)
    {
        $dp           = new AwsConfigDataProvider($awsConfig, '2012-11-05');
        $this->client = new SqsClient($dp->getConfig());
        $this->name   = $name;
    }
    
    public function createQueue(array $attributes = []): void
    {
        $args = [
            'QueueName' => $this->name,
        ];
        if ($attributes) {
            /** @noinspection PhpUnusedLocalVariableInspection */
            foreach ($attributes as $k => &$v) {
                if (!in_array($k, self::MUTABLE_ATTRIBUTES)) {
                    throw new \InvalidArgumentException("Unknown attribute $k");
                }
            }
            $args['Attributes'] = $attributes;
        }
        $result    = $this->client->createQueue($args);
        $this->url = $result['QueueUrl'];
    }

    public function deleteMessage(mixed $msg): void
    {
        $this->client->deleteMessage(
            [
                "QueueUrl"      => $this->getQueueUrl(),
                "ReceiptHandle" => $msg->getReceiptHandle(),
            ]
        );
    }
    
    public function deleteMessages(mixed $messages): void
    {
        $total = count($messages);
        if (!$total) {
            return;
        }
        
        $deleted = 0;
        $buffer  = [];
        /** @var SqsReceivedMessage $msg */
        foreach ($messages as $msg) {
            $buffer[] = $msg;
            if (count($buffer) >= 10) {
                $this->deleteMessageBatch($buffer);
                $deleted += 10;
                $this->dispatch(self::DELETE_PROGRESS, $deleted / $total);
                $buffer = [];
            }
        }
        if ($buffer) {
            $this->deleteMessageBatch($buffer);
            $this->dispatch(self::DELETE_PROGRESS, 1);
        }
    }
    
    public function deleteQueue(): void
    {
        $args = [
            "QueueUrl" => $this->getQueueUrl(),
        ];
        $this->client->deleteQueue($args);
    }
    
    public function exists(): bool
    {
        try {
            $this->url = '';
            $this->getQueueUrl();
            
            return true;
        } catch (SqsException $e) {
            if ($e->getAwsErrorCode() == 'AWS.SimpleQueueService.NonExistentQueue') {
                return false;
            }
            else {
                throw $e;
            }
        }
    }
    
    public function purge(): void
    {
        $this->client->purgeQueue(
            [
                "QueueUrl" => $this->getQueueUrl(),
            ]
        );
    }
    
    public function receiveMessage(?int $wait = null, ?int $visibility_timeout = null, array $metas = [], array $message_attributes = []): ?SqsReceivedMessage
    {
        $ret = $this->receiveMessageBatch(1, $wait, $visibility_timeout, $metas, $message_attributes);
        if (!$ret) {
            return null;
        }
        else {
            return $ret[0];
        }
    }
    
    public function receiveMessageWithAttributes(array $expected_message_attributes,
                                                 ?int $wait = null,
                                                 ?int $visibility_timeout = null,
                                                 array $metas = []): ?SqsReceivedMessage
    {
        return $this->receiveMessage($wait, $visibility_timeout, $metas, $expected_message_attributes);
    }
    
    /**
     * @return SqsReceivedMessage[]
     */
    public function receiveMessages(int $max_count,
                                    ?int $wait = null,
                                    ?int $visibility_timeout = null,
                                    array $metas = [],
                                    array $message_attributes = []): array
    {
        if ($max_count <= 0) {
            return [];
        }
        
        $buffer    = [];
        $one_batch = 10;
        while ($msgs = $this->receiveMessageBatch(
            $one_batch,
            $wait,
            $visibility_timeout,
            $metas,
            $message_attributes
        )) {
            $buffer = array_merge($buffer, $msgs);
            $this->dispatch(self::READ_PROGRESS, count($buffer) / $max_count);
            
            $one_batch = min(10, $max_count - count($buffer));
            if ($one_batch <= 0) {
                break;
            }
        }
        
        return $buffer;
    }
    
    public function sendMessage(mixed $payroll, int $delay = 0, array $attributes = []): SqsSentMessage|false
    {
        $sentMessages = $this->sendMessages([$payroll], $delay, [$attributes]);
        if (!$sentMessages) {
            return false;
        }
        else {
            return $sentMessages[0];
        }
    }
    
    /**
     * @return SqsSentMessage[] successful messages
     *
     * @NOTE: for failed messages, you can call getSendFailureMessages()
     */
    public function sendMessages(array $payrolls, int $delay = 0, array $attributesList = [], int $concurrency = 10): array
    {
        if ($attributesList && count($payrolls) != count($attributesList)) {
            throw new \UnexpectedValueException("Attribute list size is different than num of payrolls!");
        }
        
        $total         = count($payrolls);
        $progressCount = 0;
        
        $promises                  = [];
        $sentMessages              = [];
        $this->sendFailureMessages = [];
        
        $buffer             = [];
        $bufferedAttributes = [];
        $md5Expectation     = [];
        foreach ($payrolls as $idx => $payroll) {
            $buffer[$idx] = $payroll;
            if (isset($attributesList[$idx])) {
                $bufferedAttributes[$idx] = $attributesList[$idx];
            }
            else {
                $bufferedAttributes[$idx] = [];
            }
            
            if (!is_string($buffer[$idx])) {
                $buffer[$idx]                                       = base64_encode(serialize($buffer[$idx]));
                $bufferedAttributes[$idx][self::SERIALIZATION_FLAG] = 'base64_serialize';
            }
            
            $md5Expectation[$idx] = md5($buffer[$idx]);
            
            if (count($buffer) == 10) {
                $promise    = $this->getSendMessageBatchAsyncPromise($buffer, $bufferedAttributes, $delay);
                $promises[] = $promise;
                $buffer     = $bufferedAttributes = [];
            }
        }
        if (count($buffer) > 0) {
            $promise    = $this->getSendMessageBatchAsyncPromise($buffer, $bufferedAttributes, $delay);
            $promises[] = $promise;
        }
        
        \GuzzleHttp\Promise\Each::ofLimit(
            $promises,
            $concurrency,
            function (Result $result) use (
                &$sentMessages,
                &$progressCount,
                &$md5Expectation,
                $total
            ): void {
                if (isset($result['Failed'])) {
                    foreach ($result['Failed'] as $failed) {
                        mwarning(
                            "Batch sending message failed, code = %s, id = %s, msg = %s, senderfault = %s",
                            $failed['Code'],
                            $failed['Id'],
                            $failed['Message'],
                            $failed['SenderFault']
                        );
                        $this->sendFailureMessages[$failed['Id']] = $failed['Message'];
                    }
                    $progressCount += count($result['Failed']);
                }
                if (isset($result['Successful'])) {
                    foreach ($result['Successful'] as $successful) {
                        $sentMessage = new SqsSentMessage($successful);
                        if ($sentMessage->getMd5OfBody() != $md5Expectation[$successful['Id']]) {
                            $failedMessages[$successful['Id']] = "MD5 mismatch of sent message!";
                        }
                        else {
                            $sentMessages[$successful['Id']] = $sentMessage;
                        }
                    }
                    $progressCount += count($result['Successful']);
                }
                $this->dispatch(self::SEND_PROGRESS, $progressCount / $total);
            },
            function ($e): void {
                merror("Exception got: %s!", get_class($e));
                if ($e instanceof SqsException) {
                    mtrace(
                        $e,
                        sprintf(
                            "Exception while batch sending SQS messages, aws code = %s, type = %s",
                            $e->getAwsErrorCode(),
                            $e->getAwsErrorType()
                        ),
                        "error"
                    );
                }
                throw $e;
            }
        )->wait();
        
        return $sentMessages;
    }
    
    public function getAttribute(string $name): mixed
    {
        $result = $this->getAttributes([$name]);
        
        return $result[$name];
    }
    
    public function getAttributes(array $attributeNames): array
    {
        $args = [
            'QueueUrl' => $this->getQueueUrl(),
        ];
        if ($attributeNames) {
            /** @noinspection PhpUnusedLocalVariableInspection */
            foreach ($attributeNames as $name) {
                if (!in_array($name, self::ALL_ATTRIBUTES)) {
                    {
                        throw new \InvalidArgumentException("Unknown attribute $name");
                    }
                }
            }
            $args['AttributeNames'] = $attributeNames;
        }
        else {
            throw new \InvalidArgumentException("You must specify some attributes");
        }
        $result = $this->client->getQueueAttributes($args);
        
        return $result['Attributes'];
    }
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function getQueueUrl(): string
    {
        if (!$this->url) {
            $result = $this->client->getQueueUrl(
                [
                    "QueueName" => $this->name,
                ]
            );
            if ($result['QueueUrl']) {
                $this->url = $result['QueueUrl'];
            }
            else {
                throw new \RuntimeException("Cannot find queue url for queue named {$this->name}");
            }
        }
        
        return $this->url;
    }
    
    public function getSendFailureMessages(): array
    {
        return $this->sendFailureMessages;
    }
    
    public function setAttributes(array $attributes): void
    {
        $args = [
            'QueueUrl' => $this->getQueueUrl(),
        ];
        if ($attributes) {
            /** @noinspection PhpUnusedLocalVariableInspection */
            foreach ($attributes as $k => &$v) {
                if (!in_array($k, self::MUTABLE_ATTRIBUTES)) {
                    throw new \InvalidArgumentException("Unknown attribute $k");
                }
            }
            $args['Attributes'] = $attributes;
        }
        else {
            throw new \InvalidArgumentException("You must specify some attributes");
        }
        $this->client->setQueueAttributes($args);
    }
    
    protected function deleteMessageBatch(array $msgs): void
    {
        $entries = [];
        /** @var SqsReceivedMessage $bmsg */
        foreach ($msgs as $idx => $bmsg) {
            $entries[] = [
                "Id"            => "buf_$idx",
                "ReceiptHandle" => $bmsg->getReceiptHandle(),
            ];
        }
        $result = $this->client->deleteMessageBatch(
            [
                "QueueUrl" => $this->getQueueUrl(),
                "Entries"  => $entries,
            ]
        );
        if ($result['Failed']) {
            foreach ($result['Failed'] as $failed) {
                mwarning(
                    sprintf(
                        "Deleting message failed, code = %s, id = %s, msg = %s, senderfault = %s",
                        $failed['Code'],
                        $failed['Id'],
                        $failed['Message'],
                        $failed['SenderFault']
                    )
                );
            }
            throw new \RuntimeException("Cannot delete some messages, consult log for more info!");
        }
    }
    
    /**
     * @return SqsReceivedMessage[]
     */
    protected function receiveMessageBatch(int $maxCount = 1,
                                           ?int $wait = null,
                                           ?int $visibilityTimeout = null,
                                           array $metas = [],
                                           array $messageAttributes = []): array
    {
        if ($maxCount > 10 || $maxCount < 1) {
            throw new \InvalidArgumentException("Max count for SQS message receiving is 10");
        }
        
        $messageAttributes[] = self::SERIALIZATION_FLAG;
        
        $args = [
            "QueueUrl"              => $this->getQueueUrl(),
            "MaxNumberOfMessages"   => $maxCount,
            'MessageAttributeNames' => $messageAttributes,
        ];
        if ($wait !== null && is_int($wait)) {
            $args['WaitTimeSeconds'] = $wait;
        }
        if ($visibilityTimeout !== null && is_int($visibilityTimeout)) {
            $args['VisibilityTimeout'] = $visibilityTimeout;
        }
        if ($metas && is_array($metas)) {
            $args['AttributeNames'] = $metas;
        }
        
        $result   = $this->client->receiveMessage($args);
        $messages = $result['Messages'];
        if (!$messages) {
            return [];
        }
        
        $ret = [];
        foreach ($messages as $data) {
            $msg   = new SqsReceivedMessage($data);
            $ret[] = $msg;
        }
        
        return $ret;
    }
    
    protected function getSendMessageBatchAsyncPromise(array $payrolls, array $attributes, int $delay): mixed
    {
        $entries = [];
        foreach ($payrolls as $idx => $payroll) {
            $entry = [
                "Id"          => strval($idx),
                "MessageBody" => $payroll,
            ];
            if ($delay) {
                $entry['DelaySeconds'] = $delay;
            }
            if ($attributes) {
                $attributes[$idx]           = array_map(
                    function (mixed $v): array {
                        if (!is_string($v)) {
                            throw new \InvalidArgumentException(
                                "Only string attribute is supported! attribute got: " . json_encode($v)
                            );
                        }
                        
                        return ['DataType' => 'String', 'StringValue' => $v];
                    },
                    $attributes[$idx]
                );
                $entry['MessageAttributes'] = $attributes[$idx];
            }
            $entries[] = $entry;
        }
        $args = [
            "QueueUrl" => $this->getQueueUrl(),
            "Entries"  => $entries,
        ];
        
        return $this->client->sendMessageBatchAsync($args);
    }
}
