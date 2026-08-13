<?php

namespace App\Http\Presenters;

use App\Models\EntityNarrative;

class NarrativePresenter
{
    /** @return array<string, mixed>|null */
    public function description(string $entityId): ?array
    {
        $language = strtolower((string) config('services.wikimedia.language', 'en'));
        $narrative = EntityNarrative::query()
            ->where('entity_id', $entityId)
            ->where('kind', 'description')
            ->where('status', 'ready')
            ->whereIn('provider_slug', ['theaudiodb', 'wikipedia'])
            ->whereIn('language', array_values(array_unique([$language, 'en'])))
            ->orderByRaw('CASE WHEN language = ? THEN 0 WHEN language = ? THEN 1 ELSE 2 END', [$language, 'en'])
            ->orderByRaw("CASE WHEN provider_slug = 'theaudiodb' THEN 0 ELSE 1 END")
            ->first();
        if ($narrative === null) {
            return null;
        }

        return [
            'text' => $narrative->body,
            'language' => $narrative->language,
            'provider' => $narrative->provider_slug,
            'provider_name' => $narrative->provider_slug === 'theaudiodb' ? 'TheAudioDB' : 'Wikipedia',
            'source_url' => $narrative->source_url,
            'license_name' => $narrative->license_name,
            'license_url' => $narrative->license_url,
        ];
    }
}
