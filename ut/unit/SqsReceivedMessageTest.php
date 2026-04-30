<?php

namespace Oasis\Mlib\AwsWrappers\Test\Unit;

use Oasis\Mlib\AwsWrappers\SqsQueue;
use Oasis\Mlib\AwsWrappers\SqsReceivedMessage;

class SqsReceivedMessageTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Helper: build a minimal valid SQS message array.
     */
    private function buildMessageArray(array $overrides = [])
    {
        $body = isset($overrides['Body']) ? $overrides['Body'] : 'hello world';
        $defaults = [
            'MessageId'     => 'msg-001',
            'ReceiptHandle' => 'receipt-handle-abc',
            'Body'          => $body,
            'MD5OfBody'     => md5($body),
        ];

        return array_merge($defaults, $overrides);
    }

    // ================================================================
    // 1. Constructor: happy path — plain text body
    // ================================================================

    public function testConstructorWithPlainTextBody()
    {
        $msg = new SqsReceivedMessage($this->buildMessageArray());

        $this->assertSame('msg-001', $msg->getMessageId());
        $this->assertSame('receipt-handle-abc', $msg->getReceiptHandle());
        $this->assertSame('hello world', $msg->getBody());
        $this->assertSame('hello world', $msg->getOriginalBody());
    }

    // ================================================================
    // 2. MD5 body validation
    // ================================================================

    public function testMd5ValidationPassesWithCorrectHash()
    {
        // Should not throw
        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'Body'      => 'test body',
            'MD5OfBody' => md5('test body'),
        ]));

        $this->assertSame('test body', $msg->getBody());
    }

    public function testMd5ValidationThrowsOnMismatch()
    {
        $this->setExpectedException(
            \UnexpectedValueException::class,
            "Body md5 doesn't match"
        );

        new SqsReceivedMessage($this->buildMessageArray([
            'Body'      => 'test body',
            'MD5OfBody' => 'wrong-md5-hash',
        ]));
    }

    // ================================================================
    // 3. Deserialization: base64_serialize via message attribute
    // ================================================================

    public function testDeserializationViaBase64SerializeAttribute()
    {
        $originalData = ['key' => 'value', 'num' => 42];
        $serialized   = base64_encode(serialize($originalData));

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'Body'              => $serialized,
            'MD5OfBody'         => md5($serialized),
            'MessageAttributes' => [
                SqsQueue::SERIALIZATION_FLAG => [
                    'DataType'    => 'String',
                    'StringValue' => 'base64_serialize',
                ],
            ],
        ]));

        $this->assertEquals($originalData, $msg->getBody());
        $this->assertSame($serialized, $msg->getOriginalBody());
    }

    // ================================================================
    // 4. Deserialization: JSON body with Message field (SNS envelope)
    // ================================================================

    public function testDeserializationJsonWithMessageField()
    {
        $jsonBody = json_encode(['Message' => 'inner message content']);

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'Body'      => $jsonBody,
            'MD5OfBody' => md5($jsonBody),
        ]));

        $this->assertSame('inner message content', $msg->getBody());
    }

    // ================================================================
    // 5. Deserialization: JSON body with Subject=base64_serialize (SNS published serialization)
    // ================================================================

    public function testDeserializationJsonWithBase64SerializeSubject()
    {
        $originalData = ['foo' => 'bar'];
        $serialized   = base64_encode(serialize($originalData));
        $jsonBody     = json_encode([
            'Subject' => 'base64_serialize',
            'Message' => $serialized,
        ]);

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'Body'      => $jsonBody,
            'MD5OfBody' => md5($jsonBody),
        ]));

        $this->assertEquals($originalData, $msg->getBody());
    }

    // ================================================================
    // 6. Deserialization: JSON body without Message field → falls back to original body
    // ================================================================

    public function testDeserializationJsonWithoutMessageFieldFallsBackToOriginalBody()
    {
        $jsonBody = json_encode(['data' => 'no Message key']);

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'Body'      => $jsonBody,
            'MD5OfBody' => md5($jsonBody),
        ]));

        $this->assertSame($jsonBody, $msg->getBody());
    }

    // ================================================================
    // 7. Deserialization: non-JSON body → falls back to original body
    // ================================================================

    public function testDeserializationNonJsonFallsBackToOriginalBody()
    {
        $plainBody = 'this is not json at all';

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'Body'      => $plainBody,
            'MD5OfBody' => md5($plainBody),
        ]));

        $this->assertSame($plainBody, $msg->getBody());
    }

    // ================================================================
    // 8. getAttribute: String type
    // ================================================================

    public function testGetAttributeReturnsStringValue()
    {
        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes' => [
                'user' => [
                    'DataType'    => 'String',
                    'StringValue' => 'minhao',
                ],
            ],
        ]));

        $this->assertSame('minhao', $msg->getAttribute('user'));
    }

    // ================================================================
    // 9. getAttribute: Number type
    // ================================================================

    public function testGetAttributeReturnsNumberValue()
    {
        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes' => [
                'count' => [
                    'DataType'    => 'Number',
                    'StringValue' => '42',
                ],
            ],
        ]));

        $this->assertSame('42', $msg->getAttribute('count'));
    }

    // ================================================================
    // 10. getAttribute: Binary type
    // ================================================================

    public function testGetAttributeReturnsBinaryValue()
    {
        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes' => [
                'data' => [
                    'DataType'    => 'Binary',
                    'BinaryValue' => 'binary-content',
                ],
            ],
        ]));

        $this->assertSame('binary-content', $msg->getAttribute('data'));
    }

    // ================================================================
    // 11. getAttribute: unknown data type → throws
    // ================================================================

    public function testGetAttributeThrowsOnUnknownDataType()
    {
        $this->setExpectedException(
            \UnexpectedValueException::class,
            'Unknown data type'
        );

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes' => [
                'weird' => [
                    'DataType'    => 'CustomType',
                    'StringValue' => 'val',
                ],
            ],
        ]));

        $msg->getAttribute('weird');
    }

    // ================================================================
    // 12. getAttribute: non-existent key → returns null
    // ================================================================

    public function testGetAttributeReturnsNullForNonExistentKey()
    {
        $msg = new SqsReceivedMessage($this->buildMessageArray());

        $this->assertNull($msg->getAttribute('nonexistent'));
    }

    // ================================================================
    // 13. getOriginalAttributes: defaults to empty array
    // ================================================================

    public function testGetOriginalAttributesDefaultsToEmptyArray()
    {
        $msg = new SqsReceivedMessage($this->buildMessageArray());

        $this->assertSame([], $msg->getOriginalAttributes());
    }

    public function testGetOriginalAttributesReturnsProvidedAttributes()
    {
        $attrs = [
            'user' => [
                'DataType'    => 'String',
                'StringValue' => 'test',
            ],
        ];

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes' => $attrs,
        ]));

        $this->assertSame($attrs, $msg->getOriginalAttributes());
    }

    // ================================================================
    // 14. MD5 of attributes validation
    // ================================================================

    public function testMd5OfAttributesValidationPassesWithCorrectHash()
    {
        // Build the expected MD5 manually using the same algorithm as SqsReceivedMessage::validate()
        $attributes = [
            'user' => [
                'DataType'    => 'String',
                'StringValue' => 'minhao',
            ],
        ];

        $encoded_data = '';
        ksort($attributes);
        foreach ($attributes as $k => $v) {
            $klen = intval(strlen($k));
            $tlen = intval(strlen($v['DataType']));
            $transport_type = 1; // String type
            $value = $v['StringValue'];
            $vlen = intval(strlen($value));

            $encoded_data .= pack('N', $klen);
            $encoded_data .= $k;
            $encoded_data .= pack('N', $tlen);
            $encoded_data .= $v['DataType'];
            $encoded_data .= pack('C', $transport_type);
            $encoded_data .= pack('N', $vlen);
            $encoded_data .= $value;
        }
        $expectedMd5 = md5($encoded_data);

        // Should not throw
        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes'       => $attributes,
            'MD5OfMessageAttributes'  => $expectedMd5,
        ]));

        $this->assertSame('minhao', $msg->getAttribute('user'));
    }

    public function testMd5OfAttributesValidationThrowsOnMismatch()
    {
        $this->setExpectedException(
            \UnexpectedValueException::class,
            "Attribute md5 doesn't match"
        );

        new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes'      => [
                'user' => [
                    'DataType'    => 'String',
                    'StringValue' => 'minhao',
                ],
            ],
            'MD5OfMessageAttributes' => 'wrong-md5-hash',
        ]));
    }

    // ================================================================
    // 15. MD5 of attributes: Binary transport type
    // ================================================================

    public function testMd5OfAttributesValidationWithBinaryType()
    {
        $attributes = [
            'data' => [
                'DataType'    => 'Binary',
                'BinaryValue' => 'raw-bytes',
            ],
        ];

        $encoded_data = '';
        ksort($attributes);
        foreach ($attributes as $k => $v) {
            $klen = intval(strlen($k));
            $tlen = intval(strlen($v['DataType']));
            $transport_type = 2; // Binary type
            $value = $v['BinaryValue'];
            $vlen = intval(strlen($value));

            $encoded_data .= pack('N', $klen);
            $encoded_data .= $k;
            $encoded_data .= pack('N', $tlen);
            $encoded_data .= $v['DataType'];
            $encoded_data .= pack('C', $transport_type);
            $encoded_data .= pack('N', $vlen);
            $encoded_data .= $value;
        }
        $expectedMd5 = md5($encoded_data);

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes'      => $attributes,
            'MD5OfMessageAttributes' => $expectedMd5,
        ]));

        $this->assertSame('raw-bytes', $msg->getAttribute('data'));
    }

    // ================================================================
    // 16. MD5 of attributes: multiple attributes sorted by key
    // ================================================================

    public function testMd5OfAttributesValidationWithMultipleAttributesSorted()
    {
        $attributes = [
            'zebra' => [
                'DataType'    => 'String',
                'StringValue' => 'last',
            ],
            'alpha' => [
                'DataType'    => 'String',
                'StringValue' => 'first',
            ],
        ];

        // Build MD5 with sorted keys
        $encoded_data = '';
        $sorted = $attributes;
        ksort($sorted);
        foreach ($sorted as $k => $v) {
            $klen = intval(strlen($k));
            $tlen = intval(strlen($v['DataType']));
            $transport_type = 1;
            $value = $v['StringValue'];
            $vlen = intval(strlen($value));

            $encoded_data .= pack('N', $klen);
            $encoded_data .= $k;
            $encoded_data .= pack('N', $tlen);
            $encoded_data .= $v['DataType'];
            $encoded_data .= pack('C', $transport_type);
            $encoded_data .= pack('N', $vlen);
            $encoded_data .= $value;
        }
        $expectedMd5 = md5($encoded_data);

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes'      => $attributes,
            'MD5OfMessageAttributes' => $expectedMd5,
        ]));

        $this->assertSame('first', $msg->getAttribute('alpha'));
        $this->assertSame('last', $msg->getAttribute('zebra'));
    }

    // ================================================================
    // 17. MD5 of attributes: unknown data type in validate → throws
    // ================================================================

    public function testMd5OfAttributesValidationThrowsOnUnknownDataType()
    {
        $this->setExpectedException(
            \UnexpectedValueException::class,
            'Unknown data type'
        );

        new SqsReceivedMessage($this->buildMessageArray([
            'MessageAttributes'      => [
                'weird' => [
                    'DataType'    => 'CustomType',
                    'StringValue' => 'val',
                ],
            ],
            'MD5OfMessageAttributes' => 'some-md5',
        ]));
    }

    // ================================================================
    // 18. Constructor: missing mandatory fields
    // ================================================================

    public function testConstructorThrowsWhenReceiptHandleMissing()
    {
        $this->setExpectedException(\Exception::class);

        new SqsReceivedMessage([
            'MessageId' => 'msg-001',
            'Body'      => 'hello',
            'MD5OfBody' => md5('hello'),
        ]);
    }

    public function testConstructorThrowsWhenBodyMissing()
    {
        $this->setExpectedException(\Exception::class);

        new SqsReceivedMessage([
            'MessageId'     => 'msg-001',
            'ReceiptHandle' => 'handle',
            'MD5OfBody'     => 'hash',
        ]);
    }

    // ================================================================
    // 19. Unicode body round-trip
    // ================================================================

    public function testUnicodeBodyRoundTrip()
    {
        $unicodeBody = '你好世界 🌍';

        $msg = new SqsReceivedMessage($this->buildMessageArray([
            'Body'      => $unicodeBody,
            'MD5OfBody' => md5($unicodeBody),
        ]));

        $this->assertSame($unicodeBody, $msg->getBody());
    }

    // ================================================================
    // 20. Implements QueueMessageInterface
    // ================================================================

    public function testImplementsQueueMessageInterface()
    {
        $msg = new SqsReceivedMessage($this->buildMessageArray());

        $this->assertInstanceOf(
            'Oasis\Mlib\AwsWrappers\Contracts\QueueMessageInterface',
            $msg
        );
    }
}
