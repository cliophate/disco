import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useLocation, useSearchParams } from 'react-router-dom';
import { AlbumCard } from '../components/album-card';
import { BoundedPagination } from '../components/bounded-pagination';
import { FilterBar } from '../components/filter-bar';
import { PageHeading } from '../components/page-heading';
import { AlbumGridSkeleton, EmptyState, ErrorState } from '../components/states';
import { api } from '../lib/api';

export function WantToListenPage() {
    const location = useLocation();
    const [params, setParams] = useSearchParams();
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    const status = ['all', 'listened', 'removed'].includes(params.get('status') ?? '') ? params.get('status')! : 'want_to_listen';
    const ownership = ['owned', 'outside'].includes(params.get('ownership') ?? '') ? params.get('ownership')! : 'all';
    const sort = ['name', '-name', '-changed', 'changed'].includes(params.get('sort') ?? '') ? params.get('sort')! : 'name';
    const result = useQuery({ queryKey: ['want-to-listen', page, status, ownership, sort], queryFn: () => api.wantToListen(page, status, ownership, sort), placeholderData: (previous) => previous });
    const update = (changes: Record<string, string | null>) => { const next = new URLSearchParams(params); Object.entries(changes).forEach(([key, value]) => value === null ? next.delete(key) : next.set(key, value)); setParams(next); };
    useEffect(() => { if (result.data && !result.isPlaceholderData && result.data.meta.current_page !== page) { const next = new URLSearchParams(params); result.data.meta.current_page === 1 ? next.delete('page') : next.set('page', String(result.data.meta.current_page)); setParams(next, { replace: true }); } }, [page, params, result.data, result.isPlaceholderData, setParams]);
    const href = (target: number) => { const next = new URLSearchParams(params); target === 1 ? next.delete('page') : next.set('page', String(target)); return `?${next.toString()}`; };

    return <div><PageHeading eyebrow="Private listening list" title="Want to listen" description={result.data ? `${result.data.meta.total.toLocaleString()} active records in this view.` : 'Albums saved for later, with private context that stays inside Disco.'} />
        {result.data && <FilterBar label="List status" filters={[
            { id: 'want_to_listen', label: 'Want to listen', count: result.data.meta.filters.want_to_listen },
            { id: 'listened', label: 'Listened', count: result.data.meta.filters.listened },
            { id: 'all', label: 'All active', count: result.data.meta.filters.all },
            { id: 'removed', label: 'Removed', count: result.data.meta.filters.removed },
        ]} selected={status} onFilterChange={(value) => update({ status: value === 'want_to_listen' ? null : value, page: null })} sort={{ label: 'Sort', value: sort, options: [{ value: 'name', label: 'A to Z' }, { value: '-name', label: 'Z to A' }, { value: '-changed', label: 'Recently changed status' }, { value: 'changed', label: 'Least recently changed status' }], onChange: (value) => update({ sort: value === 'name' ? null : value, page: null }) }} controls={<label className="flex min-h-11 items-center gap-3 text-xs font-bold uppercase tracking-[0.14em] text-fog">Collection<select value={ownership} onChange={(event) => update({ ownership: event.target.value === 'all' ? null : event.target.value, page: null })} className="min-h-11 rounded-lg border border-line bg-panel px-3 text-sm font-semibold normal-case tracking-normal text-ink"><option value="all">All</option><option value="owned">Owned</option><option value="outside">Outside</option></select></label>} />}
        <div className="mt-10">{result.isLoading ? <AlbumGridSkeleton /> : result.isError ? <ErrorState error={result.error} retry={() => result.refetch()} /> : !result.data?.data.length ? <EmptyState title="Nothing in this view" message={status === 'want_to_listen' ? 'Save an owned, Beyond, or externally found album to begin.' : 'Choose another status or collection filter.'} /> : <div className="cover-grid">{result.data.data.map((album, index) => <AlbumCard key={album.id} album={album} index={index} state={{ from: `${location.pathname}${location.search}`, label: 'Want to listen' }} />)}</div>}</div>
        {result.data && <BoundedPagination current={result.data.meta.current_page} last={result.data.meta.last_page} href={href} label="Want to listen pages" />}
    </div>;
}
