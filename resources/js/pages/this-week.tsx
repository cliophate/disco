import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Artwork } from '../components/artwork';
import { ErrorState, AlbumGridSkeleton } from '../components/states';
import { Button } from '../components/ui/button';
import { api } from '../lib/api';
import type { DiscoverySection, Pick } from '../lib/types';
import { formatDate } from '../lib/utils';

const titles = { new_artists: 'New artists', new_releases: 'New releases', more: 'More from artists you play' };
const descriptions = { new_artists: 'A few unfamiliar names, chosen from the music you play.', new_releases: 'Recent and upcoming albums connected to your listening.', more: 'Unheard albums by familiar artists.' };
const states = { new: 'New', in_plex: 'In Plex', played: 'Played' };

export function PickCard({ pick, showRelease = false }: { pick: Pick; showRelease?: boolean }) {
    const client = useQueryClient();
    const pass = useMutation({ mutationFn: () => api.pass(pick.id), onSuccess: () => client.invalidateQueries({ queryKey: ['discovery'] }) });
    return <article className="min-w-0" aria-label={`${pick.title} by ${pick.artist}`}>
        <Artwork artwork={pick.artwork} title={pick.title} artist={pick.artist} />
        <div className="mt-5 flex flex-wrap items-center justify-between gap-2 text-xs text-fog"><span>{pick.year ?? 'Year unknown'} · {pick.type}</span><span className="text-cobalt">{states[pick.state]}</span></div>
        <h3 className="mt-2 font-serif text-2xl leading-tight [overflow-wrap:anywhere]">{pick.title}</h3>
        <p className="mt-1 text-base [overflow-wrap:anywhere]">{pick.artist}</p>
        {showRelease && <p className="mt-2 text-sm text-coral">{pick.upcoming ? 'Upcoming' : 'Released'}{pick.release_date ? ` · ${formatDate(pick.release_date)}` : ''}</p>}
        <p className="mt-3 text-sm leading-6 text-fog">{pick.reason}</p>
        <div className="mt-4 flex flex-wrap items-center gap-1">
            <Button asChild><a href={pick.qobuz_url} target="_blank" rel="noopener noreferrer">Open in Qobuz</a></Button>
            <Button variant="ghost" onClick={() => pass.mutate()} disabled={pass.isPending} aria-label={`Passed on ${pick.title} by ${pick.artist}`}>{pass.isPending ? 'Saving…' : 'Passed'}</Button>
        </div>
        {pick.qobuz_status === 'search' && <p className="mt-2 text-xs text-fog">Opens a Qobuz search</p>}
        {pass.isError && <p role="alert" className="mt-2 text-sm text-coral">{pass.error.message}</p>}
    </article>;
}

function Section({ section }: { section: DiscoverySection }) {
    return <section aria-labelledby={`heading-${section.key}`} className="mt-16 border-t border-line pt-8 first:mt-12">
        <div className="mb-8 flex flex-wrap items-baseline justify-between gap-4">
            <div><h2 id={`heading-${section.key}`} className="font-serif text-3xl sm:text-4xl">{titles[section.key]}</h2><p className="mt-2 max-w-xl text-sm leading-6 text-fog">{descriptions[section.key]}</p></div>
            {section.generated_at && <p className="text-xs text-fog">Updated {formatDate(section.generated_at)}</p>}
        </div>
        {section.message && <p role={section.stale ? 'status' : undefined} className="mb-8 max-w-2xl border-l-2 border-coral py-3 pl-4 text-sm leading-6 text-fog">{section.message}</p>}
        <div className="grid grid-cols-1 gap-x-7 gap-y-12 min-[420px]:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">{section.picks.map(pick => <PickCard key={pick.id} pick={pick} showRelease={section.key === 'new_releases'} />)}</div>
        {section.earlier.length > 0 && <details className="mt-10 border-t border-line pt-2"><summary className="min-h-11 cursor-pointer py-3 text-sm font-semibold">Earlier · {section.earlier.length}</summary><div className="mt-6 grid grid-cols-1 gap-x-7 gap-y-12 min-[420px]:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">{section.earlier.map(pick => <PickCard key={pick.id} pick={pick} showRelease={section.key === 'new_releases'} />)}</div></details>}
    </section>;
}

export function ThisWeekPage() {
    const query = useQuery({ queryKey: ['discovery'], queryFn: api.discovery });
    return <><h1 className="font-serif text-6xl leading-none tracking-tight sm:text-7xl">This week</h1><p className="mt-5 max-w-xl text-base leading-7 text-fog">Find an album. Preview it on Qobuz. Keep listening in Plex.</p>
        {query.isLoading ? <div className="mt-12"><AlbumGridSkeleton count={5} /></div> : query.isError ? <div className="mt-10"><ErrorState error={query.error} retry={() => query.refetch()} /></div> : query.data?.map(section => <Section key={section.key} section={section} />)}
    </>;
}
