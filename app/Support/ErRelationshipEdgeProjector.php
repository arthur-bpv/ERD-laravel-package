<?php

namespace App\Support;

/** Projects ER relationships and their attributes into WireFlow edges. */
final class ErRelationshipEdgeProjector
{
    private const RELATION_COLOR = '#64748b';

    private const MARKER_OFFSET = 0;

    public function __construct(private array $entities) {}

    public function relationEdges(array $relation): array
    {
        if (! $this->usesRelationshipNode($relation)) {
            return [$this->completedRelationshipEdgeFor($relation)];
        }

        $source = $relation['from'] ? $this->findEntity($relation['from']) : null;
        $target = $relation['to'] ? $this->findEntity($relation['to']) : null;
        $diamondId = $this->relationshipNodeId($relation['id']);
        $isSelfRelationship = $this->isSelfRelationship($relation);
        $selfPorts = $isSelfRelationship ? $this->selfRelationshipPortNodeIds($relation['id']) : null;
        $data = [
            'relationId' => $relation['id'],
            'relationName' => $relation['name'],
            'fromAttr' => $relation['fromAttr'],
            'toAttr' => $relation['toAttr'],
            'sourceName' => $source['name'] ?? 'não conectada',
            'targetName' => $target['name'] ?? 'não conectada',
            'isSelf' => $isSelfRelationship,
            'fromRole' => $relation['fromRole'] ?? null,
            'toRole' => $relation['toRole'] ?? null,
            'attributes' => $relation['attributes'] ?? [],
        ];
        $base = [
            'type' => 'straight',
            'pathType' => 'straight',
            'color' => self::RELATION_COLOR,
            'strokeWidth' => 1.6,
            'interactionWidth' => 34,
            'data' => $data,
        ];

        $edges = [];
        if ($source) {
            $edges[] = $base + [
                'id' => $relation['id'].':out',
                'source' => $isSelfRelationship ? $selfPorts['entityOut'] : $relation['from'],
                'target' => $isSelfRelationship ? $selfPorts['diamondOut'] : $diamondId,
                ...($isSelfRelationship ? ['label' => $this->selfRoleLabel($relation['fromRole'] ?? null, 'Origem')] : []),
                'labelStart' => $this->shortName($relation['fromAttr']),
                'markerStart' => $this->marker($relation['childCard']),
            ];
        }
        if ($target) {
            $edges[] = $base + [
                'id' => $relation['id'].':in',
                'source' => $isSelfRelationship ? $selfPorts['diamondIn'] : $diamondId,
                'target' => $isSelfRelationship ? $selfPorts['entityIn'] : $relation['to'],
                ...($isSelfRelationship ? ['label' => $this->selfRoleLabel($relation['toRole'] ?? null, 'Destino')] : []),
                'labelEnd' => $this->shortName($relation['toAttr']),
                'markerEnd' => $this->marker($relation['parentCard']),
            ];
        }

        return $edges;
    }

    private function selfRoleLabel(?string $role, string $fallback): string
    {
        return match ($role) {
            null, '' => $fallback,
            'papel_origem' => 'Origem',
            'papel_destino' => 'Destino',
            default => $role,
        };
    }

    private function completedRelationshipEdgeFor(array $relation): array
    {
        $source = $this->findEntity($relation['from']);
        $target = $this->findEntity($relation['to']);

        return [
            'id' => $relation['id'],
            'source' => $relation['from'],
            'target' => $relation['to'],
            'type' => 'floating',
            'pathType' => 'smoothstep',
            'color' => self::RELATION_COLOR,
            'strokeWidth' => 1.6,
            'interactionWidth' => 34,
            'label' => $relation['name'],
            'labelStart' => $this->shortName($relation['fromAttr']),
            'labelEnd' => $this->shortName($relation['toAttr']),
            'markerStart' => $this->marker($relation['childCard']),
            'markerEnd' => $this->marker($relation['parentCard']),
            'data' => [
                'relationId' => $relation['id'],
                'relationName' => $relation['name'],
                'fromAttr' => $relation['fromAttr'],
                'toAttr' => $relation['toAttr'],
                'sourceName' => $source['name'] ?? $relation['from'],
                'targetName' => $target['name'] ?? $relation['to'],
                'isSelf' => false,
                'fromRole' => $relation['fromRole'] ?? null,
                'toRole' => $relation['toRole'] ?? null,
                'attributes' => $relation['attributes'] ?? [],
                'associative' => $this->isAssociative($relation),
            ],
        ];
    }

    public function attributeEdges(array $relation): array
    {
        return array_map(fn ($attribute) => [
            'id' => $relation['id'].':attr:'.$attribute['id'],
            'source' => $this->usesAttributeAnchor($relation)
                ? $this->relationshipAttributeAnchorId($relation['id'])
                : $this->relationshipNodeId($relation['id']),
            'target' => $this->relationshipAttributeNodeId($relation['id'], $attribute['id']),
            'type' => 'straight',
            'pathType' => 'straight',
            'color' => '#94a3b8',
            'strokeWidth' => 1.25,
            'interactionWidth' => 20,
            'reconnectable' => false,
            'data' => ['relationId' => $relation['id'], 'isAttributeLink' => true],
        ], $relation['attributes'] ?? []);
    }

    /**
     * Describe a cardinality marker as an array instead of a string.
     *
     * A marker name alone uses AlpineFlow's default 12.5px offset, leaving a
     * visible gap between the symbol and entity. An explicit zero offset
     * keeps the crow's foot against the entity border.
     */
    private function marker(string $type): array
    {
        return [
            'type' => $type,
            'offset' => self::MARKER_OFFSET,
            'color' => self::RELATION_COLOR,
        ];
    }

    /** 'posts.user_id' → 'user_id' (the label at the end of the edge). */
    private function shortName(string $attrId): string
    {
        $pos = strrpos($attrId, '.');

        return $pos === false ? $attrId : substr($attrId, $pos + 1);
    }

    private function findEntity(string $id): ?array
    {
        foreach ($this->entities as $entity) {
            if ($entity['id'] === $id) {
                return $entity;
            }
        }

        return null;
    }

    private function isCompleteRelationship(array $relation): bool
    {
        return ! empty($relation['from']) && ! empty($relation['to']);
    }

    private function isSelfRelationship(array $relation): bool
    {
        return $this->isCompleteRelationship($relation) && $relation['from'] === $relation['to'];
    }

    private function isAssociative(array $relation): bool
    {
        return str_contains((string) ($relation['childCard'] ?? ''), 'many')
            && str_contains((string) ($relation['parentCard'] ?? ''), 'many');
    }

    private function usesRelationshipNode(array $relation): bool
    {
        return ! $this->isCompleteRelationship($relation) || $this->isSelfRelationship($relation);
    }

    private function usesAttributeAnchor(array $relation): bool
    {
        return $this->isCompleteRelationship($relation)
            && ! $this->isSelfRelationship($relation)
            && ! empty($relation['attributes']);
    }

    private function relationshipNodeId(string $relationId): string
    {
        return 'relation-'.$relationId;
    }

    private function relationshipAttributeAnchorId(string $relationId): string
    {
        return 'relation-'.$relationId.'-attribute-anchor';
    }

    private function relationshipAttributeNodeId(string $relationId, string $attributeId): string
    {
        return 'relation-'.$relationId.'-attr-'.$attributeId;
    }

    private function selfRelationshipPortNodeIds(string $relationId): array
    {
        $prefix = $this->relationshipNodeId($relationId).'-port-';

        return [
            'entityOut' => $prefix.'entity-out',
            'entityIn' => $prefix.'entity-in',
            'diamondOut' => $prefix.'diamond-out',
            'diamondIn' => $prefix.'diamond-in',
        ];
    }
}
