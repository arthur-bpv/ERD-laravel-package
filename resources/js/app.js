import './bootstrap';
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import AlpineFlow, { registerMarker } from '../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';

Alpine.plugin(AlpineFlow);

/*
 * -------------------------------------------------------------------------
 * Marcadores "pé de galinha" (crow's foot / IE notation) para o AlpineFlow.
 *
 * O AlpineFlow só traz `arrow` e `arrowclosed` nativos. Aqui registramos os
 * símbolos de cardinalidade de modelagem de dados. Cada função recebe
 * { id, color, width, height, orient } e devolve um <marker> SVG.
 *
 * O ponto (0,0) do viewBox encosta na borda da entidade; as "patas" crescem
 * para o x negativo (de volta pela linha). `orient` já vem como
 * "auto-start-reverse", então o símbolo aponta certo tanto no início
 * (markerStart) quanto no fim (markerEnd) da aresta.
 * -------------------------------------------------------------------------
 */
const CF_VIEWBOX = '-36 -16 40 32';

// converge = ponta onde as três patas do pé de galinha se encontram.
const foot = (converge = -18) =>
    `<path d="M ${converge} 0 L 0 -12 M ${converge} 0 L 0 0 M ${converge} 0 L 0 12" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;

const bar = (x) => `<line x1="${x}" y1="-11" x2="${x}" y2="11" stroke-linecap="round"/>`;

const ring = (cx, color) => `<circle cx="${cx}" cy="0" r="5" fill="#ffffff" stroke="${color}"/>`;

// shapes: string com o "miolo" do marcador (linhas/patas/círculo).
const crowMarker = (shapes) => ({ id, color, orient }) => `<marker
        id="${id}"
        viewBox="${CF_VIEWBOX}"
        markerWidth="26"
        markerHeight="20"
        orient="${orient}"
        markerUnits="strokeWidth"
        refX="0"
        refY="0"
    >
        <g stroke="${color}" stroke-width="1.6" fill="none">${shapes}</g>
    </marker>`;

// Um  (obrigatório, exatamente um)              →  ||
registerMarker('cf-one-one', crowMarker(bar(-7) + bar(-14)));
// Um  (referência simples)                      →  |
registerMarker('cf-one', crowMarker(bar(-9)));
// Zero ou um                                    →  o|
registerMarker('cf-zero-one', ({ id, color, orient }) => crowMarker(bar(-8) + ring(-18, color))({ id, color, orient }));
// Muitos (pé de galinha)                        →  <
registerMarker('cf-many', crowMarker(foot(-18)));
// Um ou muitos                                  →  |<
registerMarker('cf-one-many', crowMarker(foot(-18) + bar(-26)));
// Zero ou muitos                                →  o<
registerMarker('cf-zero-many', ({ id, color, orient }) =>
    crowMarker(foot(-18))({ id, color, orient }).replace('</g>', `</g><circle cx="-28" cy="0" r="5" fill="#ffffff" stroke="${color}" stroke-width="1.6"/>`),
);

/*
 * -------------------------------------------------------------------------
 * Editor de relacionamento (estilo ERDPlus): clique numa aresta e troque a
 * cardinalidade de cada ponta. Tudo no cliente — a aresta pode ter sido
 * criada arrastando (o servidor nem conhece o id), então lemos/gravamos
 * direto no store do AlpineFlow via a magia $flow.
 *
 * O store.update() NÃO altera markerStart/markerEnd, então trocamos o
 * marcador removendo e re-adicionando a aresta (mesmo id) — re-render limpo.
 * -------------------------------------------------------------------------
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('erdEdgeEditor', () => ({
        e: null, // aresta selecionada (reativa, via x-effect no template)

        // paleta de cardinalidade (pé de galinha / notação IE)
        options: [
            { m: 'cf-one-one', s: '&#8214;', t: 'um e só um' },
            { m: 'cf-zero-one', s: '&#9711;|', t: 'zero ou um' },
            { m: 'cf-one', s: '|', t: 'um' },
            { m: 'cf-many', s: '&lt;', t: 'muitos' },
            { m: 'cf-one-many', s: '|&lt;', t: 'um ou muitos' },
            { m: 'cf-zero-many', s: '&#9711;&lt;', t: 'zero ou muitos' },
        ],

        _selected() {
            return this.$flow.edges.find((x) => x.selected) || null;
        },

        // Remove + re-adiciona a aresta com a mudança aplicada (mesmo id).
        _replace(mutate) {
            const cur = this._selected();
            if (!cur) return;
            const clone = JSON.parse(JSON.stringify(cur));
            mutate(clone);
            clone.selected = true;
            this.$flow.removeEdges([clone.id]);
            this.$flow.addEdges([clone]);
            this.$flow.selectedEdges.add(clone.id);
        },

        setEnd(end, marker) {
            this._replace((c) => {
                c[end] = marker; // end = 'markerStart' (filho) | 'markerEnd' (pai)
            });
        },

        swap() {
            this._replace((c) => {
                [c.source, c.target] = [c.target, c.source];
                [c.sourceHandle, c.targetHandle] = [c.targetHandle, c.sourceHandle];
                [c.markerStart, c.markerEnd] = [c.markerEnd, c.markerStart];
            });
        },

        remove() {
            const cur = this._selected();
            if (cur) this.$flow.removeEdges([cur.id]);
            this.e = null;
        },
    }));
});

window.Alpine = Alpine;

Livewire.start();
