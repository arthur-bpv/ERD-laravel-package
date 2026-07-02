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
    use WithWireFlow;

    /** @var array<int, array{id:string,name:string,x:int,y:int,attributes:array}> */
    public array $entities = [];

    /** @var array<int, array{id:string,from:string,fromAttr:string,to:string,toAttr:string,childCard:string,parentCard:string}> */
    public array $relations = [];

    /** Contador para IDs únicos e estáveis (nada de random no servidor). */
    public int $seq = 0;

    public string $newEntityName = '';

    public function mount(): void
    {
        // ------- Seed: um mini-esquema (blog) já modelado -------
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

        $this->relations = [
            ['id' => 'r1', 'from' => 'posts', 'fromAttr' => 'posts.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r2', 'from' => 'comments', 'fromAttr' => 'comments.post_id', 'to' => 'posts', 'toAttr' => 'posts.id', 'childCard' => 'cf-one-many', 'parentCard' => 'cf-one-one'],
            ['id' => 'r3', 'from' => 'comments', 'fromAttr' => 'comments.user_id', 'to' => 'users', 'toAttr' => 'users.id', 'childCard' => 'cf-zero-many', 'parentCard' => 'cf-one-one'],
        ];

        $this->seq = count($this->entities);
    }

    // ---------------------------------------------------------------------
    // Construção de nodes/edges a partir do estado
    // ---------------------------------------------------------------------

    /** @return array<int, array> */
    public function buildNodes(): array
    {
        return array_map(fn ($e) => [
            'id' => $e['id'],
            'position' => ['x' => $e['x'], 'y' => $e['y']],
            'data' => ['name' => $e['name'], 'attributes' => array_values($e['attributes'])],
        ], $this->entities);
    }

    /** @return array<int, array> */
    public function buildEdges(): array
    {
        return array_map(fn ($r) => $this->edgeFor($r), $this->relations);
    }

    private function edgeFor(array $r): array
    {
        return [
            'id' => $r['id'],
            'source' => $r['from'],
            'sourceHandle' => 's:'.$r['fromAttr'],
            'target' => $r['to'],
            'targetHandle' => 't:'.$r['toAttr'],
            'type' => 'smoothstep',
            'color' => '#64748b',
            'strokeWidth' => 1.6,
            'markerStart' => $r['childCard'],   // lado "filho" (FK) = muitos
            'markerEnd' => $r['parentCard'],     // lado "pai" (PK) = um
        ];
    }

    // ---------------------------------------------------------------------
    // Ações — entidades
    // ---------------------------------------------------------------------

    public function createEntity(): void
    {
        $name = trim($this->newEntityName) ?: 'nova_tabela';
        $id = 'e'.(++$this->seq);

        // deslocamento em cascata para não empilhar tudo no mesmo ponto
        $offset = 40 + (count($this->entities) % 6) * 26;

        $entity = [
            'id' => $id,
            'name' => $name,
            'x' => $offset,
            'y' => $offset,
            'attributes' => [
                ['id' => $id.'.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK'],
            ],
        ];

        $this->entities[] = $entity;
        $this->newEntityName = '';

        // Registra o node pelo pipeline do AlpineFlow (fica arrastável).
        $this->flowAddNodes([[
            'id' => $entity['id'],
            'position' => ['x' => $entity['x'], 'y' => $entity['y']],
            'data' => ['name' => $entity['name'], 'attributes' => array_values($entity['attributes'])],
        ]]);
    }

    public function deleteEntity(string $id): void
    {
        $this->entities = array_values(array_filter($this->entities, fn ($e) => $e['id'] !== $id));

        // remove relações que tocam a entidade
        $removed = [];
        foreach ($this->relations as $r) {
            if ($r['from'] === $id || $r['to'] === $id) {
                $removed[] = $r['id'];
            }
        }
        $this->relations = array_values(array_filter($this->relations, fn ($r) => ! in_array($r['id'], $removed, true)));

        if ($removed) {
            $this->flowRemoveEdges($removed);
        }
        $this->flowRemoveNodes([$id]);
    }

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
    // Ações — atributos (colunas)
    // ---------------------------------------------------------------------

    public function addAttribute(string $entityId, string $name = '', string $type = 'varchar', string $key = ''): void
    {
        $name = trim($name) ?: 'coluna';
        $this->mutateEntity($entityId, function (&$e) use ($entityId, $name, $type, $key) {
            $attrId = $entityId.'.'.$name.'_'.(++$this->seq);
            $e['attributes'][] = [
                'id' => $attrId,
                'name' => $name,
                'type' => $type ?: 'varchar',
                'key' => in_array($key, ['PK', 'FK', 'UQ'], true) ? $key : '',
            ];
        });
    }

    public function removeAttribute(string $entityId, string $attrId): void
    {
        $this->mutateEntity($entityId, function (&$e) use ($attrId) {
            $e['attributes'] = array_values(array_filter($e['attributes'], fn ($a) => $a['id'] !== $attrId));
        });

        // limpa relações presas nessa coluna
        $removed = [];
        foreach ($this->relations as $r) {
            if ($r['fromAttr'] === $attrId || $r['toAttr'] === $attrId) {
                $removed[] = $r['id'];
            }
        }
        if ($removed) {
            $this->relations = array_values(array_filter($this->relations, fn ($r) => ! in_array($r['id'], $removed, true)));
            $this->flowRemoveEdges($removed);
        }
    }

    /** Alterna a "chave" da coluna: '' → PK → FK → UQ → '' */
    public function cycleKey(string $entityId, string $attrId): void
    {
        $order = ['', 'PK', 'FK', 'UQ'];
        $this->mutateEntity($entityId, function (&$e) use ($attrId, $order) {
            foreach ($e['attributes'] as &$a) {
                if ($a['id'] === $attrId) {
                    $i = array_search($a['key'], $order, true);
                    $a['key'] = $order[($i === false ? 0 : $i + 1) % count($order)];
                    break;
                }
            }
        });
    }

    // ---------------------------------------------------------------------
    // Ações — relações (pé de galinha)
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

    /** Persiste a posição após arrastar (mantém o layout ao recriar nodes). */
    public function onNodeDragEnd(string $nodeId, array $position): void
    {
        foreach ($this->entities as &$e) {
            if ($e['id'] === $nodeId) {
                $e['x'] = (int) round($position['x'] ?? $e['x']);
                $e['y'] = (int) round($position['y'] ?? $e['y']);
                break;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Helper: muta uma entidade e sincroniza o node no cliente via patch.
    // ---------------------------------------------------------------------

    private function mutateEntity(string $id, callable $fn): void
    {
        foreach ($this->entities as &$e) {
            if ($e['id'] === $id) {
                $fn($e);
                // Patch nos dados do node registrado (não recria o DOM).
                $this->flowUpdate(['nodes' => [$id => [
                    'data' => ['name' => $e['name'], 'attributes' => array_values($e['attributes'])],
                ]]]);
                return;
            }
        }
    }

    public function render(): View
    {
        return view('livewire.schema-board', [
            'nodes' => $this->buildNodes(),
            'edges' => $this->buildEdges(),
        ]);
    }
}
