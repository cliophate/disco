import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { Album, AlbumPage } from '../lib/types';
import { LibraryPage } from './library';

vi.mock('../lib/api', () => ({ api: { albums: vi.fn() } }));

const album: Album = { id: 'album-one', plex_item_id: 'plex-one', title: 'A Very Long Album Title That Still Belongs to One Stable Card', artist: { id: 'artist-one', name: 'Fixture Artist', portrait: null, type: null, area: null, genres: [] }, year: 2025, artwork: null, added_at: null, duration_ms: null, track_count: null, last_heard_at: null, play_count: null, listening_signals: null, release_type: 'EP', first_release_date: null, genres: [], genre_basis: null, labels: [], disambiguation: null, sources: ['Plex'], owned: true, metadata_status: 'enriched', identity_status: 'confirmed', open_in_plex_available: true, open_in_plex_status: 'exact', qobuz_search_url: 'https://www.qobuz.com/search?q=fixture' };
const response: AlbumPage = { data: [album], meta: { current_page: 3, last_page: 4, per_page: 24, total: 9, filters: { all: 40, album: 24, ep: 9, single: 5, other: 2 }, filter: 'ep', sort: 'newest' }, links: { first: '/api/v1/library/albums?page=1', prev: '/api/v1/library/albums?page=2', next: '/api/v1/library/albums?page=4', last: '/api/v1/library/albums?page=4' } };

describe('LibraryPage', () => {
    afterEach(() => { cleanup(); vi.restoreAllMocks(); });

    it('keeps real filter, sort, page, and return context URL-addressable', async () => {
        vi.mocked(api.albums).mockImplementation(async (page, filter, sort) => ({ ...response, meta: { ...response.meta, current_page: page, filter, sort } }));
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/library/albums?page=3&type=ep&sort=newest']}><LibraryPage /></MemoryRouter></QueryClientProvider>);

        expect(await screen.findByRole('heading', { name: album.title })).toBeVisible();
        expect(vi.mocked(api.albums)).toHaveBeenCalledWith(3, 'ep', 'newest');
        expect(screen.getByRole('button', { name: /EPs\s*9/ })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.queryByRole('group', { name: 'Card density' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Load more' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: /Albums\s*24/ }));
        await waitFor(() => expect(vi.mocked(api.albums)).toHaveBeenLastCalledWith(1, 'album', 'newest'));
    });
});
