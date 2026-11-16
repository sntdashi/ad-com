import { useCallback, useEffect, useRef, useState } from 'react';
import { errorMessage, request } from './api';

export function useRemote<T>(url: string) {
    const [data, setData] = useState<T | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const current = useRef<AbortController | null>(null);
    const reload = useCallback(async () => {
        current.current?.abort();
        const controller = new AbortController();
        current.current = controller;
        setLoading(true);
        try {
            const result = await request<T>(url, { signal: controller.signal });
            if (!controller.signal.aborted) {
                setData(result);
                setError('');
            }
        } catch (err) {
            if (!controller.signal.aborted) setError(errorMessage(err));
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    }, [url]);
    useEffect(() => {
        setData(null);
        void reload();
        return () => current.current?.abort();
    }, [reload]);
    return { data, error, loading, reload };
}
