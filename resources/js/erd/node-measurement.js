import { Alpine } from '../../../vendor/livewire/livewire/dist/livewire.esm';

document.addEventListener('alpine:init', () => {
    Alpine.directive('erd-measure-node', (element, { expression }, { cleanup, evaluateLater }) => {
        const evaluateNode = evaluateLater(expression);

        const publish = () => {
            evaluateNode((node) => {
                if (!node) return;

                const dimensions = {
                    width: element.offsetWidth,
                    height: element.offsetHeight,
                };

                if (node.dimensions?.width === dimensions.width
                    && node.dimensions?.height === dimensions.height) return;

                node.dimensions = dimensions;
                element.dispatchEvent(new CustomEvent('erd-node-resized', {
                    bubbles: true,
                    detail: { nodeId: node.id, dimensions },
                }));
            });
        };

        const observer = new ResizeObserver(publish);
        observer.observe(element);
        const initialFrame = requestAnimationFrame(publish);

        cleanup(() => {
            cancelAnimationFrame(initialFrame);
            observer.disconnect();
        });
    });
});
