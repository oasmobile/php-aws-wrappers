<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-30
 * Time: 10:30
 */

namespace Oasis\Mlib\AwsWrappers;

use Aws\Sns\SnsClient;
use Oasis\Mlib\Utils\ArrayDataProvider;

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
    protected $topic_arn = '';

    public function __construct($aws_config, $topic_arn)
    {
        $dp              = new ArrayDataProvider($aws_config);
        $this->config    = [
            'version' => "2010-03-31",
            "profile" => $dp->getMandatory('profile'),
            "region"  => $dp->getMandatory('region'),
        ];
        $this->client    = new SnsClient($this->config);
        $this->topic_arn = $topic_arn;
    }

    public function publish($subject, $body, $channels = [])
    {
        $structured = [
            'default' => $body,
        ];

        foreach ($channels as $channel) {
            $is_supported = true;
            switch ($channel) {
                case self::CHANNEL_EMAIL:
                case self::CHANNEL_SQS:
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
                    $body = [
                        "aps" => [
                            "alert" => $body,
                        ],
                    ];
                    break;
                case self::CHANNEL_GCM:
                case self::CHANNEL_ADM:
                    $body = [
                        "data" => [
                            "message" => $body,
                        ],
                    ];
                    break;
                case self::CHANNEL_BAIDU:
                    $body = [
                        "title"       => $body,
                        "description" => $body,
                    ];
                    break;
                case self::CHANNEL_MPNS:
                    $body = htmlentities($body, ENT_XML1);
                    $body = <<<XML
<?xml version="1.0" encoding="utf-8"?><wp:Notification xmlns:wp="WPNotification"><wp:Tile><wp:Count>ENTER COUNT</wp:Count><wp:Title>$body</wp:Title></wp:Tile></wp:Notification>
XML;
                    break;
                default:
                    mwarning("Channel [%s] is not supported by %s", $channels, static::class);
                    $is_supported = false;
                    break;
            }
            if ($is_supported) {
                $structured[$channel] = $body;
            }
        }
        $json   = json_encode($structured);
        $this->client->publish(
            [
                "Subject"          => $subject,
                "Message"          => $json,
                "MessageStructure" => "json",
                "TopicArn"         => $this->topic_arn,
            ]
        );
    }

    /**
     * @return string
     */
    public function getTopicArn()
    {
        return $this->topic_arn;
    }

    /**
     * @param string $topic_arn
     */
    public function setTopicArn($topic_arn)
    {
        $this->topic_arn = $topic_arn;
    }
    
}
