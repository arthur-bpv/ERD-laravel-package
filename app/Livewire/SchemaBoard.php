<?php

namespace App\Livewire;

use App\Livewire\Concerns\HandlesDiagramJsonExport;
use App\Models\Diagram;
use App\Services\ErRelationshipAttributeEditor;
use App\Services\RelationalDiagramCreator;
use App\Services\RelationalDiagramDriftAnalyzer;
use App\Support\BoardLayout;
use App\Support\DataTypeCatalog;
use App\Support\ErEntityNodeProjector;
use App\Support\ErRelationshipAttributePositioner;
use App\Support\ErRelationshipEdgeProjector;
use App\Support\ErSelfRelationshipPortProjector;
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Visual editor for an entity relationship model.
 *
 * Entities are boxes with attributes. Relationships are edges with a named
 * diamond at the center (Chen notation) and crow's foot cardinality at each
 * end (IE notation).
 *
 * Floating edges attach to entity borders as nodes move. Connections are
 * limited by model rules, exposed as data.canBeParent to the Blade handle.
 *
 * The server owns $entities and $relations. WireFlow receives incremental
 * commands rather than bidirectional :sync. The client discards temporary
 * drawn edges so the server can create stable IDs for reload and deletion.
 */
#[Layout('layouts.app')]
class SchemaBoard extends Component
{
    use HandlesDiagramJsonExport;

    // Send incremental canvas commands without rendering the full Livewire view.
    use WithWireFlow;

    /**
     * Accepted cardinalities, used to reject invalid values from the client.
     *
     * Must match the markers in resources/js/erd/markers.js. A marker present
     * on only one side silently becomes an ordinary arrow.
     */
    private const CARDINALITY_MARKERS = [
        'cf-one-one', 'cf-zero-one',
        'cf-one-many', 'cf-zero-many',
    ];

    /** Size of the relationship diamond in CSS. */
    private const RELATIONSHIP_WIDTH = 120.0;

    private const RELATIONSHIP_HEIGHT = 44.0;

    /** Size of invisible attribute anchors and recursive relationship ports. */
    private const RELATION_ANCHOR_SIZE = 2.0;

    /**
     * Headroom above an entity with a diamond.
     *
     * The recursive diamond sits above the entity and its nearest balloon
     * needs more space. Without headroom, the diamond hits the top boundary
     * and the balloon is pushed across the board.
     */
    private const RELATION_NODE_HEADROOM = 220.0;

    /**
     * Entities stored on the server.
     *
     * @var array<int, array{id:string,name:string,x:int,y:int,attributes:array}>
     */
    public array $entities = [];

    /**
     * Relationships stored on the server.
     *
     * name appears in the diamond. fromAttr/toAttr identify participating
     * attributes even when the edge runs from border to border.
     *
     * @var array<int, array{id:string,name:string,from:string,fromAttr:string,to:string,toAttr:string,childCard:string,parentCard:string}>
     */
    public array $relations = [];

    /** Counter for stable entity and attribute IDs. */
    public int $seq = 0;

    /** Separate counter for relationship IDs. */
    public int $relSeq = 0;

    /** Name entered for the next entity. */
    public string $newEntityName = '';

    #[Locked]
    public ?int $diagramId = null;

    #[Locked]
    public ?int $relationalDiagramId = null;

    /**
     * Whether the relational copy no longer matches this ER model.
     *
     * This is a signal only; regeneration remains a user action.
     */
    public bool $relationalIsOutdated = false;

    public string $diagramName = 'Diagrama sem nome';

    /** Target dialect stored with the ER for export. */
    #[Locked]
    public string $dialect = 'mysql';

    public function mount($diagram = null): void
    {
        if ($diagram) {
            $diagram = $diagram instanceof Diagram ? $diagram : Diagram::findOrFail($diagram);

            abort_unless($diagram->type === Diagram::TYPE_ENTITY_RELATIONSHIP, 404);

            $this->diagramId = $diagram->id;
            $this->diagramName = $diagram->name;
            $this->dialect = DataTypeCatalog::has($diagram->data['dialect'] ?? '')
                ? $diagram->data['dialect']
                : 'mysql';

            // An empty data array means a new blank project, not a seed request.
            $this->entities = array_values($diagram->data['entities'] ?? []);
            $this->relations = array_values($diagram->data['relations'] ?? []);
            $this->seq = $this->largestNumericId($this->entities, 'e');
            $this->relSeq = $this->largestNumericId($this->relations, 'r');
            foreach ($this->relations as $relation) {
                $this->seq = max($this->seq, $this->largestNumericId($relation['attributes'] ?? [], 'a'));
            }

            return;
        }

        // Demo entities, screen positions, and attributes.
        $this->entities = [
            [
                'id' => 'users', 'name' => 'users', 'x' => 720, 'y' => 200,
                'attributes' => [
                    ['id' => 'users.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'],
                    ['id' => 'users.name', 'name' => 'name', 'type' => 'varchar', 'key' => ''],
                    ['id' => 'users.email', 'name' => 'email', 'type' => 'varchar', 'key' => 'UQ'],
                ],
            ],
            [
                'id' => 'posts', 'name' => 'posts', 'x' => 390, 'y' => 60,
                'attributes' => [
                    ['id' => 'posts.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'],
                    ['id' => 'posts.user_id', 'name' => 'user_id', 'type' => 'bigint', 'key' => 'FK'],
                    ['id' => 'posts.title', 'name' => 'title', 'type' => 'varchar', 'key' => ''],
                    ['id' => 'posts.body', 'name' => 'body', 'type' => 'text', 'key' => ''],
                ],
            ],
            [
                'id' => 'comments', 'name' => 'comments', 'x' => 40, 'y' => 260,
                'attributes' => [
                    ['id' => 'comments.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'],
                    ['id' => 'comments.post_id', 'name' => 'post_id', 'type' => 'bigint', 'key' => 'FK'],
                    ['id' => 'comments.user_id', 'name' => 'user_id', 'type' => 'bigint', 'key' => 'FK'],
                    ['id' => 'comments.body', 'name' => 'body', 'type' => 'text', 'key' => ''],
                ],
            ],
        ];

        // Demo relationships; name is the verb displayed in the diamond.
        $this->relations = [
            ['id' => 'r1', 'name' => 'escreve', 'from' => 'posts', 'fromAttr' => 'posts.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-one-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r2', 'name' => 'recebe', 'from' => 'comments', 'fromAttr' => 'comments.post_id', 'to' => 'posts', 'toAttr' => 'posts.id', 'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r3', 'name' => 'comenta', 'from' => 'comments', 'fromAttr' => 'comments.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one'],
        ];

        // Initialize counters to avoid later ID collisions.
        $this->seq = count($this->entities);
        $this->relSeq = count($this->relations);
    }

    /**
     * Older boards may have gaps after deletion. Counting items could reuse
     * an existing ID, which the flow would silently reject.
     */
    private function largestNumericId(array $items, string $prefix): int
    {
        $largest = 0;

        foreach ($items as $item) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', (string) ($item['id'] ?? ''), $matches)) {
                $largest = max($largest, (int) $matches[1]);
            }
        }

        return $largest;
    }

    // ---------------------------------------------------------------------
    // Build the JavaScript canvas payload from server state.
    // ---------------------------------------------------------------------

    /**
     * Build the nodes understood by the frontend.
     *
     * @return array<int, array>
     */
    public function buildNodes(): array
    {
        $nodes = array_map(fn ($e) => ErEntityNodeProjector::node($e, $this->relations), $this->entities);

        // Use the same visual nodes for initial publication and later diffs.
        foreach ($this->relations as $relation) {
            array_push($nodes, ...$this->relationshipVisualNodesFor($relation));
        }

        return $nodes;
    }

    /**
     * Build the relationship edges understood by the frontend.
     *
     * @return array<int, array>
     */
    public function buildEdges(): array
    {
        return array_values(array_merge(...array_map(fn ($r) => [
            ...$this->edgesForRelation($r),
            ...$this->relationshipAttributeEdgesFor($r),
        ], $this->relations)));
    }

    private function relationshipNodeFor(array $relation): array
    {
        $isFixedSelfRelationship = $this->isSelfRelationship($relation);
        $source = $relation['from'] ? $this->findEntity($relation['from']) : null;
        $target = $relation['to'] ? $this->findEntity($relation['to']) : null;

        if ($source && $target && $source['id'] !== $target['id']) {
            $defaultX = (int) round(($source['x'] + $target['x']) / 2) + 50;
            $defaultY = (int) round(($source['y'] + $target['y']) / 2);
        } elseif ($source || $target) {
            $entity = $source ?? $target;
            if ($isFixedSelfRelationship) {
                // Place a recursive diamond above the entity for a short loop.
                $defaultX = $entity['x'] + 56;
                $defaultY = max(30, $entity['y'] - 104);
            } else {
                $defaultX = $entity['x'] + 330;
                $defaultY = max(30, $entity['y'] - 90);
            }
        } else {
            $defaultX = 320 + (($this->relSeq % 4) * 170);
            $defaultY = 180 + (intdiv($this->relSeq, 4) * 130);
        }

        return [
            'id' => $this->relationshipNodeId($relation['id']),
            'position' => [
                'x' => $relation['diamondX'] ?? $defaultX,
                'y' => $relation['diamondY'] ?? $defaultY,
            ],
            'dimensions' => [
                'width' => self::RELATIONSHIP_WIDTH,
                'height' => self::RELATIONSHIP_HEIGHT,
            ],
            'data' => [
                'kind' => 'relationship',
                'relationId' => $relation['id'],
                'name' => $relation['name'],
                'complete' => (bool) ($relation['from'] && $relation['to']),
                'isSelf' => $isFixedSelfRelationship,
                'from' => $relation['from'],
                'to' => $relation['to'],
                'sourceName' => $source['name'] ?? 'não conectada',
                'targetName' => $target['name'] ?? 'não conectada',
                'childCard' => $relation['childCard'],
                'parentCard' => $relation['parentCard'],
                'fromRole' => $relation['fromRole'] ?? 'papel_origem',
                'toRole' => $relation['toRole'] ?? 'papel_destino',
                'attributes' => $relation['attributes'] ?? [],
                'associative' => $this->isAssociative($relation),
            ],
        ];
    }

    private function edgesForRelation(array $relation): array
    {
        return (new ErRelationshipEdgeProjector($this->entities))->relationEdges($relation);
    }

    private function isAssociative(array $relation): bool
    {
        return str_contains((string) ($relation['childCard'] ?? ''), 'many')
            && str_contains((string) ($relation['parentCard'] ?? ''), 'many');
    }

    private function usesRelationshipNode(array $relation): bool
    {
        return ! $this->isCompleteRelationship($relation)
            || $this->isSelfRelationship($relation);
    }

    private function usesAttributeAnchor(array $relation): bool
    {
        return $this->isCompleteRelationship($relation)
            && ! $this->isSelfRelationship($relation)
            && ! empty($relation['attributes']);
    }

    private function relationshipAttributeAnchorId(string $relationId): string
    {
        return 'relation-'.$relationId.'-attribute-anchor';
    }

    private function relationshipAttributeAnchorFor(array $relation): array
    {
        return [
            'id' => $this->relationshipAttributeAnchorId($relation['id']),
            'position' => $this->relationshipAttributeAnchorPosition($relation),
            'dimensions' => [
                'width' => self::RELATION_ANCHOR_SIZE,
                'height' => self::RELATION_ANCHOR_SIZE,
            ],
            'data' => ['kind' => 'relationship-attribute-anchor', 'relationId' => $relation['id']],
        ];
    }

    /**
     * Anchor from which relationship attribute balloons hang.
     *
     * PHP stores the initial anchor position. relationship-balloons.js aligns
     * it with the rendered label. Each balloon follows anchor plus offset.
     *
     * Complete nonrecursive relationships use an anchor node; others use
     * their diamond.
     *
     * @return array{x:int,y:int}
     */
    private function relationshipAttributeAnchorPosition(array $relation): array
    {
        if ($this->usesAttributeAnchor($relation)) {
            $from = $this->findEntity($relation['from']);
            $to = $this->findEntity($relation['to']);
            $fromHeight = ErEntityNodeProjector::dimensions($from)['height'];
            $toHeight = ErEntityNodeProjector::dimensions($to)['height'];

            // Match the label's midpoint along the visible edge, using each
            // entity's actual height rather than a fixed base height.
            return [
                'x' => (int) round((($from['x'] ?? 0) + ($to['x'] ?? 0) + ErEntityNodeProjector::WIDTH) / 2),
                'y' => (int) round(
                    (($from['y'] ?? 0) + ($fromHeight / 2) + ($to['y'] ?? 0) + ($toHeight / 2)) / 2,
                ),
            ];
        }

        return $this->relationshipNodeFor($relation)['position'];
    }

    private function relationshipAttributeNodeId(string $relationId, string $attributeId): string
    {
        return 'relation-'.$relationId.'-attr-'.$attributeId;
    }

    private function relationshipAttributeNodesFor(array $relation): array
    {
        $anchor = $this->relationshipAttributeAnchorPosition($relation);
        $nodes = [];
        foreach (array_values($relation['attributes'] ?? []) as $index => $attribute) {
            $default = (new ErRelationshipAttributePositioner)->defaultOffset($index);
            $offsetX = $attribute['offsetX'] ?? $default['x'];
            $offsetY = $attribute['offsetY'] ?? $default['y'];

            $nodes[] = [
                'id' => $this->relationshipAttributeNodeId($relation['id'], $attribute['id']),
                'position' => [
                    'x' => $anchor['x'] + $offsetX,
                    'y' => $anchor['y'] + $offsetY,
                ],
                'dimensions' => [
                    'width' => ErRelationshipAttributePositioner::RELATION_ATTRIBUTE_WIDTH,
                    'height' => ErRelationshipAttributePositioner::RELATION_ATTRIBUTE_HEIGHT,
                ],
                'data' => [
                    'kind' => 'relationship-attribute',
                    'relationId' => $relation['id'],
                    'attrId' => $attribute['id'],
                    'name' => $attribute['name'],
                    'offsetX' => $offsetX,
                    'offsetY' => $offsetY,
                ],
            ];
        }

        return $nodes;
    }

    /**
     * Move the layout down when a diamond would touch the top boundary.
     *
     * Move every entity equally to preserve relative distances. fitView then
     * reframes the board.
     *
     * @param  array<string, array{x:int,y:int}>  $positions
     * @return array<string, array{x:int,y:int}>
     */
    private function reserveRelationshipNodeHeadroom(array $positions): array
    {
        $shift = 0.0;

        foreach ($this->relations as $relation) {
            if (! $this->usesRelationshipNode($relation)) {
                continue;
            }

            $entityId = $relation['from'] ?: $relation['to'];
            if (! $entityId || ! isset($positions[$entityId])) {
                continue;
            }

            $shift = max($shift, self::RELATION_NODE_HEADROOM - $positions[$entityId]['y']);
        }

        if ($shift <= 0) {
            return $positions;
        }

        $shift = (int) ceil($shift);
        foreach ($positions as $id => $position) {
            $positions[$id]['y'] += $shift;
        }

        return $positions;
    }

    private function placeRelationshipAttributes(): void
    {
        $bounds = [];
        foreach ($this->entities as $entity) {
            $dimensions = ErEntityNodeProjector::dimensions($entity);
            $bounds[] = [
                'x' => $entity['x'],
                'y' => $entity['y'],
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
            ];
        }

        $anchors = array_map(
            fn (array $relation): array => $this->relationshipAttributeAnchorPosition($relation),
            $this->relations,
        );
        $this->relations = (new ErRelationshipAttributePositioner)->place($bounds, $this->relations, $anchors);
    }

    private function relationshipAttributeEdgesFor(array $relation): array
    {
        return (new ErRelationshipEdgeProjector($this->entities))->attributeEdges($relation);
    }

    /**
     * All nodes published for a relationship.
     *
     * Publish the diamond, recursive ports, anchor, then balloons.
     *
     * @return array<int, array>
     */
    private function relationshipVisualNodesFor(array $relation): array
    {
        $nodes = [];
        if ($this->usesRelationshipNode($relation)) {
            $nodes[] = $this->relationshipNodeFor($relation);
            if ($this->isSelfRelationship($relation)) {
                array_push($nodes, ...$this->selfRelationshipPortNodesFor($relation));
            }
        }
        if ($this->usesAttributeAnchor($relation)) {
            $nodes[] = $this->relationshipAttributeAnchorFor($relation);
        }
        array_push($nodes, ...$this->relationshipAttributeNodesFor($relation));

        return $nodes;
    }

    /**
     * Relationship edges and attribute links.
     *
     * @return array<int, array>
     */
    private function relationshipVisualEdgesFor(array $relation): array
    {
        return [...$this->edgesForRelation($relation), ...$this->relationshipAttributeEdgesFor($relation)];
    }

    private function syncRelationshipVisual(array $before, array $after): void
    {
        $this->dispatch('erd-sync-relation',
            removeEdgeIds: array_column($this->relationshipVisualEdgesFor($before), 'id'),
            removeNodeIds: array_column($this->relationshipVisualNodesFor($before), 'id'),
            nodes: $this->relationshipVisualNodesFor($after),
            edges: $this->relationshipVisualEdgesFor($after),
            relationId: $after['id'],
        );
    }

    // ---------------------------------------------------------------------
    // Entity actions
    // ---------------------------------------------------------------------

    /**
     * Create an entity and publish it to the canvas.
     */
    public function createEntity(): void
    {
        $name = trim($this->newEntityName) ?: 'nova_entidade';
        $id = 'e'.(++$this->seq);

        // Use a four column grid with enough spacing for entity boxes.
        $index = count($this->entities);
        $column = $index % 4;
        $row = intdiv($index, 4);

        $entity = [
            'id' => $id,
            'name' => $name,
            'x' => 40 + $column * 280,
            'y' => ($this->diagramId ? 60 : 380) + $row * 220,
            'attributes' => [
                ['id' => $id.'.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'], // Every entity starts with an identifier.
            ],
        ];

        $this->entities[] = $entity;
        $this->newEntityName = ''; // Clear the form input.

        // Publish the new draggable node.
        $this->flowAddNodes([ErEntityNodeProjector::node($entity, $this->relations)]);
    }

    /**
     * Delete an entity and its connected relationships.
     */
    public function deleteEntity(string $id): void
    {
        $this->entities = array_values(array_filter($this->entities, fn ($e) => $e['id'] !== $id));

        // Remove each connected edge and visual node from the canvas.
        $this->dropRelations(fn (array $r): bool => $r['from'] === $id || $r['to'] === $id);

        $this->flowRemoveNodes([$id]);
    }

    /**
     * Rename an entity.
     */
    public function renameEntity(string $id, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $this->mutateEntity($id, function (&$e) use ($name) {
            $e['name'] = $name;
        });
    }

    public function renameAttribute(string $entityId, string $attrId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $this->mutateEntity($entityId, function (&$e) use ($attrId, $name) {
            foreach ($e['attributes'] as &$a) {
                if ($a['id'] === $attrId) {
                    $a['name'] = $name;
                    break;
                }
            }
            unset($a);
        });
    }

    // ---------------------------------------------------------------------
    // Attribute actions
    // ---------------------------------------------------------------------

    /**
     * Add an attribute to an existing entity.
     */
    public function addAttribute(string $entityId, string $name = '', string $type = 'varchar', string $key = ''): void
    {
        $name = trim($name) ?: 'coluna';

        $this->mutateEntity($entityId, function (&$e) use ($entityId, $name, $type, $key) {
            $attrId = $entityId.'.'.$name.'_'.(++$this->seq); // Keep IDs unique even when names repeat.
            $e['attributes'][] = [
                'id' => $attrId,
                'name' => $name,
                'type' => $type,
                'key' => in_array($key, ['PK', 'FK', 'UQ'], true) ? $key : '', // Reject unsupported keys.
            ];
        });
    }

    /**
     * Delete an attribute and the relationships that reference it.
     */
    public function removeAttribute(string $entityId, string $attrId): void
    {
        $this->mutateEntity($entityId, function (&$e) use ($attrId) {
            $e['attributes'] = array_values(array_filter($e['attributes'], fn ($a) => $a['id'] !== $attrId));
        });

        // A relationship cannot survive a missing attribute on either end.
        $this->dropRelations(fn (array $r): bool => $r['fromAttr'] === $attrId || $r['toAttr'] === $attrId);
    }

    /**
     * Remove selected relationships from state and canvas.
     *
     * Include all published edges and nodes: diamond, ports, anchor, and
     * attribute balloons. Otherwise visual remnants remain after deletion.
     *
     * @param  callable(array): bool  $shouldDrop
     */
    private function dropRelations(callable $shouldDrop): void
    {
        $kept = [];
        $edgeIds = [];
        $nodeIds = [];

        foreach ($this->relations as $relation) {
            if (! $shouldDrop($relation)) {
                $kept[] = $relation;

                continue;
            }

            array_push($edgeIds, ...array_column($this->relationshipVisualEdgesFor($relation), 'id'));
            array_push($nodeIds, ...array_column($this->relationshipVisualNodesFor($relation), 'id'));
        }

        if ($edgeIds === []) {
            return;
        }

        $this->relations = array_values($kept);
        $this->flowRemoveEdges($edgeIds);
        $this->flowRemoveNodes($nodeIds);

        // Publish freed attributes on the remaining entities.
        $this->syncNodeData();
    }

    /**
     * Cycle an attribute through no key, PK, and UQ.
     *
     * PK and UQ decide whether an entity can receive a relationship.
     * mutateEntity republishes the node data after the change.
     */
    public function cycleKey(string $entityId, string $attrId): void
    {
        $this->mutateEntity($entityId, function (&$e) use ($attrId) {
            foreach ($e['attributes'] as &$attribute) {
                if ($attribute['id'] === $attrId) {
                    $attribute['key'] = match ($attribute['key']) {
                        'PK' => 'UQ',
                        'UQ' => '',
                        default => 'PK',
                    };
                    break;
                }
            }
            unset($attribute);
        });
    }

    // ---------------------------------------------------------------------
    // Relationship actions
    // ---------------------------------------------------------------------

    /**
     * Create a recursive relationship through an explicit action.
     *
     * AlpineFlow rejects source === target during dragging, so the node
     * offers this action while the server applies the usual validation.
     */
    public function createSelfRelation(string $entityId): void
    {
        $this->onConnect($entityId, $entityId);
    }

    /**
     * Handle a connection drawn on the canvas.
     *
     * The client already discarded AlpineFlow's temporary edge. Create the
     * definitive relationship here with a stable server ID.
     *
     * Pick attributes automatically for this entity to entity connection.
     */
    public function onConnect(string $source, string $target): void
    {
        $sourceRelationId = $this->relationIdFromNode($source);
        $targetRelationId = $this->relationIdFromNode($target);

        if ($targetRelationId && ! $sourceRelationId) {
            $this->attachRelationshipEndpoint($targetRelationId, $source, 'from');

            return;
        }

        if ($sourceRelationId && ! $targetRelationId) {
            $this->attachRelationshipEndpoint($sourceRelationId, $target, 'to');

            return;
        }

        $sourceEntity = $this->findEntity($source);
        $targetEntity = $this->findEntity($target);

        if (! $sourceEntity || ! $targetEntity) {
            return;
        }

        // Reject another relationship between the same pair in either direction.
        if ($this->relationshipExistsBetween($sourceEntity['id'], $targetEntity['id'])) {
            return;
        }

        // A referenced entity needs an identifier.
        $pk = $this->identifierOf($targetEntity);
        if ($pk === null) {
            return;
        }

        // Reuse a suitable FK attribute; leave fromAttr empty if none exists.
        $fk = $this->findExistingForeignKeyColumn($sourceEntity['id'], $targetEntity) ?? '';

        $id = 'r'.(++$this->relSeq);

        $relation = [
            'id' => $id,
            'name' => 'relaciona',
            'from' => $sourceEntity['id'],
            'fromAttr' => $fk,
            'to' => $targetEntity['id'],
            'toAttr' => $pk,
            'childCard' => 'cf-one-many', // The FK side is usually many.
            'parentCard' => 'cf-one-one', // The PK side is usually exactly one.
        ];

        if ($source === $target) {
            $relation['fromRole'] = 'papel_origem';
            $relation['toRole'] = 'papel_destino';
        }

        $this->relations[] = $relation;

        if ($this->isSelfRelationship($relation)) {
            $this->flowAddNodes([
                $this->relationshipNodeFor($relation),
                ...$this->selfRelationshipPortNodesFor($relation),
            ]);
        }
        $this->flowAddEdges($this->edgesForRelation($relation));
        $this->syncNodeData();
    }

    private function attachRelationshipEndpoint(string $relationId, string $entityId, string $end): void
    {
        $entity = $this->findEntity($entityId);
        if (! $entity) {
            return;
        }

        foreach ($this->relations as &$relation) {
            if ($relation['id'] !== $relationId || $relation[$end] !== null) {
                continue;
            }

            $oldEdgeIds = array_column($this->edgesForRelation($relation), 'id');

            if ($end === 'to') {
                $identifier = $this->identifierOf($entity);
                if ($identifier === null) {
                    return;
                }
                $relation['to'] = $entityId;
                $relation['toAttr'] = $identifier;
                if ($relation['from']) {
                    $relation['fromAttr'] = $this->findExistingForeignKeyColumn($relation['from'], $entity) ?? '';
                }
            } else {
                $relation['from'] = $entityId;
                if ($relation['to'] && ($target = $this->findEntity($relation['to']))) {
                    $relation['fromAttr'] = $this->findExistingForeignKeyColumn($entityId, $target) ?? '';
                }
            }

            $this->dispatch(
                'erd-rebuild-edge',
                edges: $this->edgesForRelation($relation),
                removeIds: $oldEdgeIds,
                select: false,
            );
            if ($this->isCompleteRelationship($relation) && ! $this->isSelfRelationship($relation)) {
                $this->flowRemoveNodes([$this->relationshipNodeId($relationId)]);
            } else {
                $this->flowUpdate(['nodes' => [
                    $this->relationshipNodeId($relationId) => ['data' => $this->relationshipNodeFor($relation)['data']],
                ]]);
            }
            $this->syncNodeData();

            return;
        }
    }

    /**
     * Change the cardinality marker at one end.
     *
     * @param  string  $end  'child' (FK side) or 'parent' (PK side)
     */
    public function setCardinality(string $relationId, string $end, string $marker): void
    {
        if (! in_array($marker, self::CARDINALITY_MARKERS, true)) {
            return;
        }

        $field = $end === 'parent' ? 'parentCard' : 'childCard';

        $this->mutateRelation($relationId, function (&$relation) use ($field, $marker) {
            $relation[$field] = $marker;
        });
    }

    /**
     * Rename the relationship displayed inside the diamond.
     */
    public function renameRelation(string $relationId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        foreach ($this->relations as &$r) {
            if ($r['id'] === $relationId) {
                $r['name'] = $name;

                // flowUpdate can change an edge label without rebuilding it.
                if ($this->usesRelationshipNode($r)) {
                    $this->flowUpdate(['nodes' => [
                        $this->relationshipNodeId($relationId) => ['data' => $this->relationshipNodeFor($r)['data']],
                    ]]);
                    $this->dispatch('erd-rebuild-edge', edges: $this->edgesForRelation($r), select: false);
                } else {
                    $this->flowUpdate(['edges' => [$relationId => ['label' => $name]]]);
                }

                return;
            }
        }
    }

    public function renameSelfRelationRoles(string $relationId, string $fromRole, string $toRole): void
    {
        $fromRole = trim(mb_substr($fromRole, 0, 80));
        $toRole = trim(mb_substr($toRole, 0, 80));

        if ($fromRole === '' || $toRole === '' || mb_strtolower($fromRole) === mb_strtolower($toRole)) {
            return;
        }

        foreach ($this->relations as $relation) {
            if ($relation['id'] !== $relationId || ! $this->isSelfRelationship($relation)) {
                continue;
            }

            $this->mutateRelation($relationId, function (&$item) use ($fromRole, $toRole) {
                $item['fromRole'] = $fromRole;
                $item['toRole'] = $toRole;
            });

            return;
        }
    }

    /** Reverse the FK direction and move cardinalities to the new ends. */
    public function swapRelation(string $relationId): void
    {
        foreach ($this->relations as $relation) {
            if ($relation['id'] !== $relationId) {
                continue;
            }

            if ($this->isSelfRelationship($relation)) {
                $this->mutateRelation($relationId, function (&$item) {
                    [$item['fromAttr'], $item['toAttr']] = [$item['toAttr'], $item['fromAttr']];
                    [$item['childCard'], $item['parentCard']] = [$item['parentCard'], $item['childCard']];
                    [$item['fromRole'], $item['toRole']] = [$item['toRole'] ?? 'papel_destino', $item['fromRole'] ?? 'papel_origem'];
                });
            } elseif ($this->isCompleteRelationship($relation)) {
                $newParent = $this->findEntity($relation['from']);
                $newChild = $this->findEntity($relation['to']);
                $identifier = $newParent ? $this->identifierOf($newParent) : null;
                if (! $identifier || ! $newChild) {
                    $this->dispatch('erd-swap-rejected', relationId: $relationId,
                        message: 'A nova entidade de destino precisa de uma coluna PK ou UQ.');

                    return;
                }

                $foreignKey = $this->findExistingForeignKeyColumn($newChild['id'], $newParent) ?? '';
                $this->mutateRelation($relationId, function (&$item) use ($identifier, $foreignKey) {
                    [$item['from'], $item['to']] = [$item['to'], $item['from']];
                    $item['fromAttr'] = $foreignKey;
                    $item['toAttr'] = $identifier;
                    // Keep card values so their markers move to the new roles.
                });
            } else {
                return;
            }

            $this->syncNodeData();

            return;
        }
    }

    public function addRelationAttribute(string $relationId, string $name, string $type = 'varchar'): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            return;
        }

        $this->mutateRelation($relationId, function (&$relation) use ($name, $type) {
            $attributes = (new ErRelationshipAttributeEditor)->add(
                $relation['attributes'] ?? [], $name, $type, 'a'.($this->seq + 1),
            );
            if ($attributes !== null) {
                $relation['attributes'] = $attributes;
                $this->seq++;
            }
        });
    }

    public function renameRelationAttribute(string $relationId, string $attributeId, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            return;
        }
        $this->mutateRelation($relationId, function (&$relation) use ($attributeId, $name) {
            $attributes = (new ErRelationshipAttributeEditor)->rename($relation['attributes'] ?? [], $attributeId, $name);
            if ($attributes !== null) {
                $relation['attributes'] = $attributes;
            }
        });
    }

    public function removeRelationAttribute(string $relationId, string $attributeId): void
    {
        $this->mutateRelation($relationId, function (&$relation) use ($attributeId) {
            $relation['attributes'] = (new ErRelationshipAttributeEditor)->remove($relation['attributes'] ?? [], $attributeId);
        });
    }

    /**
     * Remove a relationship.
     */
    public function deleteRelation(string $relationId): void
    {
        $relation = collect($this->relations)->firstWhere('id', $relationId);
        $this->relations = array_values(array_filter($this->relations, fn ($r) => $r['id'] !== $relationId));

        if ($relation) {
            $this->flowRemoveEdges(array_column($this->relationshipVisualEdgesFor($relation), 'id'));
            $this->flowRemoveNodes(array_column($this->relationshipVisualNodesFor($relation), 'id'));
        }
        $this->syncNodeData();
    }

    /**
     * Handle a node released after dragging on the canvas.
     */
    public function onNodeDragEnd(string $nodeId, array $position): void
    {
        if (str_contains($nodeId, '-attr-') || str_ends_with($nodeId, '-attribute-anchor')) {
            return;
        }

        if (str_starts_with($nodeId, 'relation-')) {
            $relationId = substr($nodeId, strlen('relation-'));
            foreach ($this->relations as &$relation) {
                if ($relation['id'] === $relationId) {
                    $relation['diamondX'] = (int) round($position['x'] ?? 0);
                    $relation['diamondY'] = (int) round($position['y'] ?? 0);

                    // The dragged diamond already has its new position; patch its ports.
                    if ($this->isSelfRelationship($relation)) {
                        $this->flowUpdate(['nodes' => $this->positionPatch($this->selfRelationshipPortNodesFor($relation))]);
                    }
                    break;
                }
            }

            return;
        }

        // Persist coordinates so the layout survives a reload.
        foreach ($this->entities as &$e) {
            if ($e['id'] === $nodeId) {
                $newX = (int) round($position['x'] ?? $e['x']);
                $newY = (int) round($position['y'] ?? $e['y']);
                $deltaX = $newX - $e['x'];
                $deltaY = $newY - $e['y'];

                // Move recursive diamonds and ports with their entity. Use the
                // displayed diamond position, which may be constrained by bounds.
                foreach ($this->relations as &$relation) {
                    if (! $this->isSelfRelationship($relation) || $relation['from'] !== $nodeId) {
                        continue;
                    }

                    $diamond = $this->relationshipNodeFor($relation)['position'];
                    $relation['diamondX'] = $diamond['x'] + $deltaX;
                    $relation['diamondY'] = $diamond['y'] + $deltaY;

                    $this->flowUpdate(['nodes' => $this->positionPatch($this->selfRelationshipVisualNodesFor($relation))]);
                }
                unset($relation);

                $e['x'] = $newX;
                $e['y'] = $newY;
                break;
            }
        }
    }

    public function onRelationAttributeDragEnd(string $relationId, string $attributeId, array $offset): void
    {
        foreach ($this->relations as &$relation) {
            if ($relation['id'] !== $relationId) {
                continue;
            }
            foreach ($relation['attributes'] ?? [] as $index => $attribute) {
                if ($attribute['id'] !== $attributeId) {
                    continue;
                }
                $relation['attributes'][$index]['offsetX'] = (int) round($offset['x'] ?? 0);
                $relation['attributes'][$index]['offsetY'] = (int) round($offset['y'] ?? 0);

                return;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Helpers internos
    // ---------------------------------------------------------------------

    /** Find an entity by ID. */
    private function findEntity(string $id): ?array
    {
        foreach ($this->entities as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
    }

    /** Return the entity's PK identifier, falling back to UQ. */
    private function identifierOf(array $entity): ?string
    {
        foreach ($entity['attributes'] as $a) {
            if ($a['key'] === 'PK') {
                return $a['id'];
            }
        }

        foreach ($entity['attributes'] as $a) {
            if ($a['key'] === 'UQ') {
                return $a['id'];
            }
        }

        return null;
    }

    private function findExistingForeignKeyColumn(string $entityId, array $targetEntity): ?string
    {
        $candidateNames = array_unique([
            Str::snake(Str::singular($targetEntity['name'])).'_id',
            Str::snake($targetEntity['name']).'_id',
        ]);

        // Exclude attributes already used by another relationship.
        $occupiedAttributeIds = [];
        foreach ($this->relations as $r) {
            if ($r['from'] === $entityId) {
                $occupiedAttributeIds[] = $r['fromAttr'];
            }
        }

        $sourceEntity = $this->findEntity($entityId);
        foreach ($sourceEntity['attributes'] as $a) {
            if (in_array($a['name'], $candidateNames, true) && ! in_array($a['id'], $occupiedAttributeIds, true)) {
                return $a['id'];
            }
        }

        return null;
    }

    /**
     * Apply an entity change and synchronize its canvas node.
     *
     * flowUpdate patches data without replacing the wire:ignore DOM.
     */
    private function mutateEntity(string $id, callable $fn): void
    {
        foreach ($this->entities as &$e) {
            if ($e['id'] === $id) {
                $fn($e);
                $patch = [
                    $id => [
                        'data' => ErEntityNodeProjector::data($e, $this->relations),
                        'dimensions' => ErEntityNodeProjector::dimensions($e),
                    ],
                ];

                foreach ($this->relations as $relation) {
                    if (! $this->isSelfRelationship($relation) || $relation['from'] !== $id) {
                        continue;
                    }

                    $patch = [...$patch, ...$this->positionPatch($this->selfRelationshipVisualNodesFor($relation))];
                }

                $this->flowUpdate(['nodes' => $patch]);

                return;
            }
        }
    }

    /**
     * Apply a relationship change and redraw its edge.
     *
     * AlpineFlow cannot patch markers, type, or endpoints in place. It must
     * remove and add the edge again under the same ID.
     *
     * Separate Livewire commands in one response run in the same browser
     * tick. The reactive edge data changes, but the existing SVG group can
     * retain its old markers. Two animation frames fix that timing.
     *
     * Dispatch one erd-rebuild-edge event. canvas-controller.js removes the
     * edge, waits two frames, then adds the server supplied edge.
     */
    private function mutateRelation(string $id, callable $fn, bool $keepSelected = true): void
    {
        foreach ($this->relations as &$r) {
            if ($r['id'] === $id) {
                $before = $r;
                $fn($r);
                $oldNodes = $this->relationshipVisualNodesFor($before);
                $newNodes = $this->relationshipVisualNodesFor($r);
                $oldEdges = $this->relationshipVisualEdgesFor($before);
                $newEdges = $this->relationshipVisualEdgesFor($r);
                if (array_column($oldNodes, 'id') === array_column($newNodes, 'id')
                    && array_column($oldEdges, 'id') === array_column($newEdges, 'id')) {
                    $patch = [];
                    foreach ($newNodes as $node) {
                        $patch[$node['id']] = ['data' => $node['data']];
                    }
                    if ($patch) {
                        $this->flowUpdate(['nodes' => $patch]);
                    }
                    $this->dispatch('erd-rebuild-edge', edges: $newEdges, select: $keepSelected);
                } else {
                    $this->syncRelationshipVisual($before, $r);
                }

                return;
            }
        }
    }

    /**
     * Check for a relationship between two entities in either direction.
     *
     * Reject duplicate connections between the same pair.
     */
    private function relationshipExistsBetween(string $idA, string $idB): bool
    {
        foreach ($this->relations as $r) {
            $connectsForward = $r['from'] === $idA && $r['to'] === $idB;
            $connectsBackward = $r['from'] === $idB && $r['to'] === $idA;

            if ($connectsForward || $connectsBackward) {
                return true;
            }
        }

        return false;
    }

    private function relationIdFromNode(string $nodeId): ?string
    {
        return str_starts_with($nodeId, 'relation-')
            ? substr($nodeId, strlen('relation-'))
            : null;
    }

    private function isCompleteRelationship(array $relation): bool
    {
        return ! empty($relation['from']) && ! empty($relation['to']);
    }

    private function isSelfRelationship(array $relation): bool
    {
        return $this->isCompleteRelationship($relation) && $relation['from'] === $relation['to'];
    }

    private function relationshipNodeId(string $relationId): string
    {
        return 'relation-'.$relationId;
    }

    /**
     * Convert nodes to a flowUpdate position patch keyed by ID.
     *
     * @param  array<int, array>  $nodes
     * @return array<string, array>
     */
    private function positionPatch(array $nodes): array
    {
        $patch = [];
        foreach ($nodes as $node) {
            $patch[$node['id']] = ['position' => $node['position']];
        }

        return $patch;
    }

    /**
     * Diamond and four invisible ports of a recursive relationship.
     *
     * Include the diamond when its entity moves it. When the diamond itself
     * is dragged, AlpineFlow already provides its new position.
     *
     * @return array<int, array>
     */
    private function selfRelationshipVisualNodesFor(array $relation): array
    {
        return [$this->relationshipNodeFor($relation), ...$this->selfRelationshipPortNodesFor($relation)];
    }

    /** Returns the four canvas ports for a self relationship. */
    private function selfRelationshipPortNodesFor(array $relation): array
    {
        $entity = $this->findEntity($relation['from']);
        $diamond = $this->relationshipNodeFor($relation)['position'];

        return ErSelfRelationshipPortProjector::nodes($relation['id'], $entity, $diamond);
    }

    /**
     * Republish data for all entity nodes.
     *
     * Relationship changes can free attributes and change connectability.
     */
    private function syncNodeData(): void
    {
        $patch = [];
        foreach ($this->entities as $e) {
            $patch[$e['id']] = ['data' => ErEntityNodeProjector::data($e, $this->relations)];
        }

        if ($patch) {
            $this->flowUpdate(['nodes' => $patch]);
        }
    }

    // ---------------------------------------------------------------------
    // Diagram persistence
    // ---------------------------------------------------------------------

    /**
     * Create or update the diagram as one JSON record.
     *
     * Store entities and relationships together in diagrams.data. Diagram's
     * array cast handles JSON conversion; this component owns the state.
     *
     * Reuse diagramId after the first save to avoid duplicate records.
     */
    public function save(): void
    {
        $this->persistDiagram();

        // Blade listens globally through @saved.window to show save feedback.
        $this->dispatch('saved');
    }

    /**
     * Arrange entities, diamonds, and attribute balloons.
     *
     * Complete relationships remain floating edges recalculated by the canvas.
     *
     * Persist the layout immediately so a Livewire render cannot restore
     * older positions.
     */
    public function organizeBoard(): void
    {
        $oldPositions = collect($this->entities)->mapWithKeys(fn (array $entity): array => [
            $entity['id'] => ['x' => $entity['x'], 'y' => $entity['y']],
        ])->all();
        $links = array_map(fn (array $relation): array => [
            'source' => $relation['from'] ?? '',
            'target' => $relation['to'] ?? '',
        ], $this->relations);
        $positions = BoardLayout::centered(
            $this->entities,
            $links,
            fn (array $entity): float => ErEntityNodeProjector::dimensions($entity)['height'],
            ErEntityNodeProjector::WIDTH,
            // Leave broad corridors for readable entities and relationships.
            360,
            220,
        );
        $positions = $this->reserveRelationshipNodeHeadroom($positions);
        $positions = BoardLayout::clearLinkCorridors(
            $this->entities,
            $links,
            $positions,
            fn (array $entity): float => ErEntityNodeProjector::dimensions($entity)['height'],
            ErEntityNodeProjector::WIDTH,
            220,
            36,
        );

        foreach ($this->entities as &$entity) {
            if (isset($positions[$entity['id']])) {
                $entity['x'] = $positions[$entity['id']]['x'];
                $entity['y'] = $positions[$entity['id']]['y'];
            }
        }
        unset($entity);

        // Preserve manual diamond offsets for incomplete or recursive relations.
        foreach ($this->relations as &$relation) {
            if (! $this->usesRelationshipNode($relation)) {
                continue;
            }

            $entityId = $relation['from'] ?: $relation['to'];

            // A derived diamond position already moved with its entity.
            if (! isset($relation['diamondX'], $relation['diamondY'])) {
                continue;
            }

            if (! $entityId || ! isset($oldPositions[$entityId], $positions[$entityId])) {
                continue;
            }

            $relation['diamondX'] += $positions[$entityId]['x'] - $oldPositions[$entityId]['x'];
            $relation['diamondY'] += $positions[$entityId]['y'] - $oldPositions[$entityId]['y'];
        }
        unset($relation);

        $this->placeRelationshipAttributes();

        $this->persistDiagram();
        $this->flowFromObject(['nodes' => $this->buildNodes(), 'edges' => $this->buildEdges()]);
        $this->flowFitView();
    }

    /**
     * Save the current ER model and open its relational copy.
     */
    public function convertToRelational(RelationalDiagramCreator $copy): void
    {
        $source = $this->persistDiagram();

        $relational = $copy->findOrCreate($source);

        $this->redirectRoute('boards.relational', $relational, navigate: true);
    }

    /**
     * Update the relational drift signal in the top navigation.
     *
     * Compare in memory entities and relationships even before saving.
     */
    private function refreshRelationalSignal(RelationalDiagramDriftAnalyzer $drift, Diagram $source): void
    {
        $relational = $source->relationalDiagram()->first();

        $this->relationalDiagramId = $relational?->id;
        $this->relationalIsOutdated = $relational !== null
            && $drift->isOutdated(
                ['entities' => $this->entities, 'relations' => $this->relations],
                $relational->data ?? [],
            );
    }

    private function persistDiagram(): Diagram
    {
        $model = $this->diagramId
            ? Diagram::query()->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)->findOrFail($this->diagramId)
            : new Diagram(['type' => Diagram::TYPE_ENTITY_RELATIONSHIP]);

        $model->name = $this->diagramName;
        $model->data = [
            'dialect' => $this->dialect,
            'entities' => $this->entities,
            'relations' => $this->relations,
        ];
        $model->save();

        $this->diagramId = $model->id;

        return $model;
    }

    /**
     * Return formatted UTF-8 JSON of the current state for the modal.
     *
     * Livewire recalculates this property on render from current model state.
     */
    public function getJsonPreviewProperty(): string
    {
        return json_encode([
            '_comment' => 'Projeto: '.$this->diagramName.' | Modelo: ER',
            'dialect' => $this->dialect,
            'entities' => $this->entities,
            'relations' => $this->relations,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** Filename used by the JSON download action. */
    protected function jsonFileName(): string
    {
        return 'modelo-er.json';
    }

    /**
     * Render the Blade view with the current canvas nodes and edges.
     */
    public function render(RelationalDiagramDriftAnalyzer $drift): View
    {
        if ($this->diagramId !== null) {
            $source = Diagram::query()
                ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
                ->findOrFail($this->diagramId);
            // Signal drift during editing, before the user saves.
            $this->refreshRelationalSignal($drift, $source);
        }

        return view('livewire.schema-board', [
            'nodes' => $this->buildNodes(),
            'edges' => $this->buildEdges(),
        ]);
    }
}
