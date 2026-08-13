import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FinitePageSentinel } from './finite-page-sentinel';

describe('FinitePageSentinel', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('requests an intersecting page once until loading settles', () => {
        let intersect: ((entries: IntersectionObserverEntry[]) => void) | undefined;
        vi.stubGlobal('IntersectionObserver', class {
            constructor(callback: (entries: IntersectionObserverEntry[]) => void) { intersect = callback; }
            observe() {}
            disconnect() {}
        });
        const load = vi.fn();
        render(<FinitePageSentinel hasNext loading={false} loaded={12} total={24} onLoadMore={load} />);

        intersect?.([{ isIntersecting: true } as IntersectionObserverEntry]);
        intersect?.([{ isIntersecting: true } as IntersectionObserverEntry]);
        expect(load).toHaveBeenCalledTimes(1);
    });

    it('does no observer work for a single page and exposes retry failures', () => {
        const observer = vi.fn();
        vi.stubGlobal('IntersectionObserver', observer);
        const { rerender } = render(<FinitePageSentinel hasNext={false} loading={false} loaded={3} total={3} onLoadMore={vi.fn()} />);
        expect(observer).not.toHaveBeenCalled();
        expect(screen.getByText('All 3 items loaded.')).toBeVisible();

        const retry = vi.fn();
        rerender(<FinitePageSentinel hasNext loading={false} error={new Error('failed')} loaded={3} total={6} onLoadMore={vi.fn()} onRetry={retry} />);
        fireEvent.click(screen.getByRole('button', { name: 'Retry next page' }));
        expect(retry).toHaveBeenCalledTimes(1);
    });

    it('keeps loading explicit on coarse touch pointers', () => {
        const observer = vi.fn();
        vi.stubGlobal('IntersectionObserver', observer);
        vi.stubGlobal('matchMedia', vi.fn(() => ({
            matches: true,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })));
        const load = vi.fn();
        render(<FinitePageSentinel hasNext loading={false} loaded={12} total={24} onLoadMore={load} />);

        expect(observer).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Load more' }));
        expect(load).toHaveBeenCalledTimes(1);
    });
});
