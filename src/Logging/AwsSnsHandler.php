<?php

namespace Oasis\Mlib\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Oasis\Mlib\AwsWrappers\SnsPublisher;

class AwsSnsHandler extends AbstractProcessingHandler
{
    use MLoggingHandlerTrait;
    
    protected SnsPublisher $publisher;
    protected string $subject;
    
    private bool $isBatchHandling = false;
    private string $contentBuffer = '';
    
    public function __construct(SnsPublisher $publisher,
                                string $subject,
                                Level $level = Level::Debug,
                                bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        
        $this->publisher = $publisher;
        $this->subject   = $subject;
    }
    
    public function handleBatch(array $records): void
    {
        $this->isBatchHandling = true;
        parent::handleBatch($records);
        $this->isBatchHandling = false;
        $this->publishContent();
    }
    
    public function getPublisher(): SnsPublisher
    {
        return $this->publisher;
    }
    
    public function setPublisher(SnsPublisher $publisher): void
    {
        $this->publisher = $publisher;
    }
    
    public function getSubject(): string
    {
        return $this->subject;
    }
    
    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }
    
    protected function publishContent(): void
    {
        if ($this->contentBuffer) {
            $this->publisher->publish($this->subject, $this->contentBuffer);
            $this->contentBuffer = '';
        }
    }
    
    protected function write(LogRecord $record): void
    {
        if (!$this->isBatchHandling) {
            $this->contentBuffer = $record->formatted;
            $this->publishContent();
        }
        else {
            $this->contentBuffer = $record->formatted . $this->contentBuffer;
        }
    }
}
