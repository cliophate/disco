import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ActivityRail } from './activity-rail';
import { DetailTabs } from './detail-tabs';
import { MasonryFeed, MasonryFeedItem } from './masonry-feed';

afterEach(cleanup);

function StateTabs() {
    const [value, setValue] = useState('overview');
    return (
        <>
            <DetailTabs
                mode="state"
                label="Artist sections"
                value={value}
                onValueChange={setValue}
                tabs={[
                    { id: 'overview', label: 'Overview', panelId: 'overview-panel', tabId: 'overview-tab' },
                    { id: 'albums', label: 'Albums', panelId: 'albums-panel', tabId: 'albums-tab' },
                    { id: 'credits', label: 'Credits', panelId: 'credits-panel', tabId: 'credits-tab' },
                ]}
            />
            <div id={`${value}-panel`} role="tabpanel" aria-labelledby={`${value}-tab`}>{value} content</div>
        </>
    );
}

describe('DetailTabs', () => {
    it('renders route-backed tabs as current-aware links', () => {
        render(
            <MemoryRouter initialEntries={['/artist/albums']}>
                <DetailTabs
                    mode="route"
                    label="Artist views"
                    tabs={[
                        { id: 'overview', label: 'Overview', to: '/artist' },
                        { id: 'albums', label: 'Albums', to: '/artist/albums', count: 8 },
                    ]}
                />
            </MemoryRouter>,
        );

        expect(screen.getByRole('navigation', { name: 'Artist views' })).toBeVisible();
        expect(screen.getByRole('link', { name: /Albums.*8/ })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: /Albums.*8/ })).toHaveClass('!border-coral');
        expect(screen.getByRole('link', { name: 'Overview' })).toHaveClass('!border-transparent');
    });

    it('moves focus and automatically activates state-backed tabs with arrow, Home, and End keys', () => {
        render(<StateTabs />);
        const overview = screen.getByRole('tab', { name: 'Overview' });

        overview.focus();
        fireEvent.keyDown(overview, { key: 'ArrowRight' });
        const albums = screen.getByRole('tab', { name: 'Albums' });
        expect(albums).toHaveFocus();
        expect(albums).toHaveAttribute('aria-selected', 'true');
        expect(albums).toHaveClass('!border-coral');
        expect(overview).toHaveClass('!border-transparent');
        expect(screen.getByRole('tabpanel')).toHaveAttribute('aria-labelledby', 'albums-tab');

        fireEvent.keyDown(albums, { key: 'End' });
        const credits = screen.getByRole('tab', { name: 'Credits' });
        expect(credits).toHaveFocus();
        expect(credits).toHaveAttribute('aria-selected', 'true');

        fireEvent.keyDown(credits, { key: 'Home' });
        expect(screen.getByRole('tab', { name: 'Overview' })).toHaveFocus();
        expect(screen.getByRole('tab', { name: 'Overview' })).toHaveAttribute('aria-selected', 'true');

        fireEvent.keyDown(screen.getByRole('tab', { name: 'Overview' }), { key: 'ArrowLeft' });
        expect(screen.getByRole('tab', { name: 'Credits' })).toHaveFocus();
    });
});

describe('ActivityRail', () => {
    let clientWidth = 300;
    let scrollWidth = 900;
    let resize: (() => void) | undefined;
    let frame: FrameRequestCallback | undefined;

    beforeEach(() => {
        vi.stubGlobal('ResizeObserver', class {
            constructor(callback: ResizeObserverCallback) {
                resize = () => callback([], this as unknown as ResizeObserver);
            }
            observe() {}
            disconnect() {}
            unobserve() {}
        });
        vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => { frame = callback; return 1; });
        vi.stubGlobal('cancelAnimationFrame', vi.fn());
        Object.defineProperty(HTMLElement.prototype, 'clientWidth', { configurable: true, get: () => clientWidth });
        Object.defineProperty(HTMLElement.prototype, 'scrollWidth', { configurable: true, get: () => scrollWidth });
        Object.defineProperty(HTMLElement.prototype, 'scrollTo', { configurable: true, value: vi.fn() });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        Reflect.deleteProperty(HTMLElement.prototype, 'scrollTo');
        Reflect.deleteProperty(HTMLElement.prototype, 'scrollLeft');
        Reflect.deleteProperty(HTMLElement.prototype, 'clientWidth');
        Reflect.deleteProperty(HTMLElement.prototype, 'scrollWidth');
        clientWidth = 300;
        scrollWidth = 900;
        resize = undefined;
        frame = undefined;
    });

    it('resets a restored rail position before presenting the first item', () => {
        Object.defineProperty(HTMLElement.prototype, 'scrollLeft', { configurable: true, value: 24, writable: true });
        render(<ActivityRail label="Fresh rail"><article>First</article><article>Second</article></ActivityRail>);

        expect(screen.getByRole('list', { name: 'Fresh rail items' }).scrollLeft).toBe(0);
    });

    it('keeps items ordered and bounds controls from the rail scroll position', () => {
        render(
            <ActivityRail label="Recent activity">
                <article>First</article>
                <article>Second</article>
                <article>Third</article>
            </ActivityRail>,
        );

        const list = screen.getByRole('list', { name: 'Recent activity items' });
        const items = within(list).getAllByRole('listitem');
        expect(items.map((item) => item.textContent)).toEqual(['First', 'Second', 'Third']);
        [0, 320, 640].forEach((offset, index) => Object.defineProperty(items[index], 'offsetLeft', { configurable: true, value: offset }));

        const previous = screen.getByRole('button', { name: 'Previous' });
        const next = screen.getByRole('button', { name: 'Next' });
        expect(previous).toBeDisabled();
        expect(next).toBeEnabled();

        fireEvent.click(next);
        expect(list.scrollTo).toHaveBeenLastCalledWith({ left: 320, behavior: 'auto' });
        Object.defineProperty(list, 'scrollLeft', { configurable: true, value: 600, writable: true });
        fireEvent.scroll(list);
        fireEvent.scroll(list);
        act(() => frame?.(0));
        expect(previous).toBeEnabled();
        expect(next).toBeDisabled();

        fireEvent.click(previous);
        expect(list.scrollTo).toHaveBeenLastCalledWith({ left: 320, behavior: 'auto' });
        list.scrollLeft = 0;
        fireEvent.scroll(list);
        act(() => frame?.(0));
        expect(previous).toBeDisabled();
        expect(next).toBeEnabled();
    });

    it('shows controls only for measured overflow and supports an explicit empty state', () => {
        scrollWidth = clientWidth;
        const { rerender } = render(<ActivityRail label="Recent activity"><article>First</article><article>Second</article></ActivityRail>);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        scrollWidth = 900;
        act(() => resize?.());
        act(() => frame?.(0));
        expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled();

        rerender(<ActivityRail label="Recent activity" empty="No recent activity">{null}</ActivityRail>);
        expect(screen.getByRole('region', { name: 'Recent activity' })).toHaveTextContent('No recent activity');
    });
});

describe('MasonryFeed', () => {
    it('uses a normal grid and preserves source DOM order across visual spans', () => {
        render(
            <MasonryFeed data-testid="feed">
                <MasonryFeedItem variant="wide">First</MasonryFeedItem>
                <MasonryFeedItem variant="tall">Second</MasonryFeedItem>
                <MasonryFeedItem>Third</MasonryFeedItem>
            </MasonryFeed>,
        );

        const feed = screen.getByTestId('feed');
        expect(Array.from(feed.children).map((item) => item.textContent)).toEqual(['First', 'Second', 'Third']);
        expect(feed).toHaveClass('masonry-feed');
        expect(feed.className).not.toMatch(/columns-|grid-flow-dense|absolute/);
        expect(feed.children[0]).toHaveAttribute('data-span', 'wide');
        expect(feed.children[1]).toHaveAttribute('data-span', 'tall');
    });
});
