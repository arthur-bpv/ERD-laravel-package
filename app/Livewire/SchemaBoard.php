<?php

namespace App\Livewire;

use App\Livewire\Concerns\InterageComJson;
use App\Models\Diagram;
use App\Services\RelationalCopy;
use App\Services\RelationalDrift;
use App\Support\BoardLayout;
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
    use InterageComJson;
    // A trait WithWireFlow permite enviar comandos JavaScript granulares e diretos
    // para a biblioteca de diagramas do frontend, evitando renderizações pesadas do Livewire.
    use WithWireFlow;

    /**
     * Recuo da ponta da linha em relação à borda da entidade, em pixels.
     *
     * Precisa ser ZERO. O `offset` do AlpineFlow empurra o fim do traço para
     * FORA do nó, e o símbolo é desenhado a partir dali para trás — então
     * qualquer valor positivo vira um vão visível entre o pé de galinha e a
     * caixa. O padrão da biblioteca (12,5px, tamanho de uma seta comum) é
     * justamente o que causava o afastamento.
     *
     * Os símbolos já nascem inteiramente atrás da âncora (o viewBox vai de -40
     * a 0 em x), então com offset 0 a ponta encosta na borda e o desenho corre
     * por cima da linha, sem sobrar espaço nem invadir a entidade.
     */
    private const MARKER_OFFSET = 0;

    /**
     * Cardinalidades aceitas — usado para barrar valor inválido vindo do cliente.
     *
     * A lista tem de ser idêntica à de `CARDINALIDADES` em `resources/js/erd/markers.js`:
     * um nome só em um dos lados falha em silêncio (a aresta vira uma seta comum).
     */
    private const CARDINALIDADES = [
        'cf-one-one', 'cf-zero-one',
        'cf-one-many', 'cf-zero-many',
    ];

    /** Cor padrão das relações. */
    private const COR_RELACAO = '#64748b';

    private const ENTITY_WIDTH = 232.0;

    private const ENTITY_BASE_HEIGHT = 84.0;

    private const ENTITY_ROW_HEIGHT = 24.5;

    /**
     * Tamanho do balão de atributo de relacionamento (`.er-relation-attr` no
     * CSS). O servidor precisa conhecê-lo para escolher um ponto que não caia
     * por cima de uma entidade quando o quadro é organizado — e para declarar
     * as dimensões do nó, senão o `fitView` do canvas desiste de reenquadrar
     * (ver a nota de `dimensions` em `relationshipNodeFor`).
     */
    private const RELATION_ATTRIBUTE_WIDTH = 136.0;

    private const RELATION_ATTRIBUTE_HEIGHT = 58.0;

    /** Tamanho do losango de relacionamento (`.er-relationship` no CSS). */
    private const RELATIONSHIP_WIDTH = 120.0;

    private const RELATIONSHIP_HEIGHT = 44.0;

    /**
     * Tamanho dos nós invisíveis: âncora de atributos e portas do
     * autorrelacionamento. Não aparecem, mas também precisam de `dimensions`.
     */
    private const RELATION_ANCHOR_SIZE = 2.0;

    /**
     * Folga entre o balão e o que ele não pode cobrir: entidades e outros
     * balões. Também é o respiro entre dois balões vizinhos, e por isso que
     * os passos da grade têm de respeitar largura/altura + 2*folga.
     */
    private const RELATION_ATTRIBUTE_PADDING = 28.0;

    /**
     * Passos horizontais tentados ao redor da âncora, em px.
     *
     * Precisam ser >= largura + 2*folga (136 + 56 = 192). Com um passo menor,
     * dois balões vizinhos se bloqueavam sozinhos e a busca pulava direto para
     * o anel de fora, espalhando o grupo pelo quadro.
     */
    private const RELATION_ATTRIBUTE_COLUMNS = [0, 200, -200, 400, -400, 600, -600];

    /**
     * Passos verticais tentados ao redor da âncora, em px.
     *
     * Mesma conta: altura + 2*folga = 58 + 56 = 114.
     */
    private const RELATION_ATTRIBUTE_ROWS = [0, 120, -120, 240, -240, 360, -360];

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

    public function mount($diagram = null, ?RelationalDrift $drift = null): void
    {
        if ($diagram) {
            $diagram = $diagram instanceof Diagram ? $diagram : Diagram::findOrFail($diagram);

            abort_unless($diagram->type === Diagram::TYPE_ENTITY_RELATIONSHIP, 404);

            $this->diagramId = $diagram->id;
            $this->diagramName = $diagram->name;

            // Um projeto recém-criado possui `data: []`. Isso representa um
            // quadro realmente vazio, não um pedido para carregar o exemplo.
            $this->entities = array_values($diagram->data['entities'] ?? []);
            $this->relations = array_values($diagram->data['relations'] ?? []);
            $this->seq = $this->largestNumericId($this->entities, 'e');
            $this->relSeq = $this->largestNumericId($this->relations, 'r');
            foreach ($this->relations as $relation) {
                $this->seq = max($this->seq, $this->largestNumericId($relation['attributes'] ?? [], 'a'));
            }

            if ($drift instanceof RelationalDrift) {
                $this->refreshRelationalSignal($drift, $diagram);
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
        $nodes = array_map(fn ($e) => $this->nodeFor($e), $this->entities);

        // Os nós visuais de cada relacionamento são exatamente os mesmos que
        // `relationshipVisualNodesFor` devolve para o diff/remove — listar os
        // dois aqui e lá era a mesma regra escrita duas vezes.
        foreach ($this->relations as $relation) {
            array_push($nodes, ...$this->relationshipVisualNodesFor($relation));
        }

        return $nodes;
    }

    /**
     * Monta um node completo, já com os metadados semânticos que o Blade usa
     * para liberar ou bloquear conexões.
     *
     * `dimensions` é obrigatório em TODO node publicado, não só nas entidades:
     * o `fitView` do AlpineFlow aborta (e só aborta, sem erro) se encontrar
     * um único node sem dimensão. Sem isso, o "Organizar quadro" redesenha o
     * arranjo e nunca reenquadra — os balões ficavam fora da vista e era
     * preciso dar F5 para o quadro voltar a aparecer inteiro.
     */
    private function nodeFor(array $e): array
    {
        return [
            'id' => $e['id'],
            'position' => ['x' => $e['x'], 'y' => $e['y']],
            'dimensions' => $this->entityDimensions($e),
            'data' => $this->nodeData($e),
        ];
    }

    private function entityDimensions(array $entity): array
    {
        return [
            'width' => self::ENTITY_WIDTH,
            'height' => self::ENTITY_BASE_HEIGHT + (count($entity['attributes'] ?? []) * self::ENTITY_ROW_HEIGHT),
        ];
    }

    /**
     * Payload de `data` de um node.
     *
     * Além de nome e atributos, publica o estado de conexão da entidade. É esse
     * bloco que substitui a antiga "contagem de handles": em vez de reservar um
     * ponto físico por coluna, dizemos ao canvas o que o modelo permite.
     *
     *   canBeParent  — tem identificador (PK/UQ), então pode receber relação
     *   usedAttrs    — colunas já comprometidas com alguma relação
     */
    private function nodeData(array $e): array
    {
        $usadas = [];

        foreach ($this->relations as $r) {
            if ($r['from'] === $e['id']) {
                $usadas[] = $r['fromAttr'];
            }
            if ($r['to'] === $e['id']) {
                $usadas[] = $r['toAttr'];
            }
        }

        $temIdentificador = false;
        foreach ($e['attributes'] as $a) {
            if ($a['key'] === 'PK' || $a['key'] === 'UQ') {
                $temIdentificador = true;
                break;
            }
        }

        return [
            'name' => $e['name'],
            'attributes' => array_values($e['attributes']),
            'canBeParent' => $temIdentificador,
            'usedAttrs' => array_values(array_unique($usadas)),
        ];
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
            'color' => self::COR_RELACAO,
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
                'labelStart' => $this->nomeCurto($relation['fromAttr']),
                'markerStart' => $this->marker($relation['childCard']),
            ];
        }
        if ($target) {
            $edges[] = $base + [
                'id' => $relation['id'].':in',
                'source' => $isSelfRelationship ? $selfPorts['diamondIn'] : $diamondId,
                'target' => $isSelfRelationship ? $selfPorts['entityIn'] : $relation['to'],
                ...($isSelfRelationship ? ['label' => $this->selfRoleLabel($relation['toRole'] ?? null, 'Destino')] : []),
                'labelEnd' => $this->nomeCurto($relation['toAttr']),
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
            'color' => self::COR_RELACAO,
            'strokeWidth' => 1.6,
            'interactionWidth' => 34,
            'label' => $relation['name'],
            'labelStart' => $this->nomeCurto($relation['fromAttr']),
            'labelEnd' => $this->nomeCurto($relation['toAttr']),
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
            $fromHeight = $this->entityDimensions($from)['height'];
            $toHeight = $this->entityDimensions($to)['height'];

            // O cliente ancora no rótulo, que é o meio do TRECHO VISÍVEL da
            // linha: da borda direita de uma entidade à borda esquerda da
            // outra, na altura do centro de cada uma. Somar a altura-base
            // fixa em vez da altura real de cada entidade errava a âncora em
            // dezenas de pixels, e como a escolha do ponto do balão é feita
            // aqui, a validação olhava para um lugar que ninguém veria.
            return [
                'x' => (int) round((($from['x'] ?? 0) + ($to['x'] ?? 0) + self::ENTITY_WIDTH) / 2),
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
            $default = $this->defaultRelationshipAttributeOffset($index);
            $offsetX = $attribute['offsetX'] ?? $default['x'];
            $offsetY = $attribute['offsetY'] ?? $default['y'];

            $nodes[] = [
                'id' => $this->relationshipAttributeNodeId($relation['id'], $attribute['id']),
                'position' => [
                    'x' => $anchor['x'] + $offsetX,
                    'y' => $anchor['y'] + $offsetY,
                ],
                'dimensions' => [
                    'width' => self::RELATION_ATTRIBUTE_WIDTH,
                    'height' => self::RELATION_ATTRIBUTE_HEIGHT,
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
     * Ponto de partida do balão ainda não posicionado pelo usuário.
     *
     * O candidato é o canto superior esquerdo do balão; a âncora é o centro do
     * balão desejado, então o meio é descontado. A primeira opção fica abaixo
     * da linha — o corredor entre duas entidades é horizontal, e é o único
     * lugar livre que sobra quando o quadro é organizado.
     *
     * @return array{x:int,y:int}
     */
    private function defaultRelationshipAttributeOffset(int $index): array
    {
        $candidates = $this->relationshipAttributeOffsetCandidates();
        $candidate = $candidates[min($index, count($candidates) - 1)];

        return [
            'x' => (int) round($candidate['x'] - (self::RELATION_ATTRIBUTE_WIDTH / 2)),
            'y' => (int) round($candidate['y'] - (self::RELATION_ATTRIBUTE_HEIGHT / 2)),
        ];
    }

    /**
     * Candidatos de posição do balão, do mais para o menos desejável.
     *
     * A ordem é a parte que importa, e ela é por DISTÂNCIA à âncora — não por
     * faixa. Ordenar por faixa (esvaziar toda a linha de baixo, depois a de
     * cima, depois as laterais) fazia o balão aceitar um ponto a 450px de lado
     * antes mesmo de testar o ponto logo acima da linha, que estava livre: o
     * balão acabava do outro lado do quadro e a linha de ligação apontava
     * para lugar nenhum. Aqui o mais próximo sempre vem antes, então o
     * vinculo com o relacionamento é o mais curto possível.
     *
     * O desempate prefere a faixa de baixo (a que sobra livre num corredor
     * horizontal) e, dentro dela, o menor deslocamento lateral.
     *
     * @return array<int, array{x:int,y:int}> candidatos relativos ao centro da âncora
     */
    private function relationshipAttributeOffsetCandidates(): array
    {
        static $candidates = null;

        if ($candidates !== null) {
            return $candidates;
        }

        $candidates = [];
        foreach (self::RELATION_ATTRIBUTE_ROWS as $row) {
            foreach (self::RELATION_ATTRIBUTE_COLUMNS as $column) {
                if ($row === 0 && $column === 0) {
                    continue;
                }

                $candidates[] = ['x' => $column, 'y' => $row];
            }
        }

        usort($candidates, fn (array $left, array $right): int => [
            ($left['x'] ** 2) + ($left['y'] ** 2),
            $left['y'] < 0 ? 1 : 0,
            abs($left['x']),
        ] <=> [
            ($right['x'] ** 2) + ($right['y'] ** 2),
            $right['y'] < 0 ? 1 : 0,
            abs($right['x']),
        ]);

        return $candidates;
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

    /**
     * Recoloca os balões de atributo depois que as entidades mudaram de lugar.
     *
     * Só o offset é reescrito — ele continua medido a partir da âncora do
     * próprio relacionamento, então o balão não "desgruda": ele só troca o
     * ponto do offset que não está mais livre. Gravar o offset (e não a
     * posição absoluta) é o que faz o arranjo sobreviver a um reload e a
     * arrastar a entidade de novo.
     */
    private function placeRelationshipAttributes(): void
    {
        $occupied = [];
        foreach ($this->entities as $entity) {
            $dimensions = $this->entityDimensions($entity);
            $occupied[] = $this->paddedRectangle(
                $entity['x'],
                $entity['y'],
                $dimensions['width'],
                $dimensions['height'],
            );
        }

        $candidates = $this->relationshipAttributeOffsetCandidates();

        foreach ($this->relations as $relationIndex => $relation) {
            $anchor = $this->relationshipAttributeAnchorPosition($relation);
            $candidatesLeft = $candidates;

            foreach ($relation['attributes'] ?? [] as $index => $attribute) {
                $offset = $this->firstFreeRelationshipAttributeOffset(
                    $anchor,
                    $candidatesLeft,
                    $occupied,
                );

                // O candidato escolhido não pode ser reaproveitado por outro
                // balão: sai da lista e entra como área ocupada.
                $candidatesLeft = array_values(array_filter(
                    $candidatesLeft,
                    fn (array $candidate): bool => $candidate !== $offset,
                ));
                $occupied[] = $this->paddedRectangle(
                    $anchor['x'] + $offset['x'],
                    $anchor['y'] + $offset['y'],
                    self::RELATION_ATTRIBUTE_WIDTH,
                    self::RELATION_ATTRIBUTE_HEIGHT,
                );

                $this->relations[$relationIndex]['attributes'][$index]['offsetX'] = $offset['x'];
                $this->relations[$relationIndex]['attributes'][$index]['offsetY'] = $offset['y'];
            }
        }
    }

    /**
     * @param  array<int, array{x:int,y:int}>  $candidates  offsets candidatos, em coordenadas de canto superior esquerdo
     * @param  array<int, array{left:float,right:float,top:float,bottom:float}>  $occupied
     * @return array{x:int,y:int}
     */
    private function firstFreeRelationshipAttributeOffset(array $anchor, array $candidates, array $occupied): array
    {
        foreach ($candidates as $candidate) {
            $rectangle = $this->paddedRectangle(
                $anchor['x'] + $candidate['x'],
                $anchor['y'] + $candidate['y'],
                self::RELATION_ATTRIBUTE_WIDTH,
                self::RELATION_ATTRIBUTE_HEIGHT,
            );

            if (! $this->overlapsAny($rectangle, $occupied)) {
                return $candidate;
            }
        }

        // Nenhum ponto livre: mantém o último candidato em vez de devolver
        // zero, que esconderia o balão exatamente em cima da âncora.
        return $candidates === [] ? ['x' => 0, 'y' => 120] : end($candidates);
    }

    /**
     * @param  array<int, array{left:float,right:float,top:float,bottom:float}>  $rectangles
     * @return array{left:float,right:float,top:float,bottom:float}
     */
    private function paddedRectangle(float $x, float $y, float $width, float $height): array
    {
        $padding = self::RELATION_ATTRIBUTE_PADDING;

        return [
            'left' => $x - $padding,
            'right' => $x + $width + $padding,
            'top' => $y - $padding,
            'bottom' => $y + $height + $padding,
        ];
    }

    /**
     * @param  array{left:float,right:float,top:float,bottom:float}  $rectangle
     * @param  array<int, array{left:float,right:float,top:float,bottom:float}>  $rectangles
     */
    private function overlapsAny(array $rectangle, array $rectangles): bool
    {
        foreach ($rectangles as $other) {
            if ($rectangle['left'] < $other['right']
                && $rectangle['right'] > $other['left']
                && $rectangle['top'] < $other['bottom']
                && $rectangle['bottom'] > $other['top']) {
                return true;
            }
        }

        return false;
    }

    private function relationshipAttributeEdgesFor(array $relation): array
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

    /**
     * Descreve um marcador de cardinalidade como array em vez de string.
     *
     * Passar só o nome do marcador deixaria o AlpineFlow aplicar o recuo padrão
     * de 12,5px na ponta da linha, abrindo um vão entre o símbolo e a caixa da
     * entidade. Com o offset explícito em zero, o pé de galinha encosta.
     */
    private function marker(string $tipo): array
    {
        return [
            'type' => $tipo,
            'offset' => self::MARKER_OFFSET,
            'color' => self::COR_RELACAO,
        ];
    }

    /** 'posts.user_id' → 'user_id' (o que aparece na ponta da linha). */
    private function nomeCurto(string $attrId): string
    {
        $pos = strrpos($attrId, '.');

        return $pos === false ? $attrId : substr($attrId, $pos + 1);
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
        $indice = count($this->entities);
        $coluna = $indice % 4;
        $linha = intdiv($indice, 4);

        $entity = [
            'id' => $id,
            'name' => $name,
            'x' => 40 + $coluna * 280,
            'y' => ($this->diagramId ? 60 : 380) + $linha * 220,
            'attributes' => [
                ['id' => $id.'.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'], // toda entidade nasce identificada
            ],
        ];

        $this->entities[] = $entity;
        $this->newEntityName = ''; // limpa o input do formulário

        // Renderiza o nó dinamicamente, já registrado e arrastável.
        $this->flowAddNodes([$this->nodeFor($entity)]);
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
     * recarregar `nodeData()` da entidade.
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

        $origem = $this->findEntity($source);
        $destino = $this->findEntity($target);

        if (! $origem || ! $destino) {
            return;
        }

        // Duas entidades só podem ter UM relacionamento entre si, independente
        // da direção — impede duplicar a mesma ligação ao arrastar de novo.
        if ($this->relacaoExisteEntre($origem['id'], $destino['id'])) {
            return;
        }

        // Regra do modelo: só é possível referenciar quem tem identificador.
        $pk = $this->identificadorDe($destino);
        if ($pk === null) {
            return;
        }

        // Reaproveita a coluna que já carregaria a chave estrangeira, se existir.
        // Não cria mais uma coluna nova — se não houver candidata, a relação
        // nasce com fromAttr vazio, pronta pra ser configurada manualmente.
        $fk = $this->buscarColunaFkExistente($origem['id'], $destino) ?? '';

        $id = 'r'.(++$this->relSeq);

        $relacao = [
            'id' => $id,
            'name' => 'relaciona',
            'from' => $origem['id'],
            'fromAttr' => $fk,
            'to' => $destino['id'],
            'toAttr' => $pk,
            'childCard' => 'cf-one-many', // o lado da FK costuma ser "muitos"
            'parentCard' => 'cf-one-one', // o lado da PK costuma ser "um e só um"
        ];

        if ($source === $target) {
            $relacao['fromRole'] = 'papel_origem';
            $relacao['toRole'] = 'papel_destino';
        }

        $this->relations[] = $relacao;

        if ($this->isSelfRelationship($relacao)) {
            $this->flowAddNodes([
                $this->relationshipNodeFor($relacao),
                ...$this->selfRelationshipPortNodesFor($relacao),
            ]);
        }
        $this->flowAddEdges($this->edgesForRelation($relacao));
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
                $identifier = $this->identificadorDe($entity);
                if ($identifier === null) {
                    return;
                }
                $relation['to'] = $entityId;
                $relation['toAttr'] = $identifier;
                if ($relation['from']) {
                    $relation['fromAttr'] = $this->buscarColunaFkExistente($relation['from'], $entity) ?? '';
                }
            } else {
                $relation['from'] = $entityId;
                if ($relation['to'] && ($target = $this->findEntity($relation['to']))) {
                    $relation['fromAttr'] = $this->buscarColunaFkExistente($entityId, $target) ?? '';
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
        if (! in_array($marker, self::CARDINALIDADES, true)) {
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
                $identifier = $newParent ? $this->identificadorDe($newParent) : null;
                if (! $identifier || ! $newChild) {
                    $this->dispatch('erd-swap-rejected', relationId: $relationId,
                        message: 'A nova entidade de destino precisa de uma coluna PK ou UQ.');

                    return;
                }

                $foreignKey = $this->buscarColunaFkExistente($newChild['id'], $newParent) ?? '';
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
    private function identificadorDe(array $entity): ?string
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

    private function buscarColunaFkExistente(string $entityId, array $destino): ?string
    {
        $nomes = array_unique([
            Str::snake(Str::singular($destino['name'])).'_id',
            Str::snake($destino['name']).'_id',
        ]);

        // Colunas já comprometidas com alguma relação existente.
        $ocupadas = [];
        foreach ($this->relations as $r) {
            if ($r['from'] === $entityId) {
                $ocupadas[] = $r['fromAttr'];
            }
        }

        $origem = $this->findEntity($entityId);
        foreach ($origem['attributes'] as $a) {
            if (in_array($a['name'], $nomes, true) && ! in_array($a['id'], $ocupadas, true)) {
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
                        'data' => $this->nodeData($e),
                        'dimensions' => $this->entityDimensions($e),
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
     * erd/edge-editor.js. O servidor continua sendo autoridade do dado; só
     * a orquestração de timing do redesenho passou para o cliente.
     */
    private function mutateRelation(string $id, callable $fn, bool $manterSelecionada = true): void
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
                    $this->dispatch('erd-rebuild-edge', edges: $newEdges, select: $manterSelecionada);
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
    private function relacaoExisteEntre(string $idA, string $idB): bool
    {
        foreach ($this->relations as $r) {
            $ligaAB = $r['from'] === $idA && $r['to'] === $idB;
            $ligaBA = $r['from'] === $idB && $r['to'] === $idA;

            if ($ligaAB || $ligaBA) {
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

    /** @return array{entityOut: string, entityIn: string, diamondOut: string, diamondIn: string} */
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

    /**
     * Cria quatro pontos geométricos invisíveis sobre os dois contornos.
     * Eles deslizam continuamente: dois pela borda retangular da entidade e
     * dois pelas arestas do losango. As linhas ligam esses pontos, portanto
     * não dependem dos quatro handles discretos oferecidos pelo AlpineFlow.
     *
     * @return array<int, array>
     */
    private function selfRelationshipPortNodesFor(array $relation): array
    {
        $entity = $this->findEntity($relation['from']);
        $diamond = $this->relationshipNodeFor($relation)['position'];
        $ids = $this->selfRelationshipPortNodeIds($relation['id']);

        $entityDimensions = $this->entityDimensions($entity);
        $entityWidth = $entityDimensions['width'];
        $entityHeight = $entityDimensions['height'];
        $entityCenterX = $entity['x'] + ($entityWidth / 2);
        $entityCenterY = $entity['y'] + ($entityHeight / 2);
        $diamondCenterX = $diamond['x'] + 60.0;
        $diamondCenterY = $diamond['y'] + 22.0;

        $dx = $diamondCenterX - $entityCenterX;
        $dy = $diamondCenterY - $entityCenterY;
        $length = max(1.0, hypot($dx, $dy));
        $ux = $dx / $length;
        $uy = $dy / $length;
        $px = -$uy;
        $py = $ux;

        // Abre as duas pernas sem criar uma troca brusca de lado nos cantos.
        $spread = 0.55;
        $entityOut = $this->rectangleBorderPoint(
            $entityCenterX, $entityCenterY, $entityWidth / 2, $entityHeight / 2,
            $ux + ($px * $spread), $uy + ($py * $spread),
        );
        $entityIn = $this->rectangleBorderPoint(
            $entityCenterX, $entityCenterY, $entityWidth / 2, $entityHeight / 2,
            $ux - ($px * $spread), $uy - ($py * $spread),
        );

        // No losango, o par ocupa o eixo perpendicular ao ângulo da relação:
        // esquerda/direita quando está acima e topo/base quando está ao lado.
        $diamondOut = $this->diamondBorderPoint($diamondCenterX, $diamondCenterY, 60.0, 22.0, $px, $py);
        $diamondIn = $this->diamondBorderPoint($diamondCenterX, $diamondCenterY, 60.0, 22.0, -$px, -$py);

        $points = [
            'entityOut' => $entityOut,
            'entityIn' => $entityIn,
            'diamondOut' => $diamondOut,
            'diamondIn' => $diamondIn,
        ];

        return array_map(
            fn (string $role) => [
                'id' => $ids[$role],
                'position' => [
                    'x' => (int) round($points[$role]['x']) - 1,
                    'y' => (int) round($points[$role]['y']) - 1,
                ],
                'dimensions' => [
                    'width' => self::RELATION_ANCHOR_SIZE,
                    'height' => self::RELATION_ANCHOR_SIZE,
                ],
                'data' => [
                    'kind' => 'relationship-port',
                    'relationId' => $relation['id'],
                    'role' => $role,
                ],
            ],
            array_keys($points),
        );
    }

    /** @return array{x: float, y: float} */
    private function rectangleBorderPoint(float $cx, float $cy, float $halfWidth, float $halfHeight, float $dx, float $dy): array
    {
        $tx = abs($dx) > 0.0001 ? $halfWidth / abs($dx) : PHP_FLOAT_MAX;
        $ty = abs($dy) > 0.0001 ? $halfHeight / abs($dy) : PHP_FLOAT_MAX;
        $scale = min($tx, $ty);

        return ['x' => $cx + ($dx * $scale), 'y' => $cy + ($dy * $scale)];
    }

    /** @return array{x: float, y: float} */
    private function diamondBorderPoint(float $cx, float $cy, float $halfWidth, float $halfHeight, float $dx, float $dy): array
    {
        $scale = 1 / max(0.0001, (abs($dx) / $halfWidth) + (abs($dy) / $halfHeight));

        return ['x' => $cx + ($dx * $scale), 'y' => $cy + ($dy * $scale)];
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
            $patch[$e['id']] = ['data' => $this->nodeData($e)];
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
    public function save(RelationalDrift $drift): void
    {
        $source = $this->persistDiagram();

        // O ER é a origem da cópia Relacional: salvar aqui pode deixá-la
        // defasada, e o quadro precisa mostrar isso na mesma resposta.
        $this->refreshRelationalSignal($drift, $source);

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
    public function organizeBoard(RelationalDrift $drift): void
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
            fn (array $entity): float => $this->entityDimensions($entity)['height'],
            self::ENTITY_WIDTH,
            300,
            160,
        );
        $positions = $this->reserveRelationshipNodeHeadroom($positions);
        $positions = BoardLayout::clearLinkCorridors(
            $this->entities,
            $links,
            $positions,
            fn (array $entity): float => $this->entityDimensions($entity)['height'],
            self::ENTITY_WIDTH,
            160,
            24,
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

        $this->refreshRelationalSignal($drift, $this->persistDiagram());
        $this->flowFromObject(['nodes' => $this->buildNodes(), 'edges' => $this->buildEdges()]);
        $this->flowFitView();
    }

    /**
     * Salva o ER atual e abre sua cópia relacional já regenerada.
     * Assim a conversão nunca usa um snapshot antigo nem exige voltar antes
     * ao dashboard para encontrar o botão da Etapa 2.
     */
    public function convertToRelational(RelationalCopy $copy, RelationalDrift $drift): void
    {
        $source = $this->persistDiagram();

        $relational = $copy->findOrCreate($source);

        // A conversão só cria a cópia; se ela já existia, quem decide sobre a
        // defasagem continua sendo o quadro Relacional.
        $this->refreshRelationalSignal($drift, $source);

        $this->redirectRoute('boards.relational', $relational, navigate: true);
    }

    /**
     * Atualiza o sinal "a cópia Relacional está defasada" da aba do topo.
     *
     * A comparação usa o estado em memória (`entities`/`relations`), que é
     * exatamente o que `persistDiagram` grava — assim salvar o ER acende o
     * sinal na mesma resposta, sem esperar um reload.
     */
    private function refreshRelationalSignal(RelationalDrift $drift, Diagram $source): void
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
    public function render(): View
    {
        return view('livewire.schema-board', [
            'nodes' => $this->buildNodes(),
            'edges' => $this->buildEdges(),
        ]);
    }
}
