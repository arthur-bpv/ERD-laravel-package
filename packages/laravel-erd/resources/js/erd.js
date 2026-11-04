import '../css/app.css';
import AlpineFlow from '../../../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';
import './erd/markers';
import './erd/canvas-controller';
import './erd/relationship-editor';
import './erd/node-measurement';
import './erd/export-image';
import { relationalSelfLoopPath } from './erd/relational-self-loop';
import { installRelationalConnections } from './erd/relational-connections';
import { installBoardShortcuts } from './erd/keyboard-shortcuts';
import { copyBoardText } from './erd/clipboard';

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(AlpineFlow);
}, { once: true });

window.relationalSelfLoopPath = relationalSelfLoopPath;
window.installRelationalConnections = installRelationalConnections;
window.copyBoardText = copyBoardText;
window.installBoardShortcuts = installBoardShortcuts;

