<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-09-09
 * Time: 16:36
 */

namespace Oasis\Mlib\AwsWrappers;

class DynamoDbIndex
{
    const PRIMARY_INDEX = true;
    
    const PROJECTION_TYPE_ALL       = "ALL";
    const PROJECTION_TYPE_INCLUDE   = "INCLUDE";
    const PROJECTION_TYPE_KEYS_ONLY = "KEYS_ONLY";
    
    protected $name = '';
    
    protected $hashKey;
    protected $hashKeyType         = DynamoDbItem::ATTRIBUTE_TYPE_STRING;
    protected $rangeKey            = null;
    protected $rangeKeyType        = DynamoDbItem::ATTRIBUTE_TYPE_STRING;
    protected $projectionType      = self::PROJECTION_TYPE_ALL;
    protected $projectedAttributes = [];
    
    public function __construct($hashKey,
                                $hashKeyType = DynamoDbItem::ATTRIBUTE_TYPE_STRING,
                                $rangeKey = null,
                                $rangeKeyType = DynamoDbItem::ATTRIBUTE_TYPE_STRING,
                                $projectionType = self::PROJECTION_TYPE_ALL,
                                $projectedAttributes = []
    )
    {
        $this->hashKey             = $hashKey;
        $this->hashKeyType         = $hashKeyType;
        $this->rangeKey            = $rangeKey;
        $this->rangeKeyType        = $rangeKeyType;
        $this->projectionType      = $projectionType;
        $this->projectedAttributes = $projectedAttributes;
    }
    
    public function getAttributeDefinitions($keyAsName = true)
    {
        $attrDef = [
            $this->hashKey => [
                "AttributeName" => $this->hashKey,
                "AttributeType" => $this->hashKeyType,
            ],
        ];
        if ($this->rangeKey) {
            $attrDef[$this->rangeKey] = [
                "AttributeName" => $this->rangeKey,
                "AttributeType" => $this->rangeKeyType,
            ];
        }
        if (!$keyAsName) {
            $attrDef = array_values($attrDef);
        }
        
        return $attrDef;
    }
    
    /**
     * @return string
     */
    public function getHashKey()
    {
        return $this->hashKey;
    }
    
    /**
     * @return string
     */
    public function getHashKeyType()
    {
        return $this->hashKeyType;
    }
    
    public function getKeySchema()
    {
        $keySchema = [
            [
                "AttributeName" => $this->hashKey,
                "KeyType"       => "HASH",
            ],
        ];
        if ($this->rangeKey) {
            $keySchema[] = [
                "AttributeName" => $this->rangeKey,
                "KeyType"       => "RANGE",
            ];
        }
        
        return $keySchema;
    }
    
    public function getProjection()
    {
        $projection = [
            "ProjectionType" => $this->projectionType,
        ];
        if ($this->projectionType == self::PROJECTION_TYPE_INCLUDE) {
            $projection["NonKeyAttributes"] = $this->projectedAttributes;
        }
        
        return $projection;
    }
    
    /**
     * @return string
     */
    public function getName()
    {
        if ($this->name) {
            return $this->name;
        }
        else {
            $result     = $this->hashKey . "-" . $this->rangeKey . "-index";
            $result     = preg_replace('/(?!^)([A-Z])([a-z0-9])/', '_$1$2', $result);
            $result     = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $result);
            $result     = preg_replace('/_+/', '_', $result);
            $result     = trim($result, "_");
            $result     = strtolower($result);
            $this->name = $result;
            
            return $this->name;
        }
    }
    
    /**
     * @param string $name
     *
     * @return DynamoDbIndex
     */
    public function setName($name)
    {
        $this->name = $name;
        
        return $this;
    }
    
    /**
     * @return array
     */
    public function getProjectedAttributes()
    {
        return $this->projectedAttributes;
    }
    
    /**
     * @return string
     */
    public function getProjectionType()
    {
        return $this->projectionType;
    }
    
    /**
     * @return string
     */
    public function getRangeKey()
    {
        return $this->rangeKey;
    }
    
    /**
     * @return string
     */
    public function getRangeKeyType()
    {
        return $this->rangeKeyType;
    }
    
    public function equals(DynamoDbIndex $other)
    {
        if ($this->projectionType != $other->projectionType) {
            return false;
        }
        if ($this->projectionType == self::PROJECTION_TYPE_INCLUDE
            && (array_diff_assoc($this->projectedAttributes, $other->projectedAttributes)
                || array_diff_assoc($other->projectedAttributes, $this->projectedAttributes))
        ) {
            return false;
        }
        
        if ($this->hashKey != $other->hashKey
            || $this->hashKeyType != $other->hashKeyType
        ) {
            return false;
        }
        
        if (($this->rangeKey || $other->rangeKey)
            && (
                $this->rangeKey != $other->rangeKey
                || $this->rangeKeyType != $other->rangeKeyType
            )
        ) {
            return false;
        }
        
        return true;
    }
}
