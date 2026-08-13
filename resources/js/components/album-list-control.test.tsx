import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { AlbumListState } from '../lib/types';
import { AlbumListControl } from './album-list-control';

vi.mock('../lib/api', () => ({ api: { updateAlbumListState: vi.fn(), removeAlbumListState: vi.fn() } }));

const state: AlbumListState = { id: 'state', album_id: 'album', status: 'want_to_listen', note: null, source: null, wanted_at: '2026-07-24T00:00:00Z', listened_at: null, removed_at: null, state_changed_at: '2026-07-24T00:00:00Z', updated_at: '2026-07-24T00:00:00Z' };

describe('AlbumListControl', () => {
    it('offers an accessible icon-only rail treatment', () => {
        const client = new QueryClient();
        render(<QueryClientProvider client={client}><AlbumListControl albumId="album" iconOnly /></QueryClientProvider>);

        const control = screen.getByRole('button', { name: 'Want to listen' });
        expect(control).toHaveAttribute('title', 'Want to listen');
        expect(control).toHaveTextContent('');
    });

    it('saves private context and explicit lifecycle transitions', async () => {
        vi.mocked(api.updateAlbumListState).mockImplementation(async (_id, payload) => ({ ...state, ...payload }));
        const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
        render(<QueryClientProvider client={client}><AlbumListControl albumId="album" initialState={state} detail /></QueryClientProvider>);
        fireEvent.click(screen.getByText('Private note and source'));
        fireEvent.change(screen.getByLabelText('Recommendation source'), { target: { value: 'Alex' } });
        fireEvent.change(screen.getByLabelText('Private note'), { target: { value: 'Try mono' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save note' }));
        await waitFor(() => expect(api.updateAlbumListState).toHaveBeenCalledWith('album', { status: 'want_to_listen', note: 'Try mono', source: 'Alex' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mark listened' }));
        await waitFor(() => expect(api.updateAlbumListState).toHaveBeenLastCalledWith('album', { status: 'listened', note: 'Try mono', source: 'Alex' }));
    });
});
