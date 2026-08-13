import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { MetadataCoverage, MetadataDiagnosticsResponse, PipelineDiagnosticsResponse } from '../lib/types';
import { MetadataPage } from './metadata';

vi.mock('../lib/api', () => ({ api: {
    coverage: vi.fn(),
    metadataDiagnostics: vi.fn(),
    retryMetadataDiagnostic: vi.fn(),
    pipelineDiagnostics: vi.fn(),
    retryPipelineDiagnostic: vi.fn(),
} }));

const coverage: MetadataCoverage = {
    entities: [{
        type: 'album', total: 2, identified: 1, missing_identity: 1, enriched: 1, artwork_ready: 1, identity_percentage: 50,
        statuses: {
            identity: { ready: 1, ambiguous: 0, missing: 1 },
            enrichment: { ready: 1, missing: 1 },
            artwork: { ready: 1, stale: 0, failed: 1, pending: 0, missing: 0 },
            narrative: { ready: 1, stale: 0, failed: 0, pending: 0, missing: 1 },
        },
    }],
    overall: { total: 2, identified: 1 },
    pipelines: [{
        key: 'discographies', name: 'Artist discographies', provider: 'MusicBrainz', status: 'building',
        detail: 'The bootstrap processes two missing or expired artists per run.', cadence: 'Every 15 minutes',
        last_activity_at: '2026-07-20T10:00:00Z', next_run_at: '2026-07-20T10:15:00Z',
        metrics: [{ label: 'Fresh', value: 21, status: 'fresh' }, { label: 'Queued', value: 81, status: 'queued' }, { label: 'Eligible', value: 102 }],
    }],
    last_plex_sync_at: null,
    listenbrainz: {
        enabled: true, username: 'owner', observations: 0, current_listens: 0, recording_matched: 0,
        album_matched: 0, unmatched: 0, conflicts: 0, album_match_percentage: 0, latest_listened_at: null,
        last_import_at: null, last_import_status: null, last_full_import_at: null,
    },
};

const diagnostics: MetadataDiagnosticsResponse = {
    data: [{
        id: '11111111-1111-4111-8111-111111111111', type: 'album', category: 'artwork', status: 'failed', title: 'Fixture Album',
        provider: 'plex', last_attempt_at: '2026-07-20T10:00:00Z', failure_category: 'ProviderUnavailable',
        next_retry_at: '2026-07-20T10:05:00Z', retry_supported: true, repair_note: null,
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    links: { first: '/first', prev: null, next: null, last: '/last' },
};

const pipelineDiagnostics: PipelineDiagnosticsResponse = {
    data: [{
        id: '33333333-3333-4333-8333-333333333333', pipeline: 'discography-artwork', status: 'failed', title: 'Failed Cover',
        subject_type: 'album', provider: 'Cover Art Archive', source_basis: 'MusicBrainz release 44444444-4444-4444-8444-444444444444',
        record_url: '/albums/33333333-3333-4333-8333-333333333333', last_attempt_at: '2026-07-19T10:00:00Z',
        failure_category: 'ProviderUnavailable', next_retry_at: '2026-07-20T10:00:00Z', retry_supported: true, repair_note: null,
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    links: { first: '/first', prev: null, next: null, last: '/last' },
};

function renderPage(entry = '/metadata') {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });

    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={[entry]}>
                <Routes><Route path="/metadata" element={<MetadataPage />} /></Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('MetadataPage', () => {
    beforeEach(() => {
        vi.mocked(api.coverage).mockResolvedValue(coverage);
        vi.mocked(api.metadataDiagnostics).mockResolvedValue(diagnostics);
        vi.mocked(api.retryMetadataDiagnostic).mockResolvedValue({ data: { attempted: true, status: 'ready' } });
        vi.mocked(api.pipelineDiagnostics).mockResolvedValue(pipelineDiagnostics);
        vi.mocked(api.retryPipelineDiagnostic).mockResolvedValue({ data: { attempted: true, status: 'ready', failure_category: null } });
    });
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('links aggregate states to exact diagnostics and retries a supported row', async () => {
        renderPage('/metadata?type=album&category=artwork&status=failed&page=1');

        expect(await screen.findByRole('heading', { name: 'album artwork: failed' })).toBeVisible();
        expect(screen.getByText('Fixture Album')).toBeVisible();
        expect(screen.getByText('ProviderUnavailable')).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Enrichment pipelines' })).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Artist discographies' })).toBeVisible();
        expect(screen.getByText('building')).toBeVisible();
        expect(screen.getByText('81')).toBeVisible();
        expect(screen.getByRole('link', { name: 'Artist discographies: Queued 81' })).toHaveAttribute('href', '/metadata?pipeline=discographies&pipeline_status=queued&page=1');
        expect(screen.getAllByRole('link', { name: 'Missing 1' })[0]).toHaveAttribute('href', '/metadata?type=album&category=identity&status=missing&page=1');

        fireEvent.click(screen.getByRole('button', { name: 'Retry safely' }));

        await waitFor(() => expect(api.retryMetadataDiagnostic).toHaveBeenCalledWith('artwork', '11111111-1111-4111-8111-111111111111'));
    });

    it('opens an exact pipeline diagnostic and retries an eligible failure', async () => {
        renderPage('/metadata?pipeline=discography-artwork&pipeline_status=failed&page=1');

        expect(await screen.findByRole('heading', { name: 'discography artwork: failed' })).toBeVisible();
        expect(screen.getByRole('link', { name: 'Failed Cover' })).toHaveAttribute('href', '/albums/33333333-3333-4333-8333-333333333333');
        expect(screen.getByText('MusicBrainz release 44444444-4444-4444-8444-444444444444')).toBeVisible();
        expect(screen.getByText('ProviderUnavailable')).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Retry safely' }));

        await waitFor(() => expect(api.retryPipelineDiagnostic).toHaveBeenCalledWith('discography-artwork', '33333333-3333-4333-8333-333333333333'));
    });
});
