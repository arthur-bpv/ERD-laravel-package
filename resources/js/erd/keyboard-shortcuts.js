export function isTyping(target) {
    return Boolean(target?.closest?.('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"]'));
}

export function installBoardShortcuts(root, wire, mode) {
    let row = null;
    let busy = false;
    const controller = new AbortController();
    root.addEventListener('pointerdown', (event) => {
        row?.classList.remove('ring-2', 'ring-blue-400');
        row = event.target.closest('[data-board-column]');
        row?.classList.add('ring-2', 'ring-blue-400');
    }, { capture: true, signal: controller.signal });

    window.addEventListener('keydown', async (event) => {
        if (!root.isConnected || event.isComposing) return;
        const save = (event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 's';
        const remove = ['Delete', 'Backspace'].includes(event.key) && !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey;
        if (!save && !remove) return;
        if (remove && (isTyping(event.target) || [...root.querySelectorAll('[role="dialog"]')].some(dialog => dialog.getClientRects().length))) {
            event.stopImmediatePropagation();
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation(); // Keep canvas-only deletion from bypassing server state.
        if (busy || event.repeat) return;
        busy = true;
        try {
            if (save) {
                document.activeElement?.blur();
                const button = root.querySelector('[data-board-save]');
                if (button && !button.disabled) button.click();
                return;
            }
            if (row?.isConnected) {
                if (mode === 'er') await wire.removeAttribute(row.dataset.boardTable, row.dataset.boardColumn);
                else await wire.deleteColumn(row.dataset.boardTable, row.dataset.boardColumn);
                row = null;
                return;
            }
            const container = root.querySelector('.flow-container');
            const flow = container && window.Alpine.$data(container);
            const nodes = [...(flow?.selectedNodes ?? [])];
            const edges = [...(flow?.selectedEdges ?? [])];
            if (!nodes.length && !edges.length) return;
            if (mode === 'relational') {
                if (!window.confirm('Excluir os itens selecionados e seus relacionamentos?')) return;
                await wire.deleteSelection(nodes, edges);
            } else {
                for (const id of nodes) {
                    const node = flow.nodes.find(node => node.id === id);
                    if (node?.data?.relationId && node.data.attrId) await wire.removeRelationAttribute(node.data.relationId, node.data.attrId);
                    else if (node?.data?.relationId) await wire.deleteRelation(node.data.relationId);
                    else {
                        // Reuse the button's confirmation and Livewire action verbatim.
                        const button = [...root.querySelectorAll('[data-board-delete-entity]')]
                            .find(button => button.dataset.boardDeleteEntity === id);
                        button?.click();
                    }
                }
                for (const id of edges) {
                    const edge = flow.edges.find(edge => edge.id === id);
                    await wire.deleteRelation(edge?.data?.relationId ?? id);
                }
            }
        } finally {
            busy = false;
        }
    }, { capture: true, signal: controller.signal });
    // Alpine automatically calls function-valued x-init results. Returning an
    // object keeps initialization from immediately running the cleanup callback.
    return { destroy: () => controller.abort() };
}
