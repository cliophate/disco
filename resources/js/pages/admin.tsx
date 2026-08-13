import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowRight, Check, Clock3, KeyRound, Play, ShieldCheck, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { PageHeading } from '../components/page-heading';
import { SectionHeading } from '../components/section-heading';
import { EmptyState, ErrorState } from '../components/states';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { Skeleton } from '../components/ui/skeleton';
import { ApiError, api } from '../lib/api';
import type { AdminOperation, AdminProvider } from '../lib/types';
import { cn, formatDate, titleCase } from '../lib/utils';

const operations = [
    { key: 'plex.sync', name: 'Plex library sync', description: 'Reconcile the read-only Plex music library.' },
    { key: 'listenbrainz.import', name: 'ListenBrainz import', description: 'Import new immutable listening activity.' },
    { key: 'listenbrainz.recommendations', name: 'ListenBrainz recommendations', description: 'Refresh beyond-library album recommendations.' },
    { key: 'musicbrainz.enrich', name: 'MusicBrainz enrichment', description: 'Enrich exact owned artists and albums.' },
    { key: 'discogs.enrich', name: 'Discogs enrichment', description: 'Refresh approved catalog fields for exact matches.' },
    { key: 'artwork.discographies', name: 'Discography artwork', description: 'Cache covers for exact discography albums.' },
    { key: 'catalog.enrich', name: 'Current catalog enrichment', description: 'Drain missing details and covers from current discovery surfaces.' },
    { key: 'upcoming.refresh', name: 'Release window', description: 'Refresh the recent and upcoming album and EP feed.' },
    { key: 'notifications.deliver', name: 'Notification delivery', description: 'Deliver queued upcoming-release alerts.' },
] as const;

type CredentialFields = { secret: string; currentPassword: string };
const blankCredential: CredentialFields = { secret: '', currentPassword: '' };
const providerNames: Record<string, string> = {
    plex: 'Plex',
    listenbrainz: 'ListenBrainz',
    discogs: 'Discogs',
    gotify: 'Gotify',
    theaudiodb: 'TheAudioDB',
};

function dateTime(value: string | null) {
    return formatDate(value, { dateStyle: 'medium', timeStyle: 'short' }) ?? 'Not scheduled';
}

function statusTone(status: string | null) {
    if (status === 'completed' || status === 'succeeded' || status === 'healthy' || status === 'ready' || status === 'passed') return 'text-emerald-700';
    if (status === 'failed' || status === 'attention') return 'text-coral';
    return 'text-cobalt';
}

function operationName(key: string) {
    return operations.find((operation) => operation.key === key)?.name ?? key.replaceAll('.', ' ');
}

function ProviderEditor({ provider }: { provider: AdminProvider }) {
    const queryClient = useQueryClient();
    const name = providerNames[provider.provider] ?? titleCase(provider.provider);
    const [fields, setFields] = useState<CredentialFields>(blankCredential);
    const [removalPassword, setRemovalPassword] = useState('');
    const activate = useMutation({
        mutationFn: () => api.updateAdminProvider(provider.provider, { secret: fields.secret, current_password: fields.currentPassword }),
        onSuccess: async () => {
            setFields(blankCredential);
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['admin-overview'] }),
                queryClient.invalidateQueries({ queryKey: ['admin-providers'] }),
            ]);
        },
    });
    const remove = useMutation({
        mutationFn: () => api.removeAdminProvider(provider.provider, { secret: '', current_password: removalPassword }),
        onSuccess: async () => {
            setRemovalPassword('');
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['admin-overview'] }),
                queryClient.invalidateQueries({ queryKey: ['admin-providers'] }),
            ]);
        },
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        activate.mutate();
    }

    function submitRemoval(event: FormEvent) {
        event.preventDefault();
        remove.mutate();
    }

    return (
        <article className="border-t border-line py-7 first:border-t-0">
            <div className="grid gap-6 lg:grid-cols-[minmax(12rem,0.65fr)_minmax(0,1.35fr)] lg:gap-10">
                <div>
                    <p className="editorial-eyebrow">{provider.provider}</p>
                    <h3 className="mt-2 font-serif text-3xl font-bold">{name}</h3>
                    <dl className="mt-5 grid grid-cols-3 gap-3 text-xs">
                        <div><dt className="font-bold uppercase tracking-[0.12em] text-fog">Source</dt><dd className="mt-1 capitalize text-ink">{provider.source}</dd></div>
                        <div><dt className="font-bold uppercase tracking-[0.12em] text-fog">Configured</dt><dd className={cn('mt-1 font-semibold', provider.configured ? 'text-emerald-700' : 'text-fog')}>{provider.configured ? 'Yes' : 'No'}</dd></div>
                        <div><dt className="font-bold uppercase tracking-[0.12em] text-fog">Tested</dt><dd className={cn('mt-1 font-semibold', provider.tested_at ? 'text-emerald-700' : 'text-fog')}>{provider.tested_at ? 'Passed' : 'Not tested'}</dd></div>
                    </dl>
                    {provider.tested_at && <p className="mt-3 text-xs text-fog">Tested {dateTime(provider.tested_at)}</p>}
                </div>
                <div>
                    <form onSubmit={submit} aria-label={`Activate ${name}`} className="grid gap-4 sm:grid-cols-2">
                        <label className="grid gap-2 text-sm font-semibold">New secret<Input type="password" name="secret" autoComplete="new-password" required value={fields.secret} onChange={(event) => setFields((current) => ({ ...current, secret: event.target.value }))} /></label>
                        <label className="grid gap-2 text-sm font-semibold">Current password<Input type="password" name="current_password" autoComplete="current-password" required value={fields.currentPassword} onChange={(event) => setFields((current) => ({ ...current, currentPassword: event.target.value }))} /></label>
                        <div className="sm:col-span-2 flex flex-wrap items-center gap-3">
                            <Button type="submit" disabled={activate.isPending}><KeyRound className="size-4" />{activate.isPending ? 'Activating…' : provider.configured ? 'Replace credential' : 'Activate provider'}</Button>
                            <p className="text-xs leading-5 text-fog">Write-only. Existing credentials are never returned or displayed.</p>
                        </div>
                    </form>
                    {activate.isSuccess && <p className="mt-3 text-sm font-semibold text-emerald-700" role="status">Credential accepted and cleared from this form.</p>}
                    {activate.isError && <p className="mt-3 text-sm text-coral" role="alert">{activate.error instanceof ApiError ? activate.error.message : 'Provider activation failed.'}</p>}
                    {(provider.source === 'database' || provider.source === 'unreadable') && (
                        <details className="mt-5 border-t border-line pt-4">
                            <summary className="w-fit cursor-pointer text-sm font-semibold text-coral">Remove credential</summary>
                            <form onSubmit={submitRemoval} aria-label={`Remove ${name}`} className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                                <label className="grid w-full max-w-sm gap-2 text-sm font-semibold">Confirm current password<Input type="password" autoComplete="current-password" required value={removalPassword} onChange={(event) => setRemovalPassword(event.target.value)} /></label>
                                <Button type="submit" variant="danger" disabled={remove.isPending}><X className="size-4" />{remove.isPending ? 'Removing…' : `Remove ${name}`}</Button>
                            </form>
                            <p className="mt-2 text-xs text-fog">This explicitly removes the stored credential. Environment credentials must be removed at their source.</p>
                            {remove.isError && <p className="mt-3 text-sm text-coral" role="alert">{remove.error instanceof ApiError ? remove.error.message : 'Credential removal failed.'}</p>}
                        </details>
                    )}
                    {provider.configured && provider.source === 'environment' && <p className="mt-5 border-t border-line pt-4 text-sm text-fog">This credential is supplied by the environment and must be removed at its source.</p>}
                </div>
            </div>
        </article>
    );
}

function OperationLedger({ items }: { items: AdminOperation[] }) {
    if (!items.length) return <EmptyState title="No recent operations" message="Owner-started work will be recorded here." />;

    return (
        <div className="border-y border-line">
            {items.map((item) => (
                <article key={item.id} className="grid gap-3 border-b border-line py-5 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-3"><h3 className="font-serif text-xl font-bold">{operationName(item.operation_key)}</h3><span className={cn('text-xs font-bold uppercase tracking-[0.14em]', statusTone(item.status))}>{titleCase(item.status)}</span></div>
                        <p className="mt-1 text-sm text-fog">Queued {dateTime(item.queued_at)}{item.finished_at ? ` · Finished ${dateTime(item.finished_at)}` : item.started_at ? ` · Started ${dateTime(item.started_at)}` : ''}</p>
                        {item.error_code && <p className="mt-2 max-w-3xl text-sm leading-6 text-coral">Error: {titleCase(item.error_code)}</p>}
                    </div>
                    <code className="w-fit bg-raised px-2 py-1 text-xs text-fog">{item.operation_key}</code>
                </article>
            ))}
        </div>
    );
}

export function AdminPage() {
    const queryClient = useQueryClient();
    const overview = useQuery({ queryKey: ['admin-overview'], queryFn: api.adminOverview });
    const providers = useQuery({ queryKey: ['admin-providers'], queryFn: api.adminProviders });
    const recentOperations = useQuery({
        queryKey: ['admin-operations'],
        queryFn: api.adminOperations,
        refetchInterval: (query) => query.state.data?.some((operation) => operation.status === 'queued' || operation.status === 'running') ? 3_000 : false,
    });
    const run = useMutation({
        mutationFn: (operation: string) => api.runAdminOperation(operation),
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['admin-overview'] }),
                queryClient.invalidateQueries({ queryKey: ['admin-operations'] }),
            ]);
        },
    });

    return (
        <div className="section-stack">
            <section>
                <PageHeading eyebrow="Private owner control" title="Administration" description="Operate collection pipelines and provider credentials without exposing the secrets that power them." action={<Button asChild variant="secondary"><Link to="/metadata">Open metadata atlas<ArrowRight className="size-4" /></Link></Button>} />
                {overview.isLoading ? <div className="grid gap-px bg-line sm:grid-cols-3"><Skeleton className="h-40 rounded-none" /><Skeleton className="h-40 rounded-none" /><Skeleton className="h-40 rounded-none" /></div> : overview.isError ? <ErrorState error={overview.error} retry={() => overview.refetch()} /> : overview.data && (
                    <>
                        <div className="grid gap-px bg-line sm:grid-cols-3">
                            <div className="bg-cobalt-deep p-7 text-cream"><p className="text-xs font-bold uppercase tracking-[0.2em] text-white/70">Pipelines</p><p className="mt-4 font-serif text-6xl leading-none">{overview.data.pipelines.length}</p><p className="mt-3 text-sm text-white/70">scheduled collection systems</p></div>
                            <div className="bg-panel p-7"><p className="text-xs font-bold uppercase tracking-[0.2em] text-fog">Failed queue</p><p className={cn('mt-4 font-serif text-6xl leading-none', overview.data.failed_jobs ? 'text-coral' : 'text-ink')}>{overview.data.failed_jobs.toLocaleString()}</p><p className="mt-3 flex items-center gap-2 text-sm text-fog">{overview.data.failed_jobs ? <AlertTriangle className="size-4 text-coral" /> : <Check className="size-4 text-emerald-700" />}{overview.data.failed_jobs ? 'jobs need attention' : 'no failed jobs'}</p></div>
                            <div className="bg-panel p-7"><p className="text-xs font-bold uppercase tracking-[0.2em] text-fog">Providers</p><p className="mt-4 font-serif text-6xl leading-none">{overview.data.providers.filter((provider) => provider.configured).length}<span className="text-2xl text-fog">/{overview.data.providers.length}</span></p><p className="mt-3 text-sm text-fog">credentials configured</p></div>
                        </div>
                        <div className="mt-10">
                            <SectionHeading eyebrow="Schedule" title="Pipeline ledger" description="The previous outcome and next expected run for each background pipeline." />
                            <div className="overflow-x-auto border-y border-line">
                                <table className="w-full min-w-[720px] text-left">
                                    <thead className="text-xs uppercase tracking-[0.16em] text-fog"><tr><th className="py-3 pr-4">Pipeline</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Last activity</th><th className="py-3 pl-4">Next run</th></tr></thead>
                                    <tbody>{overview.data.pipelines.map((pipeline) => <tr key={pipeline.key} className="border-t border-line"><th className="py-5 pr-4"><span className="block font-serif text-xl">{pipeline.name}</span><span className="mt-1 block text-xs font-normal text-fog">{pipeline.provider}</span></th><td className={cn('px-4 py-5 text-sm font-bold capitalize', statusTone(pipeline.status))}>{titleCase(pipeline.status)}</td><td className="px-4 py-5 text-sm font-semibold">{pipeline.last_activity_at ? dateTime(pipeline.last_activity_at) : 'Never'}</td><td className="py-5 pl-4 text-sm"><span className="font-semibold">{dateTime(pipeline.next_run_at)}</span><span className="mt-1 block text-xs text-fog">{pipeline.cadence}</span></td></tr>)}</tbody>
                                </table>
                            </div>
                        </div>
                    </>
                )}
            </section>

            <section aria-labelledby="operations-heading">
                <SectionHeading id="operations-heading" eyebrow="Manual control" title="Run an operation" description="Each action queues one bounded background operation. Starting an action twice does not reveal or alter provider credentials." />
                <div className="grid border-y border-line md:grid-cols-2">
                    {operations.map((operation, index) => (
                        <article key={operation.key} className={cn('grid grid-cols-[1fr_auto] items-center gap-4 border-b border-line py-5 md:px-5', index % 2 === 0 && 'md:border-r md:pl-0', index % 2 === 1 && 'md:pr-0')}>
                            <div><h3 className="font-serif text-xl font-bold">{operation.name}</h3><p className="mt-1 text-sm leading-6 text-fog">{operation.description}</p></div>
                            <Button size="icon" variant="secondary" aria-label={`Run ${operation.name}`} title={`Run ${operation.name}`} disabled={run.isPending} onClick={() => run.mutate(operation.key)}><Play className="size-4" /></Button>
                        </article>
                    ))}
                </div>
                {run.isSuccess && <p className="mt-4 flex items-center gap-2 text-sm font-semibold text-emerald-700" role="status"><Check className="size-4" />Operation queued.</p>}
                {run.isError && <p className="mt-4 text-sm text-coral" role="alert">{run.error instanceof ApiError ? run.error.message : 'The operation could not be queued.'}</p>}
            </section>

            <section aria-labelledby="recent-heading">
                <SectionHeading id="recent-heading" eyebrow="Audit trail" title="Recent operations" description="Latest owner requests and their terminal or in-progress status." action={recentOperations.isFetching ? <span className="flex items-center gap-2 text-sm text-fog"><Clock3 className="size-4" />Refreshing</span> : undefined} />
                {recentOperations.isLoading ? <Skeleton className="h-64 rounded-none" /> : recentOperations.isError ? <ErrorState error={recentOperations.error} retry={() => recentOperations.refetch()} /> : <OperationLedger items={recentOperations.data ?? []} />}
            </section>

            <section aria-labelledby="providers-heading">
                <SectionHeading id="providers-heading" eyebrow="Write-only vault" title="Provider access" description="Activate or rotate credentials using the provider secret and your current owner password. Secret values never return to this screen." action={<span className="flex items-center gap-2 text-sm font-semibold text-fog"><ShieldCheck className="size-4 text-cobalt" />Owner verification required</span>} />
                {providers.isLoading ? <Skeleton className="h-96 rounded-none" /> : providers.isError ? <ErrorState error={providers.error} retry={() => providers.refetch()} /> : providers.data?.length ? <div className="border-y border-line">{providers.data.map((provider) => <ProviderEditor key={provider.provider} provider={provider} />)}</div> : <EmptyState title="No providers" message="Provider configuration will appear when the backend advertises supported integrations." />}
            </section>
        </div>
    );
}
