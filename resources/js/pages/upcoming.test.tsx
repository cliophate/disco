import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { Album, UpcomingResponse, UpcomingView } from '../lib/types';
import { UpcomingPage } from './upcoming';

vi.mock('../lib/api', () => ({ api: { upcoming: vi.fn(), updateAlbumListState: vi.fn(), removeAlbumListState: vi.fn() } }));

const album: Album = {
    id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', plex_item_id: null, title: 'Future Forms',
    artist: { id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', name: 'Fixture Artist', portrait: null, type: null, area: null, genres: [] },
    year: 2026, artwork: null, added_at: null, duration_ms: null, track_count: null, last_heard_at: null, play_count: null,
    listening_signals: null, release_type: 'Album', first_release_date: { year: 2026, month: 8, day: 14, precision: 'day' }, genres: [], genre_basis: null,
    labels: [], disambiguation: null, sources: ['MusicBrainz'], owned: false, metadata_status: 'enriched', identity_status: 'confirmed',
    open_in_plex_available: false, open_in_plex_status: 'unavailable', qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=Fixture%20Artist%20Future%20Forms',
};

function response(view: UpcomingView): UpcomingResponse {
    return {
        data: [{
            id: 'item-one', album, release_date: '2026-08-14', primary_type: 'Album', secondary_types: [], artwork_status: 'available',
            musicbrainz: { release_group_mbid: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', release_mbid: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', artist_mbids: ['eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'] },
            personalization: { match: view === 'for-you' ? 'followed' : null, reason: view === 'for-you' ? 'An artist you explicitly follow.' : null },
            provenance: { provider: 'listenbrainz', provider_name: 'ListenBrainz', source_url: 'https://api.listenbrainz.org/1/explore/fresh-releases/', source_snapshot_id: 'ffffffff-ffff-4fff-8fff-ffffffffffff', retrieved_at: '2026-07-24T03:45:00Z', identity_method: 'exact_musicbrainz_ids' },
        }],
        meta: { generation_id: '11111111-1111-4111-8111-111111111111', generated_at: '2026-07-24T03:45:00Z', expires_at: '2026-07-25T15:45:00Z', stale: true, status: 'stale', view, horizon_days: 30, horizon_reason: 'Exact Album/EP groups released during the previous 30 days through the next 30 days.', window_start: '2026-06-24', window_end: '2026-08-23', past_days: 30, future_days: 30, coverage: { eligible_groups: 40 }, current_page: 1, last_page: 1, per_page: 24, total: 1 },
        links: { first: '/api/v1/discover/upcoming?page=1', prev: null, next: null, last: '/api/v1/discover/upcoming?page=1' },
    };
}

function renderPage() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/discover/upcoming']}><UpcomingPage /></MemoryRouter></QueryClientProvider>);
}

describe('UpcomingPage', () => {
    beforeEach(() => {
        vi.mocked(api.upcoming).mockReset();
        vi.mocked(api.upcoming).mockImplementation(async (view) => response(view));
    });
    afterEach(cleanup);

    it('shows personalized and general tabs, stale state, details, and labelled Qobuz fallback', async () => {
        renderPage();

        const heading = await screen.findByRole('heading', { name: 'Future Forms' });
        expect(heading).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Recent and upcoming' }).closest('header')).not.toHaveClass('border-b');
        expect(screen.getByRole('tablist', { name: 'Release window views' })).toHaveClass('border-b');
        expect(screen.getByText('An artist you explicitly follow.')).toBeVisible();
        expect(screen.getByRole('status')).toHaveTextContent('last cached release generation');
        expect(heading.closest('a')).toHaveAttribute('href', '/albums/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        expect(heading.closest('article')).toHaveClass('border', 'bg-panel');
        expect(screen.getByRole('link', { name: 'Fixture Artist' })).toHaveAttribute('href', '/artists/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        expect(screen.getByRole('button', { name: 'Want to listen' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Search Qobuz for Future Forms' })).toHaveAttribute('href', album.qobuz_search_url);
        expect(screen.getByText('1 personal match · 30 days back · 30 days ahead').closest('footer')).not.toBeNull();
        fireEvent.click(screen.getByRole('tab', { name: 'All releases' }));
        expect(await screen.findByText('1 release · 30 days back · 30 days ahead')).toBeVisible();
        expect(vi.mocked(api.upcoming)).toHaveBeenCalledWith('all', 1, 24, '11111111-1111-4111-8111-111111111111');
    });
});
