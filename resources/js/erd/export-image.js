// Capture the same AlpineFlow DOM used by both editors, including its nodes and edges.
// The full-diagram scope fits every node while preserving their relative positions.
const exporting = new WeakSet();

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
            png = await flow.toImage({ scope: 'all', background });
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
