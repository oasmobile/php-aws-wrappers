<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-01-05
 * Time: 16:29
 */

namespace Oasis\Mlib\AwsWrappers\Test;

use Oasis\Mlib\AwsWrappers\SqsQueue;
use Oasis\Mlib\AwsWrappers\SqsReceivedMessage;
use Oasis\Mlib\Utils\ArrayDataProvider;
use Oasis\Mlib\Utils\DataProviderInterface;

class SqsQueueTest extends \PHPUnit_Framework_TestCase
{
    const DEBUG = 0;
    
    protected static $queueName;
    
    /** @var  SqsQueue */
    protected $sqs;
    
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        
        self::$queueName = UTConfig::$sqsConfig['prefix'] . time();
        
        $sqs = new SqsQueue(UTConfig::$awsConfig, self::$queueName);
        if (!$sqs->exists()) {
            $sqs->createQueue(
                [
                    SqsQueue::VISIBILITY_TIMEOUT => 20,
                    SqsQueue::DELAY_SECONDS      => 0,
                ]
            );
        }
        else {
            $sqs->purge();
        }
    }
    
    public static function tearDownAfterClass()
    {
        $sqs = new SqsQueue(UTConfig::$awsConfig, self::$queueName);
        $sqs->deleteQueue();
        
        parent::tearDownAfterClass();
    }
    
    protected function setUp()
    {
        parent::setUp();
        
        $this->sqs = new SqsQueue(UTConfig::$awsConfig, self::$queueName);
    }
    
    public function testBatchSend()
    {
        $batch    = 175;
        $payrolls = [];
        for ($i = 0; $i < $batch; ++$i) {
            $payrolls[] = json_encode(
                [
                    "id"  => $i,
                    "val" => md5($i),
                ]
            );
        }
        
        $this->sqs->sendMessages($payrolls);
        
        $received = 0;
        while ($msgs = $this->sqs->receiveMessages($batch, 1)) {
            $received += count($msgs);
            $this->sqs->deleteMessages($msgs);
        }
        
        $this->assertEquals($batch, $received);
    }
    
    public function testAttributedMessage()
    {
        $this->sqs->sendMessage('hello', 0, ['user' => 'minhao']);
        $msg = $this->sqs->receiveMessage(5, null, [], ['user']);
        $this->sqs->deleteMessage($msg);
        $this->assertTrue($msg instanceof SqsReceivedMessage);
        $this->assertEquals('minhao', $msg->getAttribute('user'));
    }
    
    public function testAutoSerialization()
    {
        $obj = new ArrayDataProvider(['a' => 9]);
        $this->sqs->sendMessage($obj, 0, ['b' => 'xyz']);
        $msg = $this->sqs->receiveMessage(5, null, [], ['b']);
        $this->sqs->deleteMessage($msg);
        $this->assertTrue($msg instanceof SqsReceivedMessage);
        /** @var ArrayDataProvider $body */
        $body = $msg->getBody();
        $this->assertTrue($body instanceof ArrayDataProvider);
        $this->assertEquals(9, $body->getMandatory('a', DataProviderInterface::INT_TYPE));
    }
    
    public function testSNSPublishedSerialization()
    {
        $obj                 = new ArrayDataProvider(['a' => 9]);
        $mockedSerialization = base64_encode(serialize($obj));
        $structrued          = [
            'Subject' => 'base64_serialize',
            'Message' => $mockedSerialization,
        ];
        $this->sqs->sendMessage(\GuzzleHttp\json_encode($structrued));
        $msg = $this->sqs->receiveMessage(5);
        $this->sqs->deleteMessage($msg);
        $this->assertTrue($msg instanceof SqsReceivedMessage);
        /** @var ArrayDataProvider $body */
        $body = $msg->getBody();
        $this->assertTrue($body instanceof ArrayDataProvider);
        $this->assertEquals(9, $body->getMandatory('a', DataProviderInterface::INT_TYPE));
    
    }
    
    public function testFailureMessages()
    {
        $msg = "\x8";
        $ret = $this->sqs->sendMessage($msg);
        $this->assertFalse($ret);
        $failed = $this->sqs->getSendFailureMessages();
        $this->assertContains('Invalid binary character', $failed[0]);
        $ret = $this->sqs->sendMessages(
            [
                "x" => "\x8",
                "y" => "\xA",
            ]
        );
        $this->assertEquals(1, count($ret));
        $failed = $this->sqs->getSendFailureMessages();
        $this->assertEquals(1, count($failed));
        $this->assertContains('Invalid binary character', $failed["x"]);
    }
}
