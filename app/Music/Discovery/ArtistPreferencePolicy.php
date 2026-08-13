<?php

namespace App\Music\Discovery;

use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Music\CanonicalEntityResolver;

class ArtistPreferencePolicy
{
    private const SPECIAL_MBIDS = [
        '89ad4ac3-39f7-470e-963a-56509c546377',
        '125ec42a-7229-4250-afc5-e057484327fe',
        'eec63d3c-3b81-4ad4-b1e4-7c147d4d2b61',
        'f731ccc4-e22a-43af-a747-64213329e088',
        '33cf029c-63b0-41a0-9855-be2a3665fb3b',
        '314e1c25-dde7-4e4d-b2f4-0a7b9f7c56dc',
        '9be7f096-97ec-4615-8957-8d40b5dcbc41',
        '7e84f845-ac16-41fe-9ff8-df12eb32af55',
        '66ea0139-149f-4a0c-8fbf-5ea9ec4a6e49',
        'a0ef7e1d-44ff-4039-9435-7d5fefdeecc9',
        '90068d37-bae7-4292-be4a-704c145bd616',
        '80a8851f-444c-4539-892b-ad2a49292aa9',
    ];

    private const SPECIAL_NAMES = [
        'various artists', '[unknown]', 'unknown artist', 'unknown artists',
        '[no artist]', 'no artist', '[anonymous]', '[data]', '[dialogue]',
        '[traditional]', '[disney]', '[theatre]', '[church chimes]',
        '[language instruction]',
    ];

    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private readonly CanonicalEntityResolver $resolver) {}

    public function allowsId(string $artistId): bool
    {
        if (array_key_exists($artistId, $this->cache)) {
            return $this->cache[$artistId];
        }
        $artist = $this->resolver->resolve($artistId, 'agent');

        return $this->cache[$artistId] = $artist !== null && $this->allows($artist);
    }

    public function allows(CatalogEntity $artist): bool
    {
        if ($artist->kind !== 'agent' || $artist->status !== 'active') {
            return false;
        }

        $entityIds = [$artist->id];
        for ($depth = 0; $depth < 10; $depth++) {
            $aliases = CatalogEntity::query()->where('kind', 'agent')->where('status', 'redirected')
                ->whereIn('redirect_entity_id', $entityIds)->whereNotIn('id', $entityIds)->pluck('id')->all();
            if ($aliases === []) {
                break;
            }
            $entityIds = array_values(array_unique([...$entityIds, ...$aliases]));
        }
        $mbids = ExternalIdentifier::query()->whereIn('entity_id', $entityIds)
            ->where('namespace', 'musicbrainz.artist')->where('status', 'active')
            ->pluck('value')->map(fn (string $value): string => strtolower($value));
        if ($mbids->intersect(self::SPECIAL_MBIDS)->isNotEmpty()) {
            return false;
        }
        if ($mbids->isNotEmpty()) {
            return true;
        }

        return ! in_array(strtolower(trim($artist->canonical_name)), self::SPECIAL_NAMES, true);
    }
}
