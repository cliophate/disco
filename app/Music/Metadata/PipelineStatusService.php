<?php

namespace App\Music\Metadata;

use App\Models\ArtistDiscographyGeneration;
use App\Models\CreditEdge;
use App\Models\EditorialItem;
use App\Models\EntityMetadata;
use App\Models\EntityNarrative;
use App\Models\ExternalIdentifier;
use App\Models\ListenImportRun;
use App\Models\PlexItem;
use App\Models\PlexSyncRun;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use App\Models\UpcomingNotificationDelivery;
use App\Models\UpcomingReleaseGeneration;
use App\Music\Admin\ProviderCredentialResolver;
use App\Music\Descriptions\NarrativeCoverageReport;
use App\Music\Discovery\CatalogEnrichmentService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PipelineStatusService
{
    public function __construct(
        private readonly NarrativeCoverageReport $narratives,
        private readonly PipelineDiagnosticService $diagnostics,
        private readonly ProviderCredentialResolver $credentials,
        private readonly CatalogEnrichmentService $catalogEnrichment,
    ) {}

    /** @return list<array<string, mixed>> */
    public function summarize(): array
    {
        return [
            $this->plex(),
            $this->listenBrainz(),
            $this->musicBrainz(),
            $this->discogs(),
            $this->discographies(),
            $this->discographyArtwork(),
            $this->narratives(),
            $this->credits(),
            $this->upcoming(),
            $this->editorial(),
        ];
    }

    /** @return array<string, mixed> */
    private function plex(): array
    {
        $last = PlexSyncRun::query()->where('status', 'completed')->latest('completed_at')->first();
        $active = PlexItem::query()->whereNull('removed_at')->count();
        $status = $last?->completed_at === null ? 'idle' : ($last->completed_at->lt(now()->subHours(2)) ? 'attention' : 'healthy');

        return $this->row('plex', 'Plex library', 'Plex', $status,
            $last === null ? 'No completed library sync is recorded.' : 'The hourly library inventory is persisted and provider-free here.',
            'Hourly', $last?->completed_at, $this->nextEveryMinutes(60), [
                $this->metric('Active records', $active),
                $this->metric('Last run items', (int) collect($last?->counts ?? [])->sum()),
            ]);
    }

    /** @return array<string, mixed> */
    private function listenBrainz(): array
    {
        $enabled = filled(config('services.listenbrainz.username'))
            && $this->credentials->resolve('listenbrainz')['configured'];
        $last = ListenImportRun::query()->latest('started_at')->first();
        $lastSuccess = ListenImportRun::query()->where('status', 'completed')->latest('completed_at')->first();
        $status = ! $enabled ? 'disabled' : match (true) {
            $last === null => 'idle',
            $last->status !== 'completed' => 'attention',
            $lastSuccess?->completed_at?->lt(now()->subMinutes(45)) ?? true => 'attention',
            default => 'healthy',
        };

        return $this->row('listenbrainz', 'Listening history', 'ListenBrainz', $status,
            ! $enabled ? 'Credentials are not configured.' : 'Incremental imports run independently of page requests.',
            'Every 15 minutes', $lastSuccess?->completed_at, $this->nextEveryMinutes(15), [
                $this->metric('Imported listens', DB::table('activity.listening_events')->count()),
                $this->metric('Latest run', $last?->status ?? 'none'),
            ]);
    }

    /** @return array<string, mixed> */
    private function musicBrainz(): array
    {
        $eligibleIds = ExternalIdentifier::query()
            ->whereIn('namespace', ['musicbrainz.artist', 'musicbrainz.release'])
            ->where('status', 'active')
            ->whereHas('entity.plexMatches', fn (Builder $query) => $query
                ->whereIn('status', ['confirmed', 'candidate'])
                ->whereHas('item', fn (Builder $query) => $query->whereNull('removed_at')))
            ->distinct()->pluck('entity_id');
        $eligible = $eligibleIds->count();
        $metadata = EntityMetadata::query()->whereIn('entity_id', $eligibleIds)->where('source_provider', 'musicbrainz');
        $ready = (clone $metadata)->where('enriched_at', '>=', now()->subDays(30))->count();
        $stale = (clone $metadata)->where('enriched_at', '<', now()->subDays(30))->count();
        $pending = max(0, $eligible - $ready - $stale);

        return $this->row('musicbrainz', 'Catalog enrichment', 'MusicBrainz', $eligible === 0 ? 'idle' : ($pending + $stale > 0 ? 'building' : 'healthy'),
            'Owned artists and albums refresh on a 30-day freshness window.',
            'Daily at 03:00', (clone $metadata)->max('enriched_at'), $this->nextDaily('03:00'), [
                $this->metric('Fresh', $ready),
                $this->metric('Stale', $stale),
                $this->metric('Queued', $pending),
                $this->metric('Eligible', $eligible),
            ]);
    }

    /** @return array<string, mixed> */
    private function discogs(): array
    {
        $enabled = $this->credentials->resolve('discogs')['configured'];
        $counts = $this->diagnostics->counts('discogs');
        $status = ! $enabled ? 'disabled' : (($counts['failed'] + $counts['conflict']) > 0 ? 'attention' : ($counts['queued'] + $counts['stale'] > 0 ? 'building' : 'healthy'));

        return $this->row('discogs', 'Catalog cross-reference', 'Discogs', $status,
            ! $enabled ? 'A personal access token is not configured.' : 'Only exact MusicBrainz-linked catalog identities and approved fields are stored.',
            'Every 10 minutes', DB::table('source.discogs_enrichment_states')->max('attempted_at'), $this->nextEveryMinutes(10), [
                $this->metric('Exact', $counts['exact'], 'exact'),
                $this->metric('Fresh', $counts['fresh'], 'fresh'),
                $this->metric('Stale', $counts['stale'], 'stale'),
                $this->metric('Ambiguous', $counts['ambiguous'], 'ambiguous'),
                $this->metric('Missing', $counts['missing'], 'missing'),
                $this->metric('Conflicts', $counts['conflict'], 'conflict'),
                $this->metric('Failed', $counts['failed'], 'failed'),
                $this->metric('Queued', $counts['queued'], 'queued'),
                $this->metric('Eligible', $this->diagnostics->eligibleCount('discogs')),
            ]);
    }

    /** @return array<string, mixed> */
    private function discographies(): array
    {
        $counts = $this->diagnostics->counts('discographies');
        $eligible = $this->diagnostics->eligibleCount('discographies');

        return $this->row('discographies', 'Artist discographies', 'MusicBrainz', $eligible === 0 ? 'idle' : ($counts['queued'] + $counts['stale'] > 0 ? 'building' : 'healthy'),
            'The bootstrap processes two missing or expired artists per run.',
            'Every 15 minutes', ArtistDiscographyGeneration::query()->whereIn('artist_entity_id', $this->diagnostics->discographyArtistIds())->max('generated_at'), $this->nextEveryMinutes(15), [
                $this->metric('Fresh', $counts['fresh'], 'fresh'),
                $this->metric('Stale', $counts['stale'], 'stale'),
                $this->metric('Queued', $counts['queued'], 'queued'),
                $this->metric('Eligible', $eligible),
            ]);
    }

    /** @return array<string, mixed> */
    private function discographyArtwork(): array
    {
        $coverage = $this->catalogEnrichment->coverage();
        $diagnosticCounts = $this->diagnostics->counts('discography-artwork');
        $status = match (true) {
            $coverage['eligible'] === 0 && $diagnosticCounts['failed'] === 0 => 'idle',
            $diagnosticCounts['failed'] > 0 => 'attention',
            $coverage['remaining_due'] > 0 => 'building',
            default => 'healthy',
        };

        return $this->row('discography-artwork', 'Current catalog details and covers', 'MusicBrainz + Cover Art Archive', $status,
            'The drain covers personalized releases, Beyond recommendations, artist discographies, and then the broad release window.',
            'Every 10 minutes', $coverage['last_activity'], $this->nextEveryMinutes(10), [
                $this->metric('Ready artwork', $coverage['ready_artwork'], 'ready'),
                $this->metric('Detail due', $coverage['detail_due'], 'queued'),
                $this->metric('Failed', $diagnosticCounts['failed'], 'failed'),
                $this->metric('Artwork due', $coverage['artwork_due'], 'queued'),
                $this->metric('Remaining', $coverage['remaining_due'], 'queued'),
                $this->metric('Albums', $coverage['eligible']),
            ]);
    }

    /** @return array<string, mixed> */
    private function narratives(): array
    {
        $coverage = collect($this->narratives->generate()['coverage']);
        $eligible = (int) $coverage->sum('eligible');
        $ready = (int) $coverage->sum('ready');
        $stale = (int) $coverage->sum('stale');
        $failed = (int) $coverage->sum('failed');
        $unattempted = max(0, $eligible - $ready);

        return $this->row('narratives', 'Artist and album narratives', 'Approved text providers', $eligible === 0 ? 'idle' : ($failed > 0 ? 'attention' : ($unattempted + $stale > 0 ? 'building' : 'healthy')),
            'Freshness and attribution remain provider-specific.',
            'Daily, 04:45-05:45', EntityNarrative::query()->max('fetched_at'), $this->nextOfDaily(['04:45', '05:15', '05:45']), [
                $this->metric('Ready', $ready),
                $this->metric('Stale', $stale),
                $this->metric('Failed', $failed),
                $this->metric('Without fresh copy', $unattempted),
                $this->metric('Eligible', $eligible),
            ]);
    }

    /** @return array<string, mixed> */
    private function credits(): array
    {
        $eligible = ExternalIdentifier::query()->whereIn('namespace', [
            'musicbrainz.release_group', 'musicbrainz.release', 'musicbrainz.recording', 'musicbrainz.work',
        ])->where('status', 'active')->whereHas('entity', fn (Builder $query) => $query->where('status', 'active'))->count();
        $subjects = CreditEdge::query()->distinct()->count('subject_entity_id');
        $snapshots = SourceSnapshot::query()->where('parser_version', 'musicbrainz-credits-v1');
        $last = (clone $snapshots)->max('retrieved_at');
        $status = $eligible === 0 ? 'idle' : ($last === null ? 'building' : ($this->olderThan($last, now()->subDays(2)) ? 'attention' : 'healthy'));

        return $this->row('credits', 'Relationship credits', 'MusicBrainz', $status,
            'Zero-credit responses are represented by source snapshots, so edge count is not a completion count.',
            'Daily at 06:15', $last, $this->nextDaily('06:15'), [
                $this->metric('Eligible IDs', $eligible),
                $this->metric('Fresh snapshots', (clone $snapshots)->where('expires_at', '>', now())->count()),
                $this->metric('Credit subjects', $subjects),
                $this->metric('Edges', CreditEdge::query()->count()),
            ]);
    }

    /** @return array<string, mixed> */
    private function upcoming(): array
    {
        $generation = UpcomingReleaseGeneration::query()->latest('generated_at')->withCount('items')->first();
        $enabled = filled(config('services.listenbrainz.username'))
            && $this->credentials->resolve('listenbrainz')['configured'];
        $deliveries = UpcomingNotificationDelivery::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $status = ! $enabled ? 'disabled' : match (true) {
            $generation === null => 'idle',
            (int) ($deliveries['failed'] ?? 0) > 0 => 'attention',
            $generation->expires_at->lte(now()) => 'attention',
            default => 'healthy',
        };

        return $this->row('upcoming', 'Upcoming releases', 'ListenBrainz + MusicBrainz', $status,
            ! $enabled ? 'ListenBrainz is not configured.' : 'The latest immutable generation powers release discovery and notifications.',
            'Daily at 03:45', $generation?->generated_at, $this->nextDaily('03:45'), [
                $this->metric('Items', (int) ($generation?->items_count ?? 0)),
                $this->metric('Horizon', $generation === null ? 'none' : $generation->horizon_days.' days'),
                $this->metric('Alerts pending', (int) ($deliveries['pending'] ?? 0)),
                $this->metric('Alerts sending', (int) ($deliveries['sending'] ?? 0)),
                $this->metric('Alerts delivered', (int) ($deliveries['delivered'] ?? 0)),
                $this->metric('Alerts failed', (int) ($deliveries['failed'] ?? 0)),
                $this->metric('Alerts skipped', (int) ($deliveries['skipped'] ?? 0)),
            ]);
    }

    /** @return array<string, mixed> */
    private function editorial(): array
    {
        $enabled = (bool) config('discovery.editorial.pitchfork.enabled')
            || SourceProvider::query()->where('slug', 'pitchfork')->where('enabled', true)->exists();
        $fresh = EditorialItem::query()->where('expires_at', '>', now())->count();
        $stale = EditorialItem::query()->where('expires_at', '<=', now())->count();
        $last = EditorialItem::query()->max('retrieved_at');
        $status = ! $enabled ? 'disabled' : ($last === null ? 'idle' : ($fresh === 0 && $stale > 0 ? 'attention' : 'healthy'));

        return $this->row('editorial', 'Editorial feeds', 'Pitchfork RSS', $status,
            ! $enabled ? 'The optional RSS integration is disabled.' : 'Only approved RSS fields are cached; article pages are never scraped.',
            'Daily at 07:15', $last, $this->nextDaily('07:15'), [
                $this->metric('Fresh', $fresh),
                $this->metric('Expired', $stale),
            ]);
    }

    /** @param list<array{label:string,value:int|string,status?:string}> $metrics
     * @return array<string, mixed>
     */
    private function row(string $key, string $name, string $provider, string $status, string $detail, string $cadence, mixed $lastActivity, CarbonInterface $nextRun, array $metrics): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'provider' => $provider,
            'status' => $status,
            'detail' => $detail,
            'cadence' => $cadence,
            'last_activity_at' => $this->atom($lastActivity),
            'next_run_at' => $nextRun->toAtomString(),
            'metrics' => $metrics,
        ];
    }

    /** @return array{label:string,value:int|string,status?:string} */
    private function metric(string $label, int|string $value, ?string $status = null): array
    {
        return array_filter(compact('label', 'value', 'status'), fn (mixed $value): bool => $value !== null);
    }

    private function nextEveryMinutes(int $minutes): CarbonInterface
    {
        $next = now()->copy()->startOfMinute();
        $remainder = $next->minute % $minutes;

        return $next->addMinutes($remainder === 0 ? $minutes : $minutes - $remainder);
    }

    private function nextDaily(string $time): CarbonInterface
    {
        $next = now()->copy()->setTimeFromTimeString($time);

        return $next->lte(now()) ? $next->addDay() : $next;
    }

    /** @param list<string> $times */
    private function nextOfDaily(array $times): CarbonInterface
    {
        return collect($times)->map(fn (string $time) => $this->nextDaily($time))->sort()->first();
    }

    private function atom(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toAtomString();
        }

        return filled($value) ? Carbon::parse((string) $value)->toAtomString() : null;
    }

    private function olderThan(mixed $value, CarbonInterface $threshold): bool
    {
        return filled($value) && Carbon::parse((string) $value)->lt($threshold);
    }
}
