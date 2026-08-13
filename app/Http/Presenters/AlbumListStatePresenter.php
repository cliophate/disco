<?php

namespace App\Http\Presenters;

use App\Music\Personal\AlbumListService;

class AlbumListStatePresenter
{
    public function __construct(private readonly AlbumListService $lists) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function overlay(array $payload, string $userId): array
    {
        $ids = [];
        $this->collectIds($payload, $ids);
        $states = $this->lists->statesFor($userId, array_keys($ids));

        return $this->apply($payload, $states);
    }

    private function collectIds(array $value, array &$ids): void
    {
        if ($this->isAlbum($value)) {
            $ids[$value['id']] = true;
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectIds($child, $ids);
            }
        }
    }

    private function apply(array $value, array $states): array
    {
        if ($this->isAlbum($value)) {
            $value['list_state'] = $states[$value['id']] ?? null;
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->apply($child, $states);
            }
        }

        return $value;
    }

    private function isAlbum(array $value): bool
    {
        return is_string($value['id'] ?? null) && array_key_exists('owned', $value) && array_key_exists('open_in_plex_status', $value) && is_string($value['title'] ?? null);
    }
}
