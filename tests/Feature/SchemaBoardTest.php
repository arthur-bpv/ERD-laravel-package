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
 * Cobre o modelo Entidade-Relacionamento do SchemaBoard.
 *
 * O foco é o que quebrava antes: relações criadas com o mouse pertenciam só ao
 * cliente, então a limpeza em cascata não as alcançava e um reload as perdia.
 * Agora toda aresta nasce aqui no servidor, com id próprio.
 */
class SchemaBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_attributes_are_nodes_and_many_to_many_is_automatically_associative(): void
    {
        $board = Livewire::test(SchemaBoard::class)
            ->call('addRelationAttribute', 'r1', 'published_at', 'date');
        $relation = $this->relacao($board->get('relations'), 'r1');
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
        $attributeId = $this->relacao($board->get('relations'), 'r1')['attributes'][0]['id'];
        $nodeId = 'relation-r1-attr-'.$attributeId;
        $before = collect($board->instance()->buildNodes())->firstWhere('id', $nodeId)['position'];

        $board->call('onNodeDragEnd', 'posts', ['x' => 590, 'y' => 160]);
        $after = collect($board->instance()->buildNodes())->firstWhere('id', $nodeId)['position'];
        $this->assertSame($before['x'] + 100, $after['x']);
        $this->assertSame($before['y'] + 50, $after['y']);

        $board->call('onRelationAttributeDragEnd', 'r1', $attributeId, ['x' => 210, 'y' => -40]);
        $relation = $this->relacao($board->get('relations'), 'r1');
        $this->assertSame(210, $relation['attributes'][0]['offsetX']);
        $this->assertSame(-40, $relation['attributes'][0]['offsetY']);
    }

    /** Localiza uma relação pelo id dentro do estado do componente. */
    private function relacao(array $relations, string $id): ?array
    {
        foreach ($relations as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    }

    /** A página /schema deve carregar com o canvas montado. */
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
     * Toda aresta sai daqui como `floating` — é isso que faz a linha encostar
     * na borda da entidade e deslizar quando a caixa é arrastada, em vez de
     * ficar presa a um ponto fixo.
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
     * O pé de galinha tem de encostar na caixa da entidade.
     *
     * O `offset` empurra a ponta da linha para FORA do nó e o símbolo é
     * desenhado dali para trás — então qualquer valor acima de zero vira um vão
     * visível. Deixar o marcador como string reativa o padrão de 12,5px da
     * biblioteca, que era exatamente o afastamento reclamado.
     */
    public function test_cardinality_markers_sit_flush_against_the_entity(): void
    {
        $edges = Livewire::test(SchemaBoard::class)->instance()->buildEdges();

        foreach ($edges as $edge) {
            foreach (['markerStart', 'markerEnd'] as $ponta) {
                if (! isset($edge[$ponta])) {
                    continue;
                }
                $this->assertIsArray($edge[$ponta], "{$ponta} não pode ser string");
                $this->assertArrayHasKey('offset', $edge[$ponta]);
                $this->assertSame(0, $edge[$ponta]['offset']);
            }
        }
    }

    /**
     * A faixa de clique precisa ser bem maior que o traço.
     *
     * Uma linha de 1,6px é um alvo minúsculo para o mouse — era por isso que
     * clicar na relação às vezes não pegava.
     */
    public function test_edges_have_a_generous_click_target(): void
    {
        $edges = Livewire::test(SchemaBoard::class)->instance()->buildEdges();

        foreach ($edges as $edge) {
            $this->assertGreaterThanOrEqual(30, $edge['interactionWidth']);
        }
    }

    /** Em uma relação completa, o losango volta a ser o label fixo da linha. */
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

        $relation = $this->relacao($component->get('relations'), 'r4');
        $this->assertSame('subordinado', $relation['fromRole']);
        $this->assertSame('supervisor', $relation['toRole']);
        $roleEdges = collect($component->instance()->buildEdges())->where('data.relationId', 'r4')->values();
        $this->assertSame(['subordinado', 'supervisor'], $roleEdges->pluck('label')->all());

        $component->call('renameSelfRelationRoles', 'r4', ' ', 'supervisor');
        $this->assertSame('subordinado', $this->relacao($component->get('relations'), 'r4')['fromRole']);

        $component->call('renameSelfRelationRoles', 'r4', 'pessoa', 'pessoa');
        $this->assertSame('subordinado', $this->relacao($component->get('relations'), 'r4')['fromRole']);
    }

    /**
     * Uma entidade só pode receber relacionamento se tiver identificador.
     * É esse dado que o x-flow-handle-connectable lê no Blade — foi o que
     * substituiu a contagem de handles ocupados.
     */
    public function test_nodes_publish_whether_they_can_be_referenced(): void
    {
        $nodes = Livewire::test(SchemaBoard::class)->instance()->buildNodes();

        foreach (array_filter($nodes, fn (array $node) => ! isset($node['data']['kind'])) as $node) {
            $this->assertTrue($node['data']['canBeParent']);
        }

        // posts.user_id e posts.id participam de relações do seed
        $posts = collect($nodes)->firstWhere('id', 'posts');
        $this->assertContains('posts.user_id', $posts['data']['usedAttrs']);
        $this->assertContains('posts.id', $posts['data']['usedAttrs']);
    }

    /**
     * Conexão desenhada no canvas vira relação do servidor, com id próprio
     * ("r4") — não com o id aleatório que o AlpineFlow gera no cliente.
     */
    public function test_drawing_a_connection_creates_a_server_owned_relation(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('onConnect', 'comments', 'comments')
            ->assertDispatched('flow:addEdges');

        $relations = $component->get('relations');
        $this->assertCount(4, $relations);

        $nova = $this->relacao($relations, 'r4');
        $this->assertNotNull($nova, 'a relação deveria ter recebido o id do servidor');
        $this->assertSame('comments', $nova['from']);
        $this->assertSame('comments', $nova['to']);
        $this->assertSame('comments.id', $nova['toAttr']);
        $this->assertSame('papel_origem', $nova['fromRole']);
        $this->assertSame('papel_destino', $nova['toRole']);
    }

    /** O modelo conceitual não inventa uma FK; isso pertence à etapa relacional. */
    public function test_connecting_does_not_create_a_foreign_key_column(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('onConnect', 'comments', 'comments');

        $comments = collect($component->get('entities'))->firstWhere('id', 'comments');
        $recursiveFk = collect($comments['attributes'])->firstWhere('name', 'comments_id');

        $this->assertNull($recursiveFk);
        $this->assertSame('', $this->relacao($component->get('relations'), 'r4')['fromAttr']);
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

    /** Sem PK nem UQ no destino, não há o que referenciar — a conexão é recusada. */
    public function test_connecting_to_an_entity_without_an_identifier_is_refused(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createEntity')                                  // nasce como "e4", com PK
            ->call('cycleKey', 'e4', 'e4.id')                       // PK  → FK
            ->call('cycleKey', 'e4', 'e4.id')                       // FK  → UQ
            ->call('cycleKey', 'e4', 'e4.id')                       // UQ  → (vazio)
            ->call('onConnect', 'users', 'e4');

        $this->assertCount(3, $component->get('relations'), 'nenhuma relação nova deveria existir');

        $nodes = $component->instance()->buildNodes();
        $orfa = collect($nodes)->firstWhere('id', 'e4');
        $this->assertFalse($orfa['data']['canBeParent']);
    }

    /**
     * Excluir a entidade tem de levar junto TODA relação que a toca — inclusive
     * as desenhadas com o mouse, que antes sobreviviam órfãs na tela.
     */
    public function test_deleting_an_entity_cascades_to_hand_drawn_relations(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('onConnect', 'users', 'posts')
            ->call('deleteEntity', 'users')
            ->assertDispatched('flow:removeEdges')
            ->assertDispatched('flow:removeNodes');

        // Sobra só r2 (comments → posts): r1 e r3 tocavam users, r4 também.
        $relations = $component->get('relations');
        $this->assertCount(1, $relations);
        $this->assertSame('r2', $relations[0]['id']);
    }

    /** Apagar a coluna derruba as relações que dependiam dela. */
    public function test_removing_an_attribute_cascades_to_its_relations(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('removeAttribute', 'posts', 'posts.user_id')
            ->assertDispatched('flow:removeEdges');

        $this->assertNull($this->relacao($component->get('relations'), 'r1'));
        $this->assertCount(2, $component->get('relations'));
    }

    /**
     * Trocar a cardinalidade redesenha a aresta E a mantém selecionada.
     *
     * Trocar um marcador exige recriar a linha, e a nova nascia sem seleção —
     * o editor, que só existe enquanto há aresta selecionada, se fechava a cada
     * clique. Era isso que parecia "a troca não funcionou".
     *
     * O redesenho não usa mais flowRemoveEdges+flowAddEdges direto: os dois
     * chegam na mesma resposta HTTP e o AlpineFlow reaproveita o elemento
     * SVG sem reavaliar o marcador (bug de reatividade da lib, confirmado
     * reproduzindo com chamadas puramente client-side). Em vez disso,
     * despachamos um único evento `erd-rebuild-edge` com a aresta pronta, e
     * o JS (erd/edge-editor.js) faz remove → aguarda um frame real do
     * navegador → add, forçando o elemento a ser reconstruído do zero.
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

        $this->assertSame('cf-one-many', $this->relacao($component->get('relations'), 'r1')['childCard']);
    }

    /** Cardinalidade inventada pelo cliente é ignorada. */
    public function test_unknown_cardinality_is_rejected(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('setCardinality', 'r1', 'child', 'nao-existe');

        $this->assertSame('cf-one-many', $this->relacao($component->get('relations'), 'r1')['childCard']);
    }

    /** Inverter muda a ponta visual e escolhe uma PK válida na nova origem. */
    public function test_swapping_a_relation_moves_cardinalities_and_reselects_its_columns(): void
    {
        $component = Livewire::test(SchemaBoard::class)->call('swapRelation', 'r1');

        $r1 = $this->relacao($component->get('relations'), 'r1');
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
        $restored = $this->relacao($component->get('relations'), 'r1');
        $this->assertSame('posts', $restored['from']);
        $this->assertSame('users', $restored['to']);
        $this->assertSame('posts.user_id', $restored['fromAttr']);
        $this->assertSame('users.id', $restored['toAttr']);
    }

    public function test_swapping_requires_an_identifier_on_the_new_parent(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('cycleKey', 'posts', 'posts.id');
        $before = $this->relacao($component->get('relations'), 'r1');

        $component->call('swapRelation', 'r1')
            ->assertDispatched('erd-swap-rejected');

        $this->assertSame($before, $this->relacao($component->get('relations'), 'r1'));
    }

    public function test_swapping_a_self_relationship_still_exchanges_its_roles(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'users');
        $before = $this->relacao($component->get('relations'), 'r4');

        $component->call('swapRelation', 'r4');
        $after = $this->relacao($component->get('relations'), 'r4');

        $this->assertSame($before['toRole'], $after['fromRole']);
        $this->assertSame($before['fromRole'], $after['toRole']);
        $this->assertSame($before['parentCard'], $after['childCard']);
        $this->assertSame($before['childCard'], $after['parentCard']);
        $labels = collect($component->instance()->buildEdges())->where('data.relationId', 'r4')->pluck('label')->all();
        $this->assertSame(['Destino', 'Origem'], $labels);
    }

    /** Renomear o relacionamento troca o texto do losango via patch, sem recriar a linha. */
    public function test_renaming_a_relation_patches_the_label(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('renameRelation', 'r1', 'publica')
            ->assertDispatched('flow:update');

        $this->assertSame('publica', $this->relacao($component->get('relations'), 'r1')['name']);
    }

    /** Nome vazio não apaga o losango. */
    public function test_renaming_a_relation_to_blank_is_ignored(): void
    {
        $component = Livewire::test(SchemaBoard::class)->call('renameRelation', 'r1', '   ');

        $this->assertSame('escreve', $this->relacao($component->get('relations'), 'r1')['name']);
    }

    /** Arrastar a entidade persiste a posição, para o layout sobreviver a um reload. */
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
     * O `fitView` do AlpineFlow aborta em silêncio quando UM único node
     * publicado vem sem `dimensions`: ele tenta 10 quadros de animação e
     * simplesmente não reenquadra. O sintoma era o "Organizar quadro" redesenhar
     * o arranjo e deixar parte dos balões fora da vista, parecendo que só o F5
     * consertava. Como a falha não gera erro nem aviso, o único jeito de não
     * reintroduzi-la é garantir a invariante aqui.
     *
     * O cenário monta de propósito todos os tipos de node: entidade, losango de
     * relação comum, losango de autorrelação com as duas portas, âncora e
     * balão de atributo de relacionamento.
     */
    public function test_every_published_node_declares_its_dimensions(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('addRelationAttribute', 'r1', 'published_at', 'date')
            ->call('createSelfRelation', 'comments')
            ->call('addRelationAttribute', 'r4', 'approved_by', 'string');

        // Entidade é o único node sem `kind` no payload (o JS usa essa ausência
        // para identificá-lo). Rótulos de papel, não ids: o objetivo é garantir
        // que todo tipo de node passou pela função que declara as dimensões.
        $kinds = collect($component->instance()->buildNodes())
            ->map(fn (array $node) => $node['data']['kind'] ?? 'entity');

        // Guarda contra o cenário deixar de cobrir algum tipo de node.
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
            // Mesmo componente do quadro Relacional, mesmo rodapé.
            ->assertSeeHtml('class="rel-modal"')
            ->assertSeeHtml('class="rel-modal-actions"')
            ->assertSee('Copiar')
            ->assertSee('Baixar .json')
            ->assertSee('wire:click="downloadJson"', escape: false);

        $component->call('downloadJson')->assertFileDownloaded('modelo-er.json');
    }
}
