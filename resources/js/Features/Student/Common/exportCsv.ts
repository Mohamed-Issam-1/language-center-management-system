export function exportCsv(
    filename: string,
    headers: string[],
    rows: Array<Array<string | number>>,
) {
    const escape = (value: string | number) => {
        const text = String(value).replace(/"/g, '""');
        return `"${text}"`;
    };

    const csv = [
        headers.map(escape).join(','),
        ...rows.map((row) => row.map(escape).join(',')),
    ].join('\n');

    const blob = new Blob([csv], {
        type: 'text/csv;charset=utf-8;',
    });

    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();

    URL.revokeObjectURL(url);
}
