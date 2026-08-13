<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogEntityArtwork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntityArtworkController extends Controller
{
    public function __invoke(Request $request, CatalogEntityArtwork $artwork, string $checksum): StreamedResponse
    {
        abort_unless(
            in_array($artwork->status, ['ready', 'stale'], true)
            && $artwork->content_sha256 !== null
            && hash_equals($artwork->content_sha256, $checksum)
            && $artwork->storage_key !== null
            && $artwork->mime_type === 'image/webp'
            && preg_match('/\Acover-art-archive\/[a-f0-9]{2}\/[a-f0-9]{64}\.webp\z/', $artwork->storage_key) === 1
            && $artwork->entity()->where('status', 'active')->exists()
            && Storage::disk('artwork')->exists($artwork->storage_key),
            404,
        );

        $etag = '"'.$artwork->content_sha256.'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->stream(fn () => null, 304, [
                'Cache-Control' => 'private, max-age=3600, must-revalidate',
                'ETag' => $etag,
            ]);
        }

        return response()->stream(function () use ($artwork): void {
            $stream = Storage::disk('artwork')->readStream($artwork->storage_key);
            abort_if($stream === false, 404);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Cache-Control' => 'private, max-age=3600, must-revalidate',
            'Content-Length' => (string) $artwork->size_bytes,
            'Content-Type' => (string) $artwork->mime_type,
            'ETag' => $etag,
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
