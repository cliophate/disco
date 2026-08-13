import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '../lib/api';
import { PlaybackProvider, type QueueTrack, usePlayback } from './playback-provider';

vi.mock('../lib/api', () => ({ api: {
    createPlaybackSession: vi.fn(),
    updatePlaybackSession: vi.fn(),
    destroyPlaybackSession: vi.fn(),
} }));

const track: QueueTrack = {
    id: 'track-1',
    title: 'Introvert',
    artist: 'Little Simz',
    album: 'Sometimes I Might Be Introvert',
    durationMs: 5000,
    artwork: null,
    plexItemId: 'plex-track-1',
    source: { id: 'part-1', mime_type: 'audio/flac', container: 'flac', codec: 'flac', channels: 2, bit_depth: 24, sample_rate_hz: 96000 },
};
const nextTrack: QueueTrack = { ...track, id: 'track-2', title: 'Woman', plexItemId: 'plex-track-2', source: { ...track.source!, id: 'part-2' } };

function Harness() {
    const playback = usePlayback();
    return <><button onClick={() => void playback.playQueue([track], 0)}>Start fixture</button><button onClick={() => void playback.addToQueue(nextTrack)}>Add fixture next</button></>;
}

describe('PlaybackProvider', () => {
    beforeEach(() => {
        window.localStorage.clear();
        vi.mocked(api.createPlaybackSession).mockResolvedValue({ id: 'session', stream_url: '/api/v1/playback/sessions/session/stream', expires_at: '2026-07-28T20:00:00Z' });
        vi.mocked(api.updatePlaybackSession).mockResolvedValue({ state: 'playing', position_ms: 0, scrobbled: false });
        vi.mocked(api.destroyPlaybackSession).mockResolvedValue(undefined);
        vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => undefined);
        vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('play')); this.dispatchEvent(new Event('playing')); return Promise.resolve(); });
        vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('pause')); });
        vi.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('probably');
    });
    afterEach(() => { cleanup(); vi.restoreAllMocks(); });

    it('creates a direct-play session and keeps a queue-backed global player', async () => {
        render(<PlaybackProvider><Harness /></PlaybackProvider>);

        fireEvent.click(screen.getByRole('button', { name: 'Start fixture' }));

        expect(await screen.findByRole('region', { name: 'Audio player' })).toBeVisible();
        expect(screen.getByText('Introvert')).toBeVisible();
        expect(screen.getByText(/Original FLAC · Direct play/)).toBeVisible();
        await waitFor(() => expect(api.createPlaybackSession).toHaveBeenCalledWith('part-1'));
        await waitFor(() => expect(api.updatePlaybackSession).toHaveBeenCalledWith('session', 'playing', 0));
        expect(api.updatePlaybackSession).not.toHaveBeenCalledWith('session', 'paused', expect.any(Number));
        fireEvent.click(screen.getByRole('button', { name: 'Pause' }));
        await waitFor(() => expect(api.updatePlaybackSession).toHaveBeenCalledWith('session', 'paused', 0));
        fireEvent.change(screen.getByRole('slider', { name: 'Volume' }), { target: { value: '0.4' } });
        await waitFor(() => expect(document.querySelector('audio')?.volume).toBe(0.4));
        fireEvent.click(screen.getByRole('button', { name: 'Add fixture next' }));
        fireEvent.click(screen.getByRole('button', { name: 'Show queue' }));
        expect(screen.getByText('Queue · 2 tracks')).toBeVisible();
        expect(screen.getByText('Woman')).toBeVisible();
        fireEvent.click(screen.getAllByRole('button', { name: 'Close player' })[0]);
        await waitFor(() => expect(api.destroyPlaybackSession).toHaveBeenCalledWith('session'));
        expect(document.querySelector('audio')).not.toHaveAttribute('src');
    });
});
