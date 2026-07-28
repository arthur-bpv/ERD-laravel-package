<?php

namespace App\Livewire;

use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
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
        'cf-one', 'cf-one-one', 'cf-zero-one',
        'cf-many', 'cf-one-many', 'cf-zero-many',
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

    /**
     * Método executado uma única vez quando o componente é iniciado.
     * Alimenta o editor com um modelo básico (Seed) de Blog.
     */
    public function mount(): void
    {
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
            ['id' => 'r1', 'name' => 'escreve', 'from' => 'posts', 'fromAttr' => 'posts.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r2', 'name' => 'recebe', 'from' => 'comments', 'fromAttr' => 'comments.post_id', 'to' => 'posts', 'toAttr' => 'posts.id', 'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r3', 'name' => 'comenta', 'from' => 'comments', 'fromAttr' => 'comments.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one'],
        ];

        // Sincroniza os sequenciadores para evitar duplicidade de IDs futuros.
        $this->seq = count($this->entities);
        $this->relSeq = count($this->relations);
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
        return array_map(fn ($e) => $this->nodeFor($e), $this->entities);
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
        return array_map(fn ($r) => $this->edgeFor($r), $this->relations);
    }

    /**
     * Monta a aresta de um relacionamento.
     *
     * `type: floating` faz o AlpineFlow calcular os extremos pela borda das duas
     * entidades — a linha desliza sozinha quando a caixa é arrastada e não fica
     * presa a um ponto fixo. `pathType` escolhe o gerador de curva usado dentro
     * do modo floating.
     *
     * `label` vira o losango (é estilizado por CSS em .flow-edge-label);
     * `labelStart`/`labelEnd` mostram as colunas de cada ponta.
     *
     * `interactionWidth` alarga a faixa invisível que captura o clique na linha
     * (o padrão da biblioteca é 20px). Uma aresta de 1,6px é um alvo minúsculo
     * para o mouse — era por isso que clicar na relação às vezes não pegava.
     */
    private function edgeFor(array $r): array
    {
        return [
            'id' => $r['id'],
            'source' => $r['from'],
            'target' => $r['to'],
            'type' => 'floating',
            'pathType' => 'smoothstep',
            'color' => self::COR_RELACAO,
            'strokeWidth' => 1.6,
            'interactionWidth' => 34,
            'label' => $r['name'],
            'labelStart' => $this->nomeCurto($r['fromAttr']),
            'labelEnd' => $this->nomeCurto($r['toAttr']),
            'markerStart' => $this->marker($r['childCard']),   // lado filho (FK) — normalmente "muitos"
            'markerEnd' => $this->marker($r['parentCard']),    // lado pai (PK) — normalmente "um"
            // ids completos das colunas — o editor de relacionamento usa isto
            // para popular o seletor de coluna de cada ponta (labelStart/End
            // só trazem o nome curto, sem o prefixo da tabela).
            'data' => [
                'fromAttr' => $r['fromAttr'],
                'toAttr' => $r['toAttr'],
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
            'y' => 380 + $linha * 220,
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

        // Toda relação que encosta nessa entidade (como origem ou destino) morre junto.
        $removidas = [];
        foreach ($this->relations as $r) {
            if ($r['from'] === $id || $r['to'] === $id) {
                $removidas[] = $r['id'];
            }
        }

        $this->relations = array_values(array_filter($this->relations, fn ($r) => ! in_array($r['id'], $removidas, true)));

        if ($removidas) {
            $this->flowRemoveEdges($removidas);
        }

        $this->flowRemoveNodes([$id]);

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
                'type' => $type ?: 'varchar',
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
        foreach ($this->relations as $r) {
            if ($r['fromAttr'] === $attrId || $r['toAttr'] === $attrId) {
                $removidas[] = $r['id'];
            }
        }

        if ($removidas) {
            $this->relations = array_values(array_filter($this->relations, fn ($r) => ! in_array($r['id'], $removidas, true)));
            $this->flowRemoveEdges($removidas);
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

        $this->mutateEntity($entityId, function (&$e) use ($attrId, $order) {
            foreach ($e['attributes'] as &$a) {
                if ($a['id'] === $attrId) {
                    $i = array_search($a['key'], $order, true);
                    // O módulo faz o índice voltar a 0 depois do último item.
                    $a['key'] = $order[($i === false ? 0 : $i + 1) % count($order)];
                    break;
                }
            }
        });
    }

    // ---------------------------------------------------------------------
    // Ações — Relacionamentos
    // ---------------------------------------------------------------------

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
        $origem = $this->findEntity($source);
        $destino = $this->findEntity($target);

        if (! $origem || ! $destino) {
            return;
        }

        // Regra do modelo: só é possível referenciar quem tem identificador.
        $pk = $this->identificadorDe($destino);
        if ($pk === null) {
            return;
        }

        // Reaproveita (ou cria) a coluna que carrega a chave estrangeira.
        $fk = $this->garantirColunaFk($origem['id'], $destino);

        $id = 'r'.(++$this->relSeq);

        $relacao = [
            'id' => $id,
            'name' => 'relaciona',
            'from' => $origem['id'],
            'fromAttr' => $fk,
            'to' => $destino['id'],
            'toAttr' => $pk,
            'childCard' => 'cf-many',      // o lado da FK costuma ser "muitos"
            'parentCard' => 'cf-one-one',  // o lado da PK costuma ser "um e só um"
        ];

        $this->relations[] = $relacao;

        $this->flowAddEdges([$this->edgeFor($relacao)]);
        $this->syncNodeData();
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
                $this->flowUpdate(['edges' => [$relationId => ['label' => $name]]]);

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
        });

        $this->syncNodeData();
    }

    /**
     * Remove um relacionamento.
     */
    public function deleteRelation(string $relationId): void
    {
        $this->relations = array_values(array_filter($this->relations, fn ($r) => $r['id'] !== $relationId));

        $this->flowRemoveEdges([$relationId]);
        $this->syncNodeData();
    }

    /**
     * Troca qual coluna participa de uma das pontas do relacionamento.
     *
     * @param  string  $end  'from' (coluna FK) ou 'to' (coluna referenciada)
     */
    public function setRelationAttr(string $relationId, string $end, string $attrId): void
    {
        $relacao = null;
        foreach ($this->relations as $r) {
            if ($r['id'] === $relationId) {
                $relacao = $r;
                break;
            }
        }

        if ($relacao === null) {
            return;
        }

        $campo = $end === 'to' ? 'toAttr' : 'fromAttr';

        // A coluna precisa pertencer à entidade daquela ponta — sem isso um
        // cliente poderia apontar a relação para uma coluna de outra tabela.
        $entidade = $this->findEntity($end === 'to' ? $relacao['to'] : $relacao['from']);
        if (! $entidade) {
            return;
        }

        $pertence = false;
        foreach ($entidade['attributes'] as $a) {
            if ($a['id'] === $attrId) {
                $pertence = true;
                break;
            }
        }

        if (! $pertence) {
            return;
        }

        $this->mutateRelation($relationId, function (&$r) use ($campo, $attrId) {
            $r[$campo] = $attrId;
        });

        $this->syncNodeData();
    }

    /**
     * Ouvinte disparado pelo frontend quando o usuário solta uma entidade
     * em outro ponto do painel.
     */
    public function onNodeDragEnd(string $nodeId, array $position): void
    {
        // Persiste as coordenadas para que o layout sobreviva a um reload.
        foreach ($this->entities as &$e) {
            if ($e['id'] === $nodeId) {
                $e['x'] = (int) round($position['x'] ?? $e['x']);
                $e['y'] = (int) round($position['y'] ?? $e['y']);
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

    /**
     * Garante que a entidade filha tenha uma coluna para carregar a chave
     * estrangeira, e devolve o id dela.
     *
     * Primeiro procura uma FK livre já chamada `{destino}_id`; se não achar,
     * cria a coluna. Assim desenhar a relação não sequestra uma coluna que já
     * significava outra coisa.
     */
    private function garantirColunaFk(string $entityId, array $destino): string
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
            if ($a['name'] === $desejado && $a['key'] !== 'PK' && ! in_array($a['id'], $ocupadas, true)) {
                return $a['id'];
            }
        }

        // Nenhuma candidata livre — cria a coluna FK.
        $attrId = $entityId.'.'.$desejado.'_'.(++$this->seq);

        foreach ($this->entities as &$e) {
            if ($e['id'] === $entityId) {
                $e['attributes'][] = [
                    'id' => $attrId,
                    'name' => $desejado,
                    'type' => 'bigint',
                    'key' => 'FK',
                ];
                break;
            }
        }

        return $attrId;
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

                $this->dispatch('erd-rebuild-edge', edge: $this->edgeFor($r), select: $manterSelecionada);

                return;
            }
        }
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
