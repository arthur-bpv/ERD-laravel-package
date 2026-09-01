<?php

namespace App\Livewire;

use App\Models\Diagram;
use App\Services\ErToRelationalTransformer;
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Illuminate\Contracts\View\View;
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

    /** Cardinalidades aceitas — usado para barrar valor inválido vindo do cliente. */
    private const CARDINALIDADES = [
        'cf-one-one', 'cf-zero-one',
        'cf-one-many', 'cf-zero-many',
    ];

    /** Cor padrão das relações. */
    private const COR_RELACAO = '#64748b';

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

    public string $diagramName = 'Diagrama sem nome';

    public function mount($diagram = null): void
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

        foreach ($this->relations as $relation) {
            if (! $this->isCompleteRelationship($relation) || $this->isSelfRelationship($relation)) {
                $nodes[] = $this->relationshipNodeFor($relation);
                if ($this->isSelfRelationship($relation)) {
                    array_push($nodes, ...$this->selfRelationshipPortNodesFor($relation));
                }
            }
        }

        return $nodes;
    }

    /**
     * Monta um node completo, já com os metadados semânticos que o Blade usa
     * para liberar ou bloquear conexões.
     */
    private function nodeFor(array $e): array
    {
        return [
            'id' => $e['id'],
            'position' => ['x' => $e['x'], 'y' => $e['y']],
            'data' => $this->nodeData($e),
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
     *   relCount     — quantas relações tocam a entidade (só informativo na UI)
     */
    private function nodeData(array $e): array
    {
        $usadas = [];
        $relCount = 0;

        foreach ($this->relations as $r) {
            if ($r['from'] === $e['id']) {
                $usadas[] = $r['fromAttr'];
                $relCount++;
            }
            if ($r['to'] === $e['id']) {
                $usadas[] = $r['toAttr'];
                $relCount++;
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
            'relCount' => $relCount,
        ];
    }

    /**
     * Transforma as relações internas no formato de "Edges" (Linhas/Arestas) para o frontend.
     *
     * @return array<int, array>
     */
    public function buildEdges(): array
    {
        return array_values(array_merge(...array_map(fn ($r) => $this->edgesForRelation($r), $this->relations)));
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
            ],
        ];
    }

    private function edgesForRelation(array $relation): array
    {
        if ($this->isCompleteRelationship($relation) && ! $this->isSelfRelationship($relation)) {
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
                'labelStart' => $this->nomeCurto($relation['fromAttr']),
                'markerStart' => $this->marker($relation['childCard']),
            ];
        }
        if ($target) {
            $edges[] = $base + [
                'id' => $relation['id'].':in',
                'source' => $isSelfRelationship ? $selfPorts['diamondIn'] : $diamondId,
                'target' => $isSelfRelationship ? $selfPorts['entityIn'] : $relation['to'],
                'labelEnd' => $this->nomeCurto($relation['toAttr']),
                'markerEnd' => $this->marker($relation['parentCard']),
            ];
        }

        return $edges;
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
            ],
        ];
    }

    /**
     * Descreve um marcador de cardinalidade como array em vez de string.
     *
     * Passar só o nome ('cf-many') deixaria o AlpineFlow aplicar o recuo padrão
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
        // Toda relação que encosta nessa entidade (como origem ou destino) morre junto.
        $removidas = [];
        $nodesRemovidos = [];
        foreach ($this->relations as $r) {
            if ($r['from'] === $id || $r['to'] === $id) {
                array_push($removidas, ...array_column($this->edgesForRelation($r), 'id'));
                $nodesRemovidos[] = $this->relationshipNodeId($r['id']);
                if ($this->isSelfRelationship($r)) {
                    array_push($nodesRemovidos, ...array_values($this->selfRelationshipPortNodeIds($r['id'])));
                }
            }
        }

        $this->entities = array_values(array_filter($this->entities, fn ($e) => $e['id'] !== $id));

        $this->relations = array_values(array_filter($this->relations, fn ($r) => $r['from'] !== $id && $r['to'] !== $id));

        if ($removidas) {
            $this->flowRemoveEdges($removidas);
        }

        $this->flowRemoveNodes([$id]);
        if ($nodesRemovidos) {
            $this->flowRemoveNodes($nodesRemovidos);
        }

        // As entidades que sobraram podem ter liberado colunas — republica o estado.
        $this->syncNodeData();
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

        $removidas = [];
        $nodesRemovidos = [];
        $relationIds = [];
        foreach ($this->relations as $r) {
            if ($r['fromAttr'] === $attrId || $r['toAttr'] === $attrId) {
                $relationIds[] = $r['id'];
                array_push($removidas, ...array_column($this->edgesForRelation($r), 'id'));
                $nodesRemovidos[] = $this->relationshipNodeId($r['id']);
                if ($this->isSelfRelationship($r)) {
                    array_push($nodesRemovidos, ...array_values($this->selfRelationshipPortNodeIds($r['id'])));
                }
            }
        }

        if ($removidas) {
            $this->relations = array_values(array_filter($this->relations, fn ($r) => ! in_array($r['id'], $relationIds, true)));
            $this->flowRemoveEdges($removidas);
            if ($nodesRemovidos) {
                $this->flowRemoveNodes($nodesRemovidos);
            }
            $this->syncNodeData();
        }
    }

    /**
     * Alterna ciclicamente a restrição da coluna:
     * comum (vazio) → PK → FK → UQ → volta ao vazio.
     *
     * Como PK/UQ definem se a entidade pode receber relacionamento, o ciclo
     * republica o estado de todos os nodes ao final.
     */
    public function cycleKey(string $entityId, string $attrId): void
    {
        $order = ['', 'PK', 'FK', 'UQ']; // lista circular

        $this->mutateEntity($entityId, function (&$e) use ($attrId) {

            $vaiVirarPk = false;
            foreach ($e['attributes'] as &$a) {
                if ($a['id'] === $attrId) {
                    $vaiVirarPk = $a['key'] !== 'PK';
                    $a['key'] = $vaiVirarPk ? 'PK' : '';
                    break;
                }
            }
            unset($a);
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
        $this->onConnect($entityId, $entityId, 's:top', 't:tr');
    }

    /**
     * Chega aqui quando o usuário desenha uma conexão no canvas.
     *
     * A aresta provisória que o AlpineFlow criou já foi descartada no cliente
     * (ver resources/js/app.js), então aqui nascemos a relação de verdade, com
     * id do servidor, e a devolvemos pronta para a tela.
     *
     * As colunas são escolhidas automaticamente porque a conexão é feita de
     * entidade para entidade — o usuário refina depois no painel lateral.
     */
    public function onConnect(string $source, string $target, ?string $sourceHandle = null, ?string $targetHandle = null): void
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
                if (! $this->isCompleteRelationship($r) || $this->isSelfRelationship($r)) {
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

    /**
     * Inverte a direção do relacionamento (quem é pai vira filho).
     */
    public function swapRelation(string $relationId): void
    {
        $this->mutateRelation($relationId, function (&$r) {
            [$r['from'], $r['to']] = [$r['to'], $r['from']];
            [$r['fromAttr'], $r['toAttr']] = [$r['toAttr'], $r['fromAttr']];
            [$r['childCard'], $r['parentCard']] = [$r['parentCard'], $r['childCard']];
            [$r['fromRole'], $r['toRole']] = [$r['toRole'] ?? 'papel_destino', $r['fromRole'] ?? 'papel_origem'];
        });

        $this->syncNodeData();
    }

    /**
     * Remove um relacionamento.
     */
    public function deleteRelation(string $relationId): void
    {
        $relation = collect($this->relations)->firstWhere('id', $relationId);
        $this->relations = array_values(array_filter($this->relations, fn ($r) => $r['id'] !== $relationId));

        if ($relation) {
            $this->flowRemoveEdges(array_column($this->edgesForRelation($relation), 'id'));
            $nodeIds = [$this->relationshipNodeId($relationId)];
            if ($this->isSelfRelationship($relation)) {
                array_push($nodeIds, ...array_values($this->selfRelationshipPortNodeIds($relationId)));
            }
            $this->flowRemoveNodes($nodeIds);
        }
        $this->syncNodeData();
    }

    /**
     * Troca qual coluna participa de uma das pontas do relacionamento.
     *
     * @param  string  $end  'from' (coluna FK) ou 'to' (coluna referenciada)
     */
    public function setRelationAttr(string $relationId, string $end, string $attrId): void
    {
        foreach ($this->relations as $relation) {
            if ($relation['id'] !== $relationId) {
                continue;
            }

            $entityId = $end === 'to' ? $relation['to'] : $relation['from'];
            $entity = $this->findEntity($entityId);
            $belongsToEntity = collect($entity['attributes'] ?? [])->contains('id', $attrId);

            if (! $belongsToEntity) {
                return;
            }

            $field = $end === 'to' ? 'toAttr' : 'fromAttr';
            $this->mutateRelation($relationId, function (array &$item) use ($field, $attrId) {
                $item[$field] = $attrId;
            });

            $this->syncNodeData();

            return;
        }
    }

    /**
     * Ouvinte disparado pelo frontend quando o usuário solta uma entidade
     * em outro ponto do painel.
     */
    public function onNodeDragEnd(string $nodeId, array $position): void
    {
        if (str_starts_with($nodeId, 'relation-')) {
            $relationId = substr($nodeId, strlen('relation-'));
            foreach ($this->relations as &$relation) {
                if ($relation['id'] === $relationId) {
                    $relation['diamondX'] = (int) round($position['x'] ?? 0);
                    $relation['diamondY'] = (int) round($position['y'] ?? 0);

                    if ($this->isSelfRelationship($relation)) {
                        $patch = [];
                        foreach ($this->selfRelationshipPortNodesFor($relation) as $port) {
                            $patch[$port['id']] = ['position' => $port['position']];
                        }
                        $this->flowUpdate(['nodes' => $patch]);
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

                foreach ($this->relations as &$relation) {
                    if (! $this->isSelfRelationship($relation) || $relation['from'] !== $nodeId) {
                        continue;
                    }

                    $diamond = $this->relationshipNodeFor($relation)['position'];
                    $relation['diamondX'] = $diamond['x'] + $deltaX;
                    $relation['diamondY'] = $diamond['y'] + $deltaY;
                }
                unset($relation);

                $e['x'] = $newX;
                $e['y'] = $newY;

                foreach ($this->relations as $relation) {
                    if ($this->isSelfRelationship($relation) && $relation['from'] === $nodeId) {
                        $node = $this->relationshipNodeFor($relation);
                        $patch = [$node['id'] => ['position' => $node['position']]];
                        foreach ($this->selfRelationshipPortNodesFor($relation) as $port) {
                            $patch[$port['id']] = ['position' => $port['position']];
                        }
                        $this->flowUpdate(['nodes' => $patch]);
                    }
                }
                break;
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
        $desejado = $destino['name'].'_id';

        // Colunas já comprometidas com alguma relação existente.
        $ocupadas = [];
        foreach ($this->relations as $r) {
            if ($r['from'] === $entityId) {
                $ocupadas[] = $r['fromAttr'];
            }
        }

        $origem = $this->findEntity($entityId);
        foreach ($origem['attributes'] as $a) {
            if ($a['name'] === $desejado && ! in_array($a['id'], $ocupadas, true)) {
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
                $this->flowUpdate(['nodes' => [$id => ['data' => $this->nodeData($e)]]]);

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
                $fn($r);

                if (! $this->isCompleteRelationship($r) || $this->isSelfRelationship($r)) {
                    $this->flowUpdate(['nodes' => [
                        $this->relationshipNodeId($id) => ['data' => $this->relationshipNodeFor($r)['data']],
                    ]]);
                }
                $this->dispatch('erd-rebuild-edge', edges: $this->edgesForRelation($r), select: $manterSelecionada);

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

        $entityWidth = 232.0;
        $entityHeight = 84.0 + (count($entity['attributes'] ?? []) * 24.5);
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
     * Salva o ER atual e abre sua cópia relacional já regenerada.
     * Assim a conversão nunca usa um snapshot antigo nem exige voltar antes
     * ao dashboard para encontrar o botão da Etapa 2.
     */
    public function convertToRelational(ErToRelationalTransformer $transformer): void
    {
        $source = $this->persistDiagram();

        $relational = Diagram::query()->updateOrCreate(
            ['source_diagram_id' => $source->id],
            [
                'name' => $source->name.' — Relacional',
                'type' => Diagram::TYPE_RELATIONAL,
                'data' => $transformer->transform($source->data ?? []),
            ],
        );

        $this->redirectRoute('boards.relational', $relational, navigate: true);
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

    public bool $showJson = false;

    /** Alterna a exibição do modal com o JSON do diagrama. */
    public function toggleJson(): void
    {
        $this->showJson = ! $this->showJson;
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
