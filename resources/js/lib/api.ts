import type {
    AlbumDetail,
    AlbumListPage,
    AlbumListState,
    AlbumPage,
    AdminOperationsResponse,
    AdminOverview,
    AdminProvider,
    AdminProviderCredential,
    AdminProvidersResponse,
    AdminOperation,
    ArtistDetail,
    ArtistDiscographyNoise,
    ArtistDiscographyRefresh,
    ArtistDiscographyResponse,
    ArtistDiscographyTypes,
    ArtistDiscographyView,
    ArtistPage,
    BeyondResponse,
    DiscoverResponse,
    ExternalCatalogResult,
    FeedbackAction,
    HomeResponse,
    HomeLensResponse,
    MetadataCoverage,
    MetadataDiagnosticCategory,
    MetadataDiagnosticsResponse,
    MetadataDiagnosticStatus,
    PipelineDiagnosticsResponse,
    PipelineDiagnosticStatus,
    PlaybackSession,
    NotificationFilter,
    NotificationResponse,
    SearchGroups,
    UpcomingResponse,
    UpcomingView,
    User,
    UpcomingNotification,
} from './types';

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

function unwrap<T>(payload: T | { data: T }): T {
    return typeof payload === 'object' && payload !== null && 'data' in payload
        ? (payload as { data: T }).data
        : payload;
}

type AdminOverviewPayload = Omit<AdminOverview, 'operations' | 'failed_jobs'> & {
    operations?: AdminOperation[];
    recent_operations?: AdminOperation[];
    failed_jobs?: number;
    failed_jobs_count?: number;
};

export const api = {
    me: async () => unwrap(await request<User | { data: User }>('/api/v1/me')),
    login: async (email: string, password: string) =>
        unwrap(await request<User | { data: User }>('/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) })),
    logout: () => request<void>('/auth/logout', { method: 'POST' }),
    home: () => request<HomeResponse>('/api/v1/home'),
    homeLens: (type: string, page: number, pageSize: number, version?: string | null) => {
        const query = new URLSearchParams({ page: String(page), size: String(pageSize) });
        if (version) query.set('version', version);

        return request<HomeLensResponse>(`/api/v1/home/lenses/${encodeURIComponent(type)}?${query.toString()}`);
    },
    discover: (page: number, pageSize: number, editionId?: string | null) => {
        const query = new URLSearchParams({
            'page[number]': String(page),
            'page[size]': String(pageSize),
        });
        if (editionId) query.set('edition_id', editionId);

        return request<DiscoverResponse>(`/api/v1/discover?${query.toString()}`);
    },
    upcoming: (view: UpcomingView, page: number, pageSize: number, generationId?: string | null) => {
        const query = new URLSearchParams({
            view,
            'page[number]': String(page),
            'page[size]': String(pageSize),
        });
        if (generationId) query.set('generation_id', generationId);

        return request<UpcomingResponse>(`/api/v1/discover/upcoming?${query.toString()}`);
    },
    notifications: (filter: NotificationFilter, page: number, pageSize: number) => {
        const query = new URLSearchParams({ filter, 'page[number]': String(page), 'page[size]': String(pageSize) });
        return request<NotificationResponse>(`/api/v1/notifications?${query.toString()}`);
    },
    updateNotification: async (id: string, read: boolean) =>
        unwrap(await request<UpcomingNotification | { data: UpcomingNotification }>(`/api/v1/notifications/${encodeURIComponent(id)}`, { method: 'PATCH', body: JSON.stringify({ read }) })),
    beyond: (page: number, pageSize: number, shuffle: string, runId?: string | null, filter = 'all') => {
        const query = new URLSearchParams({
            'page[number]': String(page),
            'page[size]': String(pageSize),
            shuffle,
            type: filter,
        });
        if (runId) query.set('run_id', runId);

        return request<BeyondResponse>(`/api/v1/beyond?${query.toString()}`);
    },
    albums: (page: number, filter: string, sort: string) => {
        const query = new URLSearchParams({ 'page[number]': String(page), 'page[size]': '24', type: filter, sort });
        return request<AlbumPage>(`/api/v1/library/albums?${query.toString()}`);
    },
    wantToListen: (page: number, status: string, ownership: string, sort: string) => {
        const query = new URLSearchParams({ 'page[number]': String(page), 'page[size]': '24', status, ownership, sort });
        return request<AlbumListPage>(`/api/v1/want-to-listen?${query.toString()}`);
    },
    updateAlbumListState: async (id: string, payload: { status: 'want_to_listen' | 'listened'; note?: string | null; source?: string | null }) =>
        unwrap(await request<AlbumListState | { data: AlbumListState }>(`/api/v1/albums/${id}/list-state`, { method: 'PATCH', body: JSON.stringify(payload) })),
    removeAlbumListState: (id: string) => request<void>(`/api/v1/albums/${id}/list-state`, { method: 'DELETE' }),
    artists: (page: number, filter: string, sort: string) => {
        const query = new URLSearchParams({
            page: String(page),
            size: '24',
            type: filter,
            sort,
        });

        return request<ArtistPage>(`/api/v1/artists?${query.toString()}`);
    },
    album: async (id: string) => unwrap(await request<AlbumDetail | { data: AlbumDetail }>(`/api/v1/albums/${id}`)),
    artist: async (id: string) => unwrap(await request<ArtistDetail | { data: ArtistDetail }>(`/api/v1/artists/${id}`)),
    artistDiscography: (id: string, view: ArtistDiscographyView, types: ArtistDiscographyTypes, noise: ArtistDiscographyNoise, page: number, pageSize: number, generationId?: string | null) => {
        const query = new URLSearchParams({
            view,
            types,
            noise,
            'page[number]': String(page),
            'page[size]': String(pageSize),
        });
        if (generationId) query.set('generation_id', generationId);

        return request<ArtistDiscographyResponse>(`/api/v1/artists/${encodeURIComponent(id)}/discography?${query.toString()}`);
    },
    refreshArtistDiscography: async (id: string) =>
        unwrap(await request<ArtistDiscographyRefresh | { data: ArtistDiscographyRefresh }>(`/api/v1/artists/${encodeURIComponent(id)}/discography/refresh`, { method: 'POST' })),
    followArtist: async (id: string) => unwrap(await request<{ artist_id: string; explicit: boolean; implicit: boolean; seed: boolean } | { data: { artist_id: string; explicit: boolean; implicit: boolean; seed: boolean } }>(`/api/v1/artists/${id}/follow`, { method: 'PUT' })),
    unfollowArtist: (id: string) => request<void>(`/api/v1/artists/${id}/follow`, { method: 'DELETE' }),
    search: async (query: string) => {
        const payload = await request<{ data: Omit<SearchGroups, 'meta'>; meta?: SearchGroups['meta'] }>(`/api/v1/search?q=${encodeURIComponent(query)}`);
        return { ...payload.data, meta: payload.meta };
    },
    externalCatalogSearch: async (query: string) =>
        unwrap(await request<ExternalCatalogResult[] | { data: ExternalCatalogResult[] }>(`/api/v1/external-catalog/search?q=${encodeURIComponent(query)}`)),
    selectExternalAlbum: async (mbid: string) =>
        unwrap(await request<{ id: string; owned: boolean; enrichment: { detail: string; credits: string; narrative: string; artwork: string } } | { data: { id: string; owned: boolean; enrichment: { detail: string; credits: string; narrative: string; artwork: string } } }>(`/api/v1/external-catalog/release-groups/${encodeURIComponent(mbid)}`, { method: 'POST' })),
    coverage: async () => unwrap(await request<MetadataCoverage | { data: MetadataCoverage }>('/api/v1/metadata/coverage')),
    metadataDiagnostics: (type: string, category: MetadataDiagnosticCategory, status: MetadataDiagnosticStatus, page: number) => {
        const query = new URLSearchParams({ type, category, status, page: String(page), size: '25' });

        return request<MetadataDiagnosticsResponse>(`/api/v1/metadata/diagnostics?${query.toString()}`);
    },
    retryMetadataDiagnostic: (category: MetadataDiagnosticCategory, id: string) =>
        request<{ data: { attempted: boolean; status: string } }>(`/api/v1/metadata/diagnostics/${encodeURIComponent(category)}/${encodeURIComponent(id)}/retry`, { method: 'POST' }),
    pipelineDiagnostics: (pipeline: string, status: PipelineDiagnosticStatus, page: number) => {
        const query = new URLSearchParams({ status, page: String(page), size: '25' });

        return request<PipelineDiagnosticsResponse>(`/api/v1/metadata/pipelines/${encodeURIComponent(pipeline)}/diagnostics?${query.toString()}`);
    },
    retryPipelineDiagnostic: (pipeline: string, id: string) =>
        request<{ data: { attempted: boolean; status: string; failure_category: string | null } }>(`/api/v1/metadata/pipelines/${encodeURIComponent(pipeline)}/diagnostics/${encodeURIComponent(id)}/retry`, { method: 'POST' }),
    plexTarget: (plexItemId: string) =>
        request<{ status: 'exact'; url: string }>(`/api/v1/plex/open-target/${encodeURIComponent(plexItemId)}`),
    createPlaybackSession: async (mediaPartId: string) =>
        unwrap(await request<PlaybackSession | { data: PlaybackSession }>('/api/v1/playback/sessions', {
            method: 'POST',
            body: JSON.stringify({ media_part_id: mediaPartId }),
        })),
    updatePlaybackSession: async (sessionId: string, state: 'playing' | 'paused' | 'stopped' | 'ended', positionMs: number) =>
        unwrap(await request<{ state: string; position_ms: number; scrobbled: boolean } | { data: { state: string; position_ms: number; scrobbled: boolean } }>(`/api/v1/playback/sessions/${encodeURIComponent(sessionId)}`, {
            method: 'PATCH',
            body: JSON.stringify({ state, position_ms: Math.max(0, Math.round(positionMs)) }),
        })),
    destroyPlaybackSession: (sessionId: string) =>
        request<void>(`/api/v1/playback/sessions/${encodeURIComponent(sessionId)}`, { method: 'DELETE', keepalive: true }),
    recommendationFeedback: (editionId: string, entityId: string, action: 'interested' | 'not_for_me' | 'already_know' | 'wrong_match') =>
        request<{ data: { id: string; action: string } }>(`/api/v1/home/editions/${encodeURIComponent(editionId)}/recommendations/${encodeURIComponent(entityId)}/feedback`, {
            method: 'PUT',
            body: JSON.stringify({ action }),
        }),
    recommendationItemFeedback: (itemId: string, action: FeedbackAction) =>
        request<{ data: { id: string; action: FeedbackAction } }>(`/api/v1/recommendations/${encodeURIComponent(itemId)}/feedback`, {
            method: 'PUT',
            body: JSON.stringify({ action }),
        }),
    clearRecommendationFeedback: (entityId: string) =>
        request<void>(`/api/v1/recommendation-feedback/${encodeURIComponent(entityId)}`, { method: 'DELETE' }),
    adminOverview: async () => {
        const payload = unwrap(await request<AdminOverviewPayload | { data: AdminOverviewPayload }>('/api/v1/admin/overview'));

        return {
            ...payload,
            operations: payload.operations ?? payload.recent_operations ?? [],
            failed_jobs: payload.failed_jobs ?? payload.failed_jobs_count ?? 0,
        };
    },
    adminProviders: async () => unwrap(await request<AdminProvidersResponse | { data: AdminProvidersResponse }>('/api/v1/admin/providers')),
    updateAdminProvider: async (provider: string, credential: AdminProviderCredential) =>
        unwrap(await request<AdminProvider | { data: AdminProvider }>(`/api/v1/admin/providers/${encodeURIComponent(provider)}`, {
            method: 'PUT',
            body: JSON.stringify(credential),
        })),
    removeAdminProvider: async (provider: string, credential: AdminProviderCredential) =>
        unwrap(await request<AdminProvider | { data: AdminProvider }>(`/api/v1/admin/providers/${encodeURIComponent(provider)}`, {
            method: 'DELETE',
            body: JSON.stringify(credential),
        })),
    adminOperations: async () => unwrap(await request<AdminOperationsResponse | { data: AdminOperationsResponse }>('/api/v1/admin/operations')),
    runAdminOperation: async (operation: string) =>
        unwrap(await request<AdminOperation | { data: AdminOperation }>(`/api/v1/admin/operations/${encodeURIComponent(operation)}`, {
            method: 'POST',
        })),
};
