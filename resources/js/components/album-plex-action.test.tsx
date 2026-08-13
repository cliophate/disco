import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { Album } from '../lib/types';
import { AlbumCard } from './album-card';
import { AlbumPlexAction } from './album-plex-action';

vi.mock('../lib/api', () => ({ api: { plexTarget: vi.fn() } }));

const album: Album = {
    id: '11111111-1111-4111-8111-111111111111',
    plex_item_id: '22222222-2222-4222-8222-222222222222',
    title: 'Fixture Album',
    artist: null,
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
    metadata_status: 'identified',
    identity_status: 'confirmed',
    open_in_plex_available: true,
    open_in_plex_status: 'exact',
    qobuz_search_url: 'https://www.qobuz.com/ie-en/search/?q=Fixture',
};

function renderCard(subject: Album) {
    const queryClient = new QueryClient({ defaultOptions: { mutations: { retry: false } } });

    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter><AlbumCard album={subject} actions={<AlbumPlexAction album={subject} />} /></MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('AlbumPlexAction', () => {
    afterEach(cleanup);

    it('places an exact Plex action beside rather than inside the album link', () => {
        renderCard(album);

        const action = screen.getByRole('button', { name: 'Open in Plex' });
        expect(action.closest('a')).toBeNull();
        expect(screen.getByRole('link', { name: /Fixture Album/ })).toHaveAttribute('href', `/albums/${album.id}`);
    });

    it('requires detail-page holding choice for multiple copies', () => {
        renderCard({ ...album, open_in_plex_status: 'choice-required' });

        expect(screen.queryByRole('button', { name: 'Open in Plex' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Choose Plex copy' })).toHaveAttribute('href', `/albums/${album.id}`);
    });

    it('hides actions for unavailable and outside-library albums', () => {
        const { rerender } = renderCard({ ...album, plex_item_id: null, open_in_plex_available: false, open_in_plex_status: 'unavailable' });
        expect(screen.queryByText(/Plex copy|Open in Plex/)).not.toBeInTheDocument();

        rerender(
            <QueryClientProvider client={new QueryClient()}>
                <MemoryRouter><AlbumPlexAction album={{ ...album, owned: false, plex_item_id: null, open_in_plex_available: false, open_in_plex_status: 'unavailable' }} /></MemoryRouter>
            </QueryClientProvider>,
        );
        expect(screen.queryByText(/Plex copy|Open in Plex/)).not.toBeInTheDocument();
    });
});
