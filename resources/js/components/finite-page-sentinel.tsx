import { useEffect, useRef } from 'react';
import { Button } from './ui/button';

export function FinitePageSentinel({ hasNext, loading, error, loaded, total, onLoadMore, onRetry }: {
    hasNext: boolean;
    loading: boolean;
    error?: Error | null;
    loaded: number;
    total: number;
    onLoadMore: () => void;
    onRetry?: () => void;
}) {
    const sentinel = useRef<HTMLDivElement>(null);
    const requestPending = useRef(false);

    useEffect(() => {
        if (!hasNext || loading || error || !sentinel.current || typeof IntersectionObserver === 'undefined') return;
        if (typeof window.matchMedia === 'function' && window.matchMedia('(hover: none) and (pointer: coarse)').matches) return;
        const observer = new IntersectionObserver(([entry]) => {
            if (!entry?.isIntersecting || requestPending.current) return;
            requestPending.current = true;
            onLoadMore();
        }, { rootMargin: '320px 0px' });
        observer.observe(sentinel.current);
        return () => observer.disconnect();
    }, [error, hasNext, loading, onLoadMore]);

    useEffect(() => { if (!loading) requestPending.current = false; }, [loaded, loading]);

    if (!hasNext && !error) return loaded > 0 ? <p className="mt-10 border-t border-line pt-5 text-center text-xs text-fog">All {total.toLocaleString()} items loaded.</p> : null;

    return <div ref={sentinel} className="mt-10 flex min-h-16 flex-col items-center justify-center gap-3 border-t border-line pt-5" aria-live="polite">
        <p className="text-xs font-semibold text-fog">Loaded {loaded.toLocaleString()} of {total.toLocaleString()}</p>
        {error ? <><p className="text-sm text-coral" role="alert">{error.message}</p><Button variant="secondary" onClick={onRetry}>Retry next page</Button></> : loading ? <p className="text-sm text-fog" role="status">Loading more…</p> : <Button variant="secondary" onClick={onLoadMore}>Load more</Button>}
    </div>;
}
