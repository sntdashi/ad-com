export function normalizeApiUrl(value: string, preview: boolean): string {
    const url = new URL(value.trim());
    if (
        !['https:', 'http:'].includes(url.protocol) ||
        url.username ||
        url.password ||
        url.search ||
        url.hash
    ) {
        throw new Error(
            'Gunakan alamat server HTTP/HTTPS tanpa kredensial, query, atau tanda #.',
        );
    }
    if (!preview && url.protocol !== 'https:')
        throw new Error('Aplikasi produksi wajib menggunakan HTTPS.');
    const path = url.pathname.replace(/\/+$/, '');
    if (path && path !== '/api/v1')
        throw new Error('Gunakan alamat utama server atau akhiran /api/v1.');
    return `${url.origin}/api/v1`;
}

export function mergeMessages<T extends { id: number }>(
    existing: T[],
    incoming: T[],
): T[] {
    const byId = new Map(existing.map((message) => [message.id, message]));
    for (const message of incoming) byId.set(message.id, message);
    return [...byId.values()].sort((a, b) => a.id - b.id);
}
