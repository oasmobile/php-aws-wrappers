<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-30
 * Time: 10:30
 */

namespace Oasis\Mlib\AwsWrappers;

use Aws\Sns\SnsClient;

class SnsPublisher
{
    const CHANNEL_EMAIL             = "email";
    const CHANNEL_SQS               = "sqs";
    const CHANNEL_LAMBDA            = "lambda";
    const CHANNEL_HTTP              = "http";
    const CHANNEL_HTTPS             = "https";
    const CHANNEL_SMS               = "sms";
    const CHANNEL_APNS              = "APNS";
    const CHANNEL_APNS_SANDBOX      = "APNS_SANDBOX";
    const CHANNEL_APNS_VOIP         = "APNS_VOIP";
    const CHANNEL_APNS_VOIP_SANDBOX = "APNS_VOIP_SANDBOX";
    const CHANNEL_MACOS             = "MACOS";
    const CHANNEL_MACOS_SANDBOX     = "MACOS_SANDBOX";
    const CHANNEL_GCM               = "GCM";
    const CHANNEL_ADM               = "ADM";
    const CHANNEL_BAIDU             = "BAIDU";
    const CHANNEL_MPNS              = "MPNS";
    const CHANNEL_WNS               = "WNS";
    
    /** @var SnsClient */
    protected $client;
    protected $config;
    protected $topicArn = '';
    
    public function __construct($awsConfig, $topicArn)
    {
        $dp             = new AwsConfigDataProvider($awsConfig, '2010-03-31');
        $this->client   = new SnsClient($dp->getConfig());
        $this->topicArn = $topicArn;
    }
    
    public function publishToSubscribedSQS($messageBody)
    {
        $this->publish('base64_serialize', base64_encode(serialize($messageBody)), self::CHANNEL_SQS);
    }
    
    public function publish($subject, $body, $channels = [])
    {
        $structured = [
            'default' => $body,
        ];
        
        if (!is_array($channels)) {
            $channels = [$channels];
        }
        foreach ($channels as $channel) {
            $is_supported   = true;
            $structuredBody = $body;
            switch ($channel) {
                case self::CHANNEL_SQS:
                case self::CHANNEL_EMAIL:
                case self::CHANNEL_LAMBDA:
                case self::CHANNEL_HTTP:
                case self::CHANNEL_HTTPS:
                case self::CHANNEL_SMS:
                    // don't touch body
                    break;
                case self::CHANNEL_APNS:
                case self::CHANNEL_APNS_SANDBOX:
                case self::CHANNEL_APNS_VOIP:
                case self::CHANNEL_APNS_VOIP_SANDBOX:
                case self::CHANNEL_MACOS:
                case self::CHANNEL_MACOS_SANDBOX:
                    $structuredBody = [
                        "aps" => [
                            "alert" => $body,
                        ],
                    ];
                    break;
                case self::CHANNEL_GCM:
                case self::CHANNEL_ADM:
                    $structuredBody = [
                        "data" => [
                            "message" => $body,
                        ],
                    ];
                    break;
                case self::CHANNEL_BAIDU:
                    $structuredBody = [
                        "title"       => $body,
                        "description" => $body,
                    ];
                    break;
                case self::CHANNEL_MPNS:
                    $structuredBody = htmlentities($body, ENT_XML1);
                    $structuredBody = <<<XML
<?xml version="1.0" encoding="utf-8"?><wp:Notification xmlns:wp="WPNotification"><wp:Tile><wp:Count>1</wp:Count><wp:Title>$structuredBody</wp:Title></wp:Tile></wp:Notification>
XML;
                    break;
                default:
                    mwarning("Channel [%s] is not supported by %s", $channels, static::class);
                    $is_supported = false;
                    break;
            }
            if ($is_supported) {
                $structured[$channel] = $structuredBody;
            }
        }
        $json = json_encode($structured);
        $this->client->publish(
            [
                "Subject"          => $subject,
                "Message"          => $json,
                "MessageStructure" => "json",
                "TopicArn"         => $this->topicArn,
            ]
        );
    }
    
    /**
     * @return string
     */
    public function getTopicArn()
    {
        return $this->topicArn;
    }
    
    /**
     * @param string $topicArn
     */
    public function setTopicArn($topicArn)
    {
        $this->topicArn = $topicArn;
    }
    
}
