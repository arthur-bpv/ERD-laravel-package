<?php

namespace Tests\Feature;

use App\Livewire\SchemaBoard;
use App\Support\ErdFileStore;
use Livewire\Livewire;
use Illuminate\Support\Facades\Storage;
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
        $response->assertSee('Modelador Entidade-Relacionamento');
    }

    /**
     * Toda aresta sai daqui como `floating` — é isso que faz a linha encostar
     * na borda da entidade e deslizar quando a caixa é arrastada, em vez de
     * ficar presa a um ponto fixo.
     */
    public function test_edges_are_floating(): void
    {
        $edges = Livewire::test(SchemaBoard::class)->instance()->buildEdges();

        $this->assertNotEmpty($edges);
        foreach ($edges as $edge) {
            $this->assertSame('floating', $edge['type']);
            $this->assertSame('smoothstep', $edge['pathType']);
            $this->assertArrayNotHasKey('sourceHandle', $edge);
            $this->assertArrayNotHasKey('targetHandle', $edge);
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

    /** O losango carrega o nome do relacionamento — é o label do meio da linha. */
    public function test_relationship_name_becomes_the_edge_label(): void
    {
        $edges = Livewire::test(SchemaBoard::class)->instance()->buildEdges();

        $this->assertSame('escreve', $edges[0]['label']);
        $this->assertSame('user_id', $edges[0]['labelStart']);
        $this->assertSame('id', $edges[0]['labelEnd']);
    }

    /**
     * Uma entidade só pode receber relacionamento se tiver identificador.
     * É esse dado que o x-flow-handle-connectable lê no Blade — foi o que
     * substituiu a contagem de handles ocupados.
     */
    public function test_nodes_publish_whether_they_can_be_referenced(): void
    {
        $nodes = Livewire::test(SchemaBoard::class)->instance()->buildNodes();

        foreach ($nodes as $node) {
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
            ->call('onConnect', 'users', 'posts', 's:right', 't:left')
            ->assertDispatched('flow:addEdges');

        $relations = $component->get('relations');
        $this->assertCount(4, $relations);

        $nova = $this->relacao($relations, 'r4');
        $this->assertNotNull($nova, 'a relação deveria ter recebido o id do servidor');
        $this->assertSame('users', $nova['from']);
        $this->assertSame('posts', $nova['to']);
        $this->assertSame('posts.id', $nova['toAttr']);
    }

    /**
     * Como a ligação é feita de entidade para entidade, a coluna que carrega a
     * chave estrangeira é criada automaticamente na entidade filha.
     */
    public function test_connecting_creates_the_foreign_key_column(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('onConnect', 'users', 'posts', 's:right', 't:left');

        $users = collect($component->get('entities'))->firstWhere('id', 'users');
        $fk = collect($users['attributes'])->firstWhere('name', 'posts_id');

        $this->assertNotNull($fk, 'a coluna FK deveria ter sido criada');
        $this->assertSame('FK', $fk['key']);
        $this->assertSame('bigint', $fk['type']);
    }

    /** Sem PK nem UQ no destino, não há o que referenciar — a conexão é recusada. */
    public function test_connecting_to_an_entity_without_an_identifier_is_refused(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createEntity')                                  // nasce como "e4", com PK
            ->call('cycleKey', 'e4', 'e4.id')                       // PK  → FK
            ->call('cycleKey', 'e4', 'e4.id')                       // FK  → UQ
            ->call('cycleKey', 'e4', 'e4.id')                       // UQ  → (vazio)
            ->call('onConnect', 'users', 'e4', 's:right', 't:left');

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
            ->call('onConnect', 'users', 'posts', 's:right', 't:left')
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
                return $params['edge']['id'] === 'r1'
                    && $params['edge']['markerStart']['type'] === 'cf-one-many'
                    && $params['select'] === true;
            });

        $this->assertSame('cf-one-many', $this->relacao($component->get('relations'), 'r1')['childCard']);
    }

    /** Cardinalidade inventada pelo cliente é ignorada. */
    public function test_unknown_cardinality_is_rejected(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('setCardinality', 'r1', 'child', 'nao-existe');

        $this->assertSame('cf-many', $this->relacao($component->get('relations'), 'r1')['childCard']);
    }

    /** Inverter troca as duas pontas por completo: entidades, colunas e símbolos. */
    public function test_swapping_a_relation_inverts_both_ends(): void
    {
        $component = Livewire::test(SchemaBoard::class)->call('swapRelation', 'r1');

        $r1 = $this->relacao($component->get('relations'), 'r1');
        $this->assertSame('users', $r1['from']);
        $this->assertSame('posts', $r1['to']);
        $this->assertSame('users.id', $r1['fromAttr']);
        $this->assertSame('posts.user_id', $r1['toAttr']);
        $this->assertSame('cf-one-one', $r1['childCard']);
        $this->assertSame('cf-many', $r1['parentCard']);
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

    /** Trocar a coluna de uma ponta atualiza a relação e redesenha a aresta. */
    public function test_setting_relation_attr_changes_the_column(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('setRelationAttr', 'r1', 'to', 'users.email')
            ->assertDispatched('erd-rebuild-edge');

        $this->assertSame('users.email', $this->relacao($component->get('relations'), 'r1')['toAttr']);
    }

    /** Coluna que não pertence à entidade daquela ponta é recusada. */
    public function test_setting_relation_attr_to_a_column_from_another_table_is_rejected(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('setRelationAttr', 'r1', 'to', 'comments.body');

        $this->assertSame('users.id', $this->relacao($component->get('relations'), 'r1')['toAttr']);
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

    // ---------------------------------------------------------------------
    // Modo de tela — troca de arquivo de verdade (App\Support\ErdFileStore)
    // ---------------------------------------------------------------------

    /**
     * Entrar no modo relacional GRAVA o diagrama ER em arquivo e CRIA o
     * arquivo do schema relacional — não é só virar uma flag em memória.
     */
    public function test_entering_relational_mode_saves_the_diagram_file_and_creates_the_schema_file(): void
    {
        Storage::fake('local');

        $component = Livewire::test(SchemaBoard::class)->call('toggleViewMode');

        $this->assertSame('relational', $component->get('viewMode'));

        Storage::disk('local')->assertExists(ErdFileStore::caminhoDiagrama());
        Storage::disk('local')->assertExists(ErdFileStore::caminhoSchemaRelacional());

        $diagrama = json_decode(Storage::disk('local')->get(ErdFileStore::caminhoDiagrama()), true);
        $this->assertCount(3, $diagrama['entities']);
        $this->assertCount(3, $diagrama['relations']);

        $schema = json_decode(Storage::disk('local')->get(ErdFileStore::caminhoSchemaRelacional()), true);
        $this->assertNotEmpty($schema['tables']);
        $this->assertNotEmpty($schema['links']);
    }

    /** A tela em modo relacional mostra o que está de fato gravado no arquivo recém-aberto. */
    public function test_relational_canvas_reflects_the_opened_file(): void
    {
        Storage::fake('local');

        $component = Livewire::test(SchemaBoard::class)->call('toggleViewMode');

        $tables = collect($component->instance()->buildRelationalNodes());
        $this->assertNull($tables->first(fn ($n) => $n['data']['isAssociative'] ?? false));

        $edges = collect($component->instance()->buildRelationalEdges());
        $r1 = $edges->firstWhere('id', 'r1');
        $this->assertNotNull($r1);
        $this->assertArrayNotHasKey('label', $r1);
        $this->assertArrayNotHasKey('markerStart', $r1);
        $this->assertSame('arrow', $r1['markerEnd']['type']);
    }

    /**
     * Relação N:M (as duas pontas em "muitos"): o arquivo criado tem uma
     * tabela associativa com chave primária composta, e a FK que só existia
     * pra sustentar a relação sai da tabela original.
     */
    public function test_relational_file_gets_an_associative_table_for_a_many_to_many_relation(): void
    {
        Storage::fake('local');

        $component = Livewire::test(SchemaBoard::class)
            ->call('setCardinality', 'r1', 'parent', 'cf-one-many') // agora as duas pontas de r1 são "muitos"
            ->call('toggleViewMode');

        $tables = collect($component->instance()->buildRelationalNodes());

        $associativa = $tables->first(fn ($n) => $n['data']['isAssociative'] ?? false);
        $this->assertNotNull($associativa, 'deveria ter nascido uma tabela associativa para a relação N:M');
        $this->assertSame('assoc_r1', $associativa['id']);

        $this->assertCount(2, $associativa['data']['attributes']);
        foreach ($associativa['data']['attributes'] as $coluna) {
            $this->assertSame('PK', $coluna['key']);
            $this->assertTrue($coluna['fk']);
        }

        $posts = $tables->firstWhere('id', 'posts');
        $this->assertNull(collect($posts['data']['attributes'])->firstWhere('id', 'posts.user_id'));

        $edges = collect($component->instance()->buildRelationalEdges());
        $this->assertNull($edges->firstWhere('id', 'r1'));
        $this->assertNotNull($edges->firstWhere('id', 'r1:a'));
        $this->assertNotNull($edges->firstWhere('id', 'r1:b'));
    }

    /**
     * Voltar pro modo ER ABRE (lê) o arquivo do diagrama — não só desfaz a
     * flag. Simula outra sessão tendo sobrescrito o arquivo enquanto o
     * usuário estava vendo o schema relacional, e confirma que o que volta
     * pra tela é o CONTEÚDO DO ARQUIVO, não o que já estava em memória.
     */
    public function test_returning_to_er_mode_opens_whatever_is_currently_in_the_diagram_file(): void
    {
        Storage::fake('local');

        $component = Livewire::test(SchemaBoard::class)->call('toggleViewMode'); // er -> relational (salva o arquivo)

        // "outra sessão" grava o arquivo com um diagrama diferente
        ErdFileStore::salvarDiagrama(
            entities: [['id' => 'x', 'name' => 'tabela_de_fora', 'x' => 0, 'y' => 0, 'attributes' => [
                ['id' => 'x.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'],
            ]]],
            relations: [],
            seq: 99,
            relSeq: 5,
        );

        $component->call('toggleViewMode'); // relational -> er (deveria abrir o arquivo, não o que já tinha em memória)

        $this->assertSame('er', $component->get('viewMode'));
        $this->assertCount(1, $component->get('entities'));
        $this->assertSame('tabela_de_fora', $component->get('entities')[0]['name']);
        $this->assertSame(99, $component->get('seq'));
    }

    /** "Ver JSON" mostra o schema relacional (não o diagrama ER) enquanto esse modo está ativo. */
    public function test_json_preview_shows_the_relational_schema_while_in_relational_mode(): void
    {
        Storage::fake('local');

        $component = Livewire::test(SchemaBoard::class)->call('toggleViewMode'); // er -> relational

        $json = json_decode($component->instance()->getJsonPreviewProperty(), true);

        $this->assertArrayHasKey('tables', $json);
        $this->assertArrayHasKey('links', $json);
        $this->assertArrayNotHasKey('entities', $json);
        $this->assertArrayNotHasKey('relations', $json);

        $component->call('toggleViewMode'); // relational -> er

        $json = json_decode($component->instance()->getJsonPreviewProperty(), true);
        $this->assertArrayHasKey('entities', $json);
        $this->assertArrayHasKey('relations', $json);
    }
}
