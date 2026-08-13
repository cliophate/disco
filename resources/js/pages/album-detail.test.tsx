import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import { PlaybackProvider } from '../components/playback-provider';
import type { AlbumDetail } from '../lib/types';
import { AlbumDetailPage } from './album-detail';

vi.mock('../lib/api', () => ({ api: { album: vi.fn() } }));

const unknownListening = {
    identity_status: 'exact',
    plex: { status: 'unavailable', play_count: null, first_listened_at: null, last_listened_at: null, availability_as_of: '2026-07-24T00:00:00Z', copy_count: 1, aggregation: 'exact_copy' },
    listenbrainz: { status: 'unavailable', play_count: null, first_listened_at: null, last_listened_at: null, availability_as_of: null, copy_count: null, aggregation: 'immutable_exact_events' },
} satisfies AlbumDetail['tracks'][number]['listening'];

const ownedAlbum: AlbumDetail = {
    id: '11111111-1111-4111-8111-111111111111',
    plex_item_id: '22222222-2222-4222-8222-222222222222',
    title: 'Owned Album',
    artist: { id: 'artist-1', name: 'Fixture Artist', portrait: null, type: null, area: null, genres: [] },
    year: 2020,
    artwork: null,
    added_at: null,
    duration_ms: 2400000,
    track_count: 10,
    last_heard_at: '2026-07-20T10:00:00Z',
    play_count: 5,
    listening_signals: {
        plex: { album_view_count: 5, played_track_count: 7, last_viewed_at: '2026-07-20T10:00:00Z' },
        listenbrainz: { listen_count: 12, first_listened_at: '2025-01-01T10:00:00Z', last_listened_at: '2026-07-21T10:00:00Z' },
    },
    release_type: 'Album',
    first_release_date: { year: 2020, month: null, day: null, precision: 'year' },
    genres: [],
    genre_basis: null,
    labels: [],
    disambiguation: null,
    sources: ['Plex', 'MusicBrainz'],
    owned: true,
    metadata_status: 'enriched',
    identity_status: 'confirmed',
    open_in_plex_available: true,
    qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=Owned',
    basis_release_id: null,
    basis_plex_item_id: '22222222-2222-4222-8222-222222222222',
    open_in_plex_status: 'exact',
    holdings: [],
    tracks: [],
    formats: ['Digital Media'],
    credits: { status: 'unavailable', groups: [] },
    recommendation: null,
    description: null,
    plex_playback_context: { status: 'available', basis: 'active_holding', player_state: null, observed_at: null, last_played_at: null, expires_at: null, availability_as_of: '2026-07-24T00:00:00Z' },
    discogs: null,
};

function renderPage(album: AlbumDetail, routeState?: { from: string; label: string }) {
    vi.mocked(api.album).mockResolvedValue(album);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={[{ pathname: `/albums/${album.id}`, state: routeState }]}>
                <PlaybackProvider><Routes><Route path="/albums/:id" element={<AlbumDetailPage />} /></Routes></PlaybackProvider>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('AlbumDetailPage provenance', () => {
    beforeEach(() => vi.mocked(api.album).mockReset());
    afterEach(cleanup);

    it('separates an owned collection source, listening evidence, and metadata', async () => {
        renderPage(ownedAlbum);

        expect(await screen.findByRole('heading', { name: 'Sources & evidence' })).toBeVisible();
        expect(screen.getByText('Plex')).toBeVisible();
        expect(screen.getByText(/Collection source · Playback evidence: 5 album views, 7 tracks played/)).toBeVisible();
        expect(screen.getByText('ListenBrainz')).toBeVisible();
        expect(screen.getByText(/Listening evidence: 12 listens/)).toBeVisible();
        expect(screen.getByText('MusicBrainz')).toBeVisible();
        expect(screen.getByText('Metadata source')).toBeVisible();
    });

    it('reports observed Plex activity independently of local playback', async () => {
        renderPage({ ...ownedAlbum, plex_playback_context: { status: 'currently_active', basis: 'active_session', player_state: 'paused', observed_at: '2026-07-24T00:00:00Z', last_played_at: null, expires_at: new Date(Date.now() + 60_000).toISOString(), availability_as_of: '2026-07-24T00:00:00Z' } });

        expect(await screen.findByText('Paused in Plex')).toBeVisible();
        expect(screen.queryByRole('button', { name: /^Play$/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Pause$/ })).not.toBeInTheDocument();
    });

    it('returns to URL-backed search context', async () => {
        renderPage(ownedAlbum, { from: '/search?q=Owned&scope=catalog', label: 'search' });

        expect(await screen.findByRole('link', { name: 'Back to search' })).toHaveAttribute('href', '/search?q=Owned&scope=catalog');
    });

    it('separates Beyond recommendation evidence from metadata without duplicate provider labels', async () => {
        renderPage({
            ...ownedAlbum,
            id: '33333333-3333-4333-8333-333333333333',
            plex_item_id: null,
            title: 'Beyond Album',
            listening_signals: null,
            sources: ['MusicBrainz', 'Cover Art Archive'],
            owned: false,
            open_in_plex_available: false,
            basis_plex_item_id: null,
            open_in_plex_status: 'unavailable',
            qobuz: { url: 'https://open.qobuz.com/album/0886445885030', status: 'exact', source: 'musicbrainz_url_relationship' },
            recommendation: {
                item_id: '44444444-4444-4444-8444-444444444444',
                reasons: [{ code: 'listenbrainz', text: 'Listeners with similar taste returned to this album.', source: 'listenbrainz' }],
                explanation_text: 'Recommendation evidence.',
                feedback: null,
            },
        });

        expect(await screen.findByText('Listeners with similar taste returned to this album.')).toBeVisible();
        expect(screen.queryByText('ListenBrainz recommendation')).not.toBeInTheDocument();
        expect(screen.getAllByText('ListenBrainz')).toHaveLength(1);
        expect(screen.getByText('Recommendation evidence')).toBeVisible();
        expect(screen.getAllByText('MusicBrainz')).toHaveLength(1);
        expect(screen.getByText('Cover Art Archive')).toBeVisible();
        expect(screen.getByRole('link', { name: 'Open on Qobuz' })).toHaveAttribute('href', 'https://open.qobuz.com/album/0886445885030');
        expect(screen.getByText('Exact catalog destination from a MusicBrainz URL relationship.')).toBeVisible();
    });

    it('keeps ordinary tracks compact and presents one or multiple structured featured artists', async () => {
        renderPage({
            ...ownedAlbum,
            tracks: [{
                id: 'track-1', disc: 1, position: 1, title: 'Ordinary Track', duration_ms: 180000, featured_artists: [], credits: { status: 'unavailable', groups: [] },
                listening: { identity_status: 'exact', plex: { status: 'counted', play_count: 4, first_listened_at: null, last_listened_at: '2026-07-20T00:00:00Z', availability_as_of: '2026-07-24T00:00:00Z', copy_count: 2, aggregation: 'maximum_across_exact_copies' }, listenbrainz: { status: 'counted', play_count: 2, first_listened_at: '2025-01-01T00:00:00Z', last_listened_at: '2026-07-21T00:00:00Z', availability_as_of: '2026-07-24T00:00:00Z', copy_count: null, aggregation: 'immutable_exact_events' } },
            }, {
                id: 'track-2', disc: 1, position: 2, title: 'Single Feature', duration_ms: 190000,
                featured_artists: [{ id: 'featured-1', name: 'Featured One' }], credits: { status: 'unavailable', groups: [] }, listening: unknownListening,
            }, {
                id: 'track-3', disc: 1, position: 3, title: 'Multiple Features', duration_ms: 200000,
                featured_artists: [{ id: 'featured-1', name: 'Featured One' }, { id: null, name: 'Featured Two' }], credits: { status: 'unavailable', groups: [] }, listening: { identity_status: 'unmatched', plex: { status: 'unmatched_identity', play_count: null, first_listened_at: null, last_listened_at: null, availability_as_of: null, copy_count: null, aggregation: null }, listenbrainz: { status: 'unmatched_identity', play_count: null, first_listened_at: null, last_listened_at: null, availability_as_of: null, copy_count: null, aggregation: null } },
            }],
        });

        expect(await screen.findByText('Ordinary Track')).toBeVisible();
        expect(screen.getByText('Ordinary Track').parentElement).not.toHaveTextContent('feat.');
        expect(screen.getAllByRole('link', { name: 'Featured One' })).toHaveLength(2);
        expect(screen.getAllByRole('link', { name: 'Featured One' })[0]).toHaveAttribute('href', '/artists/featured-1');
        expect(screen.getByText((_, element) => element?.textContent === 'feat. Featured One, Featured Two')).toBeVisible();
        expect(screen.getByText(/Plex 4 plays across 2 exact copies/)).toBeVisible();
        expect(screen.getByText(/ListenBrainz 2 listens/)).toBeVisible();
        expect(screen.getAllByText(/count unknown/)).toHaveLength(2);
        expect(screen.getByText(/exact recording identity missing/)).toBeVisible();
    });

    it('groups source-backed album and track credits without inferring missing roles', async () => {
        const provenance = { provider: 'MusicBrainz', url: 'https://musicbrainz.org/release/one', retrieved_at: '2026-07-23T00:00:00Z' };
        renderPage({
            ...ownedAlbum,
            credits: { status: 'available', groups: [{
                role: 'producer', label: 'Production', items: [{ name: 'Producer Person', target: { id: 'producer-1', kind: 'agent', name: 'Producer Person' }, relationship_type: 'producer', attributes: [], provenance }],
            }] },
            tracks: [{
                id: 'track-credits', disc: 1, position: 1, title: 'Credited Track', duration_ms: 180000, featured_artists: [],
                credits: { status: 'available', groups: [{
                    role: 'songwriter', label: 'Songwriting', items: [{ name: 'Writer Person', target: { id: 'writer-1', kind: 'agent', name: 'Writer Person' }, relationship_type: 'composer', attributes: [], via_work: { id: 'work-1', name: 'Fixture Work' }, provenance }],
                }] }, listening: unknownListening,
            }],
        });

        expect(await screen.findByRole('heading', { name: 'Credits' })).toBeVisible();
        expect(screen.getByRole('link', { name: 'Producer Person' })).toHaveAttribute('href', '/artists/producer-1');
        expect(screen.getByRole('link', { name: 'Writer Person' })).toHaveAttribute('href', '/artists/writer-1');
        expect(screen.getByText(/via Fixture Work/)).toBeVisible();
        expect(screen.queryByText('Engineering:')).not.toBeInTheDocument();
    });

    it('uses an editorial hierarchy with attributed context followed by compact release facts', async () => {
        const longTitle = 'AnUnbrokenAlbumTitleThatMustWrapWithoutExpandingTheCatalogPageAtAnyViewportWidth';
        renderPage({
            ...ownedAlbum,
            title: longTitle,
            labels: [{ name: 'Fixture Label', catalog_number: 'CAT-42' }],
            description: {
                text: 'A concise attributed account of the album.', language: 'en', provider: 'wikipedia', provider_name: 'Wikipedia',
                source_url: 'https://example.test/context', license_name: 'CC BY-SA', license_url: 'https://example.test/license',
            },
        });

        const title = await screen.findByRole('heading', { level: 1, name: longTitle });
        expect(title).toHaveClass('[overflow-wrap:anywhere]');
        const header = title.closest('header');
        expect(header).not.toBeNull();
        expect(within(header!).queryByText(/enriched metadata/i)).not.toBeInTheDocument();
        expect(within(header!).getByTestId('artwork-fallback').parentElement).toHaveClass('ml-auto');
        expect(within(header!).getByRole('button', { name: 'Want to listen' }).parentElement).toHaveClass('contents');
        const context = screen.getByRole('heading', { name: 'About this album' });
        const facts = screen.getByRole('heading', { name: 'Essential facts' });
        expect(context.compareDocumentPosition(facts) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(screen.getByLabelText('Album facts')).toHaveTextContent('Digital Media');
        expect(screen.getByLabelText('Album facts')).toHaveTextContent('Fixture Label · CAT-42');
        expect(screen.getByText('A concise attributed account of the album.')).toBeVisible();
        expect(screen.getByRole('link', { name: 'Wikipedia' })).toHaveAttribute('href', 'https://example.test/context');
        expect(screen.queryByText('Recorded detail, exact relationships, collection copies, and source evidence.')).not.toBeInTheDocument();
    });

    it('omits a redundant holdings section for one exact Plex copy', async () => {
        renderPage({ ...ownedAlbum, holdings: [{ id: 'holding-1', release_id: 'release-1', plex_item_id: 'plex-1', title: 'Owned Album', year: 2020, formats: [], edition_summary: null }] });

        expect(await screen.findByText('Available in Plex')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Open in Plex' })).toBeVisible();
        expect(screen.queryByRole('heading', { name: 'Your copy' })).not.toBeInTheDocument();
    });

    it('shows fresh Discogs catalog fields with direct attribution', async () => {
        renderPage({
            ...ownedAlbum,
            discogs: {
                object_type: 'release', external_id: '42', source_url: 'https://www.discogs.com/release/42', fetched_at: '2026-07-25T10:00:00Z',
                fields: { id: '42', object_type: 'release', country: 'UK', styles: ['Post-Punk'], formats: [{ name: 'Vinyl', descriptions: ['LP'] }], labels: [{ name: 'Fixture Label', catalog_number: 'CAT-42' }] },
            },
        });

        const facts = await screen.findByLabelText('Album facts');
        expect(facts).toHaveTextContent('Post-Punk');
        expect(facts).toHaveTextContent('Vinyl · LP');
        expect(facts).toHaveTextContent('Fixture Label · CAT-42');
        expect(screen.getByRole('link', { name: /Data provided by Discogs/ })).toHaveAttribute('href', 'https://www.discogs.com/release/42');
        expect(screen.getByText(/not affiliated with, sponsored or endorsed by Discogs/)).toBeVisible();
        expect(screen.getByText('Discogs')).toBeVisible();
    });

    it('keeps multi-copy Plex actions in a distinct holdings section and states sparse context', async () => {
        renderPage({
            ...ownedAlbum,
            open_in_plex_status: 'choice-required',
            holdings: [{ id: 'holding-1', release_id: 'release-1', plex_item_id: 'plex-1', title: 'First Copy', year: 2020, formats: ['CD'], edition_summary: 'Deluxe' }, {
                id: 'holding-2', release_id: 'release-2', plex_item_id: 'plex-2', title: 'Second Copy', year: 2021, formats: ['Digital Media'], edition_summary: null,
            }],
        });

        expect(await screen.findByRole('link', { name: 'Choose a Plex copy' })).toHaveAttribute('href', '#album-holdings');
        expect(screen.getByRole('heading', { name: 'Your copies' })).toBeVisible();
        expect(screen.getAllByRole('button', { name: 'Open this copy' })).toHaveLength(2);
        expect(screen.getByText('2020 · CD · Deluxe')).toBeVisible();
        expect(screen.getByText('No attributed album context is available yet.')).toBeVisible();
    });

    it('promotes only credit people with trustworthy portraits while retaining the complete inline list', async () => {
        const provenance = { provider: 'MusicBrainz', url: 'https://musicbrainz.org/release/one', retrieved_at: '2026-07-23T00:00:00Z' };
        renderPage({ ...ownedAlbum, credits: { status: 'available', groups: [{
            role: 'producer', label: 'Production', items: [{
                name: 'Portrait Producer', target: { id: 'producer-portrait', kind: 'agent', name: 'Portrait Producer' }, relationship_type: 'producer', attributes: [], provenance,
                portrait: { id: 'portrait', url: '/portrait.webp', width: 600, height: 600 },
            }, {
                name: 'Text Producer', target: { id: 'producer-text', kind: 'agent', name: 'Text Producer' }, relationship_type: 'producer', attributes: [], provenance, portrait: null,
            }],
        }] } });

        expect(await screen.findByRole('img', { name: 'Portrait Producer portrait' })).toBeVisible();
        expect(screen.getAllByRole('link', { name: /Portrait Producer/ })).toHaveLength(2);
        expect(screen.getByRole('link', { name: 'Text Producer' })).toHaveAttribute('href', '/artists/producer-text');
        expect(screen.queryByRole('img', { name: 'Text Producer portrait unavailable' })).not.toBeInTheDocument();
    });
});
