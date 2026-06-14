import AlpineFlow from '../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';
import './bootstrap';

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(AlpineFlow);
});
