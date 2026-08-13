import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Disc3, LoaderCircle } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { api } from '../lib/api';

export function LoginPage() {
    const queryClient = useQueryClient();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const login = useMutation({ mutationFn: () => api.login(email, password), onSuccess: () => queryClient.invalidateQueries({ queryKey: ['me'] }) });

    function submit(event: FormEvent) {
        event.preventDefault();
        login.mutate();
    }

    return (
        <main className="min-h-screen bg-paper lg:grid lg:grid-cols-[1.1fr_0.9fr]">
            <section className="relative hidden min-h-screen overflow-hidden bg-cobalt-deep p-12 text-cream lg:flex lg:flex-col lg:justify-between">
                <div className="absolute -right-48 top-20 size-[34rem] rounded-full border border-white/15" aria-hidden="true" />
                <div className="relative flex items-center gap-3 font-serif text-2xl italic"><span className="relative grid size-9 place-items-center rounded-full bg-cream text-cobalt-deep"><Disc3 className="size-5" /><span className="absolute -right-0.5 -top-0.5 size-2.5 rounded-full bg-coral" /></span>disco</div>
                <div className="relative max-w-3xl">
                    <p className="mb-5 text-xs font-bold uppercase tracking-[0.25em] text-white/70">Your self-hosted music atlas</p>
                    <h1 className="balance font-serif text-7xl font-bold leading-[0.88] tracking-[-0.055em] xl:text-8xl">The collection is the destination.</h1>
                    <p className="mt-8 max-w-lg text-base leading-7 text-white/75">Revisit overlooked records, follow artists through the shelves, and understand exactly why an album surfaced.</p>
                </div>
                <p className="relative text-xs text-white/55">Discovery from your own library. Playback remains in Plex.</p>
            </section>
            <section className="flex min-h-screen items-center justify-center px-6 py-16">
                <div className="w-full max-w-sm">
                    <div className="mb-16 flex items-center gap-3 font-serif text-2xl italic lg:hidden"><span className="relative grid size-9 place-items-center rounded-full bg-cobalt text-cream"><Disc3 className="size-5" /><span className="absolute -right-0.5 -top-0.5 size-2.5 rounded-full bg-coral" /></span>disco</div>
                    <p className="text-xs font-bold uppercase tracking-[0.24em] text-coral">Private collection</p>
                    <h2 className="mt-4 font-serif text-5xl font-bold tracking-[-0.04em]">Welcome back.</h2>
                    <p className="mt-3 text-sm text-fog">Sign in to enter your library.</p>
                    <form onSubmit={submit} className="mt-10 space-y-5">
                        <label className="block text-xs font-semibold text-ink">Email<Input className="mt-2 rounded-lg" type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>
                        <label className="block text-xs font-semibold text-ink">Password<Input className="mt-2 rounded-lg" type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} required /></label>
                        {login.isError && <p className="text-sm text-coral" role="alert">{login.error.message}</p>}
                        <Button className="mt-2 w-full" type="submit" disabled={login.isPending}>{login.isPending && <LoaderCircle className="size-4 animate-spin" />}{login.isPending ? 'Signing in…' : 'Sign in'}</Button>
                    </form>
                </div>
            </section>
        </main>
    );
}
