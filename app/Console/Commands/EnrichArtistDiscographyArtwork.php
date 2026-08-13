<?php

namespace App\Console\Commands;

use App\Music\Discovery\ArtistDiscographyArtworkEnricher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EnrichArtistDiscographyArtwork extends Command
{
    protected $signature = 'disco:discography-artwork
        {--artist= : Exact canonical artist entity UUID}
        {--limit=10 : Maximum release groups to inspect}
        {--refresh : Bypass normal artwork cooldowns}';

    protected $description = 'Cache Cover Art Archive images for exact artist discography albums';

    public function handle(ArtistDiscographyArtworkEnricher $enricher): int
    {
        $lock = Cache::lock('disco:discography-artwork', 3900);
        if (! $lock->get()) {
            $this->warn('Another discography artwork enrichment is already running.');

            return self::FAILURE;
        }

        try {
            return $this->enrich($enricher);
        } finally {
            $lock->release();
        }
    }

    private function enrich(ArtistDiscographyArtworkEnricher $enricher): int
    {
        $limit = (int) $this->option('limit');
        $artist = $this->option('artist');
        if ($limit < 1 || $limit > 50 || ($artist !== null && ! Str::isUuid((string) $artist))) {
            $this->error('Limit must be between 1 and 50 and artist must be an exact UUID.');

            return self::INVALID;
        }
        $counts = $enricher->enrich($limit, $artist === null ? null : (string) $artist, (bool) $this->option('refresh'));
        $this->table(array_keys($counts), [array_values($counts)]);

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
