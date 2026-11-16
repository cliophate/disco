import type { AdminOverview, AdminProvider, AdminProviderCredential, DiscoverySection, User } from './types';

export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public code: string,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

function csrfToken() {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');

    if (options.body) headers.set('Content-Type', 'application/json');
    if (options.method && options.method !== 'GET') headers.set('X-CSRF-TOKEN', csrfToken());

    const response = await fetch(path, { credentials: 'same-origin', ...options, headers });

    if (!response.ok) {
        let message = response.status === 401 ? 'Your session has expired.' : 'The request could not be completed.';
        let code = 'request_failed';
        let errors: Record<string, string[]> = {};
        try {
            const body = (await response.json()) as { code?: string; message?: string; errors?: Record<string, string[]> };
            message = body.message ?? message;
            code = body.code ?? code;
            errors = body.errors ?? errors;
        } catch {
            // Keep the status-based fallback for non-JSON responses.
        }
        throw new ApiError(message, response.status, code, errors);
    }

    if (response.status === 204) return undefined as T;
    return response.json() as Promise<T>;
}

export const api = {
    me: () => request<{ data: User }>('/api/v1/me').then(r => r.data),
    login: (email: string, password: string) => request('/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) }),
    logout: () => request<void>('/auth/logout', { method: 'POST' }),
    discovery: () => request<{ data: DiscoverySection[] }>('/api/v1/discovery').then(r => r.data),
    pass: (id: string) => request<void>(`/api/v1/discovery/picks/${id}/pass`, { method: 'POST' }),
    adminOverview: () => request<{ data: AdminOverview }>('/api/v1/admin/overview').then(r => r.data),
    updateProvider: (provider: AdminProvider, secret: string, current_password: string) => request<{ data: AdminProviderCredential }>(`/api/v1/admin/providers/${provider}`, { method: 'PUT', body: JSON.stringify({ secret, current_password }) }),
    deleteProvider: (provider: AdminProvider, current_password: string) => request(`/api/v1/admin/providers/${provider}`, { method: 'DELETE', body: JSON.stringify({ current_password }) }),
};
