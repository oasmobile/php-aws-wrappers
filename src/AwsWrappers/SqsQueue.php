<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-10-10
 * Time: 14:24
 */

namespace Oasis\Mlib\AwsWrappers;

use Aws\Sqs\SqsClient;
use Oasis\Mlib\Event\EventDispatcherInterface;
use Oasis\Mlib\Event\EventDispatcherTrait;
use Oasis\Mlib\Utils\ArrayDataProvider;

class SqsQueue implements EventDispatcherInterface
{
    use EventDispatcherTrait;

    const SEND_PROGRESS   = "send_progress";
    const READ_PROGRESS   = "read_progress";
    const DELETE_PROGRESS = "delete_progress";

    /** @var SqsClient */
    protected $client;
    protected $config;
    protected $url = null;
    protected $name;

    function __construct($aws_config, $name)
    {
        $dp           = new ArrayDataProvider($aws_config);
        $this->config = [
            'version' => "2012-11-05",
            "profile" => $dp->getMandatory('profile'),
            "region"  => $dp->getMandatory('region'),
        ];
        $this->client = new SqsClient($this->config);
        $this->name   = $name;
    }

    public function sendMessage($payroll, $delay = 0, $attributes = [])
    {
        $args = [
            "QueueUrl"    => $this->getQueueUrl(),
            "MessageBody" => $payroll,
        ];
        if ($delay) {
            $args['DelaySeconds'] = $delay;
        }
        if ($attributes) {
            $args['MessageAttributes'] = $attributes;
        }
        $result   = $this->client->sendMessage($args);
        $sent_msg = new SqsSentMessage($result->toArray());
        $md5      = md5($payroll);
        if ($result['MD5OfMessageBody'] != $md5) {
            throw new \RuntimeException("MD5 of payroll is different on sent message!");
        }

        return $sent_msg;
    }

    public function sendMessages(array $payrolls, $delay = 0, array $attributes_list = [])
    {
        if ($attributes_list && count($payrolls) != count($attributes_list)) {
            throw new \UnexpectedValueException("Attribute list size is different than num of payrolls!");
        }

        $total = count($payrolls);

        $buffer              = [];
        $buffered_attributes = [];
        while (count($payrolls) > 0) {
            $buffer[] = array_pop($payrolls);
            if ($attributes_list) {
                $buffered_attributes[] = array_pop($attributes_list);
            }
            if (count($buffer) == 10) {
                $this->sendMessageBatch($buffer, $buffered_attributes, $delay);
                $this->dispatch(self::SEND_PROGRESS, (1 - count($payrolls) / $total));
                $buffer = $buffered_attributes = [];
            }
        }
        if (count($buffer) > 0) {
            $this->sendMessageBatch($buffer, $buffered_attributes, $delay);
            $this->dispatch(self::SEND_PROGRESS, 1);
        }
    }

    protected function sendMessageBatch(array $payrolls, array $attributes, $delay)
    {
        $entries = [];
        foreach ($payrolls as $idx => $payroll) {
            $entry = [
                "Id"          => "buf_$idx",
                "MessageBody" => $payroll,
            ];
            if ($delay) {
                $entry['DelaySeconds'] = $delay;
            }
            if ($attributes) {
                $entry['MessageAttributes'] = $attributes[$idx];
            }
            $entries[] = $entry;
        }
        $args   = [
            "QueueUrl" => $this->getQueueUrl(),
            "Entries"  => $entries,
        ];
        $result = $this->client->sendMessageBatch($args);
        if ($result['Failed']) {
            foreach ($result['Failed'] as $failed) {
                mwarning(
                    sprintf(
                        "Batch sending message failed, code = %s, id = %s, msg = %s, senderfault = %s",
                        $failed['Code'],
                        $failed['Id'],
                        $failed['Message'],
                        $failed['SenderFault']
                    )
                );
            }
            throw new \RuntimeException("Cannot send some messages, consult log for more info!");
        }
    }

    /**
     * @param int   $wait
     * @param int   $visibility_timeout
     * @param array $metas
     * @param array $message_attributes
     *
     * @return SqsReceivedMessage
     */
    public function receiveMessage($wait = null, $visibility_timeout = null, $metas = [], $message_attributes = [])
    {
        $ret = $this->receiveMessageBatch(1, $wait, $visibility_timeout, $metas, $message_attributes);
        if (!$ret) {
            return null;
        }
        else {
            return $ret[0];
        }
    }

    /**
     * @param int   $max_count
     * @param int   $wait
     * @param int   $visibility_timeout
     * @param array $metas
     *
     * @param array $message_attributes
     *
     * @return SqsReceivedMessage[]
     */
    public function receiveMessages($max_count,
                                    $wait = null,
                                    $visibility_timeout = null,
                                    $metas = [],
                                    $message_attributes = [])
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

            // commented out because sometimes a full queue will somehow return less than 10 messages in a batch
            //if (count($msgs) < 10) {
            //    break;
            //}

            $one_batch = min(10, $max_count - count($buffer));
            if ($one_batch <= 0) {
                break;
            }
        }

        return $buffer;
    }

    /**
     * @param int   $max_count
     * @param int   $wait
     * @param int   $visibility_timeout
     * @param array $metas
     * @param array $message_attributes
     *
     * @return SqsReceivedMessage[]
     */
    protected function receiveMessageBatch($max_count = 1,
                                           $wait = null,
                                           $visibility_timeout = null,
                                           $metas = [],
                                           $message_attributes = [])
    {
        if ($max_count > 10 || $max_count < 1) {
            throw new \InvalidArgumentException("Max count for SQS message receiving is 10");
        }

        $args = [
            "QueueUrl"            => $this->getQueueUrl(),
            "MaxNumberOfMessages" => $max_count,
        ];
        if ($wait !== null && is_int($wait)) {
            $args['WaitTimeSeconds'] = $wait;
        }
        if ($visibility_timeout !== null && is_int($visibility_timeout)) {
            $args['VisibilityTimeout'] = $visibility_timeout;
        }
        if ($metas && is_array($metas)) {
            $args['AttributeNames'] = $metas;
        }
        if ($message_attributes && is_array($message_attributes)) {
            $args['MessageAttributeNames'] = $message_attributes;
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

    /**
     * @param array $expected_message_attributes
     * @param int   $wait
     * @param int   $visibility_timeout
     * @param array $metas
     *
     * @return SqsReceivedMessage
     */
    public function receiveMessageWithAttributes(array $expected_message_attributes,
                                                 $wait = null,
                                                 $visibility_timeout = null,
                                                 $metas = [])
    {
        return $this->receiveMessage($wait, $visibility_timeout, $metas, $expected_message_attributes);
    }

    public function purge()
    {
        $this->client->purgeQueue(
            [
                "QueueUrl" => $this->getQueueUrl(),
            ]
        );
    }

    public function deleteMessage(SqsReceivedMessage $msg)
    {
        $this->client->deleteMessage(
            [
                "QueueUrl"      => $this->getQueueUrl(),
                "ReceiptHandle" => $msg->getReceiptHandle(),
            ]
        );
    }

    public function deleteMessages($messages)
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

    protected function deleteMessageBatch($msgs)
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

    public function getQueueUrl()
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

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

}
