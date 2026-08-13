import { useMutation, useQueryClient } from '@tanstack/react-query';
import { BookmarkPlus, Check, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import type { AlbumListState } from '../lib/types';
import { Button } from './ui/button';
import { Input } from './ui/input';

export function AlbumListControl({ albumId, initialState = null, detail = false, iconOnly = false }: { albumId: string; initialState?: AlbumListState | null; detail?: boolean; iconOnly?: boolean }) {
    const queryClient = useQueryClient();
    const [state, setState] = useState(initialState);
    const [note, setNote] = useState(initialState?.note ?? '');
    const [source, setSource] = useState(initialState?.source ?? '');
    const hasDraft = useRef(false);
    useEffect(() => { setState(initialState); if (!hasDraft.current) { setNote(initialState?.note ?? ''); setSource(initialState?.source ?? ''); } }, [initialState]);
    const invalidate = async () => Promise.all(['album', 'want-to-listen', 'albums', 'artist', 'artist-discography', 'search', 'home', 'home-lens', 'discover', 'upcoming', 'beyond'].map((key) => queryClient.invalidateQueries({ queryKey: [key] })));
    const update = useMutation({
        mutationFn: (status: 'want_to_listen' | 'listened') => api.updateAlbumListState(albumId, { status, ...(detail ? { note, source } : {}) }),
        onSuccess: async (next) => { hasDraft.current = false; setState(next); setNote(next.note ?? ''); setSource(next.source ?? ''); await invalidate(); },
    });
    const remove = useMutation({ mutationFn: () => api.removeAlbumListState(albumId), onSuccess: async () => { setState(state ? { ...state, status: 'removed', removed_at: new Date().toISOString() } : null); await invalidate(); } });
    const active = state?.status === 'want_to_listen' || state?.status === 'listened';
    const nextStatus = state?.status === 'want_to_listen' ? 'listened' : 'want_to_listen';
    const label = state?.status === 'want_to_listen' ? 'Mark listened' : state?.status === 'listened' ? 'Listen again' : 'Want to listen';
    const Icon = state?.status === 'want_to_listen' ? Check : state?.status === 'listened' ? RotateCcw : BookmarkPlus;

    if (iconOnly) {
        return (
            <div>
                <button
                    type="button"
                    aria-label={update.isPending ? 'Saving listening list' : label}
                    title={label}
                    disabled={update.isPending || remove.isPending}
                    onClick={() => update.mutate(nextStatus)}
                    className="grid size-8 place-items-center rounded-full bg-panel/90 text-ink shadow-sm outline-none transition hover:text-cobalt focus-visible:ring-2 focus-visible:ring-cobalt focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-55"
                >
                    <Icon className="size-4" aria-hidden="true" />
                </button>
                {(update.isError || remove.isError) && <span className="sr-only" role="alert">List state could not be saved.</span>}
            </div>
        );
    }

    return (
        <div className={detail ? 'contents' : ''}>
            <Button variant={active ? 'secondary' : 'default'} size={detail ? 'default' : 'sm'} disabled={update.isPending || remove.isPending} onClick={() => update.mutate(nextStatus)}><Icon className="size-4" />{update.isPending ? 'Saving...' : label}</Button>
            {detail && active && <details className="mt-4 basis-full max-w-xl"><summary className="min-h-11 cursor-pointer text-sm font-semibold text-cobalt">Private note and source</summary><div className="mt-3 grid gap-3"><Input value={source} onChange={(event) => { hasDraft.current = true; setSource(event.target.value); }} maxLength={255} placeholder="Who or what recommended it?" aria-label="Recommendation source" /><textarea value={note} onChange={(event) => { hasDraft.current = true; setNote(event.target.value); }} maxLength={2000} rows={3} placeholder="Private note" aria-label="Private note" className="rounded-xl border border-line bg-panel p-3 text-sm outline-none focus:ring-2 focus:ring-cobalt" /><div className="flex flex-wrap gap-2"><Button size="sm" variant="secondary" onClick={() => update.mutate(state.status as 'want_to_listen' | 'listened')} disabled={update.isPending}>Save note</Button><Button size="sm" variant="ghost" onClick={() => remove.mutate()} disabled={remove.isPending}><Trash2 className="size-4" />Remove</Button></div></div></details>}
            {(update.isError || remove.isError) && <p className={detail ? 'basis-full text-xs text-coral-deep' : 'mt-2 text-xs text-coral-deep'} role="alert">List state could not be saved.</p>}
        </div>
    );
}
