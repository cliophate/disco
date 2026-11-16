import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import type { DiscoverySection, Pick } from '../lib/types';
import { PickCard, ThisWeekPage } from './this-week';

vi.mock('../lib/api', () => ({ api: { discovery: vi.fn(), pass: vi.fn() } }));
const pick: Pick = { id: 'synthetic', artist: 'Synthetic artist', title: 'Synthetic album', year: '2026', type: 'Album', reason: 'Similar to Seed One and Seed Two', state: 'new', qobuz_url: 'https://open.qobuz.com/album/synthetic', qobuz_status: 'exact', release_date: '2026-12-01', upcoming: true, artwork: null };
function show(element: React.ReactNode) { return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>{element}</QueryClientProvider>); }
afterEach(cleanup);
beforeEach(() => vi.resetAllMocks());

describe('This week', () => {
    it('shows sections in order and keeps earlier picks collapsed', async () => {
        const rows: DiscoverySection[] = ['new_artists', 'new_releases', 'more'].map((key, index) => ({ key: key as DiscoverySection['key'], generated_at: null, stale: false, message: null, picks: [{ ...pick, id: `pick-${index}` }], earlier: index === 0 ? [{ ...pick, id: 'earlier', title: 'Earlier album' }] : [] }));
        vi.mocked(api.discovery).mockResolvedValue(rows);
        show(<ThisWeekPage />);
        await screen.findByRole('heading', { name: 'New artists' });
        expect(screen.getAllByRole('heading', { level: 2 }).map(heading => heading.textContent)).toEqual(['New artists', 'New releases', 'More from artists you play']);
        const earlier = screen.getByText('Earlier · 1').closest('details');
        expect(earlier).not.toHaveAttribute('open');
        fireEvent.click(screen.getByText('Earlier · 1'));
        expect(earlier).toHaveAttribute('open');
        expect(screen.getByText(/Upcoming/)).toBeInTheDocument();
    });
    it('keeps new releases in one list without an Earlier disclosure', async () => {
        vi.mocked(api.discovery).mockResolvedValue([{ key: 'new_releases', generated_at: null, stale: false, message: null, picks: [pick], earlier: [{ ...pick, id: 'old', title: 'Old release' }] }]);
        show(<ThisWeekPage />);
        await screen.findByRole('heading', { name: 'New releases' });
        expect(screen.getByRole('heading', { name: 'Synthetic album' })).toBeInTheDocument();
        expect(screen.queryByText(/Earlier/)).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Old release' })).not.toBeInTheDocument();
    });
    it('shows the plain missing-configuration state', async () => {
        vi.mocked(api.discovery).mockResolvedValue([{ key: 'new_artists', generated_at: null, stale: false, message: 'ListenBrainz not configured. Add your credentials in admin.', picks: [], earlier: [] }]);
        show(<ThisWeekPage />);
        expect(await screen.findByText(/ListenBrainz not configured/)).toBeInTheDocument();
    });
    it('links to Qobuz and saves Passed as the only card action', async () => {
        vi.mocked(api.pass).mockResolvedValue(undefined);
        show(<PickCard pick={pick} />);
        expect(screen.getByRole('link', { name: 'Open in Qobuz' })).toHaveAttribute('href', pick.qobuz_url);
        expect(screen.getByText('New')).toBeInTheDocument();
        expect(screen.getByText(pick.reason)).toBeInTheDocument();
        expect(screen.getByTestId('artwork-fallback')).toHaveAccessibleName(/unavailable/);
        fireEvent.click(screen.getByRole('button', { name: /Passed on/ }));
        await waitFor(() => expect(api.pass).toHaveBeenCalledWith('synthetic'));
        expect(screen.getAllByRole('button')).toHaveLength(1);
    });
    it('retains failed passes with an error and explains Qobuz search fallback', async () => {
        vi.mocked(api.pass).mockRejectedValue(new Error('Could not save Passed.'));
        show(<PickCard pick={{ ...pick, state: 'in_plex', qobuz_status: 'search' }} />);
        expect(screen.getByText('In Plex')).toBeInTheDocument();
        expect(screen.getByText('Opens a Qobuz search')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Passed on/ }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Could not save Passed.');
    });
    it('contains long titles and recovers from a broken image', () => {
        const longTitle = 'A'.repeat(1000);
        show(<PickCard pick={{ ...pick, title: longTitle, state: 'played', artwork: { url: '/artwork/synthetic', width: 100, height: 100 } }} />);
        expect(screen.getByRole('heading', { name: longTitle })).toHaveClass('[overflow-wrap:anywhere]');
        const article = screen.getByRole('article');
        fireEvent.error(within(article).getByRole('img'));
        expect(screen.getByTestId('artwork-fallback')).toBeInTheDocument();
        expect(screen.getByText('Played')).toBeInTheDocument();
    });
});
