<?php

namespace App\Livewire;

use App\Livewire\Concerns\HandlesDiagramJsonExport;
use App\Models\Diagram;
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
 * Editor visual de modelo Entidade-Relacionamento.
 *
 * NOTAÇÃO — híbrida, no estilo ERDPlus:
 *   - a entidade é uma caixa com seus atributos;
 *   - o relacionamento é UMA aresta, com um losango no meio carregando o nome
 *     do relacionamento (vem da notação de Chen);
 *   - a cardinalidade fica nas pontas, em pé de galinha (notação IE).
 *
 * PONTOS DE CONEXÃO — a aresta é do tipo `floating`: o AlpineFlow calcula os
 * extremos pela borda das entidades e eles deslizam sozinhos quando a caixa se
 * move. Não existe handle fixo por coluna, então também não existe "handle
 * ocupado" — o que limita uma conexão é a regra semântica do modelo (a
 * entidade destino precisa ter identificador), publicada em `data.canBeParent`
 * e lida pelo `x-flow-handle-connectable` no Blade.
 *
 * AUTORIDADE DO ESTADO — tudo vive aqui no servidor, em $entities/$relations.
 * O diagrama não usa :sync; ele nasce dos nodes/edges calculados e cada mudança
 * é empurrada por comandos WireFlow (flowAddNodes / flowUpdate / flowAddEdges /
 * flowRemoveEdges). Arestas desenhadas com o mouse são interceptadas no cliente
 * e recriadas aqui com id próprio (ver resources/js/app.js), para que a limpeza
 * em cascata e o reload funcionem.
 */
#[Layout('layouts.app')]
class SchemaBoard extends Component
{
    use HandlesDiagramJsonExport;

    // A trait WithWireFlow permite enviar comandos JavaScript granulares e diretos
    // para a biblioteca de diagramas do frontend, evitando renderizações pesadas do Livewire.
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

    /** Tamanho do losango de relacionamento (`.er-relationship` no CSS). */
    private const RELATIONSHIP_WIDTH = 120.0;

    private const RELATIONSHIP_HEIGHT = 44.0;

    /** Nós invisíveis: âncora de atributos e portas do autorrelacionamento. */
    private const RELATION_ANCHOR_SIZE = 2.0;

    /**
     * Folga reservada acima de uma entidade que tem losango.
     *
     * O losango de uma autorrelação é desenhado 104px acima da entidade e o
     * balão mais próximo ainda precisa de ~115px. Com a entidade encostada no
     * topo do quadro o losango é travado em y=30, não sobra ponto livre ao
     * redor dele e o balão é mandado para o lado oposto do quadro.
     */
    private const RELATION_NODE_HEADROOM = 220.0;

    /**
     * Estrutura que guarda as entidades (tabelas) no servidor.
     *
     * @var array<int, array{id:string,name:string,x:int,y:int,attributes:array}>
     */
    public array $entities = [];

    /**
     * Estrutura que guarda os relacionamentos (arestas) no servidor.
     *
     * `name` é o texto do losango; `fromAttr`/`toAttr` guardam quais colunas
     * participam da relação, mesmo que a linha seja desenhada de borda a borda.
     *
     * @var array<int, array{id:string,name:string,from:string,fromAttr:string,to:string,toAttr:string,childCard:string,parentCard:string}>
     */
    public array $relations = [];

    /** Contador incremental para gerar IDs únicos e estáveis para novas entidades e colunas. */
    public int $seq = 0;

    /** Contador separado para IDs de relacionamento (r1, r2, ...). */
    public int $relSeq = 0;

    /** Propriedade capturada de um campo de texto (wire:model) para nomear novas entidades. */
    public string $newEntityName = '';

    /** Método executado uma única vez quando o componente é iniciado. */
    #[Locked]
    public ?int $diagramId = null;

    #[Locked]
    public ?int $relationalDiagramId = null;

    /**
     * A cópia Relacional já não corresponde a este ER.
     *
     * É apenas um sinal para o usuário decidir — nada é regenerado a partir
     * daqui. Ver `refreshRelationalSignal`.
     */
    public bool $relationalIsOutdated = false;

    public string $diagramName = 'Diagrama sem nome';

    /** Banco de destino salvo junto ao ER para sobreviver a exportações. */
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

            // Um projeto recém-criado possui `data: []`. Isso representa um
            // quadro realmente vazio, não um pedido para carregar o exemplo.
            $this->entities = array_values($diagram->data['entities'] ?? []);
            $this->relations = array_values($diagram->data['relations'] ?? []);
            $this->seq = $this->largestNumericId($this->entities, 'e');
            $this->relSeq = $this->largestNumericId($this->relations, 'r');
            foreach ($this->relations as $relation) {
                $this->seq = max($this->seq, $this->largestNumericId($relation['attributes'] ?? [], 'a'));
            }

            return;
        }

        // Entidades iniciais do modelo, com posições de tela (x, y) e atributos.
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

        // Relacionamentos do seed. O `name` é o verbo que aparece dentro do losango.
        $this->relations = [
            ['id' => 'r1', 'name' => 'escreve', 'from' => 'posts', 'fromAttr' => 'posts.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-one-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r2', 'name' => 'recebe', 'from' => 'comments', 'fromAttr' => 'comments.post_id', 'to' => 'posts', 'toAttr' => 'posts.id', 'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r3', 'name' => 'comenta', 'from' => 'comments', 'fromAttr' => 'comments.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one'],
        ];

        // Sincroniza os sequenciadores para evitar duplicidade de IDs futuros.
        $this->seq = count($this->entities);
        $this->relSeq = count($this->relations);
    }

    /**
     * Boards antigos podem ter lacunas (e4, e5, e6) após exclusões. Usar a
     * quantidade de itens faria a próxima entidade repetir e4 e o Flow
     * rejeitaria silenciosamente o nó duplicado.
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
    // Construção de nodes/edges a partir do estado do PHP para o formato da biblioteca JS
    // ---------------------------------------------------------------------

    /**
     * Transforma o array de entidades no formato de "Nodes" (Nós do Diagrama) que o frontend entende.
     *
     * @return array<int, array>
     */
    public function buildNodes(): array
    {
        $nodes = array_map(fn ($e) => ErEntityNodeProjector::node($e, $this->relations), $this->entities);

        // Os nós visuais de cada relacionamento são exatamente os mesmos que
        // `relationshipVisualNodesFor` devolve para o diff/remove — listar os
        // dois aqui e lá era a mesma regra escrita duas vezes.
        foreach ($this->relations as $relation) {
            array_push($nodes, ...$this->relationshipVisualNodesFor($relation));
        }

        return $nodes;
    }

    /**
     * Transforma as relações internas no formato de "Edges" (Linhas/Arestas) para o frontend.
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
                // Autorrelacionamento no formato clássico: o losango fica
                // centralizado acima da entidade e as duas pernas voltam aos
                // cantos superiores, formando um loop curto e simétrico.
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
     * Ponto de onde os balões de atributo desse relacionamento pendem.
     *
     * É a única fonte de verdade do vínculo entre o relacionamento e seus
     * atributos, e vale exatamente para os dois lados do modelo: o PHP grava
     * `position` do nó de âncora, e o `relationship-balloons.js` reancora o
     * mesmo nó no rótulo desenhado da aresta a cada quadro de animação. Como
     * o balão é sempre `âncora + offset`, ele continua grudado no
     * relacionamento mesmo quando as entidades se movem — inclusive depois de
     * "Organizar quadro", que só muda as coordenadas das entidades.
     *
     * Relações completas e não autorrelacionadas usam o nó de âncora (o
     * losango no meio da aresta); as demais pendem do losango.
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

            // O cliente ancora no rótulo, que é o meio do TRECHO VISÍVEL da
            // linha: da borda direita de uma entidade à borda esquerda da
            // outra, na altura do centro de cada uma. Somar a altura-base
            // fixa em vez da altura real de cada entidade errava a âncora em
            // dezenas de pixels, e como a escolha do ponto do balão é feita
            // aqui, a validação olhava para um lugar que ninguém veria.
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
     * Empurra o arranjo para baixo quando algum losango encostaria no topo.
     *
     * Desloca o arranjo INTEIRO em vez de mexer só na entidade que tem
     * losango: a translação preserva as distâncias entre todas elas, então não
     * cria sobreposição nova. `fitView` em seguida reenquadra o quadro, então a
     * descida não aparece para o usuário.
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
     * Todos os nós que um relacionamento desenha por conta própria.
     *
     * A ordem é a de publicação no canvas: losango primeiro (é ele que o clique
     * abre), depois as portas invisíveis do autorrelacionamento, a âncora dos
     * atributos e, por último, os balões — que dependem da âncora.
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
     * Todas as arestas que um relacionamento desenha: as pernas até o losango
     * (ou a linha reta, quando a relação é completa) e os fios dos balões.
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
    // Ações — Entidades
    // ---------------------------------------------------------------------

    /**
     * Cria uma nova entidade e notifica o editor visual no frontend.
     */
    public function createEntity(): void
    {
        $name = trim($this->newEntityName) ?: 'nova_entidade';
        $id = 'e'.(++$this->seq);

        // Grade de 4 colunas, começando abaixo do seed inicial. A entidade
        // (.er-node) tem 232px de largura — um passo de 26px em cascata
        // (o esquema anterior) deixava ~90% de sobreposição entre duas
        // caixas consecutivas, cobrindo fisicamente os handles da mais nova.
        // 280x220 garante folga real entre as caixas.
        $index = count($this->entities);
        $column = $index % 4;
        $row = intdiv($index, 4);

        $entity = [
            'id' => $id,
            'name' => $name,
            'x' => 40 + $column * 280,
            'y' => ($this->diagramId ? 60 : 380) + $row * 220,
            'attributes' => [
                ['id' => $id.'.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'], // toda entidade nasce identificada
            ],
        ];

        $this->entities[] = $entity;
        $this->newEntityName = ''; // limpa o input do formulário

        // Renderiza o nó dinamicamente, já registrado e arrastável.
        $this->flowAddNodes([ErEntityNodeProjector::node($entity, $this->relations)]);
    }

    /**
     * Apaga uma entidade e limpa em cascata os relacionamentos que a tocam.
     */
    public function deleteEntity(string $id): void
    {
        $this->entities = array_values(array_filter($this->entities, fn ($e) => $e['id'] !== $id));

        // Toda relação que encosta nessa entidade (como origem ou destino)
        // morre junto, e o canvas precisa saber exatamente quais arestas e nós
        // somem para não deixar linha nenhuma apontando para o vazio.
        $this->dropRelations(fn (array $r): bool => $r['from'] === $id || $r['to'] === $id);

        $this->flowRemoveNodes([$id]);
    }

    /**
     * Altera o nome de uma entidade.
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
    // Ações — Atributos
    // ---------------------------------------------------------------------

    /**
     * Adiciona um atributo dentro de uma entidade existente.
     */
    public function addAttribute(string $entityId, string $name = '', string $type = 'varchar', string $key = ''): void
    {
        $name = trim($name) ?: 'coluna';

        $this->mutateEntity($entityId, function (&$e) use ($entityId, $name, $type, $key) {
            $attrId = $entityId.'.'.$name.'_'.(++$this->seq); // sequência garante unicidade mesmo com nomes repetidos
            $e['attributes'][] = [
                'id' => $attrId,
                'name' => $name,
                'type' => $type,
                'key' => in_array($key, ['PK', 'FK', 'UQ'], true) ? $key : '', // barra valor fora do escopo
            ];
        });
    }

    /**
     * Exclui um atributo e limpa relacionamentos que dependiam dele.
     */
    public function removeAttribute(string $entityId, string $attrId): void
    {
        $this->mutateEntity($entityId, function (&$e) use ($attrId) {
            $e['attributes'] = array_values(array_filter($e['attributes'], fn ($a) => $a['id'] !== $attrId));
        });

        // Uma relação que apontava para a coluna que acabou de sumir não tem
        // mais o que representar: ela cai junto, em qualquer uma das pontas.
        $this->dropRelations(fn (array $r): bool => $r['fromAttr'] === $attrId || $r['toAttr'] === $attrId);
    }

    /**
     * Remove do estado as relações que o filtro escolher e limpa o canvas.
     *
     * Uma relação não é só um registro em $relations: ela virou arestas e nós
     * de verdade no AlpineFlow (o losango, as portas, a âncora e os balões de
     * atributo). Por isso a remoção precisa listar o que foi desenhado, senão
     * sobra lixo desenhado apontando para entidades que não existem mais.
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

        // As entidades que sobraram podem ter liberado colunas — republica o estado.
        $this->syncNodeData();
    }

    /**
     * Alterna a coluna entre "sem chave" e PK.
     *
     * Esse é o único par de estados editável na tela — FK e UQ só entram pelo
     * importador de JSON ou pela conversão, e viram PK/FK no modelo
     * relacional. O botão é o único ponto de entrada do editor, então qualquer
     * outro valor que chegue aqui é normalizado para "sem chave".
     *
     * É a PK (ou a UQ) que decide se a entidade pode receber uma relação, então
     * o estado do node precisa ser republicado: `mutateEntity` já faz isso ao
     * rebuilding the entity's node data.
     */
    public function cycleKey(string $entityId, string $attrId): void
    {
        $this->mutateEntity($entityId, function (&$e) use ($attrId) {
            foreach ($e['attributes'] as &$attribute) {
                if ($attribute['id'] === $attrId) {
                    $attribute['key'] = $attribute['key'] === 'PK' ? '' : 'PK';
                    break;
                }
            }
            unset($attribute);
        });
    }

    // ---------------------------------------------------------------------
    // Ações — Relacionamentos
    // ---------------------------------------------------------------------

    /**
     * Cria um autorrelacionamento por uma ação explícita.
     *
     * O AlpineFlow rejeita source === target durante o gesto de conexão,
     * então o próprio nó oferece esta ação e o servidor mantém as mesmas
     * validações usadas em qualquer relacionamento.
     */
    public function createSelfRelation(string $entityId): void
    {
        $this->onConnect($entityId, $entityId);
    }

    /**
     * Chega aqui quando o usuário desenha uma conexão no canvas.
     *
     * A aresta provisória que o AlpineFlow criou já foi descartada no cliente
     * (ver resources/js/app.js), então aqui nascemos a relação de verdade, com
     * id do servidor, e a devolvemos pronta para a tela.
     *
     * As colunas são escolhidas automaticamente porque a conexão é feita de
     * entidade para entidade — quem quiser refinar depois usa o botão de
     * inverter do painel do relacionamento, que troca as pontas inteiras.
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

        // Duas entidades só podem ter UM relacionamento entre si, independente
        // da direção — impede duplicar a mesma ligação ao arrastar de novo.
        if ($this->relationshipExistsBetween($sourceEntity['id'], $targetEntity['id'])) {
            return;
        }

        // Regra do modelo: só é possível referenciar quem tem identificador.
        $pk = $this->identifierOf($targetEntity);
        if ($pk === null) {
            return;
        }

        // Reaproveita a coluna que já carregaria a chave estrangeira, se existir.
        // Não cria mais uma coluna nova — se não houver candidata, a relação
        // nasce com fromAttr vazio, pronta pra ser configurada manualmente.
        $fk = $this->findExistingForeignKeyColumn($sourceEntity['id'], $targetEntity) ?? '';

        $id = 'r'.(++$this->relSeq);

        $relation = [
            'id' => $id,
            'name' => 'relaciona',
            'from' => $sourceEntity['id'],
            'fromAttr' => $fk,
            'to' => $targetEntity['id'],
            'toAttr' => $pk,
            'childCard' => 'cf-one-many', // o lado da FK costuma ser "muitos"
            'parentCard' => 'cf-one-one', // o lado da PK costuma ser "um e só um"
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
     * Troca o símbolo de cardinalidade de uma das pontas.
     *
     * @param  string  $end  'child' (lado FK) ou 'parent' (lado PK)
     */
    public function setCardinality(string $relationId, string $end, string $marker): void
    {
        if (! in_array($marker, self::CARDINALITY_MARKERS, true)) {
            return;
        }

        $campo = $end === 'parent' ? 'parentCard' : 'childCard';

        $this->mutateRelation($relationId, function (&$r) use ($campo, $marker) {
            $r[$campo] = $marker;
        });
    }

    /**
     * Renomeia o relacionamento — é o texto que aparece dentro do losango.
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

                // `label` é uma das poucas propriedades de aresta que o
                // flowUpdate consegue alterar in-place, sem recriar a linha.
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

    /** Inverte quem recebe a FK e move as cardinalidades para as novas pontas. */
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
                    // childCard e parentCard descrevem os novos papéis; ao manter
                    // os valores, os símbolos mudam de entidade no canvas.
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
            foreach ($relation['attributes'] ?? [] as $attribute) {
                if (mb_strtolower($attribute['name']) === mb_strtolower($name)) {
                    return;
                }
            }
            $relation['attributes'][] = [
                'id' => 'a'.(++$this->seq),
                'name' => $name,
                'type' => in_array($type, ['bigint', 'int', 'decimal', 'date', 'datetime', 'boolean', 'text', 'varchar'], true) ? $type : 'varchar',
                'key' => '',
            ];
        });
    }

    public function renameRelationAttribute(string $relationId, string $attributeId, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            return;
        }
        $this->mutateRelation($relationId, function (&$relation) use ($attributeId, $name) {
            foreach ($relation['attributes'] ?? [] as $attribute) {
                if ($attribute['id'] !== $attributeId && mb_strtolower($attribute['name']) === mb_strtolower($name)) {
                    return;
                }
            }
            $relation['attributes'] ??= [];
            foreach ($relation['attributes'] as &$attribute) {
                if ($attribute['id'] === $attributeId) {
                    $attribute['name'] = $name;
                    break;
                }
            }
            unset($attribute);
        });
    }

    public function removeRelationAttribute(string $relationId, string $attributeId): void
    {
        $this->mutateRelation($relationId, function (&$relation) use ($attributeId) {
            $relation['attributes'] = array_values(array_filter(
                $relation['attributes'] ?? [], fn ($attribute) => $attribute['id'] !== $attributeId,
            ));
        });
    }

    /**
     * Remove um relacionamento.
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
     * Ouvinte disparado pelo frontend quando o usuário solta uma entidade
     * em outro ponto do painel.
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

                    // Só as portas: o losango é o nó arrastado, então o
                    // AlpineFlow já devolveu a posição dele no evento.
                    if ($this->isSelfRelationship($relation)) {
                        $this->flowUpdate(['nodes' => $this->positionPatch($this->selfRelationshipPortNodesFor($relation))]);
                    }
                    break;
                }
            }

            return;
        }

        // Persiste as coordenadas para que o layout sobreviva a um reload.
        foreach ($this->entities as &$e) {
            if ($e['id'] === $nodeId) {
                $newX = (int) round($position['x'] ?? $e['x']);
                $newY = (int) round($position['y'] ?? $e['y']);
                $deltaX = $newX - $e['x'];
                $deltaY = $newY - $e['y'];

                // Arrastar a entidade leva junto o losango e as portas
                // invisíveis do autorrelacionamento que nasce nela. O losango
                // guarda a posição *desenhada* (que pode estar presa dentro dos
                // limites da entidade), então o deslocamento da entidade é
                // somado a ela, e não ao diamondX bruto.
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

    /** Busca uma entidade pelo id. */
    private function findEntity(string $id): ?array
    {
        foreach ($this->entities as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
    }

    /** Devolve o id da coluna identificadora da entidade (PK, ou UQ como alternativa). */
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

        // Colunas já comprometidas com alguma relação existente.
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
     * Aplica uma modificação numa entidade e sincroniza o node no cliente.
     *
     * O patch (flowUpdate) troca só os dados do nó, sem mexer na posição e sem
     * destruir o DOM protegido por wire:ignore.
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
     * Aplica uma modificação num relacionamento e redesenha a aresta.
     *
     * Recriar em vez de dar patch é obrigatório aqui: o update() do AlpineFlow
     * só altera color, strokeWidth, label, animated e class numa aresta — trocar
     * marcador, tipo ou pontas exige remover e adicionar de novo (mesmo id).
     *
     * NÃO usamos flowRemoveEdges()+flowAddEdges() diretamente. Os dois
     * despacham eventos Livewire separados, mas chegam na MESMA resposta
     * HTTP e o bridge do WireFlow os processa um atrás do outro, no mesmo
     * ciclo síncrono do Alpine — sem nenhum "tick" real entre a remoção e a
     * adição. Isolei isso rodando as duas chamadas direto no console do
     * navegador (sem Livewire): o array reativo `edges` fica correto (dá pra
     * conferir lendo `$flow.edges`), mas o elemento SVG que já existia para
     * aquele id é reaproveitado sem reavaliar o `marker-start`/`marker-end`
     * — o desenho da cardinalidade fica "grudado" no símbolo antigo mesmo com
     * o dado certo por trás. Inserir um `requestAnimationFrame` duplo entre
     * as duas chamadas resolve (testado manualmente), mas PHP não tem como
     * esperar um frame do navegador no meio de uma resposta.
     *
     * Por isso despachamos um único evento customizado (`erd-rebuild-edge`)
     * e quem faz o remove → aguarda dois frames → add é o JS, em
     * erd/relationship-editor.js. O servidor continua sendo autoridade do dado; só
     * a orquestração de timing do redesenho passou para o cliente.
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
     * Verifica se já existe relação entre duas entidades, em qualquer direção.
     *
     * Um modelo ER não deveria ter duas relações distintas ligando o mesmo par
     * de tabelas — isso normalmente é sinal de erro do usuário (clicou duas
     * vezes / arrastou de novo sem perceber), não uma modelagem válida.
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
     * Converte nós em patch de `flowUpdate`, no formato `id => posição`.
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
     * O losango mais as quatro portas invisíveis de um autorrelacionamento.
     *
     * Serve para quando o losango é *empurrado* junto com outra coisa (a
     * entidade): aí ele precisa ser reposicionado no canvas como os demais.
     * Quando o losango é o próprio nó arrastado, basta o patch das portas,
     * porque o AlpineFlow já devolve a posição dele.
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
     * Republica `data` de todas as entidades.
     *
     * Chamado sempre que o conjunto de relações muda, porque isso altera quais
     * colunas estão comprometidas e, por tabela, se a entidade ainda pode
     * receber uma nova ligação.
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
    // Persistência — Diagrama salvo como JSON
    // ---------------------------------------------------------------------

    /**
     * Salva (cria ou atualiza) o diagrama atual no banco, como um único
     * registro JSON.
     *
     * Não existe um Model por entidade/relação — o par $entities/$relations
     * inteiro é serializado de uma vez em `diagrams.data` (o cast `array` no
     * Model Diagram cuida da conversão JSON <-> array). Isso é proposital:
     * o estado já é a fonte da verdade em memória (ver nota "AUTORIDADE DO
     * ESTADO" no topo da classe), então persistir é só um dump desse estado,
     * sem remodelar em tabelas relacionais separadas.
     *
     * Se `$diagramId` já estiver preenchido (diagrama carregado ou salvo
     * antes nesta sessão), atualiza o registro existente; caso contrário,
     * cria um novo e passa a lembrar o id dele — assim cliques seguintes em
     * "Salvar" viram UPDATE, e não ficam criando registros duplicados.
     */
    public function save(): void
    {
        $this->persistDiagram();

        // Evento ouvido no Blade (Alpine, via @saved.window) para exibir o
        // selo "✅ Salvo!" por alguns segundos. O .window é necessário porque
        // o Livewire despacha o evento no nível global do navegador, não só
        // dentro do escopo do componente.

        $this->dispatch('saved'); // pra mostrar um toast/feedback no front, se quiser
    }

    /**
     * Reorganiza o quadro: entidades, losangos e balões de atributo.
     *
     * As relações completas continuam sendo as arestas clássicas
     * floating/smoothstep e se recalculam pelo canvas.
     *
     * O arranjo é gravado sozinho. Reorganizar sem persistir deixaria a
     * posição na tela divergindo do diagrama salvo, e o próximo re-render do
     * Livewire devolveria o quadro inteiro — entidades e balões — ao layout
     * antigo.
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
            // O ER privilegia a leitura do domínio. Os balões de atributos
            // podem receber pequenos ajustes manuais depois, mas as entidades
            // e relações precisam nascer com corredores amplos.
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

        // No fluxo antigo, nós de relacionamento só existem para relações
        // incompletas ou autorrelacionamentos. Preserve o deslocamento manual
        // desses losangos em relação à entidade a que pertencem.
        foreach ($this->relations as &$relation) {
            if (! $this->usesRelationshipNode($relation)) {
                continue;
            }

            $entityId = $relation['from'] ?: $relation['to'];

            // Sem losango fixado, a posição é derivada da entidade e já mudou
            // com o arranjo. Guardá-la aqui congelaria o losango (e os
            // atributos que pendem dele) na coordenada antiga.
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
     * Salva o ER atual e abre sua cópia relacional já regenerada.
     * Assim a conversão nunca usa um snapshot antigo nem exige voltar antes
     * ao dashboard para encontrar o botão da Etapa 2.
     */
    public function convertToRelational(RelationalDiagramCreator $copy): void
    {
        $source = $this->persistDiagram();

        $relational = $copy->findOrCreate($source);

        $this->redirectRoute('boards.relational', $relational, navigate: true);
    }

    /**
     * Atualiza o sinal "a cópia Relacional está defasada" da aba do topo.
     *
     * A comparação usa o estado em memória (`entities`/`relations`), inclusive
     * antes de salvar. É chamada uma vez por renderização do quadro ER.
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
     * Monta o JSON formatado (indentado, em UTF-8 sem escapar acentos) do
     * estado atual, para exibir dentro do modal.
     *
     * É uma computed property do Livewire (prefixo `get` / sufixo
     * `Property`): fica acessível na view como `$this->jsonPreview` e é
     * recalculada a cada render, sempre refletindo o estado mais recente de
     * $entities/$relations.
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

    /** Nome do arquivo baixado pelo botão "Baixar .json" do modal. */
    protected function jsonFileName(): string
    {
        return 'modelo-er.json';
    }

    /**
     * Renderiza o template Blade do componente, injetando as coleções iniciais.
     */
    public function render(RelationalDiagramDriftAnalyzer $drift): View
    {
        if ($this->diagramId !== null) {
            $source = Diagram::query()
                ->where('type', Diagram::TYPE_ENTITY_RELATIONSHIP)
                ->findOrFail($this->diagramId);
            // Compara o estado em memória para acender o aviso já na edição,
            // antes do clique em Salvar.
            $this->refreshRelationalSignal($drift, $source);
        }

        return view('livewire.schema-board', [
            'nodes' => $this->buildNodes(),
            'edges' => $this->buildEdges(),
        ]);
    }
}
