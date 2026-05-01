<?php

namespace Oasis\Mlib\AwsWrappers;

use Oasis\Mlib\Utils\Exceptions\InvalidDataTypeException;

class DynamoDbItem implements \ArrayAccess
{
    const ATTRIBUTE_TYPE_STRING = 'S';
    const ATTRIBUTE_TYPE_BINARY = 'B';
    const ATTRIBUTE_TYPE_NUMBER = 'N';
    const ATTRIBUTE_TYPE_LIST   = 'L';
    const ATTRIBUTE_TYPE_MAP    = 'M';
    const ATTRIBUTE_TYPE_BOOL   = 'BOOL';
    const ATTRIBUTE_TYPE_NULL   = 'NULL';
    
    protected array $data = [];
    
    public static function createFromTypedArray(array $typed_value): static
    {
        $ret       = new static;
        $ret->data = $typed_value;
        
        return $ret;
    }
    
    public static function createFromArray(array $normal_value, array $known_types = []): static
    {
        $ret = new static;
        foreach ($normal_value as $k => &$v) {
            $ret->data[$k] = static::toTypedValue($v, isset($known_types[$k]) ? $known_types[$k] : null);
        }
        
        return $ret;
    }
    
    protected static function toUntypedValue(mixed &$v): mixed
    {
        if (!is_array($v) || count($v) != 1) {
            throw new InvalidDataTypeException("Value used is not typed value, value = " . json_encode($v));
        }
        $value = reset($v);
        $type  = key($v);
        
        return match ($type) {
            self::ATTRIBUTE_TYPE_STRING => strval($value),
            self::ATTRIBUTE_TYPE_BINARY => base64_decode($value),
            self::ATTRIBUTE_TYPE_BOOL   => boolval($value),
            self::ATTRIBUTE_TYPE_NULL   => null,
            self::ATTRIBUTE_TYPE_NUMBER => (intval($value) == $value) ? intval($value) : floatval($value),
            self::ATTRIBUTE_TYPE_LIST,
            self::ATTRIBUTE_TYPE_MAP    => (function () use ($value, &$v): array {
                if (!is_array($value)) {
                    throw new InvalidDataTypeException("The value is expected to be an array! \$v = " . json_encode($v));
                }
                $ret = [];
                foreach ($value as $k => &$vv) {
                    $ret[$k] = static::toUntypedValue($vv);
                }
                return $ret;
            })(),
            default => throw new InvalidDataTypeException("Type $type is not recognized!"),
        };
    }
    
    protected static function toTypedValue(mixed &$v, ?string $type = null): array
    {
        if (!$type) {
            $type = static::determineAttributeType($v);
        }
        
        switch ($type) {
            case self::ATTRIBUTE_TYPE_STRING: {
                if (strlen(strval($v))) {
                    return [$type => strval($v)];
                }
                else {
                    return [self::ATTRIBUTE_TYPE_NULL => true];
                }
            }
            case self::ATTRIBUTE_TYPE_BINARY: {
                if (!$v) {
                    return [self::ATTRIBUTE_TYPE_NULL => true];
                }
                else {
                    return [$type => base64_encode($v)];
                }
            }
            case self::ATTRIBUTE_TYPE_BOOL:
                return [$type => boolval($v)];
            case self::ATTRIBUTE_TYPE_NULL:
                return [$type => true];
            case self::ATTRIBUTE_TYPE_NUMBER: {
                if (!is_numeric($v)) {
                    return [$type => "0"];
                }
                elseif (intval($v) == $v) {
                    return [$type => strval($v)];
                }
                else {
                    return [$type => strval(floatval($v))];
                }
            }
            case self::ATTRIBUTE_TYPE_LIST:
            case self::ATTRIBUTE_TYPE_MAP: {
                $children = [];
                foreach ($v as $k => &$vv) {
                    $children[$k] = static::toTypedValue($vv);
                }
                
                return [$type => $children];
            }
            default: {
                $const_key = __CLASS__ . "::ATTRIBUTE_TYPE_" . strtoupper($type);
                if (defined($const_key)) {
                    $type = constant($const_key);
                    
                    return static::toTypedValue($v, $type);
                }
                else {
                    throw new \RuntimeException("Unknown type for dynamodb item, value = $v");
                }
            }
        }
    }
    
    protected static function determineAttributeType(mixed &$v): string
    {
        if (is_string($v)) {
            return self::ATTRIBUTE_TYPE_STRING;
        }
        if (is_int($v) || is_float($v)) {
            return self::ATTRIBUTE_TYPE_NUMBER;
        }
        elseif (is_bool($v)) {
            return self::ATTRIBUTE_TYPE_BOOL;
        }
        elseif (is_null($v)) {
            return self::ATTRIBUTE_TYPE_NULL;
        }
        elseif (is_array($v)) {
            $idx = 0;
            /** @noinspection PhpUnusedLocalVariableInspection */
            foreach ($v as $k => &$vv) {
                if ($k !== $idx) {
                    return self::ATTRIBUTE_TYPE_MAP;
                }
                $idx++;
            }
            
            return self::ATTRIBUTE_TYPE_LIST;
        }
        else {
            throw new InvalidDataTypeException("Cannot determine type of attribute: " . print_r($v, true));
        }
    }
    
    public function toArray(): array
    {
        $ret = [];
        foreach ($this->data as $k => &$v) {
            $ret[$k] = static::toUntypedValue($v);
        }
        
        return $ret;
    }
    
    public function getData(): array
    {
        return $this->data;
    }
    
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }
    
    public function offsetGet(mixed $offset): mixed
    {
        if (!array_key_exists($offset, $this->data)) {
            throw new \OutOfBoundsException("Attribute $offset does not exist in DynamoDbItem!");
        }
        
        return static::toUntypedValue($this->data[$offset]);
    }
    
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = static::toTypedValue($value);
    }
    
    public function offsetUnset(mixed $offset): void
    {
        if (!array_key_exists($offset, $this->data)) {
            throw new \OutOfBoundsException("Attribute $offset does not exist in DynamoDbItem!");
        }
        
        unset($this->data[$offset]);
    }
}
