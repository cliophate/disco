<?php

namespace App\Music\Personal;

use App\Models\AlbumListItem;
use App\Music\CanonicalEntityResolver;
use Illuminate\Support\Facades\DB;

class AlbumListService
{
    public function __construct(private readonly CanonicalEntityResolver $resolver) {}

    /** @param array{status:string,note?:?string,source?:?string} $attributes */
    public function update(string $userId, string $albumId, array $attributes): AlbumListItem
    {
        $album = $this->resolver->resolve($albumId, 'release_group');
        abort_if($album === null || ! $album->releaseGroup()->exists(), 404);

        return DB::transaction(function () use ($album, $attributes, $userId): AlbumListItem {
            $this->lockUser($userId);
            DB::statement('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["album-list:{$userId}:{$album->id}"]);
            $this->normalizeLocked($userId);
            $item = AlbumListItem::query()->firstOrNew(['user_id' => $userId, 'release_group_entity_id' => $album->id]);
            $statusChanged = ! $item->exists || $item->status !== $attributes['status'];
            $now = now();
            if ($statusChanged) {
                $item->status = $attributes['status'];
                $item->state_changed_at = $now;
                $item->removed_at = null;
                if ($item->status === 'want_to_listen') {
                    $item->wanted_at = $now;
                } else {
                    $item->listened_at = $now;
                }
            }
            foreach (['note', 'source'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $value = $attributes[$field];
                    $item->{$field} = is_string($value) && trim($value) !== '' ? trim($value) : null;
                }
            }
            $item->save();

            return $item->refresh();
        });
    }

    public function remove(string $userId, string $albumId): void
    {
        $album = $this->resolver->resolve($albumId, 'release_group');
        abort_if($album === null, 404);
        DB::transaction(function () use ($album, $userId): void {
            $this->lockUser($userId);
            DB::statement('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["album-list:{$userId}:{$album->id}"]);
            $this->normalizeLocked($userId);
            $item = AlbumListItem::query()->where('user_id', $userId)->where('release_group_entity_id', $album->id)->first();
            if ($item !== null && $item->status !== 'removed') {
                $now = now();
                $item->update(['status' => 'removed', 'removed_at' => $now, 'state_changed_at' => $now]);
            }
        });
    }

    /** @param list<string> $albumIds
     * @return array<string, array<string, mixed>>
     */
    public function statesFor(string $userId, array $albumIds): array
    {
        return DB::transaction(function () use ($albumIds, $userId): array {
            $this->lockUser($userId);
            $this->normalizeLocked($userId);

            return AlbumListItem::query()->where('user_id', $userId)->whereIn('release_group_entity_id', $albumIds)->get()
                ->mapWithKeys(fn (AlbumListItem $item): array => [$item->release_group_entity_id => $this->present($item)])->all();
        });
    }

    /** @return array<string, mixed> */
    public function present(AlbumListItem $item): array
    {
        return [
            'id' => $item->id,
            'album_id' => $item->release_group_entity_id,
            'status' => $item->status,
            'note' => $item->note,
            'source' => $item->source,
            'wanted_at' => $item->wanted_at?->toAtomString(),
            'listened_at' => $item->listened_at?->toAtomString(),
            'removed_at' => $item->removed_at?->toAtomString(),
            'state_changed_at' => $item->state_changed_at?->toAtomString(),
            'updated_at' => $item->updated_at?->toAtomString(),
        ];
    }

    public function normalize(string $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $this->lockUser($userId);
            $this->normalizeLocked($userId);
        });
    }

    private function lockUser(string $userId): void
    {
        DB::statement('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["album-list:{$userId}"]);
    }

    private function normalizeLocked(string $userId): void
    {
        $items = AlbumListItem::query()->where('user_id', $userId)->with('album:id,kind,status,redirect_entity_id')->orderByDesc('state_changed_at')->orderByDesc('updated_at')->orderBy('id')->get();
        $groups = [];
        foreach ($items as $item) {
            $resolved = $item->album?->status === 'active' && $item->album->kind === 'release_group'
                ? $item->album
                : $this->resolver->resolve($item->release_group_entity_id, 'release_group');
            if ($resolved !== null) {
                $groups[$resolved->id][] = $item;
            }
        }
        foreach ($groups as $canonicalId => $group) {
            $winner = array_shift($group);
            $wantedAt = collect([$winner->wanted_at, ...array_map(fn (AlbumListItem $item) => $item->wanted_at, $group)])->filter()->max();
            $listenedAt = collect([$winner->listened_at, ...array_map(fn (AlbumListItem $item) => $item->listened_at, $group)])->filter()->max();
            foreach ($group as $loser) {
                $loser->delete();
            }
            $winner->release_group_entity_id = $canonicalId;
            $winner->wanted_at = $wantedAt;
            $winner->listened_at = $listenedAt;
            if ($winner->isDirty()) {
                $winner->save();
            }
        }
    }
}
