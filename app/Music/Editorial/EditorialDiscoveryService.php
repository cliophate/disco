<?php

namespace App\Music\Editorial;

use App\Models\EditorialItem;

class EditorialDiscoveryService
{
    /** @return list<array<string,mixed>> */
    public function current(): array
    {
        return EditorialItem::query()
            ->where('expires_at', '>', now())
            ->orderByDesc('published_at')
            ->orderBy('canonical_url')
            ->limit((int) config('discovery.editorial.pitchfork.maximum_cards', 6))
            ->get()
            ->map(fn (EditorialItem $item): array => [
                'id' => $item->id,
                'source' => $item->source,
                'publication' => 'Pitchfork',
                'publisher' => $item->publisher,
                'headline' => $item->headline,
                'excerpt' => $item->excerpt,
                'author' => $item->author,
                'category' => $item->category,
                'published_at' => $item->published_at?->toAtomString(),
                'url' => $item->canonical_url,
                'image' => $item->image_url === null ? null : [
                    'url' => $item->image_url,
                    'width' => $item->image_width,
                    'height' => $item->image_height,
                ],
            ])->all();
    }
}
