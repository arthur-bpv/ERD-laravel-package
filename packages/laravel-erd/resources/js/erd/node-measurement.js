document.addEventListener('alpine:init', () => {
    window.Alpine.directive('erd-measure-node', (element, { expression }, { cleanup, evaluateLater, effect }) => {
        const evaluateNode = evaluateLater(expression);
        let frame;

        const publish = () => {
            frame = undefined;
            evaluateNode((node) => {
                if (!node) return;

                const dimensions = {
                    width: element.offsetWidth,
                    height: element.offsetHeight,
                };
                if (!dimensions.width || !dimensions.height) return;

                if (node.dimensions?.width !== dimensions.width
                    || node.dimensions?.height !== dimensions.height) {
                    node.dimensions = dimensions;
                }
                element.dispatchEvent(new CustomEvent('erd-node-resized', {
                    bubbles: true,
                    detail: { nodeId: node.id, dimensions },
                }));
            });
        };
        const schedule = () => {
            if (frame === undefined) frame = requestAnimationFrame(publish);
        };

        // Server patches contain estimated dimensions. Reconcile them even
        // when the DOM size stays unchanged (for example after a rename).
        effect(() => evaluateNode((node) => {
            if (node && (node.dimensions?.width !== element.offsetWidth
                || node.dimensions?.height !== element.offsetHeight)) schedule();
        }));
        const observer = new ResizeObserver(schedule);
        observer.observe(element);

        cleanup(() => {
            if (frame !== undefined) cancelAnimationFrame(frame);
            observer.disconnect();
        });
    });
});
