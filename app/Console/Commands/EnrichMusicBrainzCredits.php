<?php

namespace App\Console\Commands;

use App\Models\ExternalIdentifier;
use App\Music\MusicBrainz\MusicBrainzCreditEnricher;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnrichMusicBrainzCredits extends Command
{
    protected $signature = 'disco:credits {--limit=20 : Maximum catalog subjects to process} {--refresh : Ignore fresh source snapshots}';

    protected $description = 'Project typed MusicBrainz album, recording, and work credits into the catalog graph';

    public function handle(MusicBrainzCreditEnricher $enricher): int
    {
        $limit = min(100, max(1, (int) $this->option('limit')));
        $namespaces = ['musicbrainz.release_group', 'musicbrainz.release', 'musicbrainz.recording', 'musicbrainz.work'];
        $perNamespace = max(1, intdiv($limit, count($namespaces)));
        $identifiers = collect($namespaces)->flatMap(fn (string $namespace) => $this->candidates($namespace, $perNamespace));
        if ($identifiers->count() < $limit) {
            $identifiers = $identifiers->concat(ExternalIdentifier::query()
                ->whereIn('namespace', $namespaces)
                ->whereNotIn('id', $identifiers->pluck('id'))
                ->where('status', 'active')
                ->whereHas('entity', fn ($query) => $query->where('status', 'active'))
                ->orderBy('updated_at')
                ->orderBy('id')
                ->limit($limit - $identifiers->count())
                ->get());
        }
        $counts = ['processed' => 0, 'edges' => 0, 'missing' => 0, 'failed' => 0];
        foreach ($identifiers as $identifier) {
            try {
                $counts['edges'] += $enricher->enrich($identifier, (bool) $this->option('refresh'));
                $counts['processed']++;
            } catch (RequestException $exception) {
                if ($exception->response->status() === 404) {
                    $counts['missing']++;
                } else {
                    $counts['failed']++;
                    $this->logFailure($identifier, $exception);
                }
            } catch (Throwable $exception) {
                $counts['failed']++;
                $this->logFailure($identifier, $exception);
            } finally {
                $identifier->touch();
            }
        }
        $this->table(array_keys($counts), [array_values($counts)]);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function candidates(string $namespace, int $limit): Collection
    {
        return ExternalIdentifier::query()
            ->where('namespace', $namespace)
            ->where('status', 'active')
            ->whereHas('entity', fn ($query) => $query->where('status', 'active'))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function logFailure(ExternalIdentifier $identifier, Throwable $exception): void
    {
        Log::warning('MusicBrainz credit enrichment failed.', [
            'entity_id' => $identifier->entity_id,
            'error_code' => class_basename($exception),
        ]);
    }
}
