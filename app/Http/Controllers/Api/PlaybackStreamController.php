<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlexMediaPart;
use App\Music\Plex\PlexClient;
use App\Music\Plex\PlexPlaybackSessionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlaybackStreamController extends Controller
{
    public function __invoke(Request $request, string $session, PlexPlaybackSessionService $sessions, PlexClient $client): Response
    {
        if ($request->isMethod('HEAD')) {
            abort(405, 'HEAD is not supported for playback streams.');
        }
        $part = $sessions->part($request->user(), $session);
        $range = $this->range($request->header('Range'), $part);
        if ($range === false) {
            return response('', 416, [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => "bytes */{$part->size_bytes}",
                'Cache-Control' => 'private, no-store',
            ]);
        }
        $sessions->reserveStreamRequest($request->user(), $session);

        $lease = $sessions->acquireStreamLease($request->user());
        $upstream = null;
        try {
            $upstream = $client->originalPart($part->part_key, $part->part_id, $range, $part->browserMimeType());
            $status = $upstream->status();
            if (! in_array($status, [200, 206, 416], true)
                || ($range !== null && $status === 200)
                || ($range === null && $status === 206)
                || ($range === null && $status === 416)
                || $upstream->header('Content-Encoding') !== '') {
                abort(502, 'Plex returned an invalid media response.');
            }

            $headers = $this->responseHeaders($upstream, $part, $status, $range);
            if ($status === 416) {
                $upstream->toPsrResponse()->getBody()->close();
                $lease->release();

                return response('', 416, $headers);
            }
            $body = $upstream->toPsrResponse()->getBody();
            $expectedBytes = (int) $headers['Content-Length'];
            $streamStart = $range === null ? 0 : (int) explode('-', substr($range, 6), 2)[0];
            $user = $request->user();

            return new StreamedResponse(function () use ($body, $expectedBytes, $lease, $session, $sessions, $streamStart, $user): void {
                $emitted = 0;
                try {
                    $remaining = $expectedBytes;
                    $streamMarked = false;
                    while ($remaining > 0 && ! $body->eof() && microtime(true) < $lease->deadline()) {
                        $chunk = $body->read(min(65536, $remaining));
                        if ($chunk === '') {
                            break;
                        }
                        if (! $streamMarked) {
                            $sessions->markStreamOpened($user, $session);
                            $streamMarked = true;
                        }
                        echo $chunk;
                        $bytes = strlen($chunk);
                        $remaining -= $bytes;
                        $emitted += $bytes;
                        if (connection_aborted()) {
                            break;
                        }
                    }
                } finally {
                    $body->close();
                    if ($emitted > 0) {
                        try {
                            $sessions->markStreamedRange($user, $session, $streamStart, $streamStart + $emitted - 1);
                        } catch (\Throwable) {
                            // Streaming remains safe; missing evidence only prevents scrobbling.
                        }
                    }
                    $lease->release();
                }
            }, $status, $headers);
        } catch (\Throwable $exception) {
            if ($upstream !== null) {
                $upstream->toPsrResponse()->getBody()->close();
            }
            $lease->release();

            throw $exception;
        }
    }

    private function range(?string $range, PlexMediaPart $part): string|false|null
    {
        if ($range === null || $range === '') {
            return null;
        }
        if (preg_match('/\Abytes=(?:(\d+)-(\d*)|-(\d+))\z/D', $range, $matches) !== 1) {
            return false;
        }
        $size = $part->size_bytes;
        if (($matches[3] ?? '') !== '') {
            $suffix = (int) $matches[3];
            if ($suffix < 1) {
                return false;
            }
            $start = max(0, $size - min($suffix, $size));

            return "bytes={$start}-".($size - 1);
        }
        $start = (int) $matches[1];
        $end = ($matches[2] ?? '') === '' ? $size - 1 : min((int) $matches[2], $size - 1);

        return $start < $size && $end >= $start ? "bytes={$start}-{$end}" : false;
    }

    /** @return array<string, string> */
    private function responseHeaders(\Illuminate\Http\Client\Response $upstream, PlexMediaPart $part, int $status, ?string $range): array
    {
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Type' => (string) $part->browserMimeType(),
            'X-Accel-Buffering' => 'no',
        ];
        $etag = $upstream->header('ETag');
        if (preg_match('/\A(?:W\/)?"[A-Za-z0-9._:-]{1,200}"\z/D', $etag) === 1) {
            $headers['ETag'] = $etag;
        }
        $lastModified = $upstream->header('Last-Modified');
        $parsedLastModified = \DateTimeImmutable::createFromFormat(DATE_RFC7231, $lastModified);
        if ($parsedLastModified !== false && $parsedLastModified->format(DATE_RFC7231) === $lastModified) {
            $headers['Last-Modified'] = $lastModified;
        }

        $contentLength = $upstream->header('Content-Length');
        if ($status === 200) {
            if ($contentLength !== '' && (preg_match('/\A[0-9]+\z/D', $contentLength) !== 1 || (int) $contentLength !== $part->size_bytes)) {
                abort(502, 'Plex returned an invalid media length.');
            }
            $headers['Content-Length'] = (string) $part->size_bytes;
        } elseif ($status === 206) {
            $contentRange = $upstream->header('Content-Range');
            if ($range === null || preg_match('/\Abytes=(\d+)-(\d+)\z/D', $range, $requested) !== 1
                || preg_match('/\Abytes (\d+)-(\d+)\/(\d+)\z/D', $contentRange, $returned) !== 1
                || $requested[1] !== $returned[1] || $requested[2] !== $returned[2]
                || (int) $returned[3] !== $part->size_bytes) {
                abort(502, 'Plex returned an invalid media range.');
            }
            $expectedLength = (int) $returned[2] - (int) $returned[1] + 1;
            if ($contentLength === '' || preg_match('/\A[0-9]+\z/D', $contentLength) !== 1 || (int) $contentLength !== $expectedLength) {
                abort(502, 'Plex returned an invalid partial media length.');
            }
            $headers['Content-Range'] = $contentRange;
            $headers['Content-Length'] = $contentLength;
        } else {
            $contentRange = $upstream->header('Content-Range');
            if ($contentRange !== '' && $contentRange !== "bytes */{$part->size_bytes}") {
                abort(502, 'Plex returned an invalid unsatisfied range.');
            }
            $headers['Content-Range'] = "bytes */{$part->size_bytes}";
        }

        return $headers;
    }
}
