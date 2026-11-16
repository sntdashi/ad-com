import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import { normalizeApiUrl } from './connection';

const rawUrl =
    process.env.EXPO_PUBLIC_API_URL ||
    (Platform.OS === 'android'
        ? 'http://10.0.2.2:8000/api/v1'
        : 'http://localhost:8000/api/v1');
export const IS_PREVIEW = process.env.EXPO_PUBLIC_APP_VARIANT !== 'production';
export let API_URL = normalizeApiUrl(rawUrl, IS_PREVIEW);
export let WEB_URL = API_URL.replace(/\/api\/v1$/, '');
const tokenKey = 'adcom.mobile.token';
let bearer: string | null = null;
let onUnauthorized: (() => void) | undefined;

export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public code?: string,
        public errors?: Record<string, string[]>,
    ) {
        super(message);
    }
}

export function setUnauthorizedHandler(handler: () => void) {
    onUnauthorized = handler;
}
export async function restoreToken() {
    const server = IS_PREVIEW
        ? await SecureStore.getItemAsync('adcom.mobile.server')
        : null;
    if (server) {
        API_URL = normalizeApiUrl(server, true);
        WEB_URL = API_URL.replace(/\/api\/v1$/, '');
    }
    bearer = await SecureStore.getItemAsync(tokenKey);
    return bearer;
}
export async function changeServer(url: string) {
    if (!IS_PREVIEW) throw new Error('Server produksi tidak dapat diubah.');
    const next = normalizeApiUrl(url, true);
    await saveToken(null);
    await SecureStore.setItemAsync('adcom.mobile.server', next);
    API_URL = next;
    WEB_URL = next.replace(/\/api\/v1$/, '');
}
export async function saveToken(value: string | null) {
    if (value) await SecureStore.setItemAsync(tokenKey, value);
    else await SecureStore.deleteItemAsync(tokenKey);
    bearer = value;
}

export async function request<T>(
    path: string,
    options: { method?: string; body?: unknown; signal?: AbortSignal } = {},
): Promise<T> {
    const requestToken = bearer;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    const cancel = () => controller.abort();
    options.signal?.addEventListener('abort', cancel);
    if (options.signal?.aborted) controller.abort();
    const multipart = options.body instanceof FormData;
    try {
        const response = await fetch(
            `${API_URL}/${path.replace(/^\/?api\/v1\/?|^\//, '')}`,
            {
                method: options.method || 'GET',
                headers: {
                    Accept: 'application/json',
                    ...(multipart
                        ? {}
                        : { 'Content-Type': 'application/json' }),
                    ...(requestToken
                        ? { Authorization: `Bearer ${requestToken}` }
                        : {}),
                },
                credentials: 'omit',
                body:
                    options.body === undefined
                        ? undefined
                        : multipart
                          ? (options.body as FormData)
                          : JSON.stringify(options.body),
                signal: controller.signal,
            },
        );
        if (response.status === 204) return undefined as T;
        const data = await response.json().catch(() => ({
            message: 'Server mengirim respons yang tidak dapat dibaca.',
        }));
        if (!response.ok) {
            if (
                (response.status === 401 ||
                    [
                        'mobile_role_not_supported',
                        'two_factor_setup_required',
                    ].includes(data.code)) &&
                requestToken &&
                bearer === requestToken
            ) {
                await saveToken(null);
                onUnauthorized?.();
            }
            const firstError = data.errors
                ? Object.values(data.errors as Record<string, string[]>)[0]?.[0]
                : undefined;
            throw new ApiError(
                firstError || data.message || 'Permintaan belum berhasil.',
                response.status,
                data.code,
                data.errors,
            );
        }
        return data as T;
    } catch (error) {
        if (error instanceof ApiError) throw error;
        if (controller.signal.aborted)
            throw new ApiError(
                'Koneksi terlalu lama. Periksa internet lalu coba lagi.',
                0,
            );
        throw new ApiError(
            'Tidak dapat terhubung ke AD Com. Periksa koneksi internet.',
            0,
        );
    } finally {
        clearTimeout(timeout);
        options.signal?.removeEventListener('abort', cancel);
    }
}

export function mediaUrl(url: string | null | undefined) {
    if (!url) return undefined;
    if (url.startsWith('/')) return `${WEB_URL}${url}`;
    return url.replace(/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?/, WEB_URL);
}
export const errorMessage = (error: unknown) =>
    error instanceof Error ? error.message : 'Terjadi kesalahan. Coba lagi.';
export const money = (value?: number | null) =>
    value == null
        ? 'Menunggu estimasi'
        : `Rp ${Number(value).toLocaleString('id-ID')}`;
export const dateLabel = (value?: string | null) =>
    value
        ? new Date(value).toLocaleString('id-ID', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : 'Belum dijadwalkan';
