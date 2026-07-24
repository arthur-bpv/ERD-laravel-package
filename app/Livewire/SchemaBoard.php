<?php

namespace App\Livewire;

use ArtisanFlow\WireFlow\Concerns\WithWireFlow;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Editor visual de esquema de banco de dados (estilo ERD / UML).
 *
 * O estado autoritativo vive aqui no servidor em $entities/$relations. O
 * diagrama no cliente NÃO usa :sync — ele é iniciado a partir dos nodes/edges
 * calculados e, a partir daí, cada mudança é empurrada por comandos WireFlow
 * (flowAddNodes / flowUpdate / flowAddEdges / flowRemoveNodes). Esse é o
 * caminho confiável: nodes criados assim ficam registrados e arrastáveis, e
 * o flow:update aplica um patch nos dados sem destruir o DOM (wire:ignore).
 */
#[Layout('layouts.app')]
class SchemaBoard extends Component
{
    // A trait WithWireFlow permite enviar comandos JavaScript granulares e diretos
    // para a biblioteca de diagramas do frontend, evitando renderizações pesadas do Livewire.
    use WithWireFlow;

    /** 
     * Estrutura que guarda as tabelas (entidades) no servidor.
     * @var array<int, array{id:string,name:string,x:int,y:int,attributes:array}> 
     */
    public array $entities = [];

    /** 
     * Estrutura que guarda os relacionamentos (linhas/arestas) no servidor.
     * @var array<int, array{id:string,from:string,fromAttr:string,to:string,toAttr:string,childCard:string,parentCard:string}> 
     */
    public array $relations = [];

    /** Contador incremental para gerar IDs únicos e estáveis para novas tabelas e colunas. */
    public int $seq = 0;

    /** Propriedade capturada de um campo de texto (wire:model) para nomear novas tabelas. */
    public string $newEntityName = '';

    /**
     * Método executado uma única vez quando o componente é iniciado.
     * Alimenta o editor com um modelo básico (Seed) de Blog.
     */
    public function mount(): void
    {
        // Tabelas iniciais do sistema: users, posts e comments com suas posições de tela (x, y) e colunas.
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

        // Definição das linhas que conectam as chaves estrangeiras (fromAttr) às chaves primárias (toAttr).
        $this->relations = [
            ['id' => 'r1', 'from' => 'posts', 'fromAttr' => 'posts.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r2', 'from' => 'comments', 'fromAttr' => 'comments.post_id', 'to' => 'posts', 'toAttr' => 'posts.id', 'childCard' => 'cf-one-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r3', 'from' => 'comments', 'fromAttr' => 'comments.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one'],
        ];

        // Sincroniza o sequenciador com o número atual de entidades para evitar duplicidade de IDs futuros.
        $this->seq = count($this->entities);
    }

    // ---------------------------------------------------------------------
    // Construção de nodes/edges a partir do estado do PHP para o formato da biblioteca JS
    // ---------------------------------------------------------------------

    /** 
     * Transforma o array de entidades no formato de "Nodes" (Nós do Diagrama) que o frontend entende.
     * @return array<int, array> 
     */
    public function buildNodes(): array
    {
        return array_map(fn ($e) => [
            'id' => $e['id'],
            'position' => ['x' => $e['x'], 'y' => $e['y']],
            'data' => ['name' => $e['name'], 'attributes' => array_values($e['attributes'])],
        ], $this->entities);
    }

    /** 
     * Transforma as relações internas no formato de "Edges" (Linhas/Arestas) para o frontend.
     * @return array<int, array> 
     */
    public function buildEdges(): array
    {
        return array_map(fn ($r) => $this->edgeFor($r), $this->relations);
    }

    /** 
     * Helper de formatação visual da linha, definindo as âncoras exatas de cada coluna, 
     * a cor, espessura e os marcadores de cardinalidade (estilo pé-de-galinha).
     */
    private function edgeFor(array $r): array
    {
        return [
            'id' => $r['id'],
            'source' => $r['from'],
            'sourceHandle' => 's:'.$r['fromAttr'],   // Âncora de saída (Source) vinculada à coluna de origem
            'target' => $r['to'],
            'targetHandle' => 't:'.$r['toAttr'],     // Âncora de chegada (Target) vinculada à coluna de destino
            'type' => 'smoothstep',                  // Tipo de linha ortogonal com cantos levemente arredondados
            'color' => '#64748b',                    // Cor cinza ardósia suave
            'strokeWidth' => 1.6,                    // Espessura da linha
            'markerStart' => $r['childCard'],        // Símbolo do lado da Chave Estrangeira (geralmente Muitos)
            'markerEnd' => $r['parentCard'],         // Símbolo do lado da Chave Primária (geralmente Um)
        ];
    }

    // ---------------------------------------------------------------------
    // Ações — Entidades (Tabelas)
    // ---------------------------------------------------------------------

    /**
     * Cria uma nova tabela no banco de dados e notifica o editor visual no frontend.
     */ 
    
    public function createEntity(): void
    {
        $name = trim($this->newEntityName) ?: 'nova_tabela';
        $id = 'e'.(++$this->seq);

        // Aplica um deslocamento em cascata (escada) baseado no total de tabelas.
        // Isso evita que novas tabelas nasçam exatamente umas por cima das outras no canto da tela.
        $offset = 40 + (count($this->entities) % 6) * 26;

        $entity = [
            'id' => $id,
            'name' => $name,
            'x' => $offset,
            'y' => $offset,
            'attributes' => [
                ['id' => $id.'.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'], // Toda tabela padrão já inicia com uma PK 'id'
            ],
        ];

        // Adiciona ao estado local do servidor
        $this->entities[] = $entity;
        $this->newEntityName = ''; // Limpa o input de texto do formulário

        // Envia uma instrução direta via JS (pipeline AlpineFlow) para renderizar dinamicamente o nó na tela de forma interativa.
        $this->flowAddNodes([[
            'id' => $entity['id'],
            'position' => ['x' => $entity['x'], 'y' => $entity['y']],
            'data' => ['name' => $entity['name'], 'attributes' => array_values($entity['attributes'])],
        ]]);
    }

    /**
     * Apaga uma tabela inteira e limpa em cascata qualquer relacionamento associado a ela.
     */
    public function deleteEntity(string $id): void
    {
        // Filtra o array removendo a entidade correspondente ao ID informado
        $this->entities = array_values(array_filter($this->entities, fn ($e) => $e['id'] !== $id));

        // Procura no array de relações por qualquer linha que encoste nessa tabela (origem ou destino)
        $removed = [];
        foreach ($this->relations as $r) {
            if ($r['from'] === $id || $r['to'] === $id) {
                $removed[] = $r['id'];
            }
        }
        
        // Remove as relações identificadas do estado do servidor
        $this->relations = array_values(array_filter($this->relations, fn ($r) => ! in_array($r['id'], $removed, true)));

        // Se houveram linhas afetadas, ordena o frontend a apagá-las visualmente da tela
        if ($removed) {
            $this->flowRemoveEdges($removed);
        }
        
        // Ordena o frontend a apagar a caixa (nó) da tabela da tela
        $this->flowRemoveNodes([$id]);
    }

    /**
     * Altera o nome de uma tabela específica.
     */
    public function renameEntity(string $id, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        
        // Executa a mutação de alteração usando a função utilitária 'mutateEntity'
        $this->mutateEntity($id, function (&$e) use ($name) {
            $e['name'] = $name;
        });
    }

    // ---------------------------------------------------------------------
    // Ações — Atributos (Colunas)
    // ---------------------------------------------------------------------

    /**
     * Adiciona uma nova coluna (atributo) dentro de uma tabela pré-existente.
     */
    public function addAttribute(string $entityId, string $name = '', string $type = 'varchar', string $key = ''): void
    {
        $name = trim($name) ?: 'coluna';
        
        // Acessa a entidade via callback e injeta a nova coluna no array interno de attributes
        $this->mutateEntity($entityId, function (&$e) use ($entityId, $name, $type, $key) {
            $attrId = $entityId.'.'.$name.'_'.(++$this->seq); // Concatena IDs e sequência para chaves de mapeamento únicas
            $e['attributes'][] = [
                'id' => $attrId,
                'name' => $name,
                'type' => $type ?: 'varchar',
                'key' => in_array($key, ['PK', 'FK', 'UQ'], true) ? $key : '', // Bloqueia valores inválidos fora desse escopo
            ];
        });
    }

    /**
     * Exclui uma coluna e limpa linhas de relacionamento órfãs vinculadas a ela.
     */
    public function removeAttribute(string $entityId, string $attrId): void
    {
        // Remove o atributo de dentro da lista da tabela no servidor
        $this->mutateEntity($entityId, function (&$e) use ($attrId) {
            $e['attributes'] = array_values(array_filter($e['attributes'], fn ($a) => $a['id'] !== $attrId));
        });

        // Encontra e remove quaisquer relacionamentos construídos a partir desta coluna específica
        $removed = [];
        foreach ($this->relations as $r) {
            if ($r['fromAttr'] === $attrId || $r['toAttr'] === $attrId) {
                $removed[] = $r['id'];
            }
        }
        if ($removed) {
            $this->relations = array_values(array_filter($this->relations, fn ($r) => ! in_array($r['id'], $removed, true)));
            $this->flowRemoveEdges($removed); // Notifica o JS para apagar a linha conectora visualmente
        }
    }

    /** 
     * Alterna ciclicamente o marcador de restrição (Chave) da coluna:
     * Campo comum (Vazio) -> Primary Key (PK) -> Foreign Key (FK) -> Unique Key (UQ) -> retorna ao Vazio.
     */
    public function cycleKey(string $entityId, string $attrId): void
    {
        $order = ['', 'PK', 'FK', 'UQ']; // Lista circular ordenada
        $this->mutateEntity($entityId, function (&$e) use ($attrId, $order) {
            foreach ($e['attributes'] as &$a) {
                if ($a['id'] === $attrId) {
                    $i = array_search($a['key'], $order, true);
                    // Usa a operação matemática de Módulo (%) para que ao passar do último item, ele retorne para o índice 0
                    $a['key'] = $order[($i === false ? 0 : $i + 1) % count($order)];
                    break;
                }
            }
        });
    }

    // ---------------------------------------------------------------------
    // Ações — Relações e Sincronização do Frontend
    // ---------------------------------------------------------------------

    /*
     * Observação sobre criação de relações:
     * O AlpineFlow já cria a aresta no cliente quando o usuário arrasta de uma
     * coluna (handle source, à direita) até outra (handle target, à esquerda),
     * aplicando o `defaultEdgeOptions` — ou seja, a relação nasce em pé de
     * galinha sem ida ao servidor. Por isso NÃO tratamos @connect aqui: isso
     * geraria uma aresta duplicada. O estado semente ($relations) serve para
     * montar o diagrama inicial e limpar arestas ao remover colunas/entidades.
     */

    /** 
     * Ouvinte de Evento disparado pelo frontend via JavaScript assim que o usuário 
     * solta o clique após arrastar uma tabela (nó) para outro ponto do painel.
     */
    public function onNodeDragEnd(string $nodeId, array $position): void
    {
        // Encontra a tabela no servidor e atualiza suas coordenadas cartesianas (X, Y) persistindo o novo design visual.
        foreach ($this->entities as &$e) {
            if ($e['id'] === $nodeId) {
                $e['x'] = (int) round($position['x'] ?? $e['x']);
                $e['y'] = (int) round($position['y'] ?? $e['y']);
                break;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Helper: Muta uma entidade e sincroniza o node no cliente via patch.
    // ---------------------------------------------------------------------

    /**
     * Função utilitária centralizada que executa modificações em tabelas.
     * Ela aplica a lógica recebida por parâmetro ($fn) e dispara automaticamente um comando de Patch parcial
     * para o frontend atualizar o conteúdo interno do nó na tela de forma limpa e otimizada.
     */
    private function mutateEntity(string $id, callable $fn): void
    {
        foreach ($this->entities as &$e) {
            if ($e['id'] === $id) {
                $fn($e); // Executa a função anônima de alteração (ex: adicionar colunas, redefinir chaves ou renomear)
                
                // Envia um patch incremental (flowUpdate) apenas com os novos dados de nomes e atributos, 
                // sem mexer na posição geográfica do nó e preservando a estrutura HTML da tela.
                $this->flowUpdate(['nodes' => [$id => [
                    'data' => ['name' => $e['name'], 'attributes' => array_values($e['attributes'])],
                ]]]);
                return;
            }
        }
    }

    /** 
     * Renderiza o template Blade do componente, injetando as coleções de dados iniciais.
     */
    public function render(): View
    {
        return view('livewire.schema-board', [
            'nodes' => $this->buildNodes(),
            'edges' => $this->buildEdges(),
        ]);
    }
}