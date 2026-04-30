<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Monolog\Logger;
use Oasis\Mlib\AwsWrappers\SnsPublisher;
use Oasis\Mlib\Logging\AwsSnsHandler;

/**
 * Stub SnsPublisher that records publish() calls without AWS interaction.
 */
class StubSnsPublisher extends SnsPublisher
{
    /** @var array Recorded publish calls: [['subject' => ..., 'body' => ..., 'channels' => ...], ...] */
    public $publishCalls = [];

    public function __construct()
    {
        // Skip parent constructor — no AWS SDK initialization
    }

    public function publish($subject, $body, $channels = [])
    {
        $this->publishCalls[] = [
            'subject'  => $subject,
            'body'     => $body,
            'channels' => $channels,
        ];
    }
}

class AwsSnsHandlerTest extends \PHPUnit_Framework_TestCase
{
    /** @var StubSnsPublisher */
    private $stubPublisher;

    /** @var AwsSnsHandler */
    private $handler;

    /** @var Logger */
    private $logger;

    protected function setUp()
    {
        $this->stubPublisher = new StubSnsPublisher();
        $this->handler       = new AwsSnsHandler($this->stubPublisher, 'Test Subject');
        $this->logger        = new Logger('test');
        $this->logger->pushHandler($this->handler);
    }

    // ================================================================
    // write: single log record
    // ================================================================

    public function testWriteSingleLogRecord()
    {
        $this->logger->debug('Hello World');

        $this->assertCount(1, $this->stubPublisher->publishCalls);
        $this->assertSame('Test Subject', $this->stubPublisher->publishCalls[0]['subject']);
        $this->assertContains('Hello World', $this->stubPublisher->publishCalls[0]['body']);
    }

    public function testWritePublishesFormattedContent()
    {
        $this->logger->error('Something went wrong');

        $this->assertCount(1, $this->stubPublisher->publishCalls);
        $body = $this->stubPublisher->publishCalls[0]['body'];

        // Monolog's default LineFormatter includes the level name
        $this->assertContains('ERROR', $body);
        $this->assertContains('Something went wrong', $body);
    }

    // ================================================================
    // write: multiple individual records (non-batch)
    // ================================================================

    public function testWriteMultipleIndividualRecords()
    {
        $this->logger->debug('First');
        $this->logger->info('Second');

        // Each individual write triggers a separate publish
        $this->assertCount(2, $this->stubPublisher->publishCalls);
    }

    // ================================================================
    // handleBatch: batches records into single publish
    // ================================================================

    public function testHandleBatchPublishesOnce()
    {
        $records = [
            $this->createLogRecord('First message', Logger::DEBUG),
            $this->createLogRecord('Second message', Logger::INFO),
            $this->createLogRecord('Third message', Logger::WARNING),
        ];

        $this->handler->handleBatch($records);

        // Batch handling should result in a single publish call
        $this->assertCount(1, $this->stubPublisher->publishCalls);
    }

    public function testHandleBatchContainsAllMessages()
    {
        $records = [
            $this->createLogRecord('Alpha', Logger::DEBUG),
            $this->createLogRecord('Beta', Logger::INFO),
        ];

        $this->handler->handleBatch($records);

        $body = $this->stubPublisher->publishCalls[0]['body'];
        $this->assertContains('Alpha', $body);
        $this->assertContains('Beta', $body);
    }

    public function testHandleBatchUsesSubject()
    {
        $records = [
            $this->createLogRecord('Test', Logger::DEBUG),
        ];

        $this->handler->handleBatch($records);

        $this->assertSame('Test Subject', $this->stubPublisher->publishCalls[0]['subject']);
    }

    // ================================================================
    // handleBatch: empty batch does not publish
    // ================================================================

    public function testHandleBatchEmptyDoesNotPublish()
    {
        $this->handler->handleBatch([]);

        $this->assertCount(0, $this->stubPublisher->publishCalls);
    }

    // ================================================================
    // handleBatch: records below handler level are filtered
    // ================================================================

    public function testHandleBatchFiltersRecordsBelowLevel()
    {
        // Create handler with WARNING level
        $handler = new AwsSnsHandler($this->stubPublisher, 'Subject', Logger::WARNING);
        $logger  = new Logger('test');
        $logger->pushHandler($handler);

        $records = [
            $this->createLogRecord('Debug msg', Logger::DEBUG),
            $this->createLogRecord('Warning msg', Logger::WARNING),
        ];

        $handler->handleBatch($records);

        // Only WARNING and above should be included
        $body = $this->stubPublisher->publishCalls[0]['body'];
        $this->assertNotContains('Debug msg', $body);
        $this->assertContains('Warning msg', $body);
    }

    // ================================================================
    // publisher getter/setter
    // ================================================================

    public function testGetPublisher()
    {
        $this->assertSame($this->stubPublisher, $this->handler->getPublisher());
    }

    public function testSetPublisher()
    {
        $newPublisher = new StubSnsPublisher();
        $this->handler->setPublisher($newPublisher);

        $this->assertSame($newPublisher, $this->handler->getPublisher());
    }

    public function testSetPublisherAffectsSubsequentWrites()
    {
        $newPublisher = new StubSnsPublisher();
        $this->handler->setPublisher($newPublisher);

        $this->logger->debug('After swap');

        $this->assertCount(0, $this->stubPublisher->publishCalls);
        $this->assertCount(1, $newPublisher->publishCalls);
    }

    // ================================================================
    // subject getter/setter
    // ================================================================

    public function testGetSubject()
    {
        $this->assertSame('Test Subject', $this->handler->getSubject());
    }

    public function testSetSubject()
    {
        $this->handler->setSubject('New Subject');

        $this->assertSame('New Subject', $this->handler->getSubject());
    }

    public function testSetSubjectAffectsSubsequentWrites()
    {
        $this->handler->setSubject('Updated Subject');

        $this->logger->debug('Test');

        $this->assertSame('Updated Subject', $this->stubPublisher->publishCalls[0]['subject']);
    }

    // ================================================================
    // constructor: level filtering
    // ================================================================

    public function testConstructorLevelFiltering()
    {
        $publisher = new StubSnsPublisher();
        $handler   = new AwsSnsHandler($publisher, 'Subject', Logger::ERROR);
        $logger    = new Logger('test');
        $logger->pushHandler($handler);

        $logger->debug('Should not publish');
        $logger->info('Should not publish');
        $logger->warning('Should not publish');
        $logger->error('Should publish');

        $this->assertCount(1, $publisher->publishCalls);
        $this->assertContains('Should publish', $publisher->publishCalls[0]['body']);
    }

    // ================================================================
    // constructor: bubble parameter
    // ================================================================

    public function testConstructorBubbleFalseStopsPropagation()
    {
        $publisher1 = new StubSnsPublisher();
        $publisher2 = new StubSnsPublisher();

        $handler1 = new AwsSnsHandler($publisher1, 'H1', Logger::DEBUG, false);
        $handler2 = new AwsSnsHandler($publisher2, 'H2', Logger::DEBUG, true);

        $logger = new Logger('test');
        // handler1 is pushed last, so it processes first
        $logger->pushHandler($handler2);
        $logger->pushHandler($handler1);

        $logger->debug('Test');

        // handler1 (bubble=false) should process but stop propagation
        $this->assertCount(1, $publisher1->publishCalls);
        $this->assertCount(0, $publisher2->publishCalls);
    }

    // ================================================================
    // write: Unicode content
    // ================================================================

    public function testWriteWithUnicodeContent()
    {
        $this->logger->debug('日志消息 🔥');

        $this->assertCount(1, $this->stubPublisher->publishCalls);
        $this->assertContains('日志消息 🔥', $this->stubPublisher->publishCalls[0]['body']);
    }

    // ================================================================
    // Helper: create a log record compatible with Monolog
    // ================================================================

    /**
     * @param string $message
     * @param int    $level
     *
     * @return array
     */
    private function createLogRecord($message, $level)
    {
        return [
            'message'    => $message,
            'context'    => [],
            'level'      => $level,
            'level_name' => Logger::getLevelName($level),
            'channel'    => 'test',
            'datetime'   => new \DateTime(),
            'extra'      => [],
        ];
    }
}
