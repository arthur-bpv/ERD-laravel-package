import { registerMarker } from '../../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';

/*
 * -------------------------------------------------------------------------
 * Crow's foot markers (IE notation) for AlpineFlow.
 *
 * AlpineFlow provides only `arrow` and `arrowclosed` by default. Each marker
 * factory receives { id, color, width, height, orient } and returns SVG.
 *
 * Two geometry choices prevent marker distortion:
 *
 * 1. userSpaceOnUse keeps marker size independent of edge stroke width.
 *
 * 2. The viewBox and rendered marker share the same aspect ratio.
 *
 * The viewBox origin touches the line endpoint. The symbol extends toward
 * negative x; auto-start-reverse handles either end of the edge.
 * -------------------------------------------------------------------------
 */

// The full symbol fits behind its anchor in a box measured in pixels.
const MW = 40;
const MH = 32;
const CF_VIEWBOX = `${-MW} ${-MH / 2} ${MW} ${MH}`;

/*
 * Cardinality markers the server can request.
 *
 * Keep this list in sync with SchemaBoard::CARDINALITY_MARKERS. An unregistered
 * marker silently falls back to a closed arrow.
 */
const CARDINALITY_MARKERS = [
    'cf-one-one',
    'cf-zero-one',
    'cf-one-many',
    'cf-zero-many',
];

// converge is the junction of the three strokes of the crow's foot.
const foot = (converge = -18) =>
    `<path d="M ${converge} 0 L 0 -12 M ${converge} 0 L 0 0 M ${converge} 0 L 0 12" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;

const bar = (x) => `<line x1="${x}" y1="-11" x2="${x}" y2="11" stroke-linecap="round"/>`;

const ring = (cx) => `<circle cx="${cx}" cy="0" r="5" fill="var(--er-canvas-bg, #ffffff)"/>`;

/*
 * shapes contains the strokes and rings of the marker.
 *
 * AlpineFlow supplies the current edge color. It deduplicates markers in
 * <defs> by type and color.
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
 * Register only known markers.
 *
 * Surface a mismatch between the JavaScript and PHP lists in the console.
 */
const registerCardinality = (name, marker) => {
    if (!CARDINALITY_MARKERS.includes(name)) {
        console.error(
            `[ERD] Marker "${name}" is missing from CARDINALITY_MARKERS and SchemaBoard::CARDINALITY_MARKERS.`,
        );
    }
    registerMarker(name, marker);
};

// Exactly one → ||
registerCardinality('cf-one-one', crowMarker(bar(-8) + bar(-16)));
// Zero or one → o|
registerCardinality('cf-zero-one', crowMarker(bar(-10) + ring(-22)));
// One or many → |<
registerCardinality('cf-one-many', crowMarker(foot(-18) + bar(-26)));
// Zero or many → o<
registerCardinality('cf-zero-many', crowMarker(foot(-18) + ring(-32)));
