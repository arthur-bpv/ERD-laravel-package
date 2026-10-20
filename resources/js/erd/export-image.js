// Capture the same AlpineFlow DOM used by both editors, including its nodes and edges.
// The full-diagram scope fits every node while preserving their relative positions.
const exporting = new WeakSet();
const EXPORT_SCALE = 2;
const MAX_EXPORT_EDGE = 8192;

export function exportImageSize(nodes) {
    const visible = nodes.filter((node) => !node.hidden);
    if (visible.length === 0) return { width: 2560, height: 1440 };

    const left = Math.min(...visible.map((node) => node.position.x));
    const top = Math.min(...visible.map((node) => node.position.y));
    const right = Math.max(...visible.map((node) => node.position.x + (node.dimensions?.width || 0)));
    const bottom = Math.max(...visible.map((node) => node.position.y + (node.dimensions?.height || 0)));

    // AlpineFlow rasteriza o SVG do quadro. Exportar em 2× preserva a leitura
    // de texto e linhas finas; 8K é o maior lado ainda suportado de forma
    // confiável pelos canvases modernos sem criar uma imagem desnecessariamente
    // pesada para diagramas pequenos.
    return {
        width: Math.min(MAX_EXPORT_EDGE, Math.max(2560, Math.ceil((right - left) * EXPORT_SCALE))),
        height: Math.min(MAX_EXPORT_EDGE, Math.max(1440, Math.ceil((bottom - top) * EXPORT_SCALE))),
    };
}

function download(dataUrl, filename) {
    const link = document.createElement('a');
    link.href = dataUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
}

async function asJpeg(pngUrl, background) {
    const image = new Image();
    image.src = pngUrl;
    await image.decode();

    const canvas = document.createElement('canvas');
    canvas.width = image.naturalWidth;
    canvas.height = image.naturalHeight;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Não foi possível criar a imagem JPG.');
    context.fillStyle = background;
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 0, 0);
    return canvas.toDataURL('image/jpeg', 0.95);
}

window.exportBoardImage = async (button, format) => {
    const board = button.closest('.er-board, .relational-board');
    const container = board?.querySelector('.flow-container');
    const flow = container && window.Alpine.$data(container);

    if (!flow?.toImage) {
        window.alert('Não há um diagrama para exportar.');
        return;
    }
    if (exporting.has(container)) return;
    exporting.add(container);

    try {
        const background = getComputedStyle(container).getPropertyValue('--flow-bg-color').trim() || '#ffffff';
        // AlpineFlow serializes the DOM as SVG. An unbound `wire:` attribute
        // makes that SVG invalid, so keep Livewire's directive off only while
        // the library captures the canvas and restore it immediately afterward.
        const wireIgnore = container.getAttribute('wire:ignore');
        if (wireIgnore !== null) container.removeAttribute('wire:ignore');
        let png;
        try {
            png = await flow.toImage({ scope: 'all', background, ...exportImageSize(flow.nodes) });
        } finally {
            if (wireIgnore !== null) container.setAttribute('wire:ignore', wireIgnore);
        }
        const image = format === 'jpg' ? await asJpeg(png, background) : png;
        const name = (button.dataset.exportName || 'diagrama')
            .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'diagrama';
        download(image, `${name}-${board.classList.contains('er-board') ? 'er' : 'relacional'}.${format}`);
    } catch (error) {
        console.error('Falha ao exportar o diagrama:', error);
        window.alert('Não foi possível exportar a imagem. Tente novamente.');
    } finally {
        exporting.delete(container);
    }
};
