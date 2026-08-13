import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { Album, HomeLensResponse } from '../lib/types';
import { HomeLensPage } from './home-lens';

vi.mock('../lib/api', () => ({ api: { homeLens: vi.fn() } }));

const album: Album = {
    id: 'album-1', plex_item_id: null, title: 'Waiting Album', artist: null, year: 2024,
    artwork: null, added_at: null, duration_ms: null, track_count: null, last_heard_at: null,
    play_count: null, listening_signals: null, release_type: 'Album', first_release_date: null,
    genres: [], genre_basis: null, labels: [], disambiguation: null, sources: ['Plex'],
    owned: true, metadata_status: 'identified', identity_status: 'confirmed', open_in_plex_available: true, open_in_plex_status: 'unavailable',
    qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=Waiting%20Album',
};

function response(data = true): HomeLensResponse {
    const version = 'a'.repeat(64);
    return {
        data: data ? [{ album, lens: 'Waiting on your shelves', reasons: [{ code: 'no_listen_signal', text: 'No matched listening signal.', source: 'plex' }] }] : [],
        section: { type: 'waiting', title: 'Waiting on your shelves', description: 'Owned albums with no matched listening signal.' },
        meta: { version, current_page: 2, last_page: 3, per_page: 24, total: data ? 49 : 0 },
        links: {
            first: `https://disco.test/api/v1/home/lenses/waiting?page=1&size=24&version=${version}`,
            prev: `https://disco.test/api/v1/home/lenses/waiting?page=1&size=24&version=${version}`,
            next: `https://disco.test/api/v1/home/lenses/waiting?page=3&size=24&version=${version}`,
            last: `https://disco.test/api/v1/home/lenses/waiting?page=3&size=24&version=${version}`,
        },
    };
}

function renderPage() {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const version = 'a'.repeat(64);

    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={[`/discover/lenses/waiting?page=2&version=${version}`]}>
                <Routes><Route path="/discover/lenses/:lens" element={<HomeLensPage />} /></Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('HomeLensPage', () => {
    beforeEach(() => vi.mocked(api.homeLens).mockReset());
    afterEach(cleanup);

    it('presents the lens definition, rationale, and pinned pagination', async () => {
        vi.mocked(api.homeLens).mockResolvedValue(response());
        renderPage();

        expect(await screen.findByRole('heading', { name: 'Waiting on your shelves' })).toBeVisible();
        expect(screen.getByText(/49 albums currently match this definition/)).toBeVisible();
        expect(screen.getByLabelText('Why this was recommended')).toHaveTextContent('No matched listening signal.');
        expect(screen.getByRole('button', { name: 'Load more' })).toBeVisible();
        expect(vi.mocked(api.homeLens)).toHaveBeenCalledWith('waiting', 2, 24, 'a'.repeat(64));
    });

    it('shows an explicit exhausted state', async () => {
        vi.mocked(api.homeLens).mockResolvedValue(response(false));
        renderPage();

        expect(await screen.findByRole('heading', { name: 'No albums on this page' })).toBeVisible();
    });
});
