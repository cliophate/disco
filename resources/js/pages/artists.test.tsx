import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { ArtistPage } from '../lib/types';
import { ArtistsPage } from './artists';

vi.mock('../lib/api', () => ({ api: { artists: vi.fn() } }));

const response: ArtistPage = {
    data: [{ id: 'artist-1', name: 'Fixture Artist', portrait: null, type: 'Person', area: 'Dublin', genres: [] }],
    meta: {
        current_page: 2,
        last_page: 3,
        per_page: 24,
        total: 1,
        filters: { all: 12, person: 4, group: 7, other: 1 },
        sort: '-name',
        filter: 'person',
    },
    links: {
        first: 'https://disco.test/api/v1/artists?page=1',
        prev: 'https://disco.test/api/v1/artists?page=1&size=24&type=person&sort=-name',
        next: 'https://disco.test/api/v1/artists?page=3&size=24&type=person&sort=-name',
        last: 'https://disco.test/api/v1/artists?page=3&size=24&type=person&sort=-name',
    },
};

function LocationValue() {
    const location = useLocation();
    return <output aria-label="Current location">{location.pathname}{location.search}</output>;
}

function renderPage() {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={queryClient}>
            <MemoryRouter initialEntries={['/artists?type=person&sort=-name&page=2']}>
                <ArtistsPage />
                <LocationValue />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('ArtistsPage', () => {
    beforeEach(() => vi.mocked(api.artists).mockReset());
    afterEach(cleanup);

    it('presents a paginated count-aware artist destination', async () => {
        vi.mocked(api.artists).mockResolvedValue(response);

        renderPage();

        expect(await screen.findByRole('heading', { name: 'Artists' })).toBeVisible();
        expect(screen.getByRole('button', { name: /People.*4/ })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('combobox', { name: 'Sort' })).toHaveValue('-name');
        const artistLink = screen.getByRole('link', { name: /Fixture Artist/ });
        expect(artistLink).toHaveAttribute('href', '/artists/artist-1');
        expect(artistLink.parentElement).toHaveClass('artist-index-grid');
        expect(screen.queryByRole('group', { name: 'Card density' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Load more' })).toBeVisible();
        expect(vi.mocked(api.artists)).toHaveBeenCalledWith(2, 'person', '-name');
    });

    it('resets pagination when a filter changes', async () => {
        vi.mocked(api.artists).mockResolvedValue(response);
        renderPage();
        await screen.findByRole('heading', { name: 'Artists' });

        fireEvent.click(screen.getByRole('button', { name: /Groups.*7/ }));

        await waitFor(() => expect(screen.getByLabelText('Current location')).toHaveTextContent('/artists?type=group&sort=-name'));
        expect(vi.mocked(api.artists)).toHaveBeenLastCalledWith(1, 'group', '-name');
    });

    it('shows an explicit empty filtered view', async () => {
        vi.mocked(api.artists).mockResolvedValue({ ...response, data: [], meta: { ...response.meta, total: 0 } });

        renderPage();

        expect(await screen.findByRole('heading', { name: 'No artists in this view' })).toBeVisible();
    });
});
