import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { Album, DiscoverItem, DiscoverResponse, Recommendation } from '../lib/types';
import { DiscoverPage } from './discover';

vi.mock('../lib/api', () => ({ api: { discover: vi.fn() } }));

function album(id: string): Album {
    return {
        id,
        plex_item_id: null,
        title: `Album ${id}`,
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
        owned: id !== 'two',
        metadata_status: 'enriched',
        identity_status: 'confirmed',
        open_in_plex_available: false,
        open_in_plex_status: 'unavailable',
        qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=fixture',
    };
}

function recommendation(id: string): Recommendation {
    return {
        album: album(id),
        lens: id === 'two' ? 'Beyond your library' : 'Rediscover',
        reasons: [{ code: 'fixture', text: `Reason for ${id}.`, source: 'listenbrainz' }],
    };
}

function response(items: DiscoverItem[], page: number, total: number, hasNext: boolean): DiscoverResponse {
    return {
        data: items,
        meta: {
            edition_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            edition_version: 'fixture-version',
            generated_at: '2026-07-23T12:00:00Z',
            current_page: page,
            last_page: hasNext ? page + 1 : page,
            per_page: 12,
            total,
        },
        links: {
            first: '/api/v1/discover?page=1',
            prev: page > 1 ? `/api/v1/discover?page=${page - 1}` : null,
            next: hasNext ? `/api/v1/discover?page=${page + 1}` : null,
            last: `/api/v1/discover?page=${hasNext ? page + 1 : page}`,
        },
    };
}

function renderPage() {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={['/discover']}><DiscoverPage /></MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('DiscoverPage', () => {
    beforeEach(() => vi.mocked(api.discover).mockReset());
    afterEach(cleanup);

    it('renders a distinct mixed edition and loads its pinned next page', async () => {
        vi.mocked(api.discover).mockImplementation(async (page) => page === 1
            ? response([
                { id: 'album:one', type: 'album', presentation: 'feature', span: 'feature', lens: 'Rediscover', description: 'Owned path.', recommendation: recommendation('one') },
                { id: 'artist:one', type: 'artist', presentation: 'portrait', span: 'standard', lens: 'Recently in view', artist: { id: 'artist-one', name: 'Artist One', portrait: null, type: 'Group', area: 'Dublin', genres: [] } },
            ], 1, 3, true)
            : response([
                { id: 'album:two', type: 'album', presentation: 'editorial', span: 'wide', lens: 'Beyond your library', description: 'External path.', recommendation: recommendation('two') },
            ], 2, 3, false));

        renderPage();

        expect(await screen.findByRole('heading', { name: 'Album one' })).toBeVisible();
        expect(screen.getByRole('link', { name: /Artist One/ })).toBeVisible();
        expect(screen.queryByText(/Showing .* cards/)).not.toBeInTheDocument();
        expect(screen.getByRole('banner')).not.toHaveClass('border-b');
        expect(screen.getByRole('banner')).not.toHaveClass('border-y');
        expect(screen.getByRole('link', { name: 'Current edition' })).toHaveClass('!border-coral', 'text-ink');
        expect(screen.getByRole('link', { name: 'Release window' })).toHaveClass('!border-transparent', 'text-fog');
        fireEvent.click(screen.getByRole('button', { name: 'Load more' }));
        expect(await screen.findByRole('heading', { name: 'Album two' })).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument();
        expect(vi.mocked(api.discover)).toHaveBeenNthCalledWith(2, 2, 12, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    });

    it('keeps the loaded edition visible when a later page fails', async () => {
        let secondPageAttempts = 0;
        vi.mocked(api.discover).mockImplementation(async (page) => {
            if (page === 1) return response([
                { id: 'album:one', type: 'album', presentation: 'cover', span: 'standard', lens: 'Rediscover', description: null, recommendation: recommendation('one') },
            ], 1, 2, true);
            secondPageAttempts++;
            if (secondPageAttempts === 1) throw new Error('Later page failed.');

            return response([
                { id: 'album:two', type: 'album', presentation: 'cover', span: 'standard', lens: 'Beyond your library', description: null, recommendation: recommendation('two') },
            ], 2, 2, false);
        });

        renderPage();
        await screen.findByRole('heading', { name: 'Album one' });
        fireEvent.click(screen.getByRole('button', { name: 'Load more' }));
        expect(await screen.findByRole('button', { name: 'Retry next page' })).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Album one' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Retry next page' }));
        expect(await screen.findByRole('heading', { name: 'Album two' })).toBeVisible();
    });

    it('renders an attributed outbound RSS story with its feed thumbnail', async () => {
        vi.mocked(api.discover).mockResolvedValue(response([{
            id: 'editorial:story-one', type: 'editorial', presentation: 'story', span: 'wide',
            editorial: {
                id: 'story-one', source: 'pitchfork', publication: 'Pitchfork', publisher: 'Condé Nast', headline: 'A fixture story',
                excerpt: 'A feed-supplied excerpt.', author: 'Fixture Writer', category: 'News',
                published_at: '2026-07-24T12:00:00Z', url: 'https://pitchfork.com/story/fixture/',
                image: { url: 'https://media.pitchfork.com/photos/fixture.jpg', width: 1200, height: 800 },
            },
        }], 1, 1, false));

        renderPage();

        const link = await screen.findByRole('link', { name: /A fixture story/ });
        expect(link).toHaveAttribute('href', 'https://pitchfork.com/story/fixture/');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveClass('bg-[#17191f]', 'text-[#fff8e9]');
        expect(screen.getByText('A feed-supplied excerpt.')).toBeVisible();
        expect(link.querySelector('img')).toHaveAttribute('referrerpolicy', 'no-referrer');
    });

    it('shows an explicit empty edition', async () => {
        vi.mocked(api.discover).mockResolvedValue(response([], 1, 0, false));

        renderPage();

        expect(await screen.findByRole('heading', { name: 'No discovery edition is ready' })).toBeVisible();
    });
});
