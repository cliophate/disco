import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { ErrorState } from '../components/states';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { api } from '../lib/api';
import type { AdminProviderCredential } from '../lib/types';
import { formatDate } from '../lib/utils';

function Provider({ provider }: { provider: AdminProviderCredential }) {
    const client = useQueryClient();
    const [secret, setSecret] = useState('');
    const [password, setPassword] = useState('');
    const [saved, setSaved] = useState(false);
    const mutation = useMutation({
        mutationFn: (remove: boolean) => remove ? api.deleteProvider(provider.provider, password) : api.updateProvider(provider.provider, secret, password),
        onSuccess: () => { setSecret(''); setPassword(''); setSaved(true); void client.invalidateQueries({ queryKey: ['admin'] }); },
    });
    function submit(event: FormEvent) { event.preventDefault(); setSaved(false); mutation.mutate(false); }
    return <section className="border-t border-line py-7"><h3 className="font-serif text-2xl">{provider.provider === 'plex' ? 'Plex' : 'ListenBrainz'}</h3>
        <p className="mt-2 text-sm text-fog">{provider.configured ? `Configured through ${provider.source}` : 'Not configured'}{provider.tested_at ? ` · Tested ${formatDate(provider.tested_at)}` : ''}</p>
        <form onSubmit={submit} className="mt-5 max-w-lg space-y-4">
            <label className="block text-sm">New token<Input type="password" autoComplete="off" value={secret} onChange={event => setSecret(event.target.value)} className="mt-2" required /></label>
            <label className="block text-sm">Your password<Input type="password" autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} className="mt-2" required /></label>
            <div className="flex flex-wrap gap-2"><Button type="submit" disabled={mutation.isPending}>Test and save</Button>{provider.source === 'database' && <Button type="button" variant="secondary" disabled={mutation.isPending || !password} onClick={() => { setSaved(false); mutation.mutate(true); }}>Remove saved token</Button>}</div>
            {mutation.isError && <p role="alert" className="text-sm text-coral">{mutation.error.message}</p>}
            {saved && <p role="status" className="text-sm text-fog">Credentials updated.</p>}
        </form>
    </section>;
}

export function AdminPage() {
    const query = useQuery({ queryKey: ['admin'], queryFn: api.adminOverview });
    if (query.isLoading) return <p role="status">Loading admin…</p>;
    if (query.isError) return <ErrorState error={query.error} retry={() => query.refetch()} />;
    return <><h1 className="font-serif text-5xl">Admin</h1><p className="mt-4 max-w-2xl text-sm leading-6 text-fog">Manage provider tokens here. Set the Plex address, library identity pins and ListenBrainz username in your environment configuration.</p>
        <h2 className="mt-12 font-serif text-3xl">Pipeline status</h2>
        <dl className="mt-5 max-w-3xl divide-y divide-line">{query.data?.pipelines.map(pipeline => <div key={pipeline.name} className="flex flex-wrap justify-between gap-3 py-4"><dt>{pipeline.name}</dt><dd className="text-sm text-fog">{pipeline.status}{pipeline.completed_at ? ` · ${formatDate(pipeline.completed_at, { dateStyle: 'medium', timeStyle: 'short' })}` : ''}{(pipeline.summary?.provider_failures ?? 0) > 0 && ' · Some provider requests failed'}</dd></div>)}</dl>
        <h2 className="mb-5 mt-12 font-serif text-3xl">Credentials</h2>{query.data?.providers.map(provider => <Provider key={provider.provider} provider={provider} />)}
    </>;
}
