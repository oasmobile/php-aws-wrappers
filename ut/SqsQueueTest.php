<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2017-01-05
 * Time: 16:29
 */

namespace Oasis\Mlib\AwsWrappers\Test;

use Oasis\Mlib\AwsWrappers\SqsQueue;

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
                    SqsQueue::VISIBILITY_TIMEOUT => 5,
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
}
