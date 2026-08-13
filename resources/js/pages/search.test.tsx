import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { Album, ExternalCatalogResult } from '../lib/types';
import { SearchPage } from './search';

vi.mock('../lib/api', () => ({ api: {
    search: vi.fn(),
    externalCatalogSearch: vi.fn(),
    selectExternalAlbum: vi.fn(),
} }));

const album: Album = {
    id: 'album-1',
    plex_item_id: null,
    title: 'Local Album Without Artwork',
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
    open_in_plex_available: false,
    open_in_plex_status: 'unavailable',
    qobuz_search_url: 'https://www.qobuz.com/search?q=fixture',
};

const externalResult: ExternalCatalogResult = {
    mbid: '11111111-1111-4111-8111-111111111111',
    title: 'Ambiguous Album',
    artist: 'Fixture Artist',
    first_release_date: '2024-03-02',
    primary_type: 'EP',
    disambiguation: 'Studio edition',
    artwork_status: 'unknown',
    entity_id: null,
    owned: false,
};

function LocationValue() {
    const location = useLocation();
    return <output aria-label="Current location">{location.pathname}{location.search}</output>;
}

function AlbumDestination() {
    const location = useLocation();
    const navigate = useNavigate();
    const state = location.state as { from?: string; label?: string } | null;
    return <div><p>Selected album</p><output aria-label="Album return context">{state?.from} · {state?.label}</output><button type="button" onClick={() => navigate(-1)}>Browser back</button></div>;
}

function SearchRoute() {
    const navigate = useNavigate();
    return <><SearchPage /><LocationValue /><button type="button" onClick={() => navigate(-1)}>Browser back</button></>;
}

function renderPage(entry: string) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={[entry]}>
                <Routes>
                    <Route path="/search" element={<SearchRoute />} />
                    <Route path="/albums/:id" element={<AlbumDestination />} />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('SearchPage', () => {
    beforeEach(() => {
        vi.mocked(api.search).mockReset().mockResolvedValue({ albums: [], artists: [], meta: { limit: 100, truncated: false } });
        vi.mocked(api.externalCatalogSearch).mockReset().mockResolvedValue([externalResult]);
        vi.mocked(api.selectExternalAlbum).mockReset().mockResolvedValue({ id: 'album-1', owned: false, enrichment: { detail: 'ready', credits: 'ready', narrative: 'missing', artwork: 'ready' } });
    });

    afterEach(cleanup);

    it('composes sparse mixed collection results and keeps album return context', async () => {
        vi.mocked(api.search).mockResolvedValue({
            artists: [{ id: 'artist-1', name: 'Fixture Artist With A Deliberately Long Name', portrait: null }],
            albums: [album, { ...album, id: 'album-2', title: 'Local Album With Artwork', artwork: { id: 'art-1', url: '/artwork/local.jpg', width: 600, height: 600 } }],
            meta: { limit: 100, truncated: false },
        });

        renderPage('/search?q=Fixture%20Artist');

        expect(await screen.findByRole('link', { name: /Fixture Artist With A Deliberately Long Name/ })).toBeVisible();
        expect(api.search).toHaveBeenCalledWith('Fixture Artist');
        expect(screen.getByText('Artist in your collection')).toBeVisible();
        expect(screen.getByTestId('local-artist-results')).toHaveClass('max-w-5xl', 'sm:grid-cols-2');
        expect(screen.getByTestId('local-album-results')).toHaveClass('sm:grid-cols-[repeat(auto-fit,minmax(11rem,14rem))]');
        expect(screen.getAllByTestId('artwork-fallback')).toHaveLength(1);
        expect(screen.getByRole('img', { name: 'Local Album With Artwork by Fixture Artist · 2024 artwork' })).toHaveAttribute('src', '/artwork/local.jpg');

        const albumLink = screen.getByRole('link', { name: /Local Album Without Artwork/ });
        expect(albumLink.querySelector('button')).not.toBeInTheDocument();
        fireEvent.click(albumLink);

        expect(await screen.findByText('Selected album')).toBeVisible();
        expect(screen.getByLabelText('Album return context')).toHaveTextContent('/search?q=Fixture%20Artist · search');
    });

    it('shows external results as dense text-only release rows and materializes the exact selected identity', async () => {
        const selected = { ...externalResult, mbid: '22222222-2222-4222-8222-222222222222', title: 'Ambiguous Album Two' };
        vi.mocked(api.externalCatalogSearch).mockResolvedValue([externalResult, selected]);
        renderPage('/search?q=Ambiguous&scope=catalog');

        expect(await screen.findByRole('heading', { name: 'Ambiguous Album' })).toBeVisible();
        expect(api.externalCatalogSearch).toHaveBeenCalledWith('Ambiguous');
        expect(api.search).not.toHaveBeenCalled();
        expect(screen.getByTestId('external-results-grid')).toHaveClass('lg:grid-cols-2');
        expect(screen.getAllByRole('img', { name: 'EP result placeholder; no artwork shown' })).toHaveLength(2);
        expect(screen.getAllByText('MusicBrainz search result · artwork status: unknown')).toHaveLength(2);
        expect(document.querySelector('img')).not.toBeInTheDocument();
        expect(screen.queryByTestId('artwork-fallback')).not.toBeInTheDocument();
        expect(screen.getAllByText('Studio edition')).toHaveLength(2);

        fireEvent.click(screen.getByRole('button', { name: /Add to Disco: Ambiguous Album Two by Fixture Artist/ }));

        await waitFor(() => expect(api.selectExternalAlbum).toHaveBeenCalledTimes(1));
        expect(api.selectExternalAlbum).toHaveBeenCalledWith('22222222-2222-4222-8222-222222222222');
        expect(await screen.findByText('Selected album')).toBeVisible();
        expect(screen.getByLabelText('Album return context')).toHaveTextContent('/search?q=Ambiguous&scope=catalog · search');
        fireEvent.click(screen.getByRole('button', { name: 'Browser back' }));
        expect(await screen.findByRole('button', { name: /Open in Disco: Ambiguous Album Two by Fixture Artist/ })).toBeVisible();
        expect(api.selectExternalAlbum).toHaveBeenCalledTimes(1);
    });

    it('opens an already materialized exact identity without posting it again', async () => {
        vi.mocked(api.externalCatalogSearch).mockResolvedValue([{ ...externalResult, entity_id: 'existing-album', owned: true }]);
        renderPage('/search?q=Owned&scope=catalog');

        fireEvent.click(await screen.findByRole('button', { name: /Open owned album: Ambiguous Album by Fixture Artist/ }));

        expect(await screen.findByText('Selected album')).toBeVisible();
        expect(api.selectExternalAlbum).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Album return context')).toHaveTextContent('/search?q=Owned&scope=catalog · search');
    });

    it('keeps query and scope URL-addressable and restores collection state on browser back', async () => {
        vi.mocked(api.search).mockResolvedValue({ albums: [album], artists: [], meta: { limit: 100, truncated: false } });
        renderPage('/search?q=Blue');

        expect(await screen.findByRole('heading', { name: 'Local Album Without Artwork' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Your collection' })).toHaveAttribute('aria-pressed', 'true');
        fireEvent.click(screen.getByRole('button', { name: 'External catalog' }));

        expect(await screen.findByRole('heading', { name: 'Ambiguous Album' })).toBeVisible();
        expect(screen.getByLabelText('Current location')).toHaveTextContent('/search?q=Blue&scope=catalog');
        expect(screen.getByRole('button', { name: 'External catalog' })).toHaveAttribute('aria-pressed', 'true');
        fireEvent.click(screen.getByRole('button', { name: 'Browser back' }));

        await waitFor(() => expect(screen.getByLabelText('Current location')).toHaveTextContent('/search?q=Blue'));
        expect(screen.getByRole('button', { name: 'Your collection' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('textbox', { name: 'Album title or artist name' })).toHaveValue('Blue');
    });

    it('does not show cached collection results in an empty catalog scope', async () => {
        vi.mocked(api.search).mockResolvedValue({ albums: [album], artists: [], meta: { limit: 100, truncated: false } });
        vi.mocked(api.externalCatalogSearch).mockResolvedValue([]);
        renderPage('/search?q=Blue');

        expect(await screen.findByRole('heading', { name: 'Local Album Without Artwork' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'External catalog' }));

        expect(await screen.findByRole('heading', { name: 'No matches' })).toBeVisible();
        expect(screen.queryByRole('heading', { name: 'Local Album Without Artwork' })).not.toBeInTheDocument();
    });

    it('preserves external scope when a trimmed query is submitted', async () => {
        renderPage('/search?scope=catalog');
        fireEvent.change(screen.getByRole('textbox', { name: 'Album title or artist name' }), { target: { value: '  New Name  ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        await waitFor(() => expect(api.externalCatalogSearch).toHaveBeenCalledWith('New Name'));
        expect(screen.getByLabelText('Current location')).toHaveTextContent('/search?q=New%20Name&scope=catalog');
    });

    it('keeps a large external set compact and long labels intact', async () => {
        const results = Array.from({ length: 18 }, (_, index): ExternalCatalogResult => ({
            ...externalResult,
            mbid: `${String(index).padStart(8, '0')}-1111-4111-8111-111111111111`,
            title: index === 0 ? 'AnUnbrokenAlbumTitleThatMustNeverWidenTheSearchCanvasBeyondItsBounds' : `Catalog Album ${index + 1}`,
            artist: index === 0 ? 'An exceptionally long credited artist name that remains readable' : 'Fixture Artist',
        }));
        vi.mocked(api.externalCatalogSearch).mockResolvedValue(results);

        renderPage('/search?q=Catalog&scope=catalog');

        expect(await screen.findByRole('heading', { name: results[0].title })).toBeVisible();
        expect(screen.getAllByRole('img', { name: /result placeholder; no artwork shown/ })).toHaveLength(18);
        expect(screen.getByText(results[0].artist)).toBeVisible();
        expect(document.querySelector('img')).not.toBeInTheDocument();
    });

    it('uses scope-specific loading and explicit empty states', async () => {
        vi.mocked(api.search).mockImplementation(() => new Promise(() => {}));
        const localLoading = renderPage('/search?q=Waiting');

        expect(screen.getByRole('status', { name: 'Loading collection results' })).toBeVisible();
        localLoading.unmount();

        vi.mocked(api.externalCatalogSearch).mockImplementation(() => new Promise(() => {}));
        const externalLoading = renderPage('/search?q=Waiting&scope=catalog');

        expect(screen.getByRole('status', { name: 'Loading external catalog results' })).toBeVisible();
        externalLoading.unmount();

        vi.mocked(api.externalCatalogSearch).mockResolvedValue([]);
        const externalEmpty = renderPage('/search?q=Missing&scope=catalog');

        expect(await screen.findByRole('heading', { name: 'No matches' })).toBeVisible();
        expect(screen.getByText('No supported albums or EPs match “Missing”.')).toBeVisible();
        externalEmpty.unmount();

        vi.mocked(api.search).mockResolvedValue({ albums: [], artists: [], meta: { limit: 100, truncated: false } });
        vi.mocked(api.externalCatalogSearch).mockResolvedValue([externalResult]);
        renderPage('/search?q=Missing');

        expect(await screen.findByRole('heading', { name: 'No matches' })).toBeVisible();
        expect(screen.getByText('Nothing in the collection matches “Missing”. Search the live MusicBrainz catalog without changing your query.')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: /Search external catalog/ }));
        expect(await screen.findByRole('heading', { name: 'Ambiguous Album' })).toBeVisible();
        expect(api.externalCatalogSearch).toHaveBeenCalledWith('Missing');
    });
});
