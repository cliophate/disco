<?php

namespace App\Music\Plex;

use Illuminate\Contracts\Cache\Lock;

class PlexPlaybackStreamLease
{
    private bool $released = false;

    /** @param list<Lock> $locks */
    public function __construct(private readonly array $locks, private readonly float $deadline) {}

    public function deadline(): float
    {
        return $this->deadline;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        foreach (array_reverse($this->locks) as $lock) {
            $lock->release();
        }
        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}
