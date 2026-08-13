import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { MetadataCard } from '../components/metadata-card';
import { PageHeading } from '../components/page-heading';
import { EmptyState, ErrorState } from '../components/states';
import { Skeleton } from '../components/ui/skeleton';
import { api } from '../lib/api';
import { formatDate } from '../lib/utils';
import type { MetadataDiagnosticCategory, MetadataDiagnosticStatus, MetadataPipelineStatus, PipelineDiagnosticStatus } from '../lib/types';

const pipelineTone: Record<MetadataPipelineStatus['status'], string> = {
    healthy: 'border-emerald-700 bg-emerald-50 text-emerald-900',
    building: 'border-cobalt bg-cobalt/5 text-cobalt-deep',
    attention: 'border-coral bg-coral/10 text-ink',
    idle: 'border-line bg-raised text-fog',
    disabled: 'border-line bg-paper text-fog',
};

export function MetadataPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const queryClient = useQueryClient();
    const selectedType = searchParams.get('type');
    const selectedCategory = searchParams.get('category') as MetadataDiagnosticCategory | null;
    const selectedStatus = searchParams.get('status') as MetadataDiagnosticStatus | null;
    const selectedPipeline = searchParams.get('pipeline');
    const selectedPipelineStatus = searchParams.get('pipeline_status') as PipelineDiagnosticStatus | null;
    const page = Math.max(1, Number(searchParams.get('page') ?? 1) || 1);
    const hasDiagnostic = ['artist', 'album', 'track'].includes(selectedType ?? '') && selectedCategory !== null && selectedStatus !== null;
    const hasPipelineDiagnostic = ['discogs', 'discographies', 'discography-artwork'].includes(selectedPipeline ?? '') && selectedPipelineStatus !== null;
    const coverage = useQuery({ queryKey: ['metadata-coverage'], queryFn: api.coverage });
    const diagnostics = useQuery({
        queryKey: ['metadata-diagnostics', selectedType, selectedCategory, selectedStatus, page],
        queryFn: () => api.metadataDiagnostics(selectedType!, selectedCategory!, selectedStatus!, page),
        enabled: hasDiagnostic,
    });
    const pipelineDiagnostics = useQuery({
        queryKey: ['pipeline-diagnostics', selectedPipeline, selectedPipelineStatus, page],
        queryFn: () => api.pipelineDiagnostics(selectedPipeline!, selectedPipelineStatus!, page),
        enabled: hasPipelineDiagnostic,
    });
    const retry = useMutation({
        mutationFn: ({ category, id }: { category: MetadataDiagnosticCategory; id: string }) => api.retryMetadataDiagnostic(category, id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['metadata-diagnostics'] });
            await queryClient.invalidateQueries({ queryKey: ['metadata-coverage'] });
        },
    });
    const pipelineRetry = useMutation({
        mutationFn: ({ pipeline, id }: { pipeline: string; id: string }) => api.retryPipelineDiagnostic(pipeline, id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['pipeline-diagnostics'] });
            await queryClient.invalidateQueries({ queryKey: ['metadata-coverage'] });
        },
    });
    const percentage = coverage.data?.overall.total ? Math.round((coverage.data.overall.identified / coverage.data.overall.total) * 1000) / 10 : 0;
    const setPage = (nextPage: number) => {
        const next = new URLSearchParams(searchParams);
        next.set('page', String(nextPage));
        setSearchParams(next);
    };

    return (
        <div>
            <PageHeading eyebrow="Collection health" title="Metadata atlas" description="Identity, enrichment, and artwork coverage across the current Plex collection." />
            {coverage.isLoading ? <div className="grid gap-5 lg:grid-cols-3">{Array.from({ length: 3 }, (_, index) => <Skeleton key={index} className="h-80 rounded-none" />)}</div> : coverage.isError ? <ErrorState error={coverage.error} retry={() => coverage.refetch()} /> : !coverage.data?.entities.length ? <EmptyState title="No coverage data" message="Metadata coverage will appear after the library has been assessed." /> : (
                <div>
                    <section className="mb-8 grid bg-cobalt-deep text-cream lg:grid-cols-[0.75fr_1.25fr]">
                        <div className="p-8 sm:p-10"><p className="text-xs font-bold uppercase tracking-[0.22em] text-white/70">Overall identity</p><p className="mt-5 font-serif text-7xl leading-none sm:text-8xl">{percentage}%</p></div>
                        <div className="border-t border-white/20 p-8 sm:p-10 lg:border-l lg:border-t-0"><p className="max-w-xl font-serif text-3xl leading-tight">{coverage.data.overall.identified.toLocaleString()} of {coverage.data.overall.total.toLocaleString()} library entities have confirmed external identities.</p><p className="mt-6 text-sm text-white/70">Last Plex sync: {formatDate(coverage.data.last_plex_sync_at) ?? 'Not yet completed'}</p></div>
                    </section>
                    <div className="grid gap-5 lg:grid-cols-3">{coverage.data.entities.map((entity, index) => <MetadataCard key={entity.type} entity={entity} index={index} />)}</div>
                    <section className="mt-10">
                        <header className="mb-5 flex flex-col gap-3 border-b border-line pb-5 sm:flex-row sm:items-end sm:justify-between">
                            <div><p className="text-xs font-bold uppercase tracking-[0.22em] text-coral">Operations ledger</p><h2 className="mt-2 font-serif text-4xl font-bold">Enrichment pipelines</h2></div>
                            <p className="max-w-xl text-sm leading-6 text-fog">A provider-free reading of persisted coverage, freshness, and bounded background schedules.</p>
                        </header>
                        <div className="grid gap-4 lg:grid-cols-2">
                            {coverage.data.pipelines.map((pipeline) => (
                                <article key={pipeline.key} className="border border-line bg-paper p-6">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div><p className="text-xs font-bold uppercase tracking-[0.16em] text-fog">{pipeline.provider}</p><h3 className="mt-1 font-serif text-2xl font-bold text-ink">{pipeline.name}</h3></div>
                                        <span className={`border px-2.5 py-1 text-xs font-bold uppercase tracking-[0.12em] ${pipelineTone[pipeline.status]}`}>{pipeline.status}</span>
                                    </div>
                                    <div className="mt-5 grid grid-cols-2 gap-px bg-line sm:grid-cols-3">
                                        {pipeline.metrics.map((metric) => {
                                            const content = <><p className="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-fog">{metric.label}</p><p className="mt-1 text-xl font-bold tabular-nums text-ink">{typeof metric.value === 'number' ? metric.value.toLocaleString() : metric.value}</p></>;
                                            return metric.status
                                                ? <Link key={metric.label} className="bg-paper px-3 py-4 transition hover:bg-raised focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cobalt" to={`/metadata?pipeline=${pipeline.key}&pipeline_status=${metric.status}&page=1`} aria-label={`${pipeline.name}: ${metric.label} ${metric.value}`}>{content}</Link>
                                                : <div key={metric.label} className="bg-paper px-3 py-4">{content}</div>;
                                        })}
                                    </div>
                                    <p className="mt-5 text-sm leading-6 text-fog">{pipeline.detail}</p>
                                    <dl className="mt-4 grid gap-2 border-t border-line pt-4 text-xs sm:grid-cols-2">
                                        <div><dt className="font-bold uppercase tracking-[0.12em] text-fog">Last activity</dt><dd className="mt-1 text-ink">{formatDate(pipeline.last_activity_at) ?? 'Not yet observed'}</dd></div>
                                        <div><dt className="font-bold uppercase tracking-[0.12em] text-fog">Next scheduled</dt><dd className="mt-1 text-ink">{formatDate(pipeline.next_run_at)} · {pipeline.cadence}</dd></div>
                                    </dl>
                                </article>
                            ))}
                        </div>
                    </section>
                    {hasPipelineDiagnostic && (
                        <section className="mt-8 border border-line bg-paper">
                            <header className="flex flex-col gap-4 border-b border-line p-6 sm:flex-row sm:items-end sm:justify-between sm:p-8">
                                <div><p className="text-xs font-bold uppercase tracking-[0.2em] text-coral">Pipeline diagnostic</p><h2 className="mt-2 font-serif text-3xl font-bold">{selectedPipeline?.replaceAll('-', ' ')}: {selectedPipelineStatus}</h2><p className="mt-2 text-sm text-fog">Exact persisted records, source basis, cooldown, and bounded recovery eligibility.</p></div>
                                <Link className="text-sm font-semibold text-cobalt hover:underline" to="/metadata">Close diagnostic</Link>
                            </header>
                            {pipelineDiagnostics.isLoading ? <div className="p-8"><Skeleton className="h-48 rounded-none" /></div> : pipelineDiagnostics.isError ? <div className="p-8"><ErrorState error={pipelineDiagnostics.error} retry={() => pipelineDiagnostics.refetch()} /></div> : !pipelineDiagnostics.data?.data.length ? <div className="p-8"><EmptyState title="No affected records" message="The selected pipeline state is currently empty." /></div> : (
                                <div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[900px] text-left text-sm">
                                            <thead className="bg-raised text-xs uppercase tracking-[0.16em] text-fog"><tr><th className="px-6 py-3">Record</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Source basis</th><th className="px-4 py-3">Last attempt</th><th className="px-4 py-3">Reason</th><th className="px-6 py-3 text-right">Recovery</th></tr></thead>
                                            <tbody className="divide-y divide-line">{pipelineDiagnostics.data.data.map((row) => <tr key={row.id}><td className="px-6 py-4"><Link className="font-semibold text-ink hover:text-cobalt hover:underline" to={row.record_url}>{row.title}</Link><p className="mt-1 text-xs capitalize text-fog">{row.subject_type} · {row.provider}</p></td><td className="px-4 py-4 capitalize">{row.status}</td><td className="max-w-xs px-4 py-4 text-fog">{row.source_basis}</td><td className="px-4 py-4 text-fog">{formatDate(row.last_attempt_at) ?? 'Never'}</td><td className="px-4 py-4 text-fog">{row.failure_category?.replaceAll('_', ' ') ?? 'None'}</td><td className="px-6 py-4 text-right">{row.retry_supported ? <button className="border border-cobalt px-3 py-1.5 text-xs font-bold text-cobalt disabled:opacity-50" disabled={pipelineRetry.isPending} onClick={() => pipelineRetry.mutate({ pipeline: row.pipeline, id: row.id })}>Retry safely</button> : <span className="text-xs text-fog" title={row.repair_note ?? undefined}>{row.next_retry_at ? `After ${formatDate(row.next_retry_at)}` : row.repair_note ?? 'Managed automatically'}</span>}</td></tr>)}</tbody>
                                        </table>
                                    </div>
                                    <footer className="flex items-center justify-between border-t border-line px-6 py-4 text-sm"><p className="text-fog">{pipelineDiagnostics.data.meta.total.toLocaleString()} affected</p><div className="flex gap-2"><button className="border border-line px-3 py-1.5 disabled:opacity-40" disabled={!pipelineDiagnostics.data.links.prev} onClick={() => setPage(page - 1)}>Previous</button><button className="border border-line px-3 py-1.5 disabled:opacity-40" disabled={!pipelineDiagnostics.data.links.next} onClick={() => setPage(page + 1)}>Next</button></div></footer>
                                </div>
                            )}
                        </section>
                    )}
                    {hasDiagnostic && (
                        <section className="mt-8 border border-line bg-paper">
                            <header className="flex flex-col gap-4 border-b border-line p-6 sm:flex-row sm:items-end sm:justify-between sm:p-8">
                                <div><p className="text-xs font-bold uppercase tracking-[0.2em] text-coral">Diagnostic file</p><h2 className="mt-2 font-serif text-3xl font-bold">{selectedType} {selectedCategory}: {selectedStatus}</h2><p className="mt-2 text-sm text-fog">Exact affected records. Provider failures are reduced to safe categories.</p></div>
                                <Link className="text-sm font-semibold text-cobalt hover:underline" to="/metadata">Close diagnostic</Link>
                            </header>
                            {diagnostics.isLoading ? <div className="p-8"><Skeleton className="h-48 rounded-none" /></div> : diagnostics.isError ? <div className="p-8"><ErrorState error={diagnostics.error} retry={() => diagnostics.refetch()} /></div> : !diagnostics.data?.data.length ? <div className="p-8"><EmptyState title="No affected records" message="The selected aggregate is currently empty." /></div> : (
                                <div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[780px] text-left text-sm">
                                            <thead className="bg-raised text-xs uppercase tracking-[0.16em] text-fog"><tr><th className="px-6 py-3">Record</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Provider</th><th className="px-4 py-3">Last attempt</th><th className="px-4 py-3">Failure category</th><th className="px-6 py-3 text-right">Repair</th></tr></thead>
                                            <tbody className="divide-y divide-line">{diagnostics.data.data.map((row) => <tr key={row.id}><td className="px-6 py-4 font-semibold">{row.title}</td><td className="px-4 py-4 capitalize">{row.status}</td><td className="px-4 py-4 text-fog">{row.provider ?? 'None'}</td><td className="px-4 py-4 text-fog">{formatDate(row.last_attempt_at) ?? 'Never'}</td><td className="px-4 py-4 text-fog">{row.failure_category?.replaceAll('_', ' ') ?? 'None'}</td><td className="px-6 py-4 text-right">{row.retry_supported ? <button className="border border-cobalt px-3 py-1.5 text-xs font-bold text-cobalt disabled:opacity-50" disabled={retry.isPending} onClick={() => retry.mutate({ category: row.category, id: row.id })}>Retry safely</button> : <span className="text-xs text-fog" title={row.repair_note ?? undefined}>{row.next_retry_at ? `After ${formatDate(row.next_retry_at)}` : row.repair_note ?? 'Not supported'}</span>}</td></tr>)}</tbody>
                                        </table>
                                    </div>
                                    <footer className="flex items-center justify-between border-t border-line px-6 py-4 text-sm"><p className="text-fog">{diagnostics.data.meta.total.toLocaleString()} affected</p><div className="flex gap-2"><button className="border border-line px-3 py-1.5 disabled:opacity-40" disabled={!diagnostics.data.links.prev} onClick={() => setPage(page - 1)}>Previous</button><button className="border border-line px-3 py-1.5 disabled:opacity-40" disabled={!diagnostics.data.links.next} onClick={() => setPage(page + 1)}>Next</button></div></footer>
                                </div>
                            )}
                        </section>
                    )}
                    <section className="mt-8 grid border border-line bg-paper lg:grid-cols-[0.8fr_1.2fr]">
                        <div className="border-b border-line p-7 lg:border-b-0 lg:border-r">
                            <p className="text-xs font-bold uppercase tracking-[0.22em] text-cobalt">ListenBrainz history</p>
                            <p className="mt-4 text-5xl font-bold leading-none text-ink">{coverage.data.listenbrainz.current_listens.toLocaleString()}</p>
                            <p className="mt-2 text-sm text-fog">current listens for {coverage.data.listenbrainz.username ?? 'the configured owner'}</p>
                        </div>
                        <div className="grid grid-cols-2 gap-px bg-line sm:grid-cols-4">
                            {[
                                ['Album matched', `${coverage.data.listenbrainz.album_match_percentage}%`],
                                ['Recording IDs', coverage.data.listenbrainz.recording_matched.toLocaleString()],
                                ['Unmatched', coverage.data.listenbrainz.unmatched.toLocaleString()],
                                ['Conflicts', coverage.data.listenbrainz.conflicts.toLocaleString()],
                            ].map(([label, value]) => <div key={label} className="bg-paper p-6"><p className="text-xs font-bold uppercase tracking-[0.16em] text-fog">{label}</p><p className="mt-3 text-2xl font-bold text-ink">{value}</p></div>)}
                        </div>
                    </section>
                    <p className="mt-4 text-sm text-fog">Last ListenBrainz import: {formatDate(coverage.data.listenbrainz.last_import_at) ?? 'Not yet completed'} · Latest listen: {formatDate(coverage.data.listenbrainz.latest_listened_at) ?? 'None imported'}</p>
                    <p className="mt-7 max-w-3xl text-sm leading-6 text-fog">Identification indicates a confirmed external ID. Enrichment records descriptive context from MusicBrainz. Artwork readiness reports locally cached images available to this interface.</p>
                </div>
            )}
        </div>
    );
}
