import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { AdminOperationsResponse, AdminOverview, AdminProvidersResponse } from '../lib/types';
import { AdminPage } from './admin';

vi.mock('../lib/api', () => ({
    ApiError: class ApiError extends Error {},
    api: {
        adminOverview: vi.fn(),
        adminProviders: vi.fn(),
        adminOperations: vi.fn(),
        runAdminOperation: vi.fn(),
        updateAdminProvider: vi.fn(),
        removeAdminProvider: vi.fn(),
    },
}));

const provider = {
    provider: 'listenbrainz',
    source: 'missing' as const,
    configured: false,
    tested_at: null,
};

const operation = {
    id: 'operation-1',
    operation_key: 'plex.sync',
    status: 'succeeded',
    result: { albums: 12 },
    error_code: null,
    queued_at: '2026-07-27T08:00:00Z',
    started_at: '2026-07-27T08:00:01Z',
    finished_at: '2026-07-27T08:01:00Z',
};

const overview: AdminOverview = {
    pipelines: [{
        key: 'plex',
        name: 'Plex collection',
        provider: 'Plex',
        status: 'healthy',
        detail: 'The library is current.',
        cadence: 'Hourly',
        last_activity_at: '2026-07-27T08:01:00Z',
        next_run_at: '2026-07-27T08:15:00Z',
        metrics: [],
    }],
    operations: [operation],
    failed_jobs: 3,
    providers: [provider],
};
const providers: AdminProvidersResponse = [provider];
const recentOperations: AdminOperationsResponse = [operation];

function renderPage() {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter><AdminPage /></MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('AdminPage', () => {
    beforeEach(() => {
        vi.mocked(api.adminOverview).mockResolvedValue(overview);
        vi.mocked(api.adminProviders).mockResolvedValue(providers);
        vi.mocked(api.adminOperations).mockResolvedValue(recentOperations);
        vi.mocked(api.runAdminOperation).mockResolvedValue(operation);
        vi.mocked(api.updateAdminProvider).mockResolvedValue({ ...provider, source: 'database', configured: true, tested_at: '2026-07-27T09:00:00Z' });
        vi.mocked(api.removeAdminProvider).mockResolvedValue(provider);
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders operational, queue, provider, and metadata context', async () => {
        renderPage();

        expect(await screen.findByRole('heading', { name: 'Administration' })).toBeVisible();
        expect(await screen.findByRole('heading', { name: 'Pipeline ledger' })).toBeVisible();
        expect(screen.getByText('Plex collection')).toBeVisible();
        expect(screen.getByText('jobs need attention')).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Recent operations' })).toBeVisible();
        expect(screen.getByText('plex.sync')).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Provider access' })).toBeVisible();
        expect(screen.getByRole('link', { name: /Open metadata atlas/ })).toHaveAttribute('href', '/metadata');
        expect(screen.getAllByRole('button', { name: /^Run / })).toHaveLength(9);
        expect(screen.getByRole('button', { name: 'Run Current catalog enrichment' })).toBeVisible();
    });

    it('queues a fixed operation', async () => {
        renderPage();
        const button = await screen.findByRole('button', { name: 'Run Plex library sync' });

        fireEvent.click(button);

        await waitFor(() => expect(api.runAdminOperation).toHaveBeenCalledWith('plex.sync'));
        expect(await screen.findByText('Operation queued.')).toBeVisible();
    });

    it('activates a provider through write-only fields and clears them', async () => {
        renderPage();
        const form = await screen.findByRole('form', { name: 'Activate ListenBrainz' });
        const secret = within(form).getByLabelText('New secret');
        const password = within(form).getByLabelText('Current password');

        expect(secret).toHaveValue('');
        expect(password).toHaveValue('');
        expect(screen.queryByText('existing-secret-value')).not.toBeInTheDocument();

        fireEvent.change(secret, { target: { value: 'new-provider-secret' } });
        fireEvent.change(password, { target: { value: 'owner-password' } });
        fireEvent.submit(form);

        await waitFor(() => expect(api.updateAdminProvider).toHaveBeenCalledWith('listenbrainz', {
            secret: 'new-provider-secret',
            current_password: 'owner-password',
        }));
        await waitFor(() => {
            expect(secret).toHaveValue('');
            expect(password).toHaveValue('');
        });
        expect(screen.getByText('Credential accepted and cleared from this form.')).toBeVisible();
        expect(screen.queryByText('new-provider-secret')).not.toBeInTheDocument();
    });
});
