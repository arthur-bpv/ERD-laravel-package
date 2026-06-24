<?php

namespace App\Livewire;

// Trait do WireFlow: adiciona ao componente Livewire os helpers servidor->cliente
// (flowFitView, flowFocusNode, flowHighlightNode...) e o roteamento dos eventos do
// canvas (@node-click, @connect, @edge-click...).
// Doc: https://artisanflow.dev/docs/wireflow  (seção "WithWireFlow trait")
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;

// Define qual layout Blade envolve o componente.
// Doc: https://livewire.laravel.com/docs/components
use Livewire\Attributes\Layout;

// Classe base de todo componente Livewire.
// Doc: https://livewire.laravel.com/docs/components
use Livewire\Component;

/**
 * DiagramaBanco — Diagrama Entidade-Relacionamento (DER / notação de Chen) SOMENTE
 * DESIGN, montado 100% com os componentes do ArtisanFlow (WireFlow + AlpineFlow).
 *
 * A notação de Chen usa TRÊS formas, e o WireFlow já traz todas como SHAPES nativos
 * (basta a chave 'shape' no array do node — nenhum CSS extra é necessário):
 *
 *   ┌──────────────┐  ENTIDADE   -> retângulo  (node SEM 'shape' = retângulo padrão)
 *   │   USUARIO    │
 *   └──────────────┘
 *
 *        ◇          RELACIONAMENTO -> losango   ('shape' => 'diamond')
 *       FAZ
 *
 *        ◯          ATRIBUTO       -> círculo    ('shape' => 'circle')
 *      email
 *
 * Shapes nativos disponíveis (doc: https://artisanflow.dev/docs/alpineflow/nodes/shapes):
 *   diamond | hexagon | parallelogram | triangle | circle | cylinder | stadium
 *   (cylinder é ótimo p/ representar a própria "tabela/banco"; stadium p/ estados).
 *
 * As LIGAÇÕES (edges) carregam a CARDINALIDADE. O WireFlow não tem glifo de "pé-de-
 * galinha" (crow's foot) embutido — os markers nativos são só 'arrow' e 'arrowclosed'
 * (doc: https://artisanflow.dev/docs/alpineflow/edges/markers). Então usamos a notação
 * de Chen/IE: a cardinalidade vai em TEXTO nas pontas via 'label' / 'labelStart' /
 * 'labelEnd' (doc: https://artisanflow.dev/docs/alpineflow/edges/labels), combinada com
 * markers e cores para diferenciar 1:1, 1:N e N:M. Os helpers $this->edge1para1() etc.
 * (mais abaixo) montam cada tipo de aresta.
 */
#[Layout('layouts.app')]
class DiagramaBanco extends Component
{
    // Ativa os métodos flow* e o roteamento de eventos do canvas.
    use WithWireFlow;

    // --- ESTADO (Livewire) -------------------------------------------------
    // Propriedades públicas são sincronizadas servidor<->cliente e ficam
    // disponíveis no Blade. $nodes/$edges alimentam o <x-flow>.
    // Doc: https://livewire.laravel.com/docs/properties
    public array $nodes = [];
    public array $edges = [];
    public ?string $selectedNode = null; // id do node clicado (painel lateral)
    public ?array $selectedData = null;  // data do node clicado

    /**
     * mount() — roda UMA vez na criação do componente (lifecycle hook do Livewire).
     * Aqui montamos o DER inteiro de forma declarativa.
     * Doc: https://livewire.laravel.com/docs/lifecycle-hooks#mount
     */
    public function mount(): void
    {
        // ============================================================
        // 1) NÓS — cada forma da notação de Chen é só um 'shape' diferente.
        // ============================================================
        //
        // Estrutura de um node (doc: https://artisanflow.dev/docs/alpineflow/nodes/basics):
        //   'id'         (obrigatório) identificador único
        //   'position'   (obrigatório) {x, y} em pixels no espaço do canvas
        //   'shape'      forma nativa (diamond/circle/...). Ausente = retângulo
        //   'dimensions' {width, height} — útil p/ deixar losango/círculo proporcionais
        //   'class'      classes CSS de status nativas p/ colorir (flow-node-info etc.)
        //   'data'       payload livre lido no template via node.data.*
        //
        // Esquema modelado (um e-commerce mínimo):
        //   USUARIO (1) ──faz──< (N) PEDIDO (N) >──contem──< (M) PRODUTO
        //   USUARIO (1) ──possui── (1) PERFIL          [exemplo de 1:1]
        //   + atributos (círculos) pendurados em cada entidade; PK em destaque.

        $this->nodes = [
            // ---------- ENTIDADES (retângulos) ----------
            // Sem chave 'shape' => retângulo. É a "caixinha quadrada" da entidade.
            $this->entidade('usuario', 'USUARIO', 360, 150),
            $this->entidade('pedido',  'PEDIDO',  360, 470),
            $this->entidade('produto', 'PRODUTO', 820, 470),
            $this->entidade('perfil',  'PERFIL',  820, 150),

            // ---------- RELACIONAMENTOS (losangos) ----------
            // 'shape' => 'diamond'. É o losango do relacionamento (o verbo entre entidades).
            $this->relacionamento('faz',    'faz',    360, 310),
            $this->relacionamento('contem', 'contém', 590, 470),
            $this->relacionamento('possui', 'possui', 590, 150),

            // ---------- ATRIBUTOS (círculos) ----------
            // 'shape' => 'circle'. É a bolinha do atributo. O 4º arg marca a PK (sublinhada).
            // Atributos de USUARIO (espalhados acima da entidade):
            $this->atributo('u_id',    'id',    180,  30, isPk: true),
            $this->atributo('u_nome',  'nome',  330,  10),
            $this->atributo('u_email', 'email', 480,  30),
            // Atributos de PEDIDO (à esquerda):
            $this->atributo('p_id',    'id',    150, 420, isPk: true),
            $this->atributo('p_total', 'total', 150, 560),
            // Atributos de PRODUTO (à direita):
            $this->atributo('pr_id',    'id',    1030, 420, isPk: true),
            $this->atributo('pr_preco', 'preço', 1030, 560),
            // Atributo de PERFIL (acima):
            $this->atributo('pf_bio', 'bio', 980, 30),
        ];

        // ============================================================
        // 2) ARESTAS — design das ligações + cardinalidade.
        // ============================================================
        //
        // Duas famílias de aresta neste DER:
        //   (a) ENTIDADE <-> ATRIBUTO  -> linha simples, sem cardinalidade (atributoEdge()).
        //   (b) ENTIDADE <-> RELACIONAMENTO -> linha com cardinalidade (edge1para1/1paraN/NparaM).
        //
        // Todas usam 'type' => 'floating': as pontas são calculadas dinamicamente na BORDA
        // de cada node (intersecção da reta centro-a-centro), então a linha "gruda" na
        // forma — ideal p/ DER, onde a ligação não tem direção fixa de handle.
        // Doc: https://artisanflow.dev/docs/alpineflow/edges/types  (seção "Floating edges")

        $this->edges = array_merge(
            // (a) Atributos -> sua entidade (linhas finas, cinza, sem seta nem rótulo).
            [
                $this->atributoEdge('usuario', 'u_id'),
                $this->atributoEdge('usuario', 'u_nome'),
                $this->atributoEdge('usuario', 'u_email'),
                $this->atributoEdge('pedido',  'p_id'),
                $this->atributoEdge('pedido',  'p_total'),
                $this->atributoEdge('produto', 'pr_id'),
                $this->atributoEdge('produto', 'pr_preco'),
                $this->atributoEdge('perfil',  'pf_bio'),
            ],
            // (b) Entidade -> relacionamento -> entidade, com cardinalidade nas pontas.
            [
                // USUARIO (1) ──faz──< (N) PEDIDO   => relacionamento 1:N
                $this->edge1paraN('usuario', 'faz'),      // lado "1"
                $this->edge1paraN('faz', 'pedido', muitos: true), // lado "N" (com seta)

                // PEDIDO (N) >──contem──< (M) PRODUTO => relacionamento N:M
                $this->edgeNparaM('pedido', 'contem', 'N'),
                $this->edgeNparaM('contem', 'produto', 'M'),

                // USUARIO (1) ──possui── (1) PERFIL  => relacionamento 1:1
                $this->edge1para1('usuario', 'possui'),
                $this->edge1para1('possui', 'perfil'),
            ],
        );
    }

    // =====================================================================
    //  FÁBRICAS DE NÓS — cada uma só preenche o array no formato do AlpineFlow.
    //  Separadas em métodos p/ deixar explícito QUAL chave produz QUAL forma.
    // =====================================================================

    /** ENTIDADE = retângulo (sem 'shape'). 'flow-node-primary' dá o acento roxo. */
    private function entidade(string $id, string $label, int $x, int $y): array
    {
        return [
            'id' => $id,
            'position' => ['x' => $x, 'y' => $y],
            // SEM 'shape' => retângulo padrão. Esta é a "caixinha quadrada" da entidade.
            'class' => 'flow-node-primary',                 // cor de status nativa (roxo)
            'dimensions' => ['width' => 150, 'height' => 56],
            'data' => ['label' => $label, 'kind' => 'entidade'],
        ];
    }

    /** RELACIONAMENTO = losango ('shape' => 'diamond'). 'flow-node-warning' = âmbar. */
    private function relacionamento(string $id, string $label, int $x, int $y): array
    {
        return [
            'id' => $id,
            'position' => ['x' => $x, 'y' => $y],
            'shape' => 'diamond',                            // <- losango nativo
            'class' => 'flow-node-warning',                  // cor de status (âmbar)
            'dimensions' => ['width' => 120, 'height' => 120],
            'data' => ['label' => $label, 'kind' => 'relacionamento'],
        ];
    }

    /** ATRIBUTO = círculo ('shape' => 'circle'). $isPk marca chave-primária (sublinhada). */
    private function atributo(string $id, string $label, int $x, int $y, bool $isPk = false): array
    {
        return [
            'id' => $id,
            'position' => ['x' => $x, 'y' => $y],
            'shape' => 'circle',                             // <- círculo nativo
            'class' => 'flow-node-info',                     // cor de status (azul)
            'dimensions' => ['width' => 84, 'height' => 84],
            // node.data.pk é lido no Blade p/ sublinhar a PK (convenção do DER).
            'data' => ['label' => $label, 'kind' => 'atributo', 'pk' => $isPk],
        ];
    }

    // =====================================================================
    //  FÁBRICAS DE ARESTAS — aqui mora o "design dos edges": tipo de linha,
    //  pontas (markers) e cardinalidade (labels). Doc geral:
    //  https://artisanflow.dev/docs/alpineflow/edges/types
    // =====================================================================

    /** Liga um ATRIBUTO à ENTIDADE: linha fina, cinza, reta, sem seta nem rótulo. */
    private function atributoEdge(string $entidade, string $atributo): array
    {
        return [
            'id' => "attr-{$entidade}-{$atributo}",
            'source' => $entidade,
            'target' => $atributo,
            'type' => 'floating',          // ponta gruda na borda do círculo/retângulo
            'pathType' => 'straight',      // reta (atributo de Chen é linha simples)
            'color' => '#475569',
            'strokeWidth' => 1.2,
        ];
    }

    /**
     * Lado de um relacionamento 1:N.
     *   - lado "1": linha azul fina, rótulo central "1", sem seta.
     *   - lado "N" ($muitos = true): rótulo "N" + marcador 'arrowclosed' apontando p/
     *     o lado "muitos" (o jeito do WireFlow de indicar a direção da cardinalidade).
     * Doc dos markers: https://artisanflow.dev/docs/alpineflow/edges/markers
     */
    private function edge1paraN(string $source, string $target, bool $muitos = false): array
    {
        return [
            'id' => "rel-{$source}-{$target}",
            'source' => $source,
            'target' => $target,
            'type' => 'floating',
            'pathType' => 'smoothstep',                       // cantos retos arredondados
            'color' => '#3b82f6',
            'strokeWidth' => 2,
            'label' => $muitos ? 'N' : '1',                   // cardinalidade em texto
            // No lado "muitos" usamos a seta cheia como "pé" da relação:
            'markerEnd' => $muitos ? ['type' => 'arrowclosed', 'color' => '#3b82f6'] : null,
        ];
    }

    /** Lado de um relacionamento N:M. $card é o rótulo ('N' de um lado, 'M' do outro). */
    private function edgeNparaM(string $source, string $target, string $card): array
    {
        return [
            'id' => "rel-{$source}-{$target}",
            'source' => $source,
            'target' => $target,
            'type' => 'floating',
            'pathType' => 'smoothstep',
            'color' => '#a855f7',                              // roxo p/ distinguir N:M
            'strokeWidth' => 2,
            'label' => $card,
            // N:M sem seta: ambos os lados são "muitos" (associação simétrica).
        ];
    }

    /** Lado de um relacionamento 1:1: rótulo "1" nas duas pontas, linha verde, sem seta. */
    private function edge1para1(string $source, string $target): array
    {
        return [
            'id' => "rel-{$source}-{$target}",
            'source' => $source,
            'target' => $target,
            'type' => 'floating',
            'pathType' => 'straight',
            'color' => '#10b981',                              // verde p/ distinguir 1:1
            'strokeWidth' => 2,
            'label' => '1',
        ];
    }

    // =====================================================================
    //  HANDLERS DE EVENTOS / AÇÕES (servidor) — demonstram os helpers flow*.
    //  Doc: https://artisanflow.dev/docs/wireflow  (Convenience methods)
    // =====================================================================

    /**
     * onNodeClick() — handler do evento @node-click do <x-flow>. O WireFlow injeta
     * (string $nodeId, array $node) ao clicar numa forma. Aqui: toggle de seleção,
     * preenche o painel lateral e dá um "flash" verde de feedback.
     */
    public function onNodeClick(string $nodeId, array $node): void
    {
        if ($this->selectedNode === $nodeId) {     // clicou de novo => desseleciona
            $this->selectedNode = null;
            $this->selectedData = null;
            return;
        }

        $this->selectedNode = $nodeId;
        $this->selectedData = $node['data'] ?? null;

        // flowHighlightNode(id, preset): pisca um estado visual e reverte sozinho.
        // Presets: 'success' | 'error' | 'warning' | 'info'.
        $this->flowHighlightNode($nodeId, 'info', duration: 1200);

        // flowFocusNode(id, duration?, padding?): pan + zoom centralizando no node.
        $this->flowFocusNode($nodeId, 400, 0.5);
    }

    /** Botão "Fit View": flowFitView() reposiciona o zoom p/ todos os nós caberem. */
    public function fitView(): void
    {
        $this->flowFitView();
    }

    /** Botão "X" do painel: limpa a seleção (o @if no Blade esconde o painel). */
    public function closePanel(): void
    {
        $this->selectedNode = null;
        $this->selectedData = null;
    }

    /**
     * render() — devolve a view do componente.
     * Doc: https://livewire.laravel.com/docs/components#rendering-components
     */
    public function render()
    {
        return view('livewire.diagrama-banco');
    }
}
