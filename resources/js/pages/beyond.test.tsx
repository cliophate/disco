import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { Album, BeyondResponse } from '../lib/types';
import { BeyondPage } from './beyond';

vi.mock('../lib/api', () => ({ api: { beyond: vi.fn() } }));
const shuffle = '11111111-1111-4111-8111-111111111111';
const run = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

function album(id: string): Album {
    return { id, plex_item_id: null, title: `Album ${id}`, artist: { id: null, name: 'Fixture Artist', portrait: null, type: null, area: null, genres: [] }, year: 2025, artwork: null, added_at: null, duration_ms: null, track_count: null, last_heard_at: null, play_count: null, listening_signals: null, release_type: 'Album', first_release_date: null, genres: [], genre_basis: null, labels: [], disambiguation: null, sources: ['MusicBrainz'], owned: false, metadata_status: 'enriched', identity_status: 'confirmed', open_in_plex_available: false, open_in_plex_status: 'unavailable', qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=fixture' };
}

function response(ids: string[], page: number, total: number, lastPage: number, filter: BeyondResponse['meta']['filter'] = 'all'): BeyondResponse {
    return { data: ids.map((id) => ({ item_id: `item-${id}`, entity_id: id, album: album(id) })), meta: { run_id: run, shuffle, filter, filters: { all: total, album: total, ep: total > 0 ? 1 : 0, single: 0 }, current_page: page, last_page: lastPage, per_page: 24, total, eligible_total: total, run_total: total + 2 }, links: { first: '/api/v1/beyond?page=1', prev: page > 1 ? `/api/v1/beyond?page=${page - 1}` : null, next: page < lastPage ? `/api/v1/beyond?page=${page + 1}` : null, last: `/api/v1/beyond?page=${lastPage}` } };
}

function renderPage(path = `/beyond?shuffle=${shuffle}&run=${run}`) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[path]}><BeyondPage /></MemoryRouter></QueryClientProvider>);
}

describe('BeyondPage', () => {
    beforeEach(() => { vi.mocked(api.beyond).mockReset(); vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValue(shuffle); });
    afterEach(() => { cleanup(); vi.restoreAllMocks(); });

    it('uses finite URL-backed pages pinned to one shuffle and run', async () => {
        vi.mocked(api.beyond).mockImplementation(async (page) => page === 1 ? response(['one', 'two'], 1, 3, 2) : response(['three'], 2, 3, 2));
        renderPage();
        expect(await screen.findByRole('heading', { name: 'Album one' })).toBeVisible();
        expect(screen.getByRole('button', { name: /All\s*3/ })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('group', { name: 'Release type' }).parentElement).toContainElement(screen.getByRole('button', { name: 'Shuffle again' }));
        fireEvent.click(screen.getByRole('button', { name: 'Load more' }));
        expect(await screen.findByRole('heading', { name: 'Album three' })).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Album one' })).toBeVisible();
        expect(vi.mocked(api.beyond)).toHaveBeenLastCalledWith(2, 24, shuffle, run, 'all');
    });

    it('starts a new URL-addressable browsing session when shuffled', async () => {
        vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValueOnce(shuffle).mockReturnValueOnce('22222222-2222-4222-8222-222222222222');
        vi.mocked(api.beyond).mockResolvedValue(response(['one'], 1, 1, 1));
        renderPage();
        await screen.findByRole('heading', { name: 'Album one' });
        fireEvent.click(screen.getByRole('button', { name: 'Shuffle again' }));
        await waitFor(() => expect(vi.mocked(api.beyond)).toHaveBeenLastCalledWith(1, 24, '22222222-2222-4222-8222-222222222222', null, 'all'));
    });

    it('filters release types without replacing the pinned run or shuffle', async () => {
        vi.mocked(api.beyond).mockImplementation(async (_page, _size, _shuffle, _run, filter) => response(['one'], 1, 1, 1, filter as BeyondResponse['meta']['filter']));
        renderPage();
        await screen.findByRole('heading', { name: 'Album one' });
        fireEvent.click(screen.getByRole('button', { name: /EPs\s*1/ }));
        await waitFor(() => expect(vi.mocked(api.beyond)).toHaveBeenLastCalledWith(1, 24, shuffle, run, 'ep'));
        expect(screen.queryByRole('group', { name: 'Card density' })).not.toBeInTheDocument();
    });

    it('retries a failed finite page coherently', async () => {
        let attempts = 0;
        vi.mocked(api.beyond).mockImplementation(async (page) => { if (page === 1) return response(['one'], 1, 2, 2); attempts++; if (attempts === 1) throw new Error('Later page failed.'); return response(['two'], 2, 2, 2); });
        renderPage();
        await screen.findByRole('heading', { name: 'Album one' });
        fireEvent.click(screen.getByRole('button', { name: 'Load more' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Later page failed.');
        fireEvent.click(screen.getByRole('button', { name: 'Retry next page' }));
        expect(await screen.findByRole('heading', { name: 'Album two' })).toBeVisible();
    });

    it('shows one consistent empty state for an empty eligible run', async () => {
        vi.mocked(api.beyond).mockResolvedValue(response([], 1, 0, 1));
        renderPage();
        expect(await screen.findByRole('heading', { name: 'No external recommendations yet' })).toBeVisible();
    });
});
