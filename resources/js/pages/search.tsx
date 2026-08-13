import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowUpRight, Disc3, Search } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { AlbumCard } from '../components/album-card';
import { EntityPortraitLink } from '../components/entity-portrait-link';
import { PageHeading } from '../components/page-heading';
import { SectionHeading } from '../components/section-heading';
import { EmptyState, ErrorState } from '../components/states';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';
import type { ExternalCatalogResult } from '../lib/types';
import { cn } from '../lib/utils';

type SearchScope = 'collection' | 'catalog';

function searchPath(query: string, scope: SearchScope) {
    if (!query) return scope === 'catalog' ? '/search?scope=catalog' : '/search';
    return `/search?q=${encodeURIComponent(query)}${scope === 'catalog' ? '&scope=catalog' : ''}`;
}

function SearchResultsSkeleton({ scope }: { scope: SearchScope }) {
    if (scope === 'catalog') {
        return (
            <div className="grid gap-x-8 lg:grid-cols-2" aria-label="Loading external catalog results" role="status">
                {Array.from({ length: 6 }, (_, index) => (
                    <div key={index} className="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-4 border-t border-line py-5">
                        <Skeleton className="aspect-square rounded-none" />
                        <div className="min-w-0 py-1"><Skeleton className="h-3 w-24" /><Skeleton className="mt-3 h-6 w-3/4" /><Skeleton className="mt-2 h-4 w-1/2" /></div>
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="grid grid-cols-2 gap-x-4 gap-y-10 sm:grid-cols-[repeat(auto-fit,minmax(11rem,14rem))] sm:gap-x-6" aria-label="Loading collection results" role="status">
            {Array.from({ length: 5 }, (_, index) => (
                <div key={index}><Skeleton className="aspect-square rounded-none" /><Skeleton className="mt-4 h-4 w-2/3" /><Skeleton className="mt-2 h-3 w-1/2" /></div>
            ))}
        </div>
    );
}

function ExternalResult({ album, adding, selectionPending, onOpen, onSelect }: { album: ExternalCatalogResult; adding: boolean; selectionPending: boolean; onOpen: () => void; onSelect: () => void }) {
    return (
        <article className="grid min-w-0 grid-cols-[4.5rem_minmax(0,1fr)] gap-4 border-t border-line py-5 sm:grid-cols-[5rem_minmax(0,1fr)] sm:gap-5">
            <div className="grid aspect-square place-items-center border border-line bg-raised px-2 text-center" role="img" aria-label={`${album.primary_type} result placeholder; no artwork shown`}>
                <div>
                    <Disc3 className="mx-auto size-5 text-cobalt" aria-hidden="true" />
                    <span className="mt-1 block text-[10px] font-bold uppercase tracking-[0.14em] text-fog">{album.primary_type}</span>
                </div>
            </div>
            <div className="flex min-w-0 flex-col justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <p className="font-bold uppercase tracking-[0.16em] text-coral">{album.primary_type}</p>
                        {album.first_release_date && <p className="text-fog">{album.first_release_date}</p>}
                    </div>
                    <h2 className="mt-2 break-words [overflow-wrap:anywhere] font-serif text-xl font-bold leading-tight sm:text-2xl">{album.title}</h2>
                    <p className="mt-1 break-words [overflow-wrap:anywhere] text-sm text-fog">{album.artist}</p>
                    {album.disambiguation && <p className="mt-2 break-words [overflow-wrap:anywhere] text-sm italic text-fog">{album.disambiguation}</p>}
                </div>
                <div className="mt-4 flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-end">
                    <p className="text-xs leading-5 text-fog">MusicBrainz search result · artwork status: {album.artwork_status}</p>
                    {album.entity_id ? (
                        <Button variant="secondary" size="sm" onClick={onOpen} aria-label={`${album.owned ? 'Open owned album' : 'Open in Disco'}: ${album.title} by ${album.artist}`}>{album.owned ? 'Open owned album' : 'Open in Disco'}<ArrowUpRight className="size-4" aria-hidden="true" /></Button>
                    ) : (
                        <Button size="sm" disabled={selectionPending} onClick={onSelect} aria-label={`${adding ? 'Adding' : 'Add to Disco'}: ${album.title} by ${album.artist}`}>{adding ? 'Adding…' : 'Add to Disco'}</Button>
                    )}
                </div>
            </div>
        </article>
    );
}

export function SearchPage() {
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const location = useLocation();
    const [params] = useSearchParams();
    const query = params.get('q')?.trim() ?? '';
    const scope: SearchScope = params.get('scope') === 'catalog' ? 'catalog' : 'collection';
    const [draft, setDraft] = useState(query);
    const returnContext = `${location.pathname}${location.search}`;
    const routeState = { from: returnContext, label: 'search' };
    const localSearch = useQuery({ queryKey: ['search', 'collection', query], queryFn: () => api.search(query), enabled: scope === 'collection' && query.length > 0 });
    const catalogSearch = useQuery({ queryKey: ['search', 'catalog', query], queryFn: () => api.externalCatalogSearch(query), enabled: scope === 'catalog' && query.length > 0 });
    const select = useMutation({
        mutationFn: ({ mbid }: { mbid: string; from: string; queryKey: readonly string[] }) => api.selectExternalAlbum(mbid),
        onSuccess: (album, variables) => {
            queryClient.setQueryData<ExternalCatalogResult[]>(variables.queryKey, (current) => current?.map((result) => result.mbid === variables.mbid ? { ...result, entity_id: album.id, owned: album.owned } : result));
            navigate(`/albums/${album.id}`, { state: { from: variables.from, label: 'search' } });
        },
    });

    useEffect(() => setDraft(query), [query]);

    function submit(event: FormEvent) {
        event.preventDefault();
        navigate(searchPath(draft.trim(), scope));
    }

    const results = scope === 'catalog' ? catalogSearch : localSearch;
    const localResults = localSearch.data;
    const catalogResults = catalogSearch.data;
    const localCount = (localResults?.albums.length ?? 0) + (localResults?.artists.length ?? 0);

    return (
        <div>
            <PageHeading
                eyebrow={scope === 'catalog' ? 'MusicBrainz catalog' : 'Albums and artists'}
                title={query ? `Results for “${query}”` : scope === 'catalog' ? 'Search outside the collection' : 'Search the collection'}
                description={scope === 'catalog' ? 'Find an exact album or EP, then select it before Disco creates a canonical record.' : localResults ? `${localCount} ${localCount === 1 ? 'match' : 'matches'}.${localResults.meta?.truncated ? ` Showing the first ${localResults.meta.limit}.` : ''}` : 'Move directly to an album or follow an artist through the records you own.'}
            />

            <div className="mb-14 max-w-4xl">
                <div className="mb-5 inline-flex max-w-full rounded-full border border-line bg-panel p-1" role="group" aria-label="Search scope">
                    <Button type="button" variant={scope === 'collection' ? 'default' : 'ghost'} size="sm" aria-pressed={scope === 'collection'} onClick={() => navigate(searchPath(query, 'collection'))}>Your collection</Button>
                    <Button type="button" variant={scope === 'catalog' ? 'default' : 'ghost'} size="sm" aria-pressed={scope === 'catalog'} onClick={() => navigate(searchPath(query, 'catalog'))}>External catalog</Button>
                </div>
                <form onSubmit={submit} role="search" className="flex flex-col gap-3 sm:flex-row">
                    <div className="relative min-w-0 flex-1"><Search className="pointer-events-none absolute left-5 top-1/2 size-5 -translate-y-1/2 text-fog" aria-hidden="true" /><Input value={draft} onChange={(event) => setDraft(event.target.value)} className="h-14 pl-13 text-base" placeholder="Album title or artist name" aria-label="Album title or artist name" autoFocus={!query} /></div>
                    <Button className="h-14 px-6" type="submit">Search</Button>
                </form>
            </div>

            {!query ? <EmptyState title="Start with a name" message={scope === 'catalog' ? 'Search MusicBrainz for an album title, artist, or both.' : 'Search stays inside your private collection and keeps albums and artists clearly grouped.'} /> : results.isLoading ? <SearchResultsSkeleton scope={scope} /> : results.isError ? <ErrorState error={results.error} retry={() => results.refetch()} /> : scope === 'catalog' && catalogResults?.length ? (
                <section aria-labelledby="external-results-title">
                    <SectionHeading id="external-results-title" eyebrow="Albums and EPs" title="External catalog" description="Dense, text-only MusicBrainz results. No artwork is fetched or implied; singles, broadcasts, compilations, DJ mixes, and mixtapes are excluded." />
                    <div className="grid gap-x-8 lg:grid-cols-2" data-testid="external-results-grid">
                        {catalogResults.map((album) => (
                            <ExternalResult
                                key={album.mbid}
                                album={album}
                                adding={select.isPending && select.variables?.mbid === album.mbid}
                                selectionPending={select.isPending}
                                onOpen={() => navigate(`/albums/${album.entity_id}`, { state: routeState })}
                                onSelect={() => select.mutate({ mbid: album.mbid, from: returnContext, queryKey: ['search', 'catalog', query] })}
                            />
                        ))}
                    </div>
                    {select.isError && <p className="mt-5 text-sm text-coral" role="alert">{select.error.message}</p>}
                </section>
            ) : scope === 'catalog' ? <EmptyState title="No matches" message={`No supported albums or EPs match “${query}”.`} /> : localResults && localCount > 0 ? (
                <div className="space-y-16">
                    {localResults.artists.length > 0 && (
                        <section aria-labelledby="local-artists-title">
                            <SectionHeading id="local-artists-title" eyebrow="People and groups" title="Artists" description={`${localResults.artists.length} ${localResults.artists.length === 1 ? 'artist' : 'artists'} in your collection.`} />
                            <div className={cn('grid gap-x-8', localResults.artists.length <= 4 ? 'max-w-5xl sm:grid-cols-2' : 'sm:grid-cols-2 lg:grid-cols-3')} data-testid="local-artist-results">
                                {localResults.artists.map((artist) => <EntityPortraitLink key={artist.id} to={`/artists/${artist.id}`} state={routeState} name={artist.name} portrait={artist.portrait} detail="Artist in your collection" />)}
                            </div>
                        </section>
                    )}
                    {localResults.albums.length > 0 && (
                        <section aria-labelledby="local-albums-title">
                            <SectionHeading id="local-albums-title" eyebrow="Records" title="Albums" description={`${localResults.albums.length} ${localResults.albums.length === 1 ? 'album' : 'albums'} from your shelves.`} />
                            <div className="grid grid-cols-2 gap-x-4 gap-y-10 sm:grid-cols-[repeat(auto-fit,minmax(11rem,14rem))] sm:gap-x-6" data-testid="local-album-results">
                                {localResults.albums.map((album, index) => <AlbumCard key={album.id} album={album} index={index} state={routeState} />)}
                            </div>
                        </section>
                    )}
                </div>
            ) : <EmptyState
                title="No matches"
                message={`Nothing in the collection matches “${query}”. Search the live MusicBrainz catalog without changing your query.`}
                action={<Button onClick={() => navigate(searchPath(query, 'catalog'))}>Search external catalog<ArrowUpRight className="size-4" aria-hidden="true" /></Button>}
            />}
        </div>
    );
}
