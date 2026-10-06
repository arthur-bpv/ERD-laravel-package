import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import AlpineFlow from '../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';
import './erd/markers';
import './erd/edge-editor';
import './erd/node-measurement';
import './erd/export-image';
import { relationalSelfLoopPath } from './erd/relational-self-loop';
import { installRelationalConnections } from './erd/relational-connections';
import { copyBoardText } from './erd/clipboard';

Alpine.plugin(AlpineFlow);

window.Alpine = Alpine;
window.relationalSelfLoopPath = relationalSelfLoopPath;
window.installRelationalConnections = installRelationalConnections;
window.copyBoardText = copyBoardText;

Livewire.start();
