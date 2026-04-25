<?php

declare(strict_types=1);

namespace App\Models\Core;

use ReflectionClass;
use ReflectionProperty;

/**
 * Trait for entities that hold bytea fields. Overrides jsonSerialize to
 * hex-encode any property listed in `byteaProperties()` (with `0x` prefix).
 *
 * Without this, raw bytes from bytea columns break json_encode with
 * "Malformed UTF-8 characters" since they are not valid UTF-8 strings.
 */
trait HasByteaSerialization
{
    /**
     * @return list<string> property names that store raw bytea bytes
     */
    abstract protected static function byteaProperties(): array;

    public function jsonSerialize(): array
    {
        $reflection = new ReflectionClass($this);
        $byteaSet = array_flip(static::byteaProperties());
        $out = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (!empty($prop->getAttributes(\Zephyrus\Data\JsonIgnore::class))) {
                continue;
            }
            $value = $prop->getValue($this);
            if (isset($byteaSet[$name]) && is_string($value) && $value !== '') {
                $out[$name] = '0x' . bin2hex($value);
            } else {
                $out[$name] = $value;
            }
        }
        return $out;
    }
}
