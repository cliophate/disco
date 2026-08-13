import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { Album, AlbumListPage } from '../lib/types';
import { WantToListenPage } from './want-to-listen';

vi.mock('../lib/api', () => ({ api: { wantToListen: vi.fn(), updateAlbumListState: vi.fn(), removeAlbumListState: vi.fn() } }));

const album: Album = { id: 'album-one', plex_item_id: null, title: 'Outside Album', artist: null, year: 2025, artwork: null, added_at: null, duration_ms: null, track_count: null, last_heard_at: null, play_count: null, listening_signals: null, release_type: 'Album', first_release_date: null, genres: [], genre_basis: null, labels: [], disambiguation: null, sources: ['MusicBrainz'], owned: false, metadata_status: 'enriched', identity_status: 'confirmed', open_in_plex_available: false, open_in_plex_status: 'unavailable', qobuz_search_url: 'https://www.qobuz.com/search?q=fixture', list_state: { id: 'state-one', album_id: 'album-one', status: 'listened', note: 'Private', source: 'Alex', wanted_at: null, listened_at: '2026-07-24T00:00:00+00:00', removed_at: null, state_changed_at: '2026-07-24T00:00:00+00:00', updated_at: '2026-07-24T00:00:00+00:00' } };

describe('WantToListenPage', () => {
    it('keeps status, ownership, and sort URL-addressable with alphabetical default ordering', async () => {
        vi.mocked(api.wantToListen).mockImplementation(async (page, status, ownership, sort): Promise<AlbumListPage> => ({ data: [album], meta: { current_page: page, last_page: 2, per_page: 24, total: 1, filter: status as AlbumListPage['meta']['filter'], ownership: ownership as AlbumListPage['meta']['ownership'], sort: sort as AlbumListPage['meta']['sort'], filters: { all: 3, want_to_listen: 2, listened: 1, removed: 1 }, ownership_filters: { all: 1, owned: 0, outside: 1 } }, links: { first: '', prev: null, next: '', last: '' } }));
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/want-to-listen?status=listened&ownership=outside']}><WantToListenPage /></MemoryRouter></QueryClientProvider>);

        expect(await screen.findByRole('heading', { name: album.title })).toBeVisible();
        expect(api.wantToListen).toHaveBeenCalledWith(1, 'listened', 'outside', 'name');
        expect(screen.getByRole('button', { name: /Listened\s*1/ })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('combobox', { name: 'Sort' })).toHaveValue('name');
        expect(screen.getByRole('combobox', { name: 'Collection' }).closest('div')).toBe(screen.getByRole('combobox', { name: 'Sort' }).closest('div'));
        expect(screen.queryByRole('group', { name: 'Card density' })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Want to listen\s*2/ }));
        await waitFor(() => expect(api.wantToListen).toHaveBeenLastCalledWith(1, 'want_to_listen', 'outside', 'name'));
    });
});
