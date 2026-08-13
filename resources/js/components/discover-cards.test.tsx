import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import type { Album, DiscoverAlbumItem, DiscoverArtistItem } from '../lib/types';
import {
    DiscoverAlbumCard,
    DiscoverArtistCard,
    DiscoverEditorialCard,
    DiscoverFeatureCard,
    DiscoverOverlayCard,
    DiscoverTextCard,
} from './discover-cards';

const album: Album = {
    id: 'album-1', plex_item_id: null, title: 'Hyrule', artist: null, year: 2025,
    artwork: null, added_at: null, duration_ms: null, track_count: null, last_heard_at: null,
    play_count: null, listening_signals: null, release_type: 'Album', first_release_date: null,
    genres: ['Ambient'], genre_basis: 'album', labels: [], disambiguation: null, sources: ['MusicBrainz'],
    owned: true, metadata_status: 'enriched', identity_status: 'confirmed', open_in_plex_available: false, open_in_plex_status: 'unavailable',
    qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=Hyrule',
};

function item(presentation: DiscoverAlbumItem['presentation']): DiscoverAlbumItem {
    return {
        id: `album:${presentation}`,
        type: 'album',
        presentation,
        span: presentation === 'feature' ? 'feature' : 'standard',
        lens: 'Rediscover',
        description: 'An editorial path back into the collection.',
        recommendation: { album, lens: 'Rediscover', reasons: [{ code: 'fixture', text: 'Last heard a while ago.', source: 'listenbrainz' }] },
    };
}

afterEach(cleanup);

describe('Discover cards', () => {
    it('renders the feature and standard album treatments as coherent links', () => {
        const { rerender } = render(<MemoryRouter><DiscoverFeatureCard item={item('feature')} /></MemoryRouter>);

        expect(screen.getByRole('link', { name: /Hyrule/ })).toHaveAttribute('href', '/albums/album-1');
        expect(screen.getByTestId('artwork-fallback').parentElement).toHaveClass('aspect-[16/10]');
        expect(screen.getByRole('heading', { name: 'Hyrule' })).toHaveClass('text-5xl');

        rerender(<MemoryRouter><DiscoverAlbumCard item={item('cover')} /></MemoryRouter>);
        expect(screen.getByRole('heading', { name: 'Hyrule' })).toBeVisible();
        expect(screen.queryByText('Via ListenBrainz')).not.toBeInTheDocument();
    });

    it('uses controlled editorial, text-only, and overlay variants', () => {
        const { rerender } = render(<MemoryRouter><DiscoverEditorialCard item={item('editorial')} index={0} /></MemoryRouter>);
        expect(screen.getByTestId('artwork-fallback').parentElement).toHaveClass('aspect-[4/5]');
        expect(screen.getByText('Last heard a while ago.')).toBeVisible();

        rerender(<MemoryRouter><DiscoverTextCard item={item('text')} /></MemoryRouter>);
        expect(screen.getByRole('link', { name: /Hyrule/ })).toHaveClass('bg-cobalt/5');

        rerender(<MemoryRouter><DiscoverOverlayCard item={item('overlay')} /></MemoryRouter>);
        expect(screen.getByTestId('artwork-fallback').parentElement).toHaveClass('aspect-[3/4]');
        expect(screen.getByText('Ambient')).toBeVisible();
        expect(screen.getByRole('link', { name: /Hyrule/ }).parentElement).toHaveClass('border');
    });

    it('renders a comfortably sized relationship card', () => {
        const artist: DiscoverArtistItem = {
            id: 'artist:one',
            type: 'artist',
            presentation: 'portrait',
            span: 'standard',
            lens: 'Recently in view',
            artist: { id: 'artist-1', name: 'Fixture Artist', portrait: null, type: 'Group', area: 'Dublin', genres: [] },
        };
        render(<MemoryRouter><DiscoverArtistCard item={artist} /></MemoryRouter>);

        expect(screen.getByRole('link', { name: /Fixture Artist/ })).toHaveAttribute('href', '/artists/artist-1');
        expect(screen.getByRole('heading', { name: 'Fixture Artist' })).toHaveClass('text-3xl');
    });

    it('reserves owned Plex actions for the leading feature treatment', () => {
        const exact = item('cover');
        exact.recommendation.album = {
            ...album,
            plex_item_id: 'plex-album-1',
            open_in_plex_available: true,
            open_in_plex_status: 'exact',
        };
        const { rerender } = render(<QueryClientProvider client={new QueryClient()}><MemoryRouter><DiscoverAlbumCard item={exact} /></MemoryRouter></QueryClientProvider>);

        expect(screen.queryByRole('button', { name: 'Open in Plex' })).not.toBeInTheDocument();
        rerender(<QueryClientProvider client={new QueryClient()}><MemoryRouter><DiscoverFeatureCard item={{ ...exact, presentation: 'feature', span: 'feature' }} /></MemoryRouter></QueryClientProvider>);
        const action = screen.getByRole('button', { name: 'Open in Plex' });
        expect(action.closest('a')).toBeNull();
        expect(action.parentElement?.parentElement).toHaveClass('border-t', 'pt-5');
        expect(screen.getByRole('link', { name: /Hyrule/ })).toHaveAttribute('href', '/albums/album-1');

        rerender(<QueryClientProvider client={new QueryClient()}><MemoryRouter><DiscoverOverlayCard item={{ ...exact, presentation: 'overlay' }} /></MemoryRouter></QueryClientProvider>);
        const footer = screen.getByRole('button', { name: 'Open in Plex' }).parentElement?.parentElement;
        expect(footer).toHaveClass('border-t', 'pt-5');
        expect(footer).not.toHaveClass('-mt-px');
    });
});
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
