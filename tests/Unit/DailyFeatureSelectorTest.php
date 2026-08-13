<?php

namespace Tests\Unit;

use App\Music\Discovery\DailyFeatureSelector;
use PHPUnit\Framework\TestCase;

class DailyFeatureSelectorTest extends TestCase
{
    public function test_selection_is_stable_for_the_same_day_and_catalog(): void
    {
        $selector = new DailyFeatureSelector;
        $candidates = [$this->candidate('album-a', 'owned'), $this->candidate('album-b', 'beyond')];

        $first = $selector->select($candidates, '2026-07-23');
        $second = $selector->select(array_reverse($candidates), '2026-07-23');

        $this->assertSame(data_get($first, 'album.id'), data_get($second, 'album.id'));
    }

    public function test_calendar_days_can_rotate_between_owned_and_beyond_albums(): void
    {
        $selector = new DailyFeatureSelector;
        $candidates = [$this->candidate('album-a', 'owned'), $this->candidate('album-b', 'beyond')];
        $scopes = collect(range(1, 31))
            ->map(fn (int $day): ?string => $selector->select($candidates, sprintf('2026-07-%02d', $day))['scope'] ?? null)
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(['owned', 'beyond'], $scopes);
    }

    public function test_only_confirmed_routable_albums_are_eligible(): void
    {
        $selector = new DailyFeatureSelector;
        $candidate = $this->candidate('album-valid', 'owned');
        $invalid = [
            [...$this->candidate('album-candidate', 'owned'), 'album' => ['id' => 'album-candidate', 'identity_status' => 'candidate']],
            [...$this->candidate('', 'beyond'), 'album' => ['id' => '', 'identity_status' => 'confirmed']],
        ];

        $this->assertSame('album-valid', data_get($selector->select([...$invalid, $candidate], '2026-07-23'), 'album.id'));
        $this->assertNull($selector->select($invalid, '2026-07-23'));
        $this->assertNull($selector->select([], '2026-07-23'));
    }

    /** @return array<string, mixed> */
    private function candidate(string $id, string $scope): array
    {
        return [
            'album' => ['id' => $id, 'identity_status' => 'confirmed'],
            'scope' => $scope,
        ];
    }
}
