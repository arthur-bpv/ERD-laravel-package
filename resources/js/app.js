import './bootstrap';
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import AlpineFlow from '../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';
import './erd/markers';
import './erd/edge-editor';

Alpine.plugin(AlpineFlow);

window.Alpine = Alpine;

Livewire.start();
