<?php

namespace App\Console\Commands;

use App\Models\ExternalIdentifier;
use App\Music\MusicBrainz\MusicBrainzEnricher;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnrichMusicBrainz extends Command
{
    protected $signature = 'disco:musicbrainz-enrich
        {--type=all : all, artist, or album}
        {--limit=0 : Maximum entities; zero means all}
        {--force : Refresh entities enriched within the last 30 days}';

    protected $description = 'Enrich owned artists and albums from MusicBrainz';

    public function handle(MusicBrainzEnricher $enricher): int
    {
        $lock = Cache::lock('disco:musicbrainz-enrich', 3900);
        if (! $lock->get()) {
            $this->warn('Another MusicBrainz enrichment is already running.');

            return self::FAILURE;
        }

        try {
            return $this->enrich($enricher);
        } finally {
            $lock->release();
        }
    }

    private function enrich(MusicBrainzEnricher $enricher): int
    {
        $type = (string) $this->option('type');
        $namespaces = match ($type) {
            'all' => ['musicbrainz.artist', 'musicbrainz.release'],
            'artist' => ['musicbrainz.artist'],
            'album' => ['musicbrainz.release'],
            default => [],
        };
        if ($namespaces === []) {
            $this->error('Type must be all, artist, or album.');

            return self::INVALID;
        }

        $query = ExternalIdentifier::query()
            ->whereIn('namespace', $namespaces)
            ->where('status', 'active')
            ->whereHas('entity.plexMatches', fn ($match) => $match
                ->whereIn('status', ['confirmed', 'candidate'])
                ->whereHas('item', fn ($item) => $item->whereNull('removed_at')))
            ->with(['entity.metadata'])
            ->orderBy('namespace')
            ->orderBy('value');
        if (! $this->option('force')) {
            $query->whereHas('entity', fn ($entity) => $entity
                ->whereDoesntHave('metadata')
                ->orWhereHas('metadata', fn ($metadata) => $metadata->where('enriched_at', '<', now()->subDays(30))));
        }
        $limit = max(0, (int) $this->option('limit'));
        $identifiers = $limit > 0 ? $query->limit($limit)->get() : $query->get();
        $succeeded = 0;
        $failed = 0;
        foreach ($identifiers as $index => $identifier) {
            try {
                $enricher->enrich($identifier);
                $succeeded++;
            } catch (Throwable $exception) {
                Log::warning('MusicBrainz enrichment failed.', [
                    'entity_id' => $identifier->entity_id,
                    'namespace' => $identifier->namespace,
                    'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                    'error_code' => class_basename($exception),
                ]);
                $failed++;
            }
        }

        $this->table(['Requested', 'Enriched', 'Failed'], [[
            $identifiers->count(), $succeeded, $failed,
        ]]);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
