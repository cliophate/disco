<?php

namespace Tests\Unit;

use App\Music\Discovery\AlbumEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlbumEligibilityTest extends TestCase
{
    public static function releaseTypes(): array
    {
        $cases = [
            'album' => ['Album', [], true], 'ep' => ['EP', [], true],
            'single' => ['Single', [], false], 'untyped' => ['', [], false],
            'soundtrack' => ['Album', ['Soundtrack'], true],
        ];
        foreach (['Compilation', 'Live', 'Remix', 'DJ-mix', 'Demo', 'Interview', 'Spokenword', 'Audiobook', 'Audio drama', 'Mixtape/Street'] as $type) {
            $cases[$type] = ['Album', [$type], false];
        }

        return $cases;
    }

    #[DataProvider('releaseTypes')]
    public function test_release_type_contract(string $primary, array $secondary, bool $allowed): void
    {
        $this->assertSame($allowed, app(AlbumEligibility::class)->allows(['primary-type' => $primary, 'secondary-types' => $secondary]));
    }

    public function test_artist_must_be_the_primary_credit(): void
    {
        $group = ['primary-type' => 'Album', 'artist-credit' => [['artist' => ['id' => 'primary']], ['artist' => ['id' => 'guest']]]];
        $this->assertTrue(app(AlbumEligibility::class)->allows($group, 'primary'));
        $this->assertFalse(app(AlbumEligibility::class)->allows($group, 'guest'));
    }
}
