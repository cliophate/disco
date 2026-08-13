import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { ArtistDetail } from '../lib/types';
import { ArtistDetailPage } from './artist-detail';

vi.mock('../lib/api', () => ({ api: { artist: vi.fn(), artistDiscography: vi.fn(), refreshArtistDiscography: vi.fn(), followArtist: vi.fn(), unfollowArtist: vi.fn(), updateAlbumListState: vi.fn(), removeAlbumListState: vi.fn(), plexTarget: vi.fn() } }));

const idleRefresh = { status: 'idle' as const, requested_at: null, started_at: null, finished_at: null, generation_id: null, message: null };

const artist: ArtistDetail = {
    id: 'artist-1',
    name: 'Fixture Artist',
    portrait: null,
    type: 'Person',
    area: 'London',
    genres: ['Electronic'],
    begin_date: null,
    end_date: null,
    disambiguation: null,
    external_links: { primary: [], groups: [] },
    description: {
        text: 'An attributed artist biography.',
        language: 'en',
        provider: 'theaudiodb',
        provider_name: 'TheAudioDB',
        source_url: 'https://www.theaudiodb.com/artist/12345',
        license_name: 'TheAudioDB terms of use',
        license_url: 'https://www.theaudiodb.com/docs_terms_of_use.php',
    },
    relationships: { status: 'unavailable', roles: [], people: [], works: [] },
    follow_state: { explicit: false, implicit: true, seed: true },
    plex_item_id: null,
    open_in_plex_available: false,
    open_in_plex_status: 'unavailable',
    albums: [],
    recommended_albums: [],
    discogs: null,
};

const ownedAlbum: ArtistDetail['albums'][number] = {
    id: 'album-owned',
    plex_item_id: 'plex-album-owned',
    title: 'Owned Album',
    artist: { id: 'artist-1', name: 'Fixture Artist', portrait: null, type: null, area: null, genres: [] },
    year: 2024,
    artwork: null,
    added_at: null,
    duration_ms: null,
    track_count: null,
    last_heard_at: null,
    play_count: null,
    listening_signals: null,
    release_type: 'Album',
    first_release_date: null,
    genres: [],
    genre_basis: null,
    labels: [],
    disambiguation: null,
    sources: ['Plex'],
    owned: true,
    metadata_status: 'enriched',
    identity_status: 'confirmed',
    open_in_plex_available: true,
    open_in_plex_status: 'exact',
    qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=Owned',
};

function renderPage(state?: { from: string; label: string }) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={[{ pathname: '/artists/artist-1', state }]}>
                <Routes><Route path="/artists/:id" element={<ArtistDetailPage />} /><Route path="/albums/:id" element={<RouteStateProbe />} /></Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

function RouteStateProbe() {
    const location = useLocation();
    const state = location.state as { from?: string; label?: string } | null;

    return <p>{state?.from} · {state?.label}</p>;
}

describe('ArtistDetailPage', () => {
    beforeEach(() => {
        vi.mocked(api.artist).mockReset();
        vi.mocked(api.followArtist).mockReset();
        vi.mocked(api.unfollowArtist).mockReset();
        vi.mocked(api.artistDiscography).mockReset();
        vi.mocked(api.refreshArtistDiscography).mockReset().mockResolvedValue({ ...idleRefresh, status: 'queued', requested_at: '2026-07-28T12:00:00Z', message: 'Refresh queued.' });
        vi.mocked(api.plexTarget).mockReset();
        vi.mocked(api.artistDiscography).mockResolvedValue({
            data: [],
            meta: {
                generation_id: null, generated_at: null, expires_at: null, status: 'empty', refresh: idleRefresh, stale: false, truncated: false,
                source_total: 0, view: 'missing', types: 'albums', noise: 'core', counts: { views: { missing: 0, present: 0, all: 0 }, types: { albums: 0, albums_eps: 0, all: 0 } },
                current_page: 1, last_page: 1, total: 0, per_page: 24,
            },
            links: { first: '', prev: null, next: null, last: '' },
        });
    });
    afterEach(cleanup);

    it('returns to the originating artist index state', async () => {
        vi.mocked(api.artist).mockResolvedValue(artist);

        renderPage({ from: '/artists?type=person&page=2', label: 'artists' });

        expect(await screen.findByRole('link', { name: 'Back to artists' })).toHaveAttribute('href', '/artists?type=person&page=2');
    });

    it('presents the cached biography and attribution', async () => {
        vi.mocked(api.artist).mockResolvedValue(artist);

        renderPage();

        expect(await screen.findByRole('heading', { name: 'About Fixture Artist' })).toBeVisible();
        expect(screen.getByText('An attributed artist biography.')).toBeVisible();
        expect(screen.getByRole('link', { name: 'TheAudioDB' })).toHaveAttribute('href', 'https://www.theaudiodb.com/artist/12345');
    });

    it('shows fresh Discogs artist fields with direct attribution', async () => {
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            discogs: {
                object_type: 'artist', external_id: '99', source_url: 'https://www.discogs.com/artist/99', fetched_at: '2026-07-25T10:00:00Z',
                fields: { id: '99', object_type: 'artist', name: 'Fixture Artist', real_name: 'Fixture Person', name_variations: ['F. Artist'] },
            },
        });

        renderPage();

        expect(await screen.findByText('Fixture Person')).toBeVisible();
        expect(screen.getByText('F. Artist')).toBeVisible();
        expect(screen.getByRole('link', { name: /Data provided by Discogs/ })).toHaveAttribute('href', 'https://www.discogs.com/artist/99');
    });

    it('offers only the exact artist Plex action alongside follow', async () => {
        vi.mocked(api.artist).mockResolvedValue({ ...artist, plex_item_id: 'plex-artist-1', open_in_plex_available: true, open_in_plex_status: 'exact' });

        renderPage();

        expect(await screen.findByRole('button', { name: 'Open in Plex' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Follow artist' })).toBeVisible();
        expect(screen.queryByRole('button', { name: /play|save|share/i })).not.toBeInTheDocument();
        vi.mocked(api.plexTarget).mockImplementation(() => new Promise(() => undefined));
        fireEvent.click(screen.getByRole('button', { name: 'Open in Plex' }));
        await waitFor(() => expect(api.plexTarget).toHaveBeenCalledWith('plex-artist-1'));
        expect(await screen.findByRole('button', { name: 'Finding in Plex…' })).toBeDisabled();

        cleanup();
        vi.mocked(api.artist).mockResolvedValue({ ...artist, plex_item_id: null, open_in_plex_available: false, open_in_plex_status: 'unavailable' });
        renderPage();
        expect(await screen.findByRole('heading', { name: 'Fixture Artist' })).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Open in Plex' })).not.toBeInTheDocument();
    });

    it('leaves no empty biography section when none is cached', async () => {
        vi.mocked(api.artist).mockResolvedValue({ ...artist, description: null });

        renderPage();

        expect(await screen.findByRole('heading', { name: 'Fixture Artist' })).toBeVisible();
        expect(screen.queryByRole('heading', { name: 'About Fixture Artist' })).not.toBeInTheDocument();
        expect(screen.queryByText('Artist context')).not.toBeInTheDocument();
    });

    it('presents a bounded set of links and discloses the remainder', async () => {
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            external_links: {
                primary: [
                    { type: 'official', label: 'Official site', url: 'https://fixture.test' },
                    { type: 'musicbrainz', label: 'MusicBrainz', url: 'https://musicbrainz.org/artist/artist-1' },
                    { type: 'wikipedia', label: 'Wikipedia', url: 'https://en.wikipedia.org/wiki/Fixture' },
                    { type: 'discogs', label: 'Discogs', url: 'https://discogs.com/artist/artist-1' },
                ],
                groups: [{
                    label: 'Listen',
                    links: [
                        { type: 'spotify', label: 'Spotify', url: 'https://open.spotify.com/artist/artist-1' },
                        { type: 'youtube', label: 'YouTube', url: 'https://youtube.com/@artist-1' },
                    ],
                }],
            },
        });

        renderPage();

        const officialLink = await screen.findByRole('link', { name: /Official site/ });
        expect(officialLink).toHaveAttribute('href', 'https://fixture.test');
        expect(officialLink.parentElement?.querySelectorAll('a')).toHaveLength(4);
        expect(screen.getByText('More links').closest('details')).not.toHaveAttribute('open');
        fireEvent.click(screen.getByText('More links'));
        expect(screen.getByText('More links').closest('details')).toHaveAttribute('open');
        expect(screen.getByRole('link', { name: /Spotify/ })).toHaveAttribute('href', 'https://open.spotify.com/artist/artist-1');
    });

    it('separates an exact Qobuz destination from generic external links', async () => {
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            qobuz: { url: 'https://open.qobuz.com/artist/384387', status: 'exact', source: 'musicbrainz_url_relationship' },
            external_links: {
                primary: [],
                groups: [{ label: 'Official and stores', links: [{ type: 'qobuz', label: 'Qobuz', url: 'https://www.qobuz.com/us-en/interpreter/jon-hopkins/384387' }] }],
            },
        });

        renderPage();

        expect(await screen.findByRole('link', { name: /Open artist on Qobuz/ })).toHaveAttribute('href', 'https://open.qobuz.com/artist/384387');
        expect(screen.getByText('Exact MusicBrainz-linked destination')).toBeVisible();
        expect(screen.queryByRole('link', { name: 'Qobuz' })).not.toBeInTheDocument();
    });

    it('presents cached gaps with state and artist return context', async () => {
        vi.mocked(api.artist).mockResolvedValue(artist);
        vi.mocked(api.artistDiscography).mockResolvedValue({
            data: [{
                id: 'album-beyond',
                primary_type: 'album',
                secondary_types: [],
                states: { holding: 'absent', wanted: true, listened: false, recommended: true, upcoming: true, observed_listening: true, last_listened_at: '2026-07-01T00:00:00Z' },
                official_release_evidence: { status: 'official', release_mbid: '11111111-1111-4111-8111-111111111111', release_date: null },
                album: {
                id: 'album-beyond',
                plex_item_id: null,
                title: 'Beyond Album',
                artist: { id: null, name: 'Fixture Artist', portrait: null, type: null, area: null, genres: [] },
                year: 2025,
                artwork: null,
                added_at: null,
                duration_ms: null,
                track_count: null,
                last_heard_at: null,
                play_count: null,
                listening_signals: null,
                release_type: 'Album',
                first_release_date: null,
                genres: [],
                genre_basis: null,
                labels: [],
                disambiguation: null,
                sources: ['MusicBrainz'],
                owned: false,
                metadata_status: 'enriched',
                identity_status: 'confirmed',
                open_in_plex_available: false,
                open_in_plex_status: 'unavailable',
                qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=Fixture%20Artist%20Beyond%20Album',
                list_state: { id: 'list', album_id: 'album-beyond', status: 'want_to_listen', note: null, source: null, wanted_at: null, listened_at: null, removed_at: null, state_changed_at: '2026-07-01T00:00:00Z', updated_at: '2026-07-01T00:00:00Z' },
            }}],
            meta: {
                generation_id: '22222222-2222-4222-8222-222222222222', generated_at: '2026-07-24T00:00:00Z', expires_at: '2026-08-01T00:00:00Z', status: 'ready', refresh: idleRefresh, stale: false, truncated: false,
                source_total: 1, view: 'missing', types: 'albums', noise: 'core', counts: { views: { missing: 1, present: 0, all: 1 }, types: { albums: 1, albums_eps: 1, all: 1 } },
                current_page: 1, last_page: 1, total: 1, per_page: 24,
            },
            links: { first: '', prev: null, next: null, last: '' },
        });

        renderPage();

        expect(await screen.findByRole('tab', { name: /Discography/ })).toBeVisible();
        fireEvent.click(screen.getByRole('tab', { name: /Discography/ }));
        expect(screen.getByRole('heading', { name: 'Discography gaps' })).toBeVisible();
        const albumLink = await screen.findByRole('link', { name: /Beyond Album/ });
        expect(albumLink).toHaveAttribute('href', '/albums/album-beyond');
        expect(await screen.findByLabelText('Beyond Album states')).toHaveTextContent('MissingWantedHeardBeyond pickUpcomingOfficial');
        fireEvent.click(albumLink);
        expect(screen.getByText(/\/artists\/artist-1\?.*tab=discography.* · Fixture Artist/)).toBeVisible();
    });

    it('uses the canonical card treatment for owned discography albums', async () => {
        vi.mocked(api.artist).mockResolvedValue(artist);
        vi.mocked(api.artistDiscography).mockResolvedValue({
            data: [{ id: 'album-owned', primary_type: 'album', secondary_types: [], states: { holding: 'present', wanted: false, listened: false, recommended: false, upcoming: false, observed_listening: false, last_listened_at: null }, official_release_evidence: { status: 'official', release_mbid: '11111111-1111-4111-8111-111111111111', release_date: null }, album: {
                id: 'album-owned',
                plex_item_id: 'plex-album-owned',
                title: 'Owned Album',
                artist: { id: 'artist-1', name: 'Fixture Artist', portrait: null, type: null, area: null, genres: [] },
                year: 2024,
                artwork: null,
                added_at: null,
                duration_ms: null,
                track_count: null,
                last_heard_at: null,
                play_count: null,
                listening_signals: null,
                release_type: 'Album',
                first_release_date: null,
                genres: [],
                genre_basis: null,
                labels: [],
                disambiguation: null,
                sources: ['Plex'],
                owned: true,
                metadata_status: 'enriched',
                identity_status: 'confirmed',
                open_in_plex_available: true,
                open_in_plex_status: 'exact',
                qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=Owned',
            }}],
            meta: { generation_id: '22222222-2222-4222-8222-222222222222', generated_at: null, expires_at: null, status: 'ready', refresh: idleRefresh, stale: false, truncated: false, source_total: 1, view: 'missing', types: 'albums', noise: 'core', counts: { views: { missing: 0, present: 1, all: 1 }, types: { albums: 1, albums_eps: 1, all: 1 } }, current_page: 1, last_page: 1, total: 1, per_page: 24 },
            links: { first: '', prev: null, next: null, last: '' },
        });

        renderPage();

        fireEvent.click(await screen.findByRole('tab', { name: /Discography/ }));
        const albumLink = await screen.findByRole('link', { name: /Owned Album/ });
        expect(albumLink).toHaveAttribute('href', '/albums/album-owned');
        expect(albumLink.closest('article')).toHaveClass('border', 'bg-panel');
        expect(screen.getByRole('button', { name: 'Want to listen' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Open in Plex' })).not.toBeInTheDocument();
    });

    it('keeps owned albums as a conditional URL-backed tab with album return context', async () => {
        vi.mocked(api.artist).mockResolvedValue({ ...artist, albums: [ownedAlbum] });

        renderPage({ from: '/artists?type=person', label: 'artists' });

        expect(await screen.findAllByRole('tab')).toHaveLength(3);
        fireEvent.click(screen.getByRole('tab', { name: 'Albums1' }));
        const albumLink = await screen.findByRole('link', { name: /Owned Album/ });
        expect(albumLink).toHaveAttribute('href', '/albums/album-owned');
        fireEvent.click(albumLink);
        expect(screen.getByText('/artists/artist-1?tab=albums · Fixture Artist')).toBeVisible();
    });

    it('shows stale cache status and changes bounded discography filters', async () => {
        vi.mocked(api.artist).mockResolvedValue(artist);
        vi.mocked(api.artistDiscography).mockResolvedValue({
            data: [],
            meta: {
                generation_id: '22222222-2222-4222-8222-222222222222', generated_at: null, expires_at: null,
                status: 'stale', refresh: { ...idleRefresh, status: 'queued', requested_at: '2026-07-28T12:00:00Z', message: 'Refresh queued.' }, stale: true, truncated: false, source_total: 40, view: 'missing', types: 'albums', noise: 'core',
                counts: { views: { missing: 0, present: 0, all: 0 }, types: { albums: 0, albums_eps: 0, all: 0 } },
                current_page: 1, last_page: 2, total: 25, per_page: 24,
            },
            links: { first: '', prev: null, next: '', last: '' },
        });
        renderPage();

        fireEvent.click(await screen.findByRole('tab', { name: /Discography/ }));
        expect(await screen.findByText(/last complete cached discography/)).toBeVisible();
        expect(screen.getByText(/Refreshing the exact MusicBrainz discography/)).toBeVisible();
        expect(screen.getByRole('button', { name: 'Refreshing…' })).toBeDisabled();
        expect(screen.getByTestId('discography-filters')).toHaveClass('xl:grid-cols-2');
        expect(screen.getByText('Collection state')).toBeVisible();
        expect(screen.getByText('Release scope')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: /Albums \+ EPs/ }));
        await waitFor(() => expect(api.artistDiscography).toHaveBeenCalledWith('artist-1', 'missing', 'albums_eps', 'core', 1, 24, '22222222-2222-4222-8222-222222222222'));
        fireEvent.click(await screen.findByRole('button', { name: 'Load more' }));
        await waitFor(() => expect(api.artistDiscography).toHaveBeenCalledWith('artist-1', 'missing', 'albums_eps', 'core', 2, 24, '22222222-2222-4222-8222-222222222222'));
    });

    it('queues a manual discography refresh without blocking the cached view', async () => {
        vi.mocked(api.artist).mockResolvedValue(artist);
        renderPage();

        fireEvent.click(await screen.findByRole('tab', { name: /Discography/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Refresh now' }));

        await waitFor(() => expect(api.refreshArtistDiscography).toHaveBeenCalledWith('artist-1'));
        expect(api.artistDiscography).toHaveBeenCalled();
    });

    it('does not render an empty outside-library section', async () => {
        vi.mocked(api.artist).mockResolvedValue(artist);

        renderPage();

        expect(await screen.findByRole('heading', { name: 'Fixture Artist' })).toBeVisible();
        expect(screen.queryByText('Beyond the collection')).not.toBeInTheDocument();
    });

    it('uses a cinematic banner only for sufficiently large wide imagery', async () => {
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            portrait: { id: 'wide', url: '/wide.webp', width: 1600, height: 800 },
        });

        renderPage();

        expect(await screen.findByTestId('artist-banner')).toBeVisible();
        expect(screen.getByTestId('artist-banner')).toHaveClass('bg-[#17191f]', 'text-[#fff8e9]');
        const banner = screen.getByRole('img', { name: 'Fixture Artist artist image' });
        expect(banner).toHaveClass('object-cover');
        fireEvent.error(banner);
        expect(await screen.findByRole('img', { name: 'Fixture Artist portrait unavailable' })).toBeVisible();
        expect(screen.queryByTestId('artist-banner')).not.toBeInTheDocument();
        expect(screen.getByTestId('artist-split-hero')).toBeVisible();
        expect(screen.getByTestId('artist-split-hero')).toHaveClass('bg-[#17191f]', 'text-[#fff8e9]');
    });

    it('keeps square, portrait, and generated fallback imagery in the two-column treatment', async () => {
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            portrait: { id: 'square', url: '/square.webp', width: 600, height: 600 },
        });
        renderPage();

        const square = await screen.findByRole('img', { name: 'Fixture Artist portrait' });
        expect(screen.getByTestId('artist-split-hero')).toBeVisible();
        expect(square.parentElement).toHaveClass('aspect-square');
        expect(screen.queryByTestId('artist-banner')).not.toBeInTheDocument();

        cleanup();
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            portrait: { id: 'portrait', url: '/portrait.webp', width: 400, height: 600 },
        });
        renderPage();

        expect(await screen.findByRole('heading', { level: 1, name: 'Fixture Artist' })).toHaveClass('[overflow-wrap:anywhere]');
        expect(screen.getByRole('img', { name: 'Fixture Artist portrait' }).parentElement).toHaveClass('aspect-[4/5]');
        expect(screen.queryByTestId('artist-banner')).not.toBeInTheDocument();

        cleanup();
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            portrait: { id: 'unknown', url: '/unknown.webp', width: null, height: null },
        });
        renderPage();

        expect(await screen.findByRole('img', { name: 'Fixture Artist portrait' })).toBeVisible();
        expect(screen.getByTestId('artist-split-hero')).toBeVisible();
        expect(screen.queryByTestId('artist-banner')).not.toBeInTheDocument();

        cleanup();
        vi.mocked(api.artist).mockResolvedValue({ ...artist, portrait: null });
        renderPage();
        expect(await screen.findByRole('img', { name: 'Fixture Artist portrait unavailable' })).toBeVisible();
        expect(screen.queryByTestId('artist-banner')).not.toBeInTheDocument();
    });

    it('uses the same deterministic fallback after square image failure', async () => {
        vi.mocked(api.artist).mockResolvedValue({ ...artist, portrait: { id: 'square', url: '/square.webp', width: 600, height: 600 } });
        renderPage();

        fireEvent.error(await screen.findByRole('img', { name: 'Fixture Artist portrait' }));
        expect(await screen.findByRole('img', { name: 'Fixture Artist portrait unavailable' })).toBeVisible();
        expect(screen.getByTestId('artist-split-hero')).toBeVisible();
    });

    it('keeps long identity text readable and sparse artists free of empty tabs', async () => {
        const longName = 'An Exceptionally Long Canonical Artist Name Without Unsupported Controls';
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            name: longName,
            begin_date: { year: 1984, month: null, day: null, precision: 'year' },
            end_date: { year: 2020, month: null, day: null, precision: 'year' },
            disambiguation: 'A source-backed disambiguation that remains secondary even when it wraps across several lines on a narrow screen.',
            genres: ['Electronic', 'Experimental', 'Contemporary classical', 'Ambient'],
        });
        renderPage();

        expect(await screen.findByRole('heading', { level: 1, name: longName })).toHaveClass('break-words', '[overflow-wrap:anywhere]');
        expect(screen.getByText(/Artist file/)).toHaveTextContent('Artist file · Person');
        expect(screen.getByText('London · 1984–2020')).toBeVisible();
        expect(screen.getByText(/source-backed disambiguation/)).toBeVisible();
        expect(screen.getByLabelText('Genres')).toHaveTextContent('ElectronicExperimentalContemporary classicalAmbient');

        cleanup();
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            type: null,
            area: null,
            genres: [],
            disambiguation: null,
            description: null,
            relationships: { status: 'unavailable', roles: [], people: [], works: [] },
            follow_state: { explicit: false, implicit: false, seed: false },
        });
        renderPage();
        expect(await screen.findByRole('heading', { name: 'Discography gaps' })).toBeVisible();
        expect(screen.queryByRole('tablist', { name: 'Artist detail' })).not.toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Fixture Artist portrait unavailable' })).toBeVisible();
    });

    it('presents bounded canonical people and works without inventing missing imagery', async () => {
        vi.mocked(api.artist).mockResolvedValue({
            ...artist,
            relationships: {
                status: 'available', roles: ['producer'],
                people: [
                    { id: 'person-image', name: 'Pictured Collaborator', portrait: { id: 'portrait', url: '/portrait.webp', width: 600, height: 600 }, roles: ['performer'], shared_credits: 3 },
                    { id: 'person-text', name: 'Inline Collaborator', portrait: null, roles: ['engineer'], shared_credits: 1 },
                ],
                works: [{ id: 'work-1', name: 'Exact Work', relationship_type: 'writer' }],
            },
        });

        renderPage();

        expect(await screen.findByRole('heading', { name: 'Related people & works' })).toBeVisible();
        expect(screen.getByRole('link', { name: /Pictured Collaborator/ })).toHaveAttribute('href', '/artists/person-image');
        expect(screen.getByRole('img', { name: 'Pictured Collaborator portrait' })).toBeVisible();
        expect(screen.getByRole('link', { name: 'Inline Collaborator' })).toHaveAttribute('href', '/artists/person-text');
        expect(screen.getByText('Exact Work')).toBeVisible();
        expect(screen.getByText(/never inferred from prose or titles/)).toBeVisible();
    });

    it('follows and unfollows explicitly while retaining the implicit Plex seed explanation', async () => {
        vi.mocked(api.artist)
            .mockResolvedValueOnce(artist)
            .mockResolvedValueOnce({ ...artist, follow_state: { explicit: true, implicit: true, seed: true } })
            .mockResolvedValue(artist);
        vi.mocked(api.followArtist).mockResolvedValue({ artist_id: 'artist-1', explicit: true, implicit: true, seed: true });
        vi.mocked(api.unfollowArtist).mockResolvedValue();
        renderPage();

        expect(await screen.findByText('Personalization seed from your Plex library.')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Follow artist' }));
        await waitFor(() => expect(api.followArtist).toHaveBeenCalledWith('artist-1'));
        expect(await screen.findByRole('button', { name: 'Unfollow artist' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Unfollow artist' }));
        await waitFor(() => expect(api.unfollowArtist).toHaveBeenCalledWith('artist-1'));
        expect(screen.getByText('Personalization seed from your Plex library.')).toBeVisible();
    });
});
