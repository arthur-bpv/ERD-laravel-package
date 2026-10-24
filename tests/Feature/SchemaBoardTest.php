<?php

namespace Tests\Feature;

use App\Livewire\RelationalBoard;
use App\Livewire\SchemaBoard;
use App\Models\Diagram;
use App\Services\ErToRelationalTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the ER model managed by SchemaBoard.
 *
 * Relationships drawn by mouse now receive server IDs, allowing cascade
 * deletion and reload to preserve the same model.
 */
class SchemaBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_keys_can_be_created_and_selected_in_er_and_preserved_on_conversion(): void
    {
        $board = Livewire::test(SchemaBoard::class)
            ->call('addAttribute', 'users', 'document', 'varchar', 'UQ')
            ->call('cycleKey', 'posts', 'posts.title')
            ->call('cycleKey', 'posts', 'posts.title')
            ->call('convertToRelational');

        $source = Diagram::query()->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)->sole();
        $relational = Diagram::query()->where('type', Diagram::TYPE_RELATIONAL)->sole();
        foreach (['users' => 'document', 'posts' => 'title'] as $entityId => $name) {
            $entity = collect($source->data['entities'])->firstWhere('id', $entityId);
            $table = collect($relational->data['tables'])->firstWhere('id', $entityId);
            $this->assertSame('UQ', collect($entity['attributes'])->firstWhere('name', $name)['key']);
            $this->assertSame('UQ', collect($table['columns'])->firstWhere('name', $name)['key']);
        }

        $board->call('cycleKey', 'posts', 'posts.title');
        $posts = collect($board->get('entities'))->firstWhere('id', 'posts');
        $this->assertSame('', collect($posts['attributes'])->firstWhere('name', 'title')['key']);
    }

    public function test_relationship_attributes_are_nodes_and_many_to_many_is_automatically_associative(): void
    {
        $board = Livewire::test(SchemaBoard::class)
            ->call('addRelationAttribute', 'r1', 'published_at', 'date');
        $relation = $this->findRelation($board->get('relations'), 'r1');
        $this->assertSame('date', $relation['attributes'][0]['type']);
        $nodes = $board->instance()->buildNodes();
        $this->assertContains('relation-r1-attribute-anchor', array_column($nodes, 'id'));
        $this->assertNotContains('relation-r1', array_column($nodes, 'id'));
        $this->assertContains('relation-r1-attr-'.$relation['attributes'][0]['id'], array_column($nodes, 'id'));
        $attributeEdge = collect($board->instance()->buildEdges())->firstWhere('id', 'r1:attr:'.$relation['attributes'][0]['id']);
        $this->assertNotNull($attributeEdge);
        $this->assertFalse($attributeEdge['reconnectable']);
        $originalEdge = collect($board->instance()->buildEdges())->firstWhere('id', 'r1');
        $this->assertSame('floating', $originalEdge['type']);

        $board->call('setCardinality', 'r1', 'parent', 'cf-zero-many');
        $mainEdge = collect($board->instance()->buildEdges())->firstWhere('id', 'r1');
        $this->assertSame('floating', $mainEdge['type']);
        $this->assertTrue($mainEdge['data']['associative']);
        foreach (['id', 'source', 'target', 'type', 'pathType'] as $connectionField) {
            $this->assertSame($originalEdge[$connectionField], $mainEdge[$connectionField]);
        }
        $result = app(ErToRelationalTransformer::class)->transform([
            'entities' => $board->get('entities'), 'relations' => $board->get('relations'),
        ]);
        $table = collect($result['tables'])->firstWhere('id', 'relation_r1');
        $this->assertNotNull($table);
        $this->assertContains('published_at', array_column($table['columns'], 'name'));

        $board->call('setCardinality', 'r1', 'parent', 'cf-one-one');
        $this->assertFalse(collect($board->instance()->buildEdges())->firstWhere('id', 'r1')['data']['associative']);
        $board->call('removeRelationAttribute', 'r1', $relation['attributes'][0]['id']);
        $this->assertNotContains('relation-r1-attribute-anchor', array_column($board->instance()->buildNodes(), 'id'));
    }

    public function test_relationship_balloons_keep_relative_offsets_when_an_entity_moves(): void
    {
        $board = Livewire::test(SchemaBoard::class)->call('addRelationAttribute', 'r1', 'signed_at');
        $attributeId = $this->findRelation($board->get('relations'), 'r1')['attributes'][0]['id'];
        $nodeId = 'relation-r1-attr-'.$attributeId;
        $before = collect($board->instance()->buildNodes())->firstWhere('id', $nodeId)['position'];

        $board->call('onNodeDragEnd', 'posts', ['x' => 590, 'y' => 160]);
        $after = collect($board->instance()->buildNodes())->firstWhere('id', $nodeId)['position'];
        $this->assertSame($before['x'] + 100, $after['x']);
        $this->assertSame($before['y'] + 50, $after['y']);

        $board->call('onRelationAttributeDragEnd', 'r1', $attributeId, ['x' => 210, 'y' => -40]);
        $relation = $this->findRelation($board->get('relations'), 'r1');
        $this->assertSame(210, $relation['attributes'][0]['offsetX']);
        $this->assertSame(-40, $relation['attributes'][0]['offsetY']);
    }

    /** Find a relationship by ID in component state. */
    private function findRelation(array $relations, string $id): ?array
    {
        foreach ($relations as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    }

    /** The schema page should load with its canvas mounted. */
    public function test_schema_page_loads(): void
    {
        $response = $this->get('/schema');

        $response->assertStatus(200);
        $response->assertSee('flow-container', false);
        $response->assertSee('Modelador ER');
        $response->assertSee('x-erd-measure-node="node"', false);
        $response->assertSee('Papéis do auto-relacionamento');
        $response->assertDontSee('Novo relacionamento');
    }

    public function test_er_background_control_uses_artisanflow_patterns(): void
    {
        $this->get('/schema')
            ->assertSee('Alterar fundo do modelo ER')
            ->assertSee('Pontilhado')
            ->assertSee('Liso')
            ->assertSee('Grade')
            ->assertSee('Cruzes')
            ->assertSee('patchConfig({ background: pattern })', false);
    }

    public function test_self_relationship_diamond_opens_the_standard_relationship_editor(): void
    {
        $response = $this->get('/schema');

        $response->assertOk();
        $response->assertSee('erd-open-relation', false);
        $response->assertSee('node.data.relationId', false);
        $response->assertDontSee('er-diamond-add', false);

        $blade = file_get_contents(resource_path('views/livewire/schema-board.blade.php'));
        preg_match(
            '/<template x-if="node\.data\.kind === \'relationship\'">(.*?)<template x-if="node\.data\.kind === \'relationship-port\'">/s',
            $blade,
            $relationshipTemplate,
        );
        $this->assertNotEmpty($relationshipTemplate);
        $this->assertStringNotContainsString('x-flow-handle', $relationshipTemplate[1]);
    }

    /**
     * Floating edges follow entity borders while nodes are dragged.
     */
    public function test_completed_relationships_between_distinct_entities_are_floating(): void
    {
        $edges = Livewire::test(SchemaBoard::class)->instance()->buildEdges();

        $this->assertNotEmpty($edges);
        foreach ($edges as $edge) {
            $this->assertSame('floating', $edge['type']);
            $this->assertSame('smoothstep', $edge['pathType']);
        }
    }

    /**
     * Crow's foot markers must touch the entity border.
     *
     * Positive offsets create a visible gap. A string marker restores the
     * library's default 12.5px offset, so require an explicit zero offset.
     */
    public function test_cardinality_markers_sit_flush_against_the_entity(): void
    {
        $edges = Livewire::test(SchemaBoard::class)->instance()->buildEdges();

        foreach ($edges as $edge) {
            foreach (['markerStart', 'markerEnd'] as $end) {
                if (! isset($edge[$end])) {
                    continue;
                }
                $this->assertIsArray($edge[$end], "{$end} must not be a string");
                $this->assertArrayHasKey('offset', $edge[$end]);
                $this->assertSame(0, $edge[$end]['offset']);
            }
        }
    }

    /**
     * The click target must be wider than the stroke.
     *
     * A 1.6px line alone is too narrow for reliable clicking.
     */
    public function test_edges_have_a_generous_click_target(): void
    {
        $edges = Livewire::test(SchemaBoard::class)->instance()->buildEdges();

        foreach ($edges as $edge) {
            $this->assertGreaterThanOrEqual(30, $edge['interactionWidth']);
        }
    }

    /** A complete relationship shows its diamond as an edge label. */
    public function test_completed_relationship_name_becomes_the_edge_diamond(): void
    {
        $component = Livewire::test(SchemaBoard::class);
        $nodes = collect($component->instance()->buildNodes());
        $edge = collect($component->instance()->buildEdges())->firstWhere('id', 'r1');

        $this->assertNull($nodes->firstWhere('id', 'relation-r1'));
        $this->assertSame('escreve', $edge['label']);
        $this->assertSame('user_id', $edge['labelStart']);
        $this->assertSame('id', $edge['labelEnd']);
    }

    public function test_self_relationship_diamond_keeps_its_chosen_offset_when_entity_moves(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'comments');

        $initialPorts = collect($component->instance()->buildNodes())
            ->filter(fn (array $node) => ($node['data']['kind'] ?? null) === 'relationship-port')
            ->keyBy(fn (array $node) => $node['data']['role']);

        $component->call('onNodeDragEnd', 'relation-r4', ['x' => 400, 'y' => 120]);
        $nodesAfterDiamondMove = collect($component->instance()->buildNodes());
        $afterDiamondMove = $nodesAfterDiamondMove->firstWhere('id', 'relation-r4')['position'];
        $this->assertSame(['x' => 400, 'y' => 120], $afterDiamondMove);
        $portsAfterDiamondMove = $nodesAfterDiamondMove
            ->filter(fn (array $node) => ($node['data']['kind'] ?? null) === 'relationship-port')
            ->keyBy(fn (array $node) => $node['data']['role']);
        foreach ($portsAfterDiamondMove as $role => $port) {
            $this->assertNotSame($initialPorts[$role]['position'], $port['position']);
        }

        $component->call('onNodeDragEnd', 'comments', ['x' => 200, 'y' => 300]);
        $nodesAfterEntityMove = collect($component->instance()->buildNodes());
        $afterEntityMove = $nodesAfterEntityMove->firstWhere('id', 'relation-r4')['position'];
        $this->assertSame(['x' => 560, 'y' => 160], $afterEntityMove);

        $portsAfterEntityMove = $nodesAfterEntityMove
            ->filter(fn (array $node) => ($node['data']['kind'] ?? null) === 'relationship-port')
            ->keyBy(fn (array $node) => $node['data']['role']);
        foreach ($portsAfterEntityMove as $role => $port) {
            $this->assertSame($portsAfterDiamondMove[$role]['position']['x'] + 160, $port['position']['x']);
            $this->assertSame($portsAfterDiamondMove[$role]['position']['y'] + 40, $port['position']['y']);
        }
    }

    public function test_attribute_changes_publish_fresh_dimensions_and_reposition_self_relationship_ports(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'comments');

        $before = collect($component->instance()->buildNodes());
        $entityBefore = $before->firstWhere('id', 'comments');
        $portsBefore = $before
            ->filter(fn (array $node) => ($node['data']['kind'] ?? null) === 'relationship-port')
            ->keyBy(fn (array $node) => $node['data']['role']);

        $component
            ->call('addAttribute', 'comments', 'created_at')
            ->assertDispatched('flow:update');

        $after = collect($component->instance()->buildNodes());
        $entityAfter = $after->firstWhere('id', 'comments');
        $portsAfter = $after
            ->filter(fn (array $node) => ($node['data']['kind'] ?? null) === 'relationship-port')
            ->keyBy(fn (array $node) => $node['data']['role']);

        $this->assertGreaterThan($entityBefore['dimensions']['height'], $entityAfter['dimensions']['height']);
        $this->assertNotSame(
            $portsBefore->pluck('position')->all(),
            $portsAfter->pluck('position')->all(),
        );
    }

    public function test_self_relationship_roles_can_be_renamed_but_not_blank_or_duplicated(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'comments')
            ->call('renameSelfRelationRoles', 'r4', 'subordinado', 'supervisor')
            ->assertDispatched('flow:update');

        $relation = $this->findRelation($component->get('relations'), 'r4');
        $this->assertSame('subordinado', $relation['fromRole']);
        $this->assertSame('supervisor', $relation['toRole']);
        $roleEdges = collect($component->instance()->buildEdges())->where('data.relationId', 'r4')->values();
        $this->assertSame(['subordinado', 'supervisor'], $roleEdges->pluck('label')->all());

        $component->call('renameSelfRelationRoles', 'r4', ' ', 'supervisor');
        $this->assertSame('subordinado', $this->findRelation($component->get('relations'), 'r4')['fromRole']);

        $component->call('renameSelfRelationRoles', 'r4', 'pessoa', 'pessoa');
        $this->assertSame('subordinado', $this->findRelation($component->get('relations'), 'r4')['fromRole']);
    }

    /**
     * An entity needs an identifier to receive a relationship. Blade reads
     * that state through x-flow-handle-connectable.
     */
    public function test_nodes_publish_whether_they_can_be_referenced(): void
    {
        $nodes = Livewire::test(SchemaBoard::class)->instance()->buildNodes();

        foreach (array_filter($nodes, fn (array $node) => ! isset($node['data']['kind'])) as $node) {
            $this->assertTrue($node['data']['canBeParent']);
        }

        // Both attributes participate in the demo relationships.
        $posts = collect($nodes)->firstWhere('id', 'posts');
        $this->assertContains('posts.user_id', $posts['data']['usedAttrs']);
        $this->assertContains('posts.id', $posts['data']['usedAttrs']);
    }

    /**
     * A drawn connection receives a stable server ID rather than AlpineFlow's
     * temporary client ID.
     */
    public function test_drawing_a_connection_creates_a_server_owned_relation(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('onConnect', 'comments', 'comments')
            ->assertDispatched('flow:addEdges');

        $relations = $component->get('relations');
        $this->assertCount(4, $relations);

        $newRelation = $this->findRelation($relations, 'r4');
        $this->assertNotNull($newRelation, 'The relationship should have a server ID.');
        $this->assertSame('comments', $newRelation['from']);
        $this->assertSame('comments', $newRelation['to']);
        $this->assertSame('comments.id', $newRelation['toAttr']);
        $this->assertSame('papel_origem', $newRelation['fromRole']);
        $this->assertSame('papel_destino', $newRelation['toRole']);
    }

    /** The conceptual model leaves FK creation to the relational stage. */
    public function test_connecting_does_not_create_a_foreign_key_column(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('onConnect', 'comments', 'comments');

        $comments = collect($component->get('entities'))->firstWhere('id', 'comments');
        $recursiveFk = collect($comments['attributes'])->firstWhere('name', 'comments_id');

        $this->assertNull($recursiveFk);
        $this->assertSame('', $this->findRelation($component->get('relations'), 'r4')['fromAttr']);
    }

    public function test_self_relationship_is_drawn_through_an_external_diamond(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'comments')
            ->assertDispatched('flow:addNodes')
            ->assertDispatched('flow:addEdges');

        $nodes = collect($component->instance()->buildNodes());
        $entity = $nodes->firstWhere('id', 'comments');
        $diamond = $nodes->firstWhere('id', 'relation-r4');
        $ports = $nodes->filter(fn (array $node) => ($node['data']['kind'] ?? null) === 'relationship-port');
        $edges = collect($component->instance()->buildEdges())
            ->filter(fn (array $edge) => ($edge['data']['relationId'] ?? null) === 'r4')
            ->values();

        $this->assertSame('relationship', $diamond['data']['kind']);
        $this->assertSame('relaciona', $diamond['data']['name']);
        $this->assertSame($entity['position']['x'] + 56, $diamond['position']['x']);
        $this->assertLessThan($entity['position']['y'], $diamond['position']['y']);
        $this->assertCount(4, $ports);
        $this->assertEqualsCanonicalizing(
            ['entityOut', 'entityIn', 'diamondOut', 'diamondIn'],
            $ports->pluck('data.role')->all(),
        );
        $this->assertCount(2, $edges);
        $this->assertSame(
            ['relation-r4-port-entity-out', 'relation-r4-port-diamond-out'],
            [$edges[0]['source'], $edges[0]['target']],
        );
        $this->assertSame(
            ['relation-r4-port-diamond-in', 'relation-r4-port-entity-in'],
            [$edges[1]['source'], $edges[1]['target']],
        );
        $this->assertSame('straight', $edges[0]['type']);
        $this->assertSame('straight', $edges[1]['type']);
        $this->assertSame(['Origem', 'Destino'], $edges->pluck('label')->all());
        $this->assertArrayNotHasKey('sourceHandle', $edges[0]);
        $this->assertArrayNotHasKey('targetHandle', $edges[0]);
        $this->assertArrayNotHasKey('sourceHandle', $edges[1]);
        $this->assertArrayNotHasKey('targetHandle', $edges[1]);
    }

    /** Reject a connection when the target has no PK or UQ. */
    public function test_connecting_to_an_entity_without_an_identifier_is_refused(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createEntity')
            ->call('cycleKey', 'e4', 'e4.id')
            ->call('cycleKey', 'e4', 'e4.id')
            ->call('onConnect', 'users', 'e4');

        $this->assertCount(3, $component->get('relations'), 'No new relationship should exist.');

        $nodes = $component->instance()->buildNodes();
        $unidentifiedNode = collect($nodes)->firstWhere('id', 'e4');
        $this->assertFalse($unidentifiedNode['data']['canBeParent']);
    }

    /**
     * Deleting an entity also removes every connected relationship, including
     * those drawn with the mouse.
     */
    public function test_deleting_an_entity_cascades_to_hand_drawn_relations(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('onConnect', 'users', 'posts')
            ->call('deleteEntity', 'users')
            ->assertDispatched('flow:removeEdges')
            ->assertDispatched('flow:removeNodes');

        // Only r2 remains; r1, r3, and r4 touched users.
        $relations = $component->get('relations');
        $this->assertCount(1, $relations);
        $this->assertSame('r2', $relations[0]['id']);
    }

    /** Deleting an attribute removes relationships that depend on it. */
    public function test_removing_an_attribute_cascades_to_its_relations(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('removeAttribute', 'posts', 'posts.user_id')
            ->assertDispatched('flow:removeEdges');

        $this->assertNull($this->findRelation($component->get('relations'), 'r1'));
        $this->assertCount(2, $component->get('relations'));
    }

    /**
     * Changing cardinality redraws the edge and keeps it selected.
     *
     * The editor needs the recreated edge to remain selected after each click.
     *
     * A single erd-rebuild-edge event lets canvas-controller.js wait two
     * animation frames between removing and adding the server supplied edge.
     */
    public function test_setting_cardinality_keeps_the_edge_selected(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('setCardinality', 'r1', 'child', 'cf-one-many')
            ->assertDispatched('erd-rebuild-edge', function (string $name, array $params) {
                return $params['edges'][0]['id'] === 'r1'
                    && $params['edges'][0]['markerStart']['type'] === 'cf-one-many'
                    && $params['select'] === true;
            });

        $this->assertSame('cf-one-many', $this->findRelation($component->get('relations'), 'r1')['childCard']);
    }

    /** Ignore cardinalities unknown to the server. */
    public function test_unknown_cardinality_is_rejected(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('setCardinality', 'r1', 'child', 'nao-existe');

        $this->assertSame('cf-one-many', $this->findRelation($component->get('relations'), 'r1')['childCard']);
    }

    /** Swapping a relationship reselects a valid key for the new direction. */
    public function test_swapping_a_relation_moves_cardinalities_and_reselects_its_columns(): void
    {
        $component = Livewire::test(SchemaBoard::class)->call('swapRelation', 'r1');

        $r1 = $this->findRelation($component->get('relations'), 'r1');
        $this->assertSame('users', $r1['from']);
        $this->assertSame('posts', $r1['to']);
        $this->assertSame('', $r1['fromAttr']);
        $this->assertSame('posts.id', $r1['toAttr']);
        $this->assertSame('cf-one-many', $r1['childCard']);
        $this->assertSame('cf-one-one', $r1['parentCard']);

        $edge = collect($component->instance()->buildEdges())->firstWhere('id', 'r1');
        $this->assertSame('users', $edge['source']);
        $this->assertSame('posts', $edge['target']);
        $this->assertSame('cf-one-many', $edge['markerStart']['type']);
        $this->assertSame('cf-one-one', $edge['markerEnd']['type']);
        $component->assertDispatched('erd-rebuild-edge');

        $component->call('swapRelation', 'r1');
        $restored = $this->findRelation($component->get('relations'), 'r1');
        $this->assertSame('posts', $restored['from']);
        $this->assertSame('users', $restored['to']);
        $this->assertSame('posts.user_id', $restored['fromAttr']);
        $this->assertSame('users.id', $restored['toAttr']);
    }

    public function test_swapping_requires_an_identifier_on_the_new_parent(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('cycleKey', 'posts', 'posts.id')
            ->call('cycleKey', 'posts', 'posts.id');
        $before = $this->findRelation($component->get('relations'), 'r1');

        $component->call('swapRelation', 'r1')
            ->assertDispatched('erd-swap-rejected');

        $this->assertSame($before, $this->findRelation($component->get('relations'), 'r1'));
    }

    public function test_swapping_a_self_relationship_still_exchanges_its_roles(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'users');
        $before = $this->findRelation($component->get('relations'), 'r4');

        $component->call('swapRelation', 'r4');
        $after = $this->findRelation($component->get('relations'), 'r4');

        $this->assertSame($before['toRole'], $after['fromRole']);
        $this->assertSame($before['fromRole'], $after['toRole']);
        $this->assertSame($before['parentCard'], $after['childCard']);
        $this->assertSame($before['childCard'], $after['parentCard']);
        $labels = collect($component->instance()->buildEdges())->where('data.relationId', 'r4')->pluck('label')->all();
        $this->assertSame(['Destino', 'Origem'], $labels);
    }

    /** Renaming a relationship patches the diamond label without rebuilding the edge. */
    public function test_renaming_a_relation_patches_the_label(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('renameRelation', 'r1', 'publica')
            ->assertDispatched('flow:update');

        $this->assertSame('publica', $this->findRelation($component->get('relations'), 'r1')['name']);
    }

    /** A blank name does not erase the diamond label. */
    public function test_renaming_a_relation_to_blank_is_ignored(): void
    {
        $component = Livewire::test(SchemaBoard::class)->call('renameRelation', 'r1', '   ');

        $this->assertSame('escreve', $this->findRelation($component->get('relations'), 'r1')['name']);
    }

    /** Persist dragged positions so the layout survives reloads. */
    public function test_dragging_a_node_persists_its_position(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('onNodeDragEnd', 'posts', ['x' => 123.7, 'y' => 456.2]);

        $posts = collect($component->get('entities'))->firstWhere('id', 'posts');
        $this->assertSame(124, $posts['x']);
        $this->assertSame(456, $posts['y']);
    }

    public function test_converting_saves_the_current_er_and_opens_an_independent_relational_board(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('addAttribute', 'users', 'active')
            ->call('convertToRelational')
            ->assertRedirect();

        $source = Diagram::query()->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)->sole();
        $relational = Diagram::query()->where('type', Diagram::TYPE_RELATIONAL)->sole();
        $users = collect($source->data['entities'])->firstWhere('id', 'users');
        $usersTable = collect($relational->data['tables'])->firstWhere('id', 'users');

        $this->assertSame($source->id, $relational->source_diagram_id);
        $this->assertContains('active', array_column($users['attributes'], 'name'));
        $this->assertContains('active', array_column($usersTable['columns'], 'name'));
        $this->assertNotEmpty($relational->data['foreignKeys']);
    }

    public function test_er_header_opens_its_existing_relational_model_after_saving(): void
    {
        $source = Diagram::create([
            'name' => 'Biblioteca',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => ['entities' => [], 'relations' => []],
        ]);
        $relational = Diagram::create([
            'name' => 'Biblioteca — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $source->id,
            'data' => ['tables' => [], 'foreignKeys' => [], 'warnings' => []],
        ]);

        Livewire::test(SchemaBoard::class, ['diagram' => $source])
            ->assertSet('relationalDiagramId', $relational->id)
            ->assertSee('Modelo ER')
            ->assertSee('Modelo Relacional')
            ->assertSeeHtml('wire:click="convertToRelational"');
    }

    public function test_er_header_flags_a_relational_copy_left_behind_by_later_edits(): void
    {
        $source = Diagram::create([
            'name' => 'Biblioteca',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => ['entities' => [], 'relations' => []],
        ]);
        $relational = Diagram::create([
            'name' => 'Biblioteca — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $source->id,
            'data' => app(ErToRelationalTransformer::class)->transform($source->data),
        ]);

        Livewire::test(SchemaBoard::class, ['diagram' => $source])
            ->assertSet('relationalIsOutdated', false)
            ->assertDontSee('board-tab-flag')
            ->call('createEntity')
            ->assertSet('relationalIsOutdated', true)
            ->assertSee('board-tab-flag')
            ->assertSee('Modelo Relacional desatualizado')
            ->call('save')
            ->assertSet('relationalIsOutdated', true)
            ->assertSee('Modelo Relacional desatualizado');

        Livewire::test(RelationalBoard::class, ['diagram' => $relational])
            ->assertViewHas('isOutdated', true)
            ->assertSee('Este modelo Relacional está desatualizado');

        $this->assertCount(1, $source->fresh()->data['entities']);

        Livewire::test(SchemaBoard::class, ['diagram' => $source->fresh()])
            ->call('convertToRelational')
            ->assertRedirect(route('boards.relational', $relational));

        $this->assertCount(1, $source->fresh()->data['entities']);
        $this->assertSame([], $relational->fresh()->data['tables']);
    }

    public function test_self_relationship_and_cardinality_edits_flag_the_relational_copy_immediately(): void
    {
        $demo = Livewire::test(SchemaBoard::class);
        $source = Diagram::create([
            'name' => 'Exemplo',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [
                'entities' => $demo->get('entities'),
                'relations' => $demo->get('relations'),
            ],
        ]);
        Diagram::create([
            'name' => 'Exemplo — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $source->id,
            'data' => app(ErToRelationalTransformer::class)->transform($source->data),
        ]);

        Livewire::test(SchemaBoard::class, ['diagram' => $source])
            ->assertSet('relationalIsOutdated', false)
            ->call('setCardinality', 'r1', 'child', 'cf-zero-many')
            ->assertSet('relationalIsOutdated', true);

        Livewire::test(SchemaBoard::class, ['diagram' => $source])
            ->assertSet('relationalIsOutdated', false)
            ->call('createSelfRelation', 'users')
            ->assertSet('relationalIsOutdated', true);
    }

    public function test_er_guide_is_rendered_below_the_header_instead_of_over_the_minimap(): void
    {
        $component = Livewire::test(SchemaBoard::class);

        $component
            ->assertSeeHtml('class="er-guide"')
            ->assertDontSeeHtml('position="bottom-right" class="er-legend"');

        $html = $component->html();
        $this->assertLessThan(strpos($html, 'class="relative min-h-0 flex-1 overflow-hidden"'), strpos($html, 'class="er-guide"'));
    }

    public function test_opening_an_existing_relational_model_does_not_regenerate_its_manual_edits(): void
    {
        $source = Diagram::create([
            'name' => 'Biblioteca',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => ['entities' => [], 'relations' => []],
        ]);
        $relational = Diagram::create([
            'name' => 'Biblioteca — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $source->id,
            'data' => [
                'tables' => [['id' => 'manual', 'name' => 'Auditoria', 'kind' => 'strong', 'x' => 0, 'y' => 0, 'columns' => [], 'primaryKey' => []]],
                'foreignKeys' => [],
                'warnings' => [],
                'customized' => true,
            ],
        ]);

        Livewire::test(SchemaBoard::class, ['diagram' => $source])
            ->call('convertToRelational')
            ->assertRedirect(route('boards.relational', $relational));

        $this->assertSame('Auditoria', $relational->fresh()->data['tables'][0]['name']);
        $this->assertTrue($relational->fresh()->data['customized']);
    }

    /**
     * AlpineFlow's fitView silently fails if any published node lacks
     * dimensions. Require them for every node kind.
     *
     * Cover entities, diamonds, recursive ports, anchors, and balloons.
     */
    public function test_every_published_node_declares_its_dimensions(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('addRelationAttribute', 'r1', 'published_at', 'date')
            ->call('createSelfRelation', 'comments')
            ->call('addRelationAttribute', 'r4', 'approved_by', 'string');

        // Entities are the only nodes without kind. Check every visual kind.
        $kinds = collect($component->instance()->buildNodes())
            ->map(fn (array $node) => $node['data']['kind'] ?? 'entity');

        // Ensure the scenario still includes all expected node kinds.
        $this->assertEqualsCanonicalizing(
            ['entity', 'relationship', 'relationship-attribute', 'relationship-attribute-anchor', 'relationship-port'],
            $kinds->unique()->sort()->values()->all(),
        );

        foreach ($component->instance()->buildNodes() as $node) {
            $this->assertArrayHasKey('width', $node['dimensions'] ?? [], "O node {$node['id']} não declarou a largura.");
            $this->assertArrayHasKey('height', $node['dimensions'] ?? [], "O node {$node['id']} não declarou a altura.");
            $this->assertGreaterThan(0, $node['dimensions']['width'], "O node {$node['id']} tem largura inválida.");
            $this->assertGreaterThan(0, $node['dimensions']['height'], "O node {$node['id']} tem altura inválida.");
        }
    }

    public function test_json_preview_remains_the_er_source_model(): void
    {
        $json = json_decode(
            Livewire::test(SchemaBoard::class)->instance()->getJsonPreviewProperty(),
            true,
        );

        $this->assertArrayHasKey('entities', $json);
        $this->assertSame('Projeto: Diagrama sem nome | Modelo: ER', $json['_comment']);
        $this->assertArrayHasKey('relations', $json);
        $this->assertArrayNotHasKey('tables', $json);
    }

    public function test_json_modal_copies_and_downloads_the_er_model(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createEntity')
            ->assertSet('showJson', false)
            ->call('toggleJson')
            ->assertSet('showJson', true)
            // Both boards share the same modal and footer.
            ->assertSeeHtml('class="rel-modal"')
            ->assertSeeHtml('class="rel-modal-actions"')
            ->assertSee('Copiar')
            ->assertSee('Baixar .json')
            ->assertSee('wire:click="downloadJson"', escape: false);

        $component->call('downloadJson')->assertFileDownloaded('modelo-er.json');
    }
}
