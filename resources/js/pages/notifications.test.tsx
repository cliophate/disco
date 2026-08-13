import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { NotificationResponse } from '../lib/types';
import { NotificationsPage } from './notifications';

vi.mock('../lib/api', () => ({ api: { notifications: vi.fn(), updateNotification: vi.fn() } }));

const response: NotificationResponse = {
    data: [{
        id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', release_group_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', artist: 'Fixture Artist', title: 'Future Forms', release_date: '2026-08-14', primary_type: 'Album',
        personalization: { match: 'followed', reason: 'An artist you explicitly follow.' },
        source: { provider: 'listenbrainz', provider_name: 'ListenBrainz', url: 'https://listenbrainz.example/releases', snapshot_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' },
        status: 'withdrawn', resolution_reason: 'source_absent', status_detail: 'This release was absent from two fresh ListenBrainz generations. It may have moved or been withdrawn.',
        read: false, read_at: null, links: { album: '/albums/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', upcoming: '/discover/upcoming' }, created_at: '2026-07-24T04:00:00Z', updated_at: '2026-07-25T04:00:00Z',
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, filter: 'all' },
    links: { first: '/api/v1/notifications?page=1', prev: null, next: null, last: '/api/v1/notifications?page=1' },
};

describe('NotificationsPage', () => {
    beforeEach(() => {
        vi.mocked(api.notifications).mockResolvedValue(response);
        vi.mocked(api.updateNotification).mockResolvedValue({ ...response.data[0], read: true, read_at: '2026-07-25T05:00:00Z' });
    });
    afterEach(() => { cleanup(); vi.clearAllMocks(); });

    it('shows durable release context and keeps read controls separate from links', async () => {
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/notifications']}><NotificationsPage /></MemoryRouter></QueryClientProvider>);

        const albumLink = await screen.findByRole('link', { name: 'Future Forms' });
        expect(albumLink).toHaveAttribute('href', '/albums/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        expect(screen.getByText('An artist you explicitly follow.')).toBeVisible();
        expect(screen.getByText(/absent from two fresh ListenBrainz generations/)).toBeVisible();
        expect(screen.getByRole('link', { name: /ListenBrainz/ })).toHaveAttribute('href', 'https://listenbrainz.example/releases');
        const control = screen.getByRole('button', { name: 'Mark read' });
        expect(albumLink.contains(control)).toBe(false);
        fireEvent.click(control);
        await waitFor(() => expect(api.updateNotification).toHaveBeenCalledWith('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', true));

        fireEvent.click(screen.getByRole('tab', { name: 'Unread' }));
        await waitFor(() => expect(api.notifications).toHaveBeenCalledWith('unread', 1, 25));
    });
});
