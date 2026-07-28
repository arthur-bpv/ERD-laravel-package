import { registerMarker } from '../../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';

/*
 * -------------------------------------------------------------------------
 * Marcadores "pé de galinha" (crow's foot / IE notation) para o AlpineFlow.
 *
 * O AlpineFlow só traz `arrow` e `arrowclosed` nativos. Aqui registramos os
 * símbolos de cardinalidade de modelagem de dados. Cada função recebe
 * { id, color, width, height, orient } e devolve um <marker> SVG.
 *
 * DUAS DECISÕES DE GEOMETRIA IMPORTANTES (foram a causa dos bugs de linha):
 *
 * 1. markerUnits="userSpaceOnUse" em vez de "strokeWidth". Com "strokeWidth" o
 *    símbolo era multiplicado pela espessura do traço, então selecionar uma
 *    aresta (que engrossa a linha) fazia o pé de galinha mudar de tamanho.
 *    Em userSpaceOnUse 1 unidade do viewBox = 1 pixel, sempre.
 *
 * 2. O viewBox tem a mesma proporção de markerWidth/markerHeight. Antes o
 *    viewBox era 40x32 e o marcador 26x20 — proporções diferentes distorciam
 *    o desenho.
 *
 * O ponto (0,0) do viewBox é a âncora que encosta na ponta da linha; as
 * "patas" crescem para o x negativo (de volta pela linha). `orient` já vem
 * como "auto-start-reverse", então o símbolo aponta certo tanto no início
 * (markerStart) quanto no fim (markerEnd) da aresta.
 * -------------------------------------------------------------------------
 */

// Caixa do marcador em unidades de usuário (= pixels). O x vai de -MW até 0,
// ou seja: todo o desenho fica ATRÁS da âncora, recuando pela linha.
const MW = 40; // largura
const MH = 32; // altura
const CF_VIEWBOX = `${-MW} ${-MH / 2} ${MW} ${MH}`;

/*
 * Símbolos de cardinalidade que o servidor pode pedir.
 *
 * Serve de conferência: SchemaBoard::CARDINALIDADES precisa listar exatamente
 * estes nomes. Pedir um marcador não registrado faz o AlpineFlow cair no
 * fallback de seta fechada — a relação aparece com uma flecha comum em vez do
 * pé de galinha, sem nenhum erro no console.
 */
const CARDINALIDADES = [
    'cf-one',
    'cf-one-one',
    'cf-zero-one',
    'cf-many',
    'cf-one-many',
    'cf-zero-many',
];

// converge = ponta onde as três patas do pé de galinha se encontram.
const foot = (converge = -18) =>
    `<path d="M ${converge} 0 L 0 -12 M ${converge} 0 L 0 0 M ${converge} 0 L 0 12" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;

const bar = (x) => `<line x1="${x}" y1="-11" x2="${x}" y2="11" stroke-linecap="round"/>`;

const ring = (cx) => `<circle cx="${cx}" cy="0" r="5" fill="var(--er-canvas-bg, #ffffff)"/>`;

/*
 * shapes: string com o "miolo" do marcador (linhas/patas/círculo).
 *
 * `color` vem do AlpineFlow e reflete a cor da aresta no momento em que o
 * marcador é gerado. Os marcadores são deduplicados no <defs> por tipo+cor,
 * então cada cor distinta gera o seu próprio <marker>.
 */
const crowMarker = (shapes) => ({ id, color, orient }) => `<marker
        id="${id}"
        viewBox="${CF_VIEWBOX}"
        markerWidth="${MW}"
        markerHeight="${MH}"
        orient="${orient}"
        markerUnits="userSpaceOnUse"
        refX="0"
        refY="0"
    >
        <g stroke="${color}" stroke-width="1.6" fill="none">${shapes}</g>
    </marker>`;

/*
 * Registra o marcador conferindo que ele consta da lista conhecida.
 *
 * Um símbolo fora de sincronia entre JS e PHP falha em silêncio — o AlpineFlow
 * desenha uma seta fechada e segue em frente. Melhor gritar no console do que
 * descobrir isso olhando o diagrama.
 */
const registrarCardinalidade = (nome, marcador) => {
    if (!CARDINALIDADES.includes(nome)) {
        console.error(
            `[ERD] Marcador "${nome}" não está em CARDINALIDADES. ` +
            'Declare-o aqui e em SchemaBoard::CARDINALIDADES, senão a relação vira uma seta comum.',
        );
    }
    registerMarker(nome, marcador);
};

// Um  (obrigatório, exatamente um)              →  ||
registrarCardinalidade('cf-one-one', crowMarker(bar(-8) + bar(-16)));
// Um  (referência simples)                      →  |
registrarCardinalidade('cf-one', crowMarker(bar(-10)));
// Zero ou um                                    →  o|
registrarCardinalidade('cf-zero-one', crowMarker(bar(-10) + ring(-22)));
// Muitos (pé de galinha)                        →  <
registrarCardinalidade('cf-many', crowMarker(foot(-18)));
// Um ou muitos                                  →  |<
registrarCardinalidade('cf-one-many', crowMarker(foot(-18) + bar(-26)));
// Zero ou muitos                                →  o<
registrarCardinalidade('cf-zero-many', crowMarker(foot(-18) + ring(-32)));
