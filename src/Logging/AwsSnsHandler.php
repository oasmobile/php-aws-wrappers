<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-30
 * Time: 10:10
 */

namespace Oasis\Mlib\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Oasis\Mlib\AwsWrappers\SnsPublisher;

class AwsSnsHandler extends AbstractProcessingHandler
{
    use MLoggingHandlerTrait;
    
    /** @var SnsPublisher */
    protected $publisher;
    protected $subject;
    
    private $isBatchHandling = false;
    private $contentBuffer   = '';
    
    public function __construct(SnsPublisher $publisher,
                                $subject,
                                $level = Logger::DEBUG,
                                $bubble = true)
    {
        parent::__construct($level, $bubble);
        
        $this->publisher = $publisher;
        $this->subject   = $subject;
    }
    
    public function handleBatch(array $records)
    {
        $this->isBatchHandling = true;
        parent::handleBatch($records);
        $this->isBatchHandling = false;
        $this->publishContent();
    }
    
    /**
     * @return SnsPublisher
     */
    public function getPublisher()
    {
        return $this->publisher;
    }
    
    /**
     * @param SnsPublisher $publisher
     */
    public function setPublisher($publisher)
    {
        $this->publisher = $publisher;
    }
    
    /**
     * @return mixed
     */
    public function getSubject()
    {
        return $this->subject;
    }
    
    /**
     * @param mixed $subject
     */
    public function setSubject($subject)
    {
        $this->subject = $subject;
    }
    
    protected function publishContent()
    {
        if ($this->contentBuffer) {
            $this->publisher->publish($this->subject, $this->contentBuffer);
            $this->contentBuffer = '';
        }
    }
    
    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param  array $record
     *
     * @return void
     */
    protected function write(array $record)
    {
        if (!$this->isBatchHandling) {
            $this->contentBuffer = $record['formatted'];
            $this->publishContent();
        }
        else {
            $this->contentBuffer = $record['formatted'] . $this->contentBuffer;
        }
    }
}
