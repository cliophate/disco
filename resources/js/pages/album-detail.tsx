import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, ExternalLink, ListPlus, Play } from 'lucide-react';
import { type ReactNode, useEffect } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { RecommendationReasons } from '../components/album-card';
import { AlbumListControl } from '../components/album-list-control';
import { PlexPlaybackContext } from '../components/plex-playback-context';
import { Artwork } from '../components/artwork';
import { AttributedNarrative } from '../components/attributed-narrative';
import { DiscogsAttribution } from '../components/discogs-attribution';
import { EditorialHeader } from '../components/editorial-header';
import { EntityPortraitLink } from '../components/entity-portrait-link';
import { FactList } from '../components/fact-list';
import { OpenInPlexButton } from '../components/open-in-plex-button';
import { playableSource, type QueueTrack, usePlayback } from '../components/playback-provider';
import { RecommendationFeedback } from '../components/recommendation-feedback';
import { ErrorState } from '../components/states';
import { Skeleton } from '../components/ui/skeleton';
import { Button } from '../components/ui/button';
import { api } from '../lib/api';
import type { AlbumDetail, CreditGroup, TrackListeningEvidence } from '../lib/types';
import { formatDate, formatDuration, formatLongDuration, formatPartialDate } from '../lib/utils';

function providerName(source: string) {
    if (source.toLowerCase() === 'listenbrainz') return 'ListenBrainz';
    if (source.toLowerCase() === 'musicbrainz') return 'MusicBrainz';
    if (source.toLowerCase() === 'plex') return 'Plex';

    return source;
}

function provenanceRows(album: AlbumDetail) {
    const providers = new Map<string, string[]>();
    const add = (provider: string, role: string) => {
        const roles = providers.get(provider) ?? [];
        if (!roles.includes(role)) roles.push(role);
        providers.set(provider, roles);
    };

    if (album.owned) add('Plex', 'Collection source');
    if (album.listening_signals) {
        const plex = album.listening_signals.plex;
        add('Plex', `Playback evidence: ${plex.album_view_count.toLocaleString()} album views, ${plex.played_track_count.toLocaleString()} tracks played`);
        const listenBrainz = album.listening_signals.listenbrainz;
        add('ListenBrainz', `Listening evidence: ${listenBrainz.listen_count.toLocaleString()} listens${listenBrainz.last_listened_at ? `, last ${formatDate(listenBrainz.last_listened_at)}` : ''}`);
    }
    album.recommendation?.reasons.forEach((reason) => add(providerName(reason.source), 'Recommendation evidence'));
    album.sources.forEach((source) => add(providerName(source), source === 'Plex' ? 'Collection source' : 'Metadata source'));
    if (album.discogs) add('Discogs', 'Fresh exact-linked catalog metadata');

    return Array.from(providers, ([provider, roles]) => ({ provider, roles }));
}

function SectionHeading({ eyebrow, title, detail, id }: { eyebrow: string; title: string; detail?: ReactNode; id: string }) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-4 border-b border-ink pb-4">
            <div>
                <p className="editorial-eyebrow text-coral">{eyebrow}</p>
                <h2 id={id} className="mt-2 font-serif text-3xl font-bold sm:text-4xl">{title}</h2>
            </div>
            {detail && <div className="text-xs uppercase tracking-[0.16em] text-fog">{detail}</div>}
        </div>
    );
}

function CreditNames({ group }: { group: CreditGroup }) {
    return <>{group.items.map((item, index) => <span key={`${item.target?.id ?? item.name}-${index}`}>{index > 0 && ', '}{item.target?.kind === 'agent' ? <Link to={`/artists/${item.target.id}`} className="underline decoration-line underline-offset-2 hover:text-cobalt">{item.name}</Link> : item.name}{item.via_work && <span className="text-fog"> via {item.via_work.name}</span>}</span>)}</>;
}

function CreditPeople({ groups }: { groups: CreditGroup[] }) {
    const people = new Map<string, { id: string; name: string; portrait: NonNullable<CreditGroup['items'][number]['portrait']>; roles: string[] }>();
    groups.forEach((group) => group.items.forEach((item) => {
        if (item.target?.kind !== 'agent' || !item.portrait) return;
        const person = people.get(item.target.id) ?? { id: item.target.id, name: item.target.name, portrait: item.portrait, roles: [] };
        if (!person.roles.includes(group.label)) person.roles.push(group.label);
        people.set(person.id, person);
    }));
    const visible = Array.from(people.values()).slice(0, 8);
    if (visible.length === 0) return null;

    return <div className="grid gap-x-8 sm:grid-cols-2">{visible.map((person) => <EntityPortraitLink key={person.id} to={`/artists/${person.id}`} name={person.name} portrait={person.portrait} detail={person.roles.join(' · ')} />)}</div>;
}

function AlbumActions({ album }: { album: AlbumDetail }) {
    let primary: ReactNode = null;
    let primaryDetail: ReactNode = null;
    if (album.open_in_plex_status === 'exact' && album.basis_plex_item_id) {
        primary = <OpenInPlexButton plexItemId={album.basis_plex_item_id} primary />;
    } else if (album.open_in_plex_status === 'choice-required') {
        primary = <a href="#album-holdings" className="inline-flex min-h-11 items-center rounded-full border border-cobalt px-4 text-sm font-semibold text-cobalt outline-none hover:bg-cobalt hover:text-cream focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-2">Choose a Plex copy</a>;
    } else if (!album.owned) {
        const qobuz = album.qobuz ?? { url: album.qobuz_search_url, status: 'search' as const };
        primary = <a href={qobuz.url} target="_blank" rel="noreferrer" className="inline-flex min-h-11 items-center justify-center gap-2 rounded-full bg-cobalt px-4 text-sm font-semibold text-cream outline-none hover:bg-cobalt-deep focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-2"><ExternalLink className="size-4" />{qobuz.status === 'exact' ? 'Open on Qobuz' : 'Search Qobuz'}</a>;
        primaryDetail = <span className="basis-full text-xs text-fog">{qobuz.status === 'exact' ? 'Exact catalog destination from a MusicBrainz URL relationship.' : 'Opens a catalogue search; availability is not checked.'}</span>;
    }

    return <>{primary}<AlbumListControl albumId={album.id} initialState={album.list_state ?? null} detail />{primaryDetail}</>;
}

function listeningLabel(provider: 'Plex' | 'ListenBrainz', evidence: TrackListeningEvidence) {
    if (evidence.status === 'counted' || evidence.status === 'known_zero') {
        const count = evidence.play_count ?? 0;
        const copies = provider === 'Plex' && (evidence.copy_count ?? 0) > 1 ? ` across ${evidence.copy_count} exact copies` : '';
        const recent = evidence.last_listened_at ? ` · last ${formatDate(evidence.last_listened_at)}` : '';
        return `${provider} ${count.toLocaleString()} ${provider === 'Plex' ? (count === 1 ? 'play' : 'plays') : (count === 1 ? 'listen' : 'listens')}${copies}${recent}`;
    }
    if (evidence.status === 'unavailable') return `${provider} count unknown`;
    if (evidence.status === 'unsupported_source') return `${provider} not attached`;
    return `${provider} identity unmatched`;
}

function TrackListening({ track }: { track: AlbumDetail['tracks'][number] }) {
    if (track.listening.identity_status === 'unmatched') {
        return <span className="mt-2 block text-xs leading-5 text-fog">Listening counts unavailable · exact recording identity missing</span>;
    }

    return <span className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs leading-5 text-fog"><span>{listeningLabel('Plex', track.listening.plex)}</span><span>{listeningLabel('ListenBrainz', track.listening.listenbrainz)}</span></span>;
}

function TrackList({ album }: { album: AlbumDetail }) {
    const player = usePlayback();
    const discs = album.tracks.reduce((groups, track) => {
        const tracks = groups.get(track.disc) ?? [];
        tracks.push(track);
        groups.set(track.disc, tracks);
        return groups;
    }, new Map<number, typeof album.tracks>());
    const playbackQueue = album.tracks.flatMap((track): QueueTrack[] => {
        if (!track.playback) return [];
        const source = playableSource(track.playback?.sources ?? []);
        return [{
            id: track.playback.plex_item_id,
            title: track.title,
            artist: album.artist?.name ?? 'Unknown artist',
            album: album.title,
            durationMs: track.duration_ms ?? 0,
            artwork: album.artwork,
            plexItemId: track.playback.plex_item_id,
            source,
        }];
    });

    return (
        <section aria-labelledby="album-track-list-title" className="bounded-track-titles">
            <SectionHeading eyebrow="Sequence" title="Track list" id="album-track-list-title" detail={`${album.tracks.length} tracks`} />
            {album.tracks.length ? Array.from(discs.entries()).map(([disc, tracks]) => (
                <div key={disc} className="mt-7">
                    {discs.size > 1 && <h3 className="text-xs font-bold uppercase tracking-[0.22em] text-coral">Disc {disc}</h3>}
                    <ol className={discs.size > 1 ? 'mt-2' : ''}>{tracks.map((track) => { const source = playableSource(track.playback?.sources ?? []); const queueIndex = playbackQueue.findIndex((item) => item.id === track.playback?.plex_item_id); return <li key={track.id} className="-mx-2 grid min-h-16 grid-cols-[2.5rem_minmax(0,1fr)_auto] items-start gap-3 border-b border-line px-2 py-5 transition-colors hover:bg-raised/60 focus-within:bg-raised/60"><span className="pt-1 font-mono text-xs text-fog">{String(track.position).padStart(2, '0')}</span><span className="min-w-0"><span className="block break-words text-base font-semibold leading-6">{track.title}</span>{track.featured_artists.length > 0 && <span className="mt-1 block break-words text-xs leading-5 text-fog">feat. {track.featured_artists.map((artist, index) => <span key={`${artist.id ?? artist.name}-${index}`}>{index > 0 && ', '}{artist.id ? <Link to={`/artists/${artist.id}`} className="underline decoration-line underline-offset-2 hover:text-cobalt">{artist.name}</Link> : artist.name}</span>)}</span>}{track.credits.status === 'available' && track.credits.groups.some((group) => group.role !== 'performer' && group.role !== 'work') && <span className="mt-1 block break-words text-xs leading-5 text-fog">{track.credits.groups.filter((group) => group.role !== 'performer' && group.role !== 'work').map((group, index) => <span key={group.role}>{index > 0 && ' · '}<span className="font-semibold">{group.label}:</span> <CreditNames group={group} /></span>)}</span>}<TrackListening track={track} /></span><span className="flex items-center gap-1">{source && queueIndex >= 0 && <><Button variant="ghost" size="icon" onClick={() => void player.playQueue(playbackQueue, queueIndex)} aria-label={`Play ${track.title}`}><Play className="ml-0.5 size-4" /></Button><Button variant="ghost" size="icon" onClick={() => void player.addToQueue(playbackQueue[queueIndex]!)} aria-label={`Add ${track.title} to queue`}><ListPlus className="size-4" /></Button></>}{!source && track.playback && <OpenInPlexButton plexItemId={track.playback.plex_item_id} label="Plex" compact />}<span className="min-w-10 pt-1 text-right font-mono text-xs text-fog">{formatDuration(track.duration_ms) ?? '—'}</span></span></li>; })}</ol>
                </div>
            )) : <p className="border-b border-line py-8 text-sm text-fog">No tracks are catalogued for this album yet.</p>}
        </section>
    );
}

export function AlbumDetailPage() {
    const location = useLocation();
    const navigate = useNavigate();
    const { id = '' } = useParams();
    const album = useQuery({ queryKey: ['album', id], queryFn: () => api.album(id), refetchInterval: (query) => query.state.data?.owned ? 60_000 : false });
    useEffect(() => { if (album.data?.id && album.data.id !== id) navigate(`/albums/${album.data.id}${location.search}${location.hash}`, { replace: true, state: location.state }); }, [album.data?.id, id, location.hash, location.search, location.state, navigate]);

    if (album.isLoading) return <div><Skeleton className="h-5 w-32" /><div className="mt-8 grid gap-8 md:grid-cols-[minmax(0,1fr)_minmax(12rem,22rem)]"><div><Skeleton className="h-10 w-28" /><Skeleton className="mt-5 h-28 w-full" /><Skeleton className="mt-6 h-12 w-2/3" /></div><Skeleton className="aspect-square rounded-none" /></div></div>;
    if (album.isError || !album.data) return <ErrorState error={album.error} retry={() => album.refetch()} />;
    const data = album.data;
    const provenance = provenanceRows(data);
    const releaseDate = formatPartialDate(data.first_release_date) ?? data.year;
    const labelValue = data.labels.length > 0 ? data.labels.map((label) => `${label.name}${label.catalog_number ? ` · ${label.catalog_number}` : ''}`).join(' / ') : null;
    const discogsFormats = data.discogs?.fields.formats?.map((format) => [format.name, ...(format.descriptions ?? [])].filter(Boolean).join(' · ')).filter(Boolean).join(' / ') || null;
    const discogsLabels = data.discogs?.fields.labels?.map((label) => `${label.name ?? 'Unknown label'}${label.catalog_number ? ` · ${label.catalog_number}` : ''}`).join(' / ') || null;
    const facts = [
        { id: 'release', label: 'Release', value: data.release_type },
        { id: 'date', label: 'First released', value: releaseDate },
        { id: 'runtime', label: 'Runtime', value: formatLongDuration(data.duration_ms) },
        { id: 'tracks', label: 'Tracks', value: data.track_count ?? (data.tracks.length || null) },
        { id: 'format', label: 'Format', value: data.formats.length > 0 ? data.formats.join(' / ') : null },
        { id: 'label', label: 'Label', value: labelValue },
        { id: 'discogs-country', label: 'Discogs country', value: data.discogs?.fields.country },
        { id: 'discogs-styles', label: 'Discogs styles', value: data.discogs?.fields.styles?.join(' / ') },
        { id: 'discogs-format', label: 'Discogs format', value: discogsFormats },
        { id: 'discogs-label', label: 'Discogs label', value: discogsLabels },
        { id: 'collection', label: 'Collection', value: data.owned ? `${data.holdings.length || 1} ${data.holdings.length === 1 ? 'copy' : 'copies'}` : 'Not in your collection' },
    ];
    const routeState = location.state as { from?: unknown; label?: unknown } | null;
    const contextualFrom = typeof routeState?.from === 'string' && (/^\/library\/albums(?:\?|$)/.test(routeState.from) || /^\/want-to-listen(?:\?|$)/.test(routeState.from) || /^\/beyond(?:\?|$)/.test(routeState.from) || /^\/search(?:\?|$)/.test(routeState.from) || /^\/notifications(?:\?|$)/.test(routeState.from) || /^\/discover\/upcoming(?:\?|$)/.test(routeState.from) || /^\/artists\/[0-9a-f-]+(?:\?|$)/i.test(routeState.from) || /^\/discover\/lenses\//.test(routeState.from)) ? routeState.from : null;
    const backTarget = contextualFrom ?? (data.owned ? '/library/albums' : '/beyond');
    const backLabel = typeof routeState?.label === 'string' && contextualFrom ? routeState.label : (data.owned ? 'albums' : 'Beyond');

    return (
        <article className="min-w-0">
            <Link to={backTarget} className="mb-8 inline-flex min-h-11 items-center gap-2 text-xs font-semibold text-fog outline-none hover:text-cobalt focus-visible:ring-2 focus-visible:ring-cobalt"><ArrowLeft className="size-4" />Back to {backLabel}</Link>

            <EditorialHeader
                title={data.title}
                variant="feature"
                mediaFirstOnMobile
                eyebrow={<span className={data.owned ? 'text-cobalt' : 'text-coral-deep'}>{data.owned ? 'In your collection' : 'Beyond your library'}{releaseDate ? <span className="text-fog"> · {releaseDate}</span> : null}</span>}
                identity={<>{data.artist?.id ? <Link to={`/artists/${data.artist.id}`} className="underline decoration-line underline-offset-4 hover:text-cobalt">{data.artist.name}</Link> : data.artist?.name ?? 'Unknown artist'}{data.artist?.credited_name && <span className="mt-1 block max-h-12 overflow-hidden font-sans text-xs text-fog [contain:paint]">Credited as <bdi>{data.artist.credited_name}</bdi></span>}</>}
                description={<><p className="text-sm font-semibold text-fog">{[data.release_type, formatLongDuration(data.duration_ms), data.track_count ? `${data.track_count} tracks` : null].filter(Boolean).join(' · ')}</p>{data.disambiguation && <p className="mt-3 font-serif text-lg italic text-fog">{data.disambiguation}</p>}{data.genres.length > 0 && <ul aria-label="Genres" className="mt-4 flex flex-wrap gap-x-4 gap-y-2">{data.genres.map((genre) => <li key={genre} className="border-b border-coral/40 pb-0.5 text-xs font-semibold uppercase tracking-[0.12em] text-fog">{genre}</li>)}</ul>}<div className="mt-5"><PlexPlaybackContext context={data.plex_playback_context} /></div></>}
                actions={<AlbumActions album={data} />}
                media={<Artwork artwork={data.artwork} title={data.title} artist={data.artist?.name} priority className="ml-auto w-full max-w-[27.5rem]" />}
            />

            <section className="mt-14 grid items-start gap-12 lg:grid-cols-[minmax(0,3fr)_minmax(15rem,1fr)] xl:gap-16" aria-label="Album context and facts">
                <div className="min-w-0">
                    {data.description ? <AttributedNarrative description={data.description} eyebrow="Album context" title="About this album" titleId="album-description-title" /> : <section aria-labelledby="album-description-title" className="max-w-3xl"><p className="editorial-eyebrow text-coral">Album context</p><h2 id="album-description-title" className="mt-2 font-serif text-3xl font-bold">About this album</h2><p className="mt-4 text-sm leading-7 text-fog">No attributed album context is available yet.</p></section>}
                </div>
                <aside aria-labelledby="album-facts-title" className="border-t-2 border-cobalt bg-raised/65 p-6 lg:border-t-0 lg:border-l-2">
                    <p className="editorial-eyebrow text-cobalt">Release file</p>
                    <h2 id="album-facts-title" className="mt-2 font-serif text-2xl font-bold">Essential facts</h2>
                    <div className="mt-6"><FactList label="Album facts" facts={facts} empty="No release facts are available." /></div>
                    {data.discogs && <DiscogsAttribution sourceUrl={data.discogs.source_url} />}
                </aside>
            </section>

            <div className="mt-20"><TrackList album={data} /></div>

            <div className="mt-16 grid items-start gap-14 xl:grid-cols-2 xl:gap-16">
                {data.credits.status === 'available' && (
                    <section aria-labelledby="album-credits-title">
                        <SectionHeading eyebrow="People & works" title="Credits" id="album-credits-title" />
                        <CreditPeople groups={data.credits.groups} />
                        <dl className="divide-y divide-line">{data.credits.groups.map((group) => <div key={group.role} className="grid gap-1 py-4 sm:grid-cols-[9rem_1fr]"><dt className="font-semibold">{group.label}</dt><dd className="text-sm leading-6"><CreditNames group={group} /></dd></div>)}</dl>
                        <p className="mt-4 text-xs text-fog">Exact MusicBrainz relationships. Missing roles are not inferred.</p>
                    </section>
                )}

                {data.owned && (data.holdings.length > 1 || data.open_in_plex_status === 'choice-required') && (
                    <section id="album-holdings" aria-labelledby="album-holdings-title" className="scroll-mt-24">
                        <SectionHeading eyebrow="Collection" title={data.holdings.length === 1 ? 'Your copy' : 'Your copies'} id="album-holdings-title" />
                        <ul className="divide-y divide-line">{data.holdings.map((holding) => <li key={holding.id} className="flex flex-wrap items-center justify-between gap-4 py-5"><div className="min-w-0"><p className="break-words font-semibold">{holding.title}</p><p className="mt-1 text-xs leading-5 text-fog">{[holding.year, ...holding.formats, holding.edition_summary].filter(Boolean).join(' · ') || 'Plex library copy'}</p></div>{data.open_in_plex_status === 'choice-required' && <OpenInPlexButton plexItemId={holding.plex_item_id} label="Open this copy" />}</li>)}</ul>
                    </section>
                )}

                {data.recommendation && (
                    <aside className="border-l-2 border-coral bg-raised p-6" aria-labelledby="recommendation-reason-title">
                        <p className="editorial-eyebrow text-coral">Recommendation context</p>
                        <h2 id="recommendation-reason-title" className="mt-2 font-serif text-2xl font-bold">Why this album appeared</h2>
                        <RecommendationReasons reasons={data.recommendation.reasons} />
                        <div className="mt-6 border-t border-line pt-5"><p className="mb-3 text-xs font-semibold text-fog">Tune future discovery</p><RecommendationFeedback itemId={data.recommendation.item_id} entityId={data.id} initialAction={data.recommendation.feedback?.action ?? null} /></div>
                    </aside>
                )}
            </div>

            <section className="mt-16 border-y border-line py-7" aria-labelledby="album-provenance-title">
                <p className="editorial-eyebrow text-cobalt">Technical provenance</p>
                <h2 id="album-provenance-title" className="mt-2 font-serif text-2xl font-bold">Sources &amp; evidence</h2>
                <dl className="mt-5 divide-y divide-line border-t border-line">
                    {provenance.map((source) => <div key={source.provider} className="grid gap-1 py-4 sm:grid-cols-[10rem_1fr]"><dt className="font-semibold text-ink">{source.provider}</dt><dd className="text-sm leading-6 text-fog">{source.roles.join(' · ')}</dd></div>)}
                    <div className="grid gap-1 py-4 sm:grid-cols-[10rem_1fr]"><dt className="font-semibold text-ink">Disco catalog</dt><dd className="text-sm leading-6 text-fog">{data.metadata_status} metadata · {data.identity_status} identity</dd></div>
                </dl>
            </section>
        </article>
    );
}
