<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ArtistFollow;
use App\Music\CanonicalEntityResolver;
use App\Music\Discovery\ArtistPreferencePolicy;
use App\Music\Discovery\ArtistSeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ArtistFollowController extends Controller
{
    public function update(Request $request, string $id, CanonicalEntityResolver $resolver, ArtistSeedService $seeds, ArtistPreferencePolicy $preferences): JsonResponse
    {
        $artist = $resolver->resolve($id, 'agent');
        abort_if($artist === null || ! Agent::query()->whereKey($artist->id)->exists(), 404);
        abort_unless($preferences->allows($artist), 422, 'Special-purpose artists cannot be preference seeds.');
        $userId = (string) $request->user()->id;
        DB::transaction(function () use ($artist, $resolver, $userId): void {
            DB::statement('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["artist-follow:{$userId}:{$artist->id}"]);
            $this->deleteRedirectedDuplicates($userId, $artist->id, $resolver);
            ArtistFollow::query()->firstOrCreate(['user_id' => $userId, 'artist_entity_id' => $artist->id]);
        });

        return response()->json(['data' => ['artist_id' => $artist->id, ...$seeds->state($userId, $artist->id)]]);
    }

    public function destroy(Request $request, string $id, CanonicalEntityResolver $resolver): Response
    {
        $artist = $resolver->resolve($id, 'agent');
        abort_if($artist === null, 404);
        $this->deleteRedirectedDuplicates((string) $request->user()->id, $artist->id, $resolver, true);

        return response()->noContent();
    }

    private function deleteRedirectedDuplicates(string $userId, string $canonicalId, CanonicalEntityResolver $resolver, bool $includeCanonical = false): void
    {
        ArtistFollow::query()->where('user_id', $userId)->get()->each(function (ArtistFollow $follow) use ($canonicalId, $includeCanonical, $resolver): void {
            $resolved = $resolver->resolve($follow->artist_entity_id, 'agent');
            if ($resolved?->id === $canonicalId && ($includeCanonical || $follow->artist_entity_id !== $canonicalId)) {
                $follow->delete();
            }
        });
    }
}
