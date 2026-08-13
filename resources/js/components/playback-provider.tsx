import { Disc3, ListMusic, Pause, Play, SkipBack, SkipForward, Volume2, VolumeX, X } from 'lucide-react';
import { createContext, type ReactNode, useContext, useEffect, useEffectEvent, useRef, useState } from 'react';
import { api } from '../lib/api';
import type { ArtworkImage, PlaybackSession, PlaybackSource } from '../lib/types';
import { formatDuration } from '../lib/utils';
import { Button } from './ui/button';
import { OpenInPlexButton } from './open-in-plex-button';

export interface QueueTrack {
    id: string;
    title: string;
    artist: string;
    album: string;
    durationMs: number;
    artwork: ArtworkImage | null;
    plexItemId: string;
    source: PlaybackSource | null;
}

interface PlaybackContextValue {
    playQueue: (queue: QueueTrack[], index: number) => Promise<void>;
    addToQueue: (track: QueueTrack) => Promise<void>;
}

const PlaybackContext = createContext<PlaybackContextValue | null>(null);

const mimeSupport = new Map<string, boolean>();
const volumeStorageKey = 'disco-player-volume';

function initialVolume() {
    try {
        const raw = window.localStorage?.getItem(volumeStorageKey);
        const stored = raw === null ? null : Number(raw);
        if (stored !== null && Number.isFinite(stored) && stored >= 0 && stored <= 1) return stored;
    } catch {
        // Use full volume when browser storage is unavailable.
    }
    return 1;
}

export function playableSource(sources: PlaybackSource[]): PlaybackSource | null {
    return sources.find((source) => {
        if (!mimeSupport.has(source.mime_type)) {
            mimeSupport.set(source.mime_type, document.createElement('audio').canPlayType(source.mime_type) !== '');
        }
        return mimeSupport.get(source.mime_type);
    }) ?? null;
}

export function usePlayback() {
    const value = useContext(PlaybackContext);
    if (!value) throw new Error('Playback context is unavailable.');
    return value;
}

export function PlaybackProvider({ children }: { children: ReactNode }) {
    const audioRef = useRef<HTMLAudioElement>(null);
    const sessionRef = useRef<PlaybackSession | null>(null);
    const queueRef = useRef<QueueTrack[]>([]);
    const indexRef = useRef(-1);
    const requestRef = useRef(0);
    const startedRef = useRef(false);
    const reportChainRef = useRef<Promise<unknown>>(Promise.resolve());
    const playQueueRef = useRef<(queue: QueueTrack[], index: number) => Promise<void>>(async () => undefined);
    const addToQueueRef = useRef<(track: QueueTrack) => Promise<void>>(async () => undefined);
    const contextRef = useRef<PlaybackContextValue>({
        playQueue: (nextQueue, trackIndex) => playQueueRef.current(nextQueue, trackIndex),
        addToQueue: (track) => addToQueueRef.current(track),
    });
    const [queue, setQueue] = useState<QueueTrack[]>([]);
    const [index, setIndex] = useState(-1);
    const [playing, setPlaying] = useState(false);
    const [position, setPosition] = useState(0);
    const [duration, setDuration] = useState(0);
    const [queueOpen, setQueueOpen] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [volume, setVolume] = useState(initialVolume);
    const [muted, setMuted] = useState(false);
    const current = queue[index] ?? null;

    useEffect(() => {
        queueRef.current = queue;
        indexRef.current = index;
    }, [index, queue]);

    async function report(state: 'playing' | 'paused' | 'stopped' | 'ended') {
        const session = sessionRef.current;
        const audio = audioRef.current;
        if (!session || !audio) return true;
        const positionMs = audio.currentTime * 1000;
        const operation = reportChainRef.current.then(() => api.updatePlaybackSession(session.id, state, positionMs));
        reportChainRef.current = operation.catch(() => undefined);
        try {
            await operation;
            return true;
        } catch (reason) {
            if (state !== 'playing' && sessionRef.current?.id === session.id) setError(reason instanceof Error ? reason.message : 'Playback history could not be updated.');
            return false;
        }
    }

    async function closeSession() {
        const session = sessionRef.current;
        sessionRef.current = null;
        startedRef.current = false;
        if (!session) return;
        try {
            await api.destroyPlaybackSession(session.id);
        } catch {
            // The short-lived server session will expire if the final update cannot be delivered.
        }
    }

    async function start(trackIndex: number, nextQueue = queueRef.current) {
        const track = nextQueue[trackIndex];
        const audio = audioRef.current;
        if (!track || !audio) return;
        const request = ++requestRef.current;
        const closing = closeSession();
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
        await closing;
        setError(null);
        setPosition(0);
        setDuration(track.durationMs / 1000);
        setIndex(trackIndex);
        indexRef.current = trackIndex;
        if (!track.source) {
            await closing;
            if (request === requestRef.current) setError('This original format is not supported by the browser. Open the track in Plex instead.');
            return;
        }
        try {
            const session = await api.createPlaybackSession(track.source.id);
            if (request !== requestRef.current) {
                await api.destroyPlaybackSession(session.id);
                return;
            }
            sessionRef.current = session;
            audio.src = session.stream_url;
            audio.load();
            await audio.play();
        } catch (reason) {
            if (request === requestRef.current) {
                await closeSession();
                setPlaying(false);
                setError(reason instanceof Error ? reason.message : 'This track could not be played.');
            }
        }
    }

    async function playQueue(nextQueue: QueueTrack[], trackIndex: number) {
        if (nextQueue.length === 0 || !nextQueue[trackIndex]) return;
        queueRef.current = nextQueue;
        setQueue(nextQueue);
        await start(trackIndex, nextQueue);
    }
    playQueueRef.current = playQueue;

    async function addToQueue(track: QueueTrack) {
        if (queueRef.current.length === 0) {
            await playQueue([track], 0);
            return;
        }
        const nextQueue = [...queueRef.current, track];
        queueRef.current = nextQueue;
        setQueue(nextQueue);
    }
    addToQueueRef.current = addToQueue;

    async function next() {
        const nextIndex = indexRef.current + 1;
        if (nextIndex < queueRef.current.length) await start(nextIndex);
    }

    async function previous() {
        const audio = audioRef.current;
        if (audio && audio.currentTime > 5) {
            audio.currentTime = 0;
            setPosition(0);
            return;
        }
        if (indexRef.current > 0) await start(indexRef.current - 1);
    }

    async function stop() {
        requestRef.current++;
        const closing = closeSession();
        const audio = audioRef.current;
        audio?.pause();
        audio?.removeAttribute('src');
        audio?.load();
        await closing;
        queueRef.current = [];
        indexRef.current = -1;
        setQueue([]);
        setIndex(-1);
        setPlaying(false);
        setQueueOpen(false);
        setError(null);
    }

    function toggleMuted() {
        if (volume === 0) {
            setVolume(0.5);
            setMuted(false);
            return;
        }
        setMuted((value) => !value);
    }

    const reportEvent = useEffectEvent(report);
    const nextEvent = useEffectEvent(next);
    const closeSessionEvent = useEffectEvent(closeSession);

    useEffect(() => {
        const audio = audioRef.current;
        if (!audio) return;
        const onPlay = () => { startedRef.current = true; setPlaying(true); };
        const onPlaying = () => { void reportEvent('playing'); };
        const onPause = () => { setPlaying(false); if (startedRef.current && !audio.ended && sessionRef.current) void reportEvent('paused'); };
        const onTime = () => setPosition(audio.currentTime);
        const onDuration = () => setDuration(Number.isFinite(audio.duration) ? audio.duration : (queueRef.current[indexRef.current]?.durationMs ?? 0) / 1000);
        const onEnded = async () => {
            setPlaying(false);
            const generation = requestRef.current;
            const historyUpdated = await reportEvent('ended');
            if (generation !== requestRef.current) return;
            if (!historyUpdated) {
                await closeSessionEvent();
                return;
            }
            if (indexRef.current + 1 < queueRef.current.length) await nextEvent();
            else await closeSessionEvent();
        };
        const onError = () => { void closeSessionEvent(); setError('The original file could not be decoded by this browser. Open it in Plex instead.'); };
        audio.addEventListener('play', onPlay);
        audio.addEventListener('playing', onPlaying);
        audio.addEventListener('pause', onPause);
        audio.addEventListener('timeupdate', onTime);
        audio.addEventListener('durationchange', onDuration);
        audio.addEventListener('ended', onEnded);
        audio.addEventListener('error', onError);
        return () => {
            audio.removeEventListener('play', onPlay);
            audio.removeEventListener('playing', onPlaying);
            audio.removeEventListener('pause', onPause);
            audio.removeEventListener('timeupdate', onTime);
            audio.removeEventListener('durationchange', onDuration);
            audio.removeEventListener('ended', onEnded);
            audio.removeEventListener('error', onError);
        };
    }, []);

    useEffect(() => {
        const onPageHide = () => { void closeSessionEvent(); };
        window.addEventListener('pagehide', onPageHide);
        return () => {
            window.removeEventListener('pagehide', onPageHide);
            void closeSessionEvent();
        };
    }, []);

    useEffect(() => {
        if (!playing) return;
        const timer = window.setInterval(() => { void reportEvent('playing'); }, 15_000);
        return () => window.clearInterval(timer);
    }, [playing]);

    useEffect(() => {
        const audio = audioRef.current;
        if (audio) {
            audio.volume = volume;
            audio.muted = muted;
        }
        try { window.localStorage?.setItem(volumeStorageKey, String(volume)); } catch { /* Volume still works for this session. */ }
    }, [muted, volume]);

    useEffect(() => {
        if (!current || !('mediaSession' in navigator)) return;
        navigator.mediaSession.metadata = new MediaMetadata({
            title: current.title,
            artist: current.artist,
            album: current.album,
            artwork: current.artwork ? [{ src: current.artwork.url }] : [],
        });
        navigator.mediaSession.setActionHandler('play', () => { void audioRef.current?.play(); });
        navigator.mediaSession.setActionHandler('pause', () => audioRef.current?.pause());
        navigator.mediaSession.setActionHandler('previoustrack', () => { void previous(); });
        navigator.mediaSession.setActionHandler('nexttrack', () => { void next(); });
        navigator.mediaSession.setActionHandler('seekto', (details) => {
            if (audioRef.current && details.seekTime !== undefined) audioRef.current.currentTime = details.seekTime;
        });
        return () => {
            for (const action of ['play', 'pause', 'previoustrack', 'nexttrack', 'seekto'] as MediaSessionAction[]) {
                try { navigator.mediaSession.setActionHandler(action, null); } catch { /* Unsupported actions are optional. */ }
            }
        };
    }, [current]);

    return (
        <PlaybackContext.Provider value={contextRef.current}>
            {children}
            <audio ref={audioRef} preload="metadata" className="hidden" />
            {current && <div className="h-32 sm:h-24" aria-hidden="true" />}
            {current && (
                <section className="fixed inset-x-0 bottom-0 z-[70] border-t border-line bg-paper shadow-[0_-12px_40px_rgb(25_32_51/0.16)] sm:bg-paper/98 sm:backdrop-blur-xl" aria-label="Audio player">
                    {queueOpen && <div className="absolute inset-x-0 bottom-full max-h-[min(28rem,65vh)] overflow-y-auto border-y border-line bg-panel shadow-2xl"><div className="site-frame page-gutter mx-auto py-4"><div className="mb-3 flex items-center justify-between"><p className="editorial-eyebrow text-cobalt">Queue · {queue.length} tracks</p><Button variant="ghost" size="icon" onClick={() => setQueueOpen(false)} aria-label="Close queue"><X className="size-4" /></Button></div><ol className="divide-y divide-line">{queue.map((track, trackIndex) => <li key={`${track.id}-${trackIndex}`}><button type="button" onClick={() => void start(trackIndex)} className="grid min-h-14 w-full grid-cols-[2rem_minmax(0,1fr)_auto] items-center gap-3 px-2 text-left outline-none hover:bg-raised focus-visible:ring-2 focus-visible:ring-cobalt"><span className="font-mono text-xs text-fog">{String(trackIndex + 1).padStart(2, '0')}</span><span className="min-w-0"><span className="block truncate text-sm font-semibold">{track.title}</span><span className="block truncate text-xs text-fog">{track.artist}</span></span><span className="font-mono text-xs text-fog">{formatDuration(track.durationMs)}</span></button></li>)}</ol></div></div>}
                    <div className="site-frame page-gutter mx-auto grid min-h-24 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 py-3 sm:grid-cols-[minmax(10rem,1fr)_minmax(16rem,28rem)_minmax(10rem,1fr)] lg:grid-cols-[minmax(12rem,1fr)_minmax(20rem,32rem)_minmax(12rem,1fr)]">
                        <div className="flex min-w-0 items-center gap-3">{current.artwork ? <img src={current.artwork.url} alt="" className="size-12 shrink-0 object-cover sm:size-14" /> : <span className="grid size-12 shrink-0 place-items-center bg-raised sm:size-14"><Disc3 className="size-5 text-fog" /></span>}<div className="min-w-0"><p className="truncate text-sm font-bold">{current.title}</p><p className="truncate text-xs text-fog">{current.artist} · {current.album}</p>{current.source && <p className="mt-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-cobalt">Original {current.source.codec?.toUpperCase() ?? current.source.container?.toUpperCase()} · Direct play</p>}</div></div>
                        <div className="row-span-2 flex items-center justify-center gap-1 sm:row-span-1"><Button variant="ghost" size="icon" onClick={() => void previous()} aria-label="Previous track"><SkipBack className="size-4" /></Button><Button size="icon" disabled={!current.source} onClick={() => playing ? audioRef.current?.pause() : sessionRef.current ? void audioRef.current?.play() : void start(index)} aria-label={playing ? 'Pause' : 'Play'}>{playing ? <Pause className="size-5" /> : <Play className="ml-0.5 size-5" />}</Button><Button variant="ghost" size="icon" onClick={() => void next()} disabled={index >= queue.length - 1} aria-label="Next track"><SkipForward className="size-4" /></Button></div>
                        <div className="hidden min-w-0 items-center justify-end gap-1 sm:flex"><Button variant="ghost" size="icon" onClick={toggleMuted} aria-label={muted || volume === 0 ? 'Unmute' : 'Mute'}>{muted || volume === 0 ? <VolumeX className="size-4" /> : <Volume2 className="size-4" />}</Button><input type="range" min={0} max={1} step={0.05} value={volume} onChange={(event) => { const nextVolume = Number(event.target.value); setVolume(nextVolume); if (nextVolume > 0) setMuted(false); }} className="hidden h-5 w-20 accent-cobalt lg:block" aria-label="Volume" /><Button variant="ghost" size="icon" onClick={() => setQueueOpen((open) => !open)} aria-expanded={queueOpen} aria-label="Show queue"><ListMusic className="size-4" /></Button><Button variant="ghost" size="icon" onClick={() => void stop()} aria-label="Close player"><X className="size-4" /></Button></div>
                        <div className="col-span-2 mt-2 flex min-w-0 items-center gap-2 sm:col-span-1 sm:col-start-2 sm:mt-0"><span className="w-9 text-right font-mono text-[10px] text-fog">{formatDuration(position * 1000)}</span><input type="range" min={0} max={Math.max(duration, 1)} step={0.1} value={Math.min(position, Math.max(duration, 1))} onChange={(event) => { if (audioRef.current) audioRef.current.currentTime = Number(event.target.value); }} className="h-5 min-w-0 flex-1 accent-cobalt" aria-label="Seek" /><span className="w-9 font-mono text-[10px] text-fog">{formatDuration(duration * 1000)}</span></div>
                        <div className="col-span-2 flex items-center justify-end sm:hidden"><Button variant="ghost" size="icon" onClick={toggleMuted} aria-label={muted || volume === 0 ? 'Unmute' : 'Mute'}>{muted || volume === 0 ? <VolumeX className="size-4" /> : <Volume2 className="size-4" />}</Button><Button variant="ghost" size="sm" onClick={() => setQueueOpen((open) => !open)}><ListMusic className="size-4" />Queue</Button><Button variant="ghost" size="icon" onClick={() => void stop()} aria-label="Close player"><X className="size-4" /></Button></div>
                        {error && <div role="alert" className="col-span-full mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-coral"><span>{error}</span>{!current.source && <OpenInPlexButton plexItemId={current.plexItemId} label="Open track in Plex" compact />}</div>}
                    </div>
                </section>
            )}
        </PlaybackContext.Provider>
    );
}
