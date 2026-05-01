<?php

namespace Oasis\Mlib\AwsWrappers;

class DynamoDbIndex
{
    const PRIMARY_INDEX = true;
    
    const PROJECTION_TYPE_ALL       = "ALL";
    const PROJECTION_TYPE_INCLUDE   = "INCLUDE";
    const PROJECTION_TYPE_KEYS_ONLY = "KEYS_ONLY";
    
    protected string $name = '';
    
    public function __construct(
        protected string $hashKey,
        protected string $hashKeyType = DynamoDbItem::ATTRIBUTE_TYPE_STRING,
        protected ?string $rangeKey = null,
        protected ?string $rangeKeyType = DynamoDbItem::ATTRIBUTE_TYPE_STRING,
        protected string $projectionType = self::PROJECTION_TYPE_ALL,
        protected array $projectedAttributes = [],
    ) {
    }
    
    public function getAttributeDefinitions(bool $keyAsName = true): array
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
    
    public function getHashKey(): string
    {
        return $this->hashKey;
    }
    
    public function getHashKeyType(): string
    {
        return $this->hashKeyType;
    }
    
    public function getKeySchema(): array
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
    
    public function getProjection(): array
    {
        $projection = [
            "ProjectionType" => $this->projectionType,
        ];
        if ($this->projectionType == self::PROJECTION_TYPE_INCLUDE) {
            $projection["NonKeyAttributes"] = $this->projectedAttributes;
        }
        
        return $projection;
    }
    
    public function getName(): string
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
    
    public function setName(string $name): static
    {
        $this->name = $name;
        
        return $this;
    }
    
    public function getProjectedAttributes(): array
    {
        return $this->projectedAttributes;
    }
    
    public function getProjectionType(): string
    {
        return $this->projectionType;
    }
    
    public function getRangeKey(): ?string
    {
        return $this->rangeKey;
    }
    
    public function getRangeKeyType(): ?string
    {
        return $this->rangeKeyType;
    }
    
    public function equals(DynamoDbIndex $other): bool
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
