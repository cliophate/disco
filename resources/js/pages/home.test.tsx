import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { Album, HomeResponse } from '../lib/types';
import { HomePage } from './home';

vi.mock('../lib/api', () => ({ api: { home: vi.fn() } }));

const album: Album = {
    id: 'album-1',
    plex_item_id: 'plex-album-1',
    title: 'Blue Lines',
    artist: { id: 'artist-1', name: 'Massive Attack', portrait: null, type: 'Group', area: 'Bristol', genres: [] },
    year: 1991,
    artwork: null,
    added_at: '2026-07-23T08:00:00Z',
    duration_ms: null,
    track_count: null,
    last_heard_at: '2026-07-24T10:00:00Z',
    play_count: 3,
    listening_signals: null,
    release_type: 'Album',
    first_release_date: null,
    genres: [],
    genre_basis: null,
    labels: [],
    disambiguation: null,
    sources: ['Plex', 'MusicBrainz'],
    owned: true,
    metadata_status: 'enriched',
    identity_status: 'confirmed',
    open_in_plex_available: true,
    open_in_plex_status: 'exact',
    qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=Blue%20Lines',
    list_state: null,
};

function response(overrides: Partial<HomeResponse> = {}): HomeResponse {
    return {
        data: {
            feature: null,
            collection: { artists: 12, albums: 34, tracks: 567 },
            recent_artists: [{ id: 'recent-artist', name: 'Removed Artist Strip', portrait: null, type: null, area: null, genres: [] }],
            activity: [
                { id: 'played:album-1', kind: 'played', occurred_at: '2026-07-24T10:00:00Z', album },
                { id: 'added:album-1', kind: 'added', occurred_at: '2026-07-23T08:00:00Z', album },
            ],
            sections: [
                { type: 'recently-heard', title: 'Recently heard', description: 'Duplicate plays.', items: [{ album, reasons: [] }] },
                { type: 'recently-added', title: 'Latest additions', description: 'Duplicate additions.', items: [{ album, reasons: [] }] },
                { type: 'waiting', title: 'Waiting on your shelves', description: 'A distinct lens.', items: [{ album, reasons: [] }] },
            ],
        },
        meta: {
            edition_id: 'edition-1',
            edition_version: 'version-1',
            algorithm: 'fixture',
            generated_at: '2026-07-24T11:00:00Z',
            facts_as_of: '2026-07-24T11:00:00Z',
            last_plex_sync_at: '2026-07-24T11:00:00Z',
            last_listenbrainz_import_at: '2026-07-24T11:00:00Z',
            source_coverage: {},
            activity: { status: 'ready', stale: false, added_as_of: '2026-07-24T11:00:00Z', played_as_of: '2026-07-24T11:00:00Z' },
        },
        ...overrides,
    };
}

function renderPage(initialData?: HomeResponse) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    if (initialData) queryClient.setQueryData(['home'], initialData);

    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter><HomePage /></MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('HomePage', () => {
    beforeEach(() => vi.mocked(api.home).mockReset());
    afterEach(cleanup);

    it('places explicit activity after exactly three stats and removes duplicate Home rails and the artist strip', async () => {
        vi.mocked(api.home).mockResolvedValue(response());
        renderPage();

        const activity = await screen.findByRole('region', { name: 'Recent activity' });
        expect(screen.getAllByRole('group', { name: /Artists|Albums|Tracks/ })).toHaveLength(3);
        expect(screen.getByRole('group', { name: '01 / Artists' })).toHaveTextContent('12');
        expect(screen.getByRole('group', { name: '02 / Albums' })).toHaveTextContent('34');
        expect(screen.getByRole('group', { name: '03 / Tracks' })).toHaveTextContent('567');
        expect(screen.getByLabelText('Collection totals').compareDocumentPosition(activity) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

        expect(within(activity).getByText(/Played means the latest matched listening event/)).toBeVisible();
        expect(within(activity).getAllByRole('link', { name: 'Blue Lines' })).toHaveLength(2);
        expect(within(activity).getAllByRole('link', { name: 'Massive Attack' })[0]).toHaveAttribute('href', '/artists/artist-1');
        expect(within(activity).getAllByRole('link', { name: 'Blue Lines' })[0]).toHaveAttribute('href', '/albums/album-1');
        expect(within(activity).getAllByText('Played', { exact: true })).toHaveLength(2);
        expect(within(activity).getAllByText('Added', { exact: true })).toHaveLength(2);
        expect(within(activity).getAllByRole('button', { name: 'Want to listen' })).toHaveLength(2);
        expect(activity.querySelector('time[datetime="2026-07-24T10:00:00Z"]')).toBeInTheDocument();
        expect(screen.queryByText('Removed Artist Strip')).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Recently heard' })).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Latest additions' })).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Waiting on your shelves' })).toBeVisible();
        expect(screen.queryByRole('link', { name: 'How discovery works' })).not.toBeInTheDocument();
        expect(screen.getByText(/Owned-library lenses use Plex and ListenBrainz facts/)).toBeVisible();
    });

    it('renders explicit loading, stale, empty, and background-refresh states', async () => {
        vi.mocked(api.home).mockResolvedValue(response());
        const loading = renderPage();
        expect(screen.getByRole('status', { name: 'Loading Home' })).toBeVisible();
        expect(screen.getByLabelText('Loading recent activity')).toBeVisible();
        loading.unmount();

        const stale = response();
        stale.data.activity = [];
        stale.meta.activity = { status: 'empty', stale: true, added_as_of: null, played_as_of: null };
        vi.mocked(api.home).mockResolvedValue(stale);
        renderPage(stale);
        const activity = screen.getByRole('region', { name: 'Recent activity' });
        expect(within(activity).getByText('No matched plays or synchronized addition dates are available yet.')).toBeVisible();
        expect(within(activity).getByText(/No successful library sync is available/)).toBeVisible();
        expect(within(activity).getByText('Refreshing activity in the background.')).toBeVisible();
    });
});
