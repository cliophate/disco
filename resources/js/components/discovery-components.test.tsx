import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import type { Album } from '../lib/types';
import { AlbumCard, RecommendationReasons } from './album-card';
import { AlbumRail } from './album-rail';
import { Artwork } from './artwork';

const album: Album = {
    id: 'album-1', plex_item_id: null, title: 'External Album', artist: null, year: 2024,
    artwork: null, added_at: null, duration_ms: null, track_count: null, last_heard_at: null,
    play_count: null, listening_signals: null, release_type: 'Album', first_release_date: null,
    genres: [], genre_basis: null, labels: [], disambiguation: null, sources: ['MusicBrainz'],
    owned: false, metadata_status: 'enriched', identity_status: 'confirmed', open_in_plex_available: false, open_in_plex_status: 'unavailable',
    qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=External%20Album',
    list_state: null,
};

afterEach(cleanup);

function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={new QueryClient()}><MemoryRouter>{children}</MemoryRouter></QueryClientProvider>;
}

describe('Artwork', () => {
    it('replaces an image that fails to load with a deterministic labelled fallback', () => {
        render(<Artwork artwork={{ id: 'art-1', url: '/missing.jpg', width: 600, height: 600 }} title="Blue Lines" artist="Massive Attack" />);

        fireEvent.error(screen.getByRole('img', { name: 'Blue Lines by Massive Attack artwork' }));

        expect(screen.queryByRole('img', { name: 'Blue Lines by Massive Attack artwork' })).not.toBeInTheDocument();
        expect(screen.getByTestId('artwork-fallback')).toHaveAccessibleName('Blue Lines by Massive Attack artwork unavailable');
        expect(screen.getByText('Blue Lines')).toBeInTheDocument();
    });
});

describe('RecommendationReasons', () => {
    it('renders factual explanation text without a provider pill', () => {
        render(<RecommendationReasons reasons={[{ code: 'short_runtime', text: 'Runs 42 minutes.', source: 'plex' }]} />);

        expect(screen.getByLabelText('Why this was recommended')).toHaveTextContent('Runs 42 minutes.');
        expect(screen.queryByText('plex')).not.toBeInTheDocument();
    });
});

describe('AlbumCard', () => {
    it('keeps recommendation feedback actions outside the album link', () => {
        render(<Wrapper><AlbumCard album={album} actions={<button type="button">Not for me</button>} /></Wrapper>);

        expect(screen.getByRole('button', { name: 'Not for me' }).closest('a')).toBeNull();
        expect(screen.getByRole('button', { name: 'Want to listen' }).closest('a')).toBeNull();
        expect(screen.getByRole('link', { name: /External Album/i })).toHaveAttribute('href', '/albums/album-1');
    });

    it('contains symbol-heavy album titles inside the card', () => {
        const title = `Record ${'\u035c'.repeat(12)}`;
        render(<Wrapper><AlbumCard album={{ ...album, title }} /></Wrapper>);

        const heading = screen.getByRole('heading', { name: 'Symbolic title' });
        expect(heading).toHaveAttribute('title', title);
        expect(screen.getByRole('link', { name: 'Album with a symbolic title by Unknown artist' })).toHaveAttribute('href', '/albums/album-1');
    });
});

describe('AlbumRail', () => {
    it('links headings with additional results to their full destination', () => {
        const recommendation = { album, reasons: [], lens: 'Waiting on your shelves' };
        const { rerender } = render(<Wrapper><AlbumRail section={{ type: 'waiting', title: 'Waiting on your shelves', description: 'No listening signal.', total: 9, items: [recommendation] }} /></Wrapper>);

        expect(screen.getByRole('link', { name: 'View all' })).toHaveAttribute('href', '/discover/lenses/waiting');

        rerender(<Wrapper><AlbumRail section={{ type: 'beyond-library', title: 'Beyond your library', description: 'Outside the collection.', items: [recommendation] }} /></Wrapper>);
        expect(screen.getByRole('link', { name: 'View all' })).toHaveAttribute('href', '/beyond');
    });

    it('uses equal-height bordered cards with an icon-only list control and no Plex action', () => {
        const owned = { ...album, artist: { id: 'artist-1', name: 'Example Artist', portrait: null, type: null, area: null, genres: [] }, added_at: '2026-07-23T08:00:00Z', owned: true, plex_item_id: 'plex-1', open_in_plex_available: true, open_in_plex_status: 'exact' as const };
        const recommendation = { album: owned, reasons: [{ code: 'no_listen_signal', text: 'No play signals.', source: 'plex' }], lens: 'Waiting on your shelves' };
        render(<Wrapper><AlbumRail section={{ type: 'waiting', title: 'Waiting on your shelves', description: 'No listening signal.', items: [recommendation] }} /></Wrapper>);

        const bookmark = screen.getByRole('button', { name: 'Want to listen' });
        expect(bookmark).toHaveAttribute('title', 'Want to listen');
        expect(bookmark).toHaveTextContent('');
        expect(bookmark).not.toHaveClass('border', 'bg-panel/95');
        expect(bookmark).toHaveClass('size-8', 'rounded-full', 'bg-panel/90');
        expect(bookmark.parentElement?.parentElement).toHaveClass('opacity-0', 'group-hover:opacity-100');
        expect(screen.queryByRole('button', { name: 'Open in Plex' })).not.toBeInTheDocument();
        expect(bookmark.closest('article')).toHaveClass('h-full', 'border', 'bg-panel');
        expect(screen.getByRole('link', { name: 'Example Artist' })).toHaveAttribute('href', '/artists/artist-1');
        expect(screen.getByText('No play signals.').closest('.mt-auto')).toBeInTheDocument();
        expect(screen.getByText(/^Added /)).toBeVisible();
    });
});
