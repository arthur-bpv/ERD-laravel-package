export async function copyBoardText(text) {
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);
            return;
        } catch {
            // Some browsers expose Clipboard API but reject writes on HTTP.
        }
    }

    const field = document.createElement('textarea');
    field.value = text;
    field.style.position = 'fixed';
    field.style.left = '-9999px';
    document.body.appendChild(field);

    try {
        field.focus();
        field.select();
        if (! document.execCommand('copy')) {
            throw new Error('Clipboard write was rejected.');
        }
    } finally {
        field.remove();
    }
}
