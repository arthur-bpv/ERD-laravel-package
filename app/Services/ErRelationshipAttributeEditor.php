<?php

namespace App\Services;

/** Applies validated edits to a relationship's attributes. */
final class ErRelationshipAttributeEditor
{
    private const TYPES = ['bigint', 'int', 'decimal', 'date', 'datetime', 'boolean', 'text', 'varchar'];

    public function add(array $attributes, string $name, string $type, string $id): ?array
    {
        foreach ($attributes as $attribute) {
            if (mb_strtolower($attribute['name']) === mb_strtolower($name)) {
                return null;
            }
        }

        $attributes[] = [
            'id' => $id,
            'name' => $name,
            'type' => in_array($type, self::TYPES, true) ? $type : 'varchar',
            'key' => '',
        ];

        return $attributes;
    }

    public function rename(array $attributes, string $attributeId, string $name): ?array
    {
        foreach ($attributes as $attribute) {
            if ($attribute['id'] !== $attributeId && mb_strtolower($attribute['name']) === mb_strtolower($name)) {
                return null;
            }
        }

        foreach ($attributes as &$attribute) {
            if ($attribute['id'] === $attributeId) {
                $attribute['name'] = $name;
                break;
            }
        }
        unset($attribute);

        return $attributes;
    }

    public function remove(array $attributes, string $attributeId): array
    {
        return array_values(array_filter(
            $attributes,
            fn (array $attribute): bool => $attribute['id'] !== $attributeId,
        ));
    }
}
