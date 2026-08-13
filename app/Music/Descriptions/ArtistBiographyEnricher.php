<?php

namespace App\Music\Descriptions;

use App\Models\CatalogEntity;
use App\Models\EntityNarrative;
use App\Models\ExternalIdentifier;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ArtistBiographyEnricher
{
    public function __construct(
        private readonly TheAudioDbClient $theAudioDb,
        private readonly WikimediaClient $wikimedia,
        private readonly MusicBrainzClient $musicBrainz,
    ) {}

    /** @return array{requested:int,theaudiodb:int,wikipedia:int,missing:int,failed:int} */
    public function enrichOwned(int $limit = 20, bool $force = false): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new RuntimeException('Invalid artist biography enrichment limit.');
        }
        $language = strtolower((string) config('services.wikimedia.language', 'en'));
        $languages = array_values(array_unique([$language, 'en']));
        $eligibleNarratives = fn ($query) => $query
            ->where('kind', 'description')
            ->whereIn('language', $languages);
        $readyAudioDb = function ($query) use ($eligibleNarratives): void {
            $eligibleNarratives($query);
            $query->where('provider_slug', 'theaudiodb')->where('status', 'ready');
        };
        $recentAttempt = function ($query) use ($eligibleNarratives): void {
            $eligibleNarratives($query);
            $query->where('fetched_at', '>=', now()->subDays(7));
        };
        $recentFailure = function ($query) use ($eligibleNarratives): void {
            $eligibleNarratives($query);
            $query->where('provider_slug', 'narrative_pipeline')
                ->where('status', 'failed')
                ->where('fetched_at', '>=', now()->subDays(7));
        };
        $lastAttempt = EntityNarrative::query()
            ->select('fetched_at')
            ->whereColumn('entity_id', 'catalog.entities.id')
            ->where('kind', 'description')
            ->whereIn('language', $languages)
            ->latest('fetched_at')
            ->limit(1);
        $uuidPattern = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';
        $entities = CatalogEntity::query()
            ->select('catalog.entities.*')
            ->addSelect(['last_narrative_attempt_at' => $lastAttempt])
            ->where('kind', 'agent')
            ->where('status', 'active')
            ->whereHas('agent')
            ->whereHas('identifiers', fn ($query) => $query
                ->where('namespace', 'musicbrainz.artist')
                ->where('status', 'active'))
            ->whereRaw("(select count(*) from catalog.external_identifiers where entity_id = catalog.entities.id and namespace = 'musicbrainz.artist' and status = 'active') = 1")
            ->whereRaw("exists (select 1 from catalog.external_identifiers where entity_id = catalog.entities.id and namespace = 'musicbrainz.artist' and status = 'active' and value ~* ?)", [$uuidPattern])
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('library.plex_entity_matches as artist_matches')
                ->join('library.plex_items as artist_items', 'artist_items.id', '=', 'artist_matches.plex_item_id')
                ->join('library.plex_item_guids as artist_guids', 'artist_guids.plex_item_id', '=', 'artist_items.id')
                ->join('catalog.external_identifiers as artist_identifiers', 'artist_identifiers.entity_id', '=', 'artist_matches.entity_id')
                ->whereColumn('artist_matches.entity_id', 'catalog.entities.id')
                ->whereRaw('lower(artist_guids.value) = lower(artist_identifiers.value)')
                ->where('artist_matches.match_scope', 'agent')
                ->where('artist_matches.status', 'confirmed')
                ->where('artist_matches.method', 'external_id')
                ->where('artist_items.item_type', 'artist')
                ->whereNull('artist_items.removed_at')
                ->where('artist_guids.namespace', 'mbid')
                ->whereRaw('artist_guids.value ~* ?', [$uuidPattern])
                ->whereRaw("(select count(distinct lower(valid_artist_guids.value)) from library.plex_item_guids as valid_artist_guids where valid_artist_guids.plex_item_id = artist_items.id and valid_artist_guids.namespace = 'mbid' and valid_artist_guids.value ~* ?) = 1", [$uuidPattern])
                ->where('artist_identifiers.namespace', 'musicbrainz.artist')
                ->where('artist_identifiers.status', 'active'))
            ->with([
                'identifiers' => fn ($query) => $query
                    ->select(['id', 'entity_id', 'namespace', 'value', 'status'])
                    ->where('namespace', 'musicbrainz.artist')
                    ->where('status', 'active'),
                'narratives' => function ($query) use ($eligibleNarratives): void {
                    $eligibleNarratives($query);
                    $query->select(['id', 'entity_id', 'provider_slug', 'kind', 'language', 'status', 'fetched_at']);
                },
            ]);
        if (! $force) {
            $entities->whereDoesntHave('narratives', $recentFailure)
                ->where(function ($query) use ($readyAudioDb, $recentAttempt): void {
                    $query->whereHas('narratives', function ($query) use ($readyAudioDb): void {
                        $readyAudioDb($query);
                        $query->where('fetched_at', '<', now()->subDays(30));
                    })->orWhere(function ($query) use ($readyAudioDb, $recentAttempt): void {
                        $query->whereDoesntHave('narratives', $readyAudioDb)
                            ->whereDoesntHave('narratives', $recentAttempt);
                    });
                });
        }
        $entities = $entities
            ->orderByRaw('last_narrative_attempt_at ASC NULLS FIRST')
            ->orderBy('catalog.entities.id')
            ->limit($limit)
            ->get();

        $counts = $this->emptyCounts();
        foreach ($entities as $entity) {
            if (! $this->shouldEnrich($entity, $force)) {
                continue;
            }
            $counts['requested']++;
            try {
                $provider = $this->enrich($entity);
                $this->clearFailure($entity);
                $provider === null ? $counts['missing']++ : $counts[$provider]++;
            } catch (Throwable $exception) {
                $counts['failed']++;
                $this->markFailed($entity);
                Log::warning('Artist biography enrichment failed.', [
                    'entity_id' => $entity->id,
                    'error_code' => class_basename($exception),
                ]);
            }
        }

        return $counts;
    }

    /** @return array{attempted:bool,status:string} */
    public function retryEntity(CatalogEntity $entity): array
    {
        return Cache::lock("disco:narrative-entity:{$entity->id}", 300)->block(2, function () use ($entity): array {
            $entity->unsetRelation('narratives')->load('narratives');
            if (! $this->shouldEnrich($entity, false)) {
                return ['attempted' => false, 'status' => 'cooldown'];
            }

            try {
                $provider = $this->enrich($entity);
                $this->clearFailure($entity);

                return ['attempted' => true, 'status' => $provider ?? 'missing'];
            } catch (Throwable $exception) {
                $this->markFailed($entity);
                Log::warning('Artist narrative retry failed.', [
                    'entity_id' => $entity->id,
                    'error_code' => class_basename($exception),
                ]);

                return ['attempted' => true, 'status' => 'failed'];
            }
        });
    }

    /** @return 'theaudiodb'|'wikipedia'|null */
    public function enrich(CatalogEntity $entity): ?string
    {
        if ($entity->kind !== 'agent' || $entity->status !== 'active' || ! $entity->agent()->exists()) {
            throw new RuntimeException('Artist biographies require an active artist.');
        }
        $mbid = $this->mbid($entity);
        if ($mbid === null) {
            throw new RuntimeException('Artist biography has no exact MusicBrainz artist identity.');
        }
        $hasExactPlexMatch = $entity->plexMatches()
            ->where('match_scope', 'agent')
            ->where('status', 'confirmed')
            ->where('method', 'external_id')
            ->whereHas('item', fn ($query) => $query->where('item_type', 'artist')->whereNull('removed_at'))
            ->with('item.guids')
            ->get()
            ->contains(function ($match) use ($mbid): bool {
                $mbids = $match->item->guids
                    ->where('namespace', 'mbid')
                    ->pluck('value')
                    ->filter(fn (mixed $value): bool => is_string($value) && Str::isUuid($value))
                    ->map(fn (string $value): string => strtolower($value))
                    ->unique()
                    ->values();

                return $mbids->count() === 1 && $mbids->first() === $mbid;
            });
        if (! $hasExactPlexMatch) {
            throw new RuntimeException('Artist biography has no active exact Plex match.');
        }
        $language = strtolower((string) config('services.wikimedia.language', 'en'));
        $artist = $this->theAudioDb->artist($mbid);
        $biography = $this->audioDbBiography($artist, $language);
        if ($biography !== null) {
            $id = (string) ($artist['idArtist'] ?? '');
            if (preg_match('/\A[0-9]+\z/', $id) !== 1) {
                throw new RuntimeException('TheAudioDB artist has no valid source identity.');
            }
            $this->store($entity, 'theaudiodb', $biography['language'], $biography['text'], "https://www.theaudiodb.com/artist/{$id}", $id, 'TheAudioDB terms of use', 'https://www.theaudiodb.com/docs_terms_of_use.php');

            return 'theaudiodb';
        }
        $this->markMissing($entity, 'theaudiodb', $language);

        [$title, $englishTitle, $qid] = $this->musicBrainzRelations($mbid, $language);
        if ($title === null && $qid !== null) {
            $title = $this->wikimedia->titleForWikidata($qid, $language);
        }
        $introduction = $title === null ? null : $this->wikimedia->introduction($title, $language);
        if ($introduction === null && $language !== 'en') {
            $fallbackTitle = $qid === null ? $englishTitle : $this->wikimedia->titleForWikidata($qid, 'en');
            $introduction = $fallbackTitle === null ? null : $this->wikimedia->introduction($fallbackTitle, 'en');
        }
        if ($introduction === null) {
            $this->markMissing($entity, 'wikipedia', $language);

            return null;
        }
        $this->store($entity, 'wikipedia', $introduction['language'], $introduction['text'], $introduction['source_url'], $introduction['external_id'], 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/');

        return 'wikipedia';
    }

    private function mbid(CatalogEntity $entity): ?string
    {
        $identifiers = ExternalIdentifier::query()
            ->where('entity_id', $entity->id)
            ->where('namespace', 'musicbrainz.artist')
            ->where('status', 'active')
            ->get(['value']);
        if ($identifiers->count() !== 1) {
            return null;
        }
        $mbid = $identifiers->first()?->value;

        return is_string($mbid) && Str::isUuid($mbid) ? strtolower($mbid) : null;
    }

    /** @param array<string, mixed>|null $artist
     * @return array{text:string,language:string}|null
     */
    private function audioDbBiography(?array $artist, string $language): ?array
    {
        if ($artist === null) {
            return null;
        }
        $fields = [
            'en' => 'strBiography',
            'de' => 'strBiographyDE',
            'fr' => 'strBiographyFR',
            'es' => 'strBiographyES',
            'it' => 'strBiographyIT',
            'pt' => 'strBiographyPT',
            'nl' => 'strBiographyNL',
            'pl' => 'strBiographyPL',
            'ru' => 'strBiographyRU',
        ];
        $candidates = array_values(array_unique(array_filter([$language, 'en'], fn (string $candidate): bool => isset($fields[$candidate]))));
        foreach ($candidates as $candidate) {
            $value = $artist[$fields[$candidate]] ?? null;
            $normalized = is_string($value) ? $this->normalize($value) : null;
            if ($normalized !== null) {
                return ['text' => $normalized, 'language' => $candidate];
            }
        }

        return null;
    }

    /** @return array{0:?string,1:?string,2:?string} */
    private function musicBrainzRelations(string $mbid, string $language): array
    {
        $payload = $this->musicBrainz->entity('artist', $mbid);
        $title = null;
        $englishTitle = null;
        $qid = null;
        foreach ($payload['relations'] ?? [] as $relation) {
            $type = is_array($relation) ? ($relation['type'] ?? null) : null;
            $url = is_array($relation) ? data_get($relation, 'url.resource') : null;
            if (! is_string($url)) {
                continue;
            }
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');
            if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
                continue;
            }
            if ($type === 'wikipedia' && $host === "{$language}.wikipedia.org" && str_starts_with($path, '/wiki/')) {
                $title = rawurldecode(substr($path, 6));
            }
            if ($type === 'wikipedia' && $host === 'en.wikipedia.org' && str_starts_with($path, '/wiki/')) {
                $englishTitle = rawurldecode(substr($path, 6));
            }
            if ($type === 'wikidata' && $host === 'www.wikidata.org' && preg_match('#\A/wiki/(Q[1-9][0-9]*)\z#i', $path, $matches) === 1) {
                $qid = strtoupper($matches[1]);
            }
        }

        return [$title, $englishTitle, $qid];
    }

    private function normalize(string $text): ?string
    {
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : Str::limit($text, 20_000, '');
    }

    private function store(CatalogEntity $entity, string $provider, string $language, string $body, string $sourceUrl, string $externalId, string $licenseName, string $licenseUrl): void
    {
        $body = $this->normalize($body);
        if ($body === null) {
            throw new RuntimeException('Artist biography was empty after normalization.');
        }
        DB::transaction(function () use ($body, $entity, $externalId, $language, $licenseName, $licenseUrl, $provider, $sourceUrl): void {
            EntityNarrative::query()
                ->where('entity_id', $entity->id)
                ->where('provider_slug', $provider)
                ->where('kind', 'description')
                ->where('language', '!=', $language)
                ->where('status', 'ready')
                ->update(['status' => 'stale', 'fetched_at' => now(), 'updated_at' => now()]);
            EntityNarrative::query()->updateOrCreate(
                ['entity_id' => $entity->id, 'provider_slug' => $provider, 'kind' => 'description', 'language' => $language],
                [
                    'status' => 'ready',
                    'body' => $body,
                    'source_url' => $sourceUrl,
                    'external_id' => $externalId,
                    'content_sha256' => hash('sha256', $body),
                    'license_name' => $licenseName,
                    'license_url' => $licenseUrl,
                    'fetched_at' => now(),
                ],
            );
        });
    }

    private function markMissing(CatalogEntity $entity, string $provider, string $language): void
    {
        EntityNarrative::query()
            ->where('entity_id', $entity->id)
            ->where('provider_slug', $provider)
            ->where('kind', 'description')
            ->get()
            ->each(function (EntityNarrative $narrative): void {
                $narrative->fill(['status' => $narrative->body === null ? 'missing' : 'stale', 'fetched_at' => now()])->save();
            });
        $narrative = EntityNarrative::query()->firstOrNew([
            'entity_id' => $entity->id,
            'provider_slug' => $provider,
            'kind' => 'description',
            'language' => $language,
        ]);
        if (! $narrative->exists) {
            $narrative->fill(['status' => 'missing', 'fetched_at' => now()])->save();
        }
    }

    private function markFailed(CatalogEntity $entity): void
    {
        EntityNarrative::query()->updateOrCreate(
            [
                'entity_id' => $entity->id,
                'provider_slug' => 'narrative_pipeline',
                'kind' => 'description',
                'language' => strtolower((string) config('services.wikimedia.language', 'en')),
            ],
            ['status' => 'failed', 'fetched_at' => now()],
        );
    }

    private function clearFailure(CatalogEntity $entity): void
    {
        EntityNarrative::query()
            ->where('entity_id', $entity->id)
            ->where('provider_slug', 'narrative_pipeline')
            ->where('kind', 'description')
            ->delete();
    }

    private function shouldEnrich(CatalogEntity $entity, bool $force): bool
    {
        if ($force) {
            return true;
        }
        $language = strtolower((string) config('services.wikimedia.language', 'en'));
        $eligible = $entity->narratives
            ->where('kind', 'description')
            ->whereIn('language', array_values(array_unique([$language, 'en'])));
        if ($eligible->where('provider_slug', 'narrative_pipeline')->where('status', 'failed')->where('fetched_at', '>=', now()->subDays(7))->isNotEmpty()) {
            return false;
        }
        $theAudioDb = $eligible
            ->where('provider_slug', 'theaudiodb')
            ->where('status', 'ready')
            ->sortBy(fn (EntityNarrative $narrative): int => $narrative->language === $language ? 0 : 1)
            ->first();
        if ($theAudioDb !== null) {
            return $theAudioDb->fetched_at?->lt(now()->subDays(30)) ?? true;
        }
        $lastAttempt = $eligible->max('fetched_at');

        return $lastAttempt === null || $lastAttempt->lt(now()->subDays(7));
    }

    /** @return array{requested:int,theaudiodb:int,wikipedia:int,missing:int,failed:int} */
    private function emptyCounts(): array
    {
        return ['requested' => 0, 'theaudiodb' => 0, 'wikipedia' => 0, 'missing' => 0, 'failed' => 0];
    }
}
