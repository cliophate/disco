<?php

namespace App\Jobs;

use App\Music\Discovery\ArtistDiscographyRefresher;
use App\Music\Discovery\ArtistDiscographyRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RefreshArtistDiscography implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3700;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $artistId)
    {
        $this->onConnection('redis-admin')->onQueue('admin');
    }

    public function uniqueId(): string
    {
        return $this->artistId;
    }

    public function handle(ArtistDiscographyRefresher $refresher, ArtistDiscographyRefreshService $refreshes): void
    {
        $refreshes->markRunning($this->artistId);
        try {
            $result = $refresher->refresh($this->artistId);
            $refreshes->markSucceeded($this->artistId, $result['generation_id']);
        } catch (Throwable $exception) {
            $refreshes->markFailed($this->artistId);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ArtistDiscographyRefreshService::class)->markFailed($this->artistId);
    }
}
