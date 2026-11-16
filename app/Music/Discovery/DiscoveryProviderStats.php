<?php

namespace App\Music\Discovery;

use Illuminate\Http\Client\RequestException;
use Throwable;
use WeakMap;

class DiscoveryProviderStats
{
    public int $musicBrainzCalls = 0;

    public array $failures = [];

    private bool $active = false;

    private ?int $limit = null;

    private WeakMap $recorded;

    public function start(?int $limit = null): void
    {
        $this->active = true;
        $this->limit = $limit;
        $this->musicBrainzCalls = 0;
        $this->failures = [];
        $this->recorded = new WeakMap;
    }

    public function stop(): void
    {
        $this->active = false;
    }

    public function exhausted(): bool
    {
        return $this->active && $this->limit !== null && $this->musicBrainzCalls >= $this->limit;
    }

    public function musicBrainzCall(): void
    {
        if ($this->exhausted()) {
            throw new ProviderCallBudgetExceeded('Discovery MusicBrainz call budget exhausted.');
        }
        if ($this->active) {
            $this->musicBrainzCalls++;
        }
    }

    public function failure(string $endpoint, Throwable $exception): void
    {
        if (! $this->active || $exception instanceof ProviderCallBudgetExceeded || isset($this->recorded[$exception])) {
            return;
        }
        $this->recorded[$exception] = true;
        $category = $endpoint.':'.($exception instanceof RequestException ? $exception->response->status() : class_basename($exception));
        $this->failures[$category] = ($this->failures[$category] ?? 0) + 1;
    }
}
