import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { Shell } from './shell';

describe('Shell theme control', () => {
    afterEach(() => {
        cleanup();
        window.localStorage.removeItem('disco-theme');
        delete document.documentElement.dataset.theme;
    });

    it('persists an explicit view choice', () => {
        const queryClient = new QueryClient();
        render(
            <QueryClientProvider client={queryClient}>
                <MemoryRouter>
                    <Shell user={{ id: 'owner', name: 'Owner', email: 'owner@example.test', unread_notification_count: 0 }}>
                        <p>Collection</p>
                    </Shell>
                </MemoryRouter>
            </QueryClientProvider>,
        );

        fireEvent.click(screen.getAllByRole('button', { name: 'Switch to dark view' })[0]);

        expect(document.documentElement.dataset.theme).toBe('dark');
        expect(window.localStorage.getItem('disco-theme')).toBe('dark');
        expect(screen.getAllByRole('button', { name: 'Switch to light view' })[0]).toBeInTheDocument();
        expect(screen.getAllByRole('link', { name: 'Artists' })[0]).toHaveAttribute('href', '/artists');
        expect(screen.getAllByRole('link', { name: 'Search' })[0]).toHaveAttribute('href', '/search');
    });

    it('keeps Metadata in active utility navigation outside desktop and mobile primary navigation', () => {
        const queryClient = new QueryClient();
        render(
            <QueryClientProvider client={queryClient}>
                <MemoryRouter initialEntries={['/metadata']}>
                    <Shell user={{ id: 'owner', name: 'Owner', email: 'owner@example.test', unread_notification_count: 124 }}>
                        <p>Metadata atlas</p>
                    </Shell>
                </MemoryRouter>
            </QueryClientProvider>,
        );

        expect(within(screen.getByRole('navigation', { name: 'Primary navigation' })).queryByRole('link', { name: 'Metadata' })).not.toBeInTheDocument();
        const utilityLink = within(screen.getByRole('navigation', { name: 'Utility navigation' })).getByRole('link', { name: 'Metadata' });
        expect(utilityLink).toHaveAttribute('href', '/metadata');
        expect(utilityLink).toHaveAttribute('aria-current', 'page');

        fireEvent.click(screen.getByRole('button', { name: 'Open navigation' }));

        expect(within(screen.getByRole('navigation', { name: 'Mobile navigation' })).queryByRole('link', { name: 'Metadata' })).not.toBeInTheDocument();
        expect(utilityLink).toBeVisible();
        expect(screen.getAllByRole('link', { name: 'Notifications, 124 unread notifications' })).toHaveLength(2);
        expect(screen.getAllByText('99+')).toHaveLength(2);
    });

    it('links both owner identities to private administration', () => {
        const queryClient = new QueryClient();
        render(
            <QueryClientProvider client={queryClient}>
                <MemoryRouter>
                    <Shell user={{ id: 'owner', name: 'Owner', email: 'owner@example.test', unread_notification_count: 0 }}>
                        <p>Collection</p>
                    </Shell>
                </MemoryRouter>
            </QueryClientProvider>,
        );

        expect(screen.getByTitle('owner@example.test · Owner administration')).toHaveAttribute('href', '/admin');

        fireEvent.click(screen.getByRole('button', { name: 'Open navigation' }));

        expect(screen.getByRole('link', { name: 'Owner, owner administration' })).toHaveAttribute('href', '/admin');
    });
});
