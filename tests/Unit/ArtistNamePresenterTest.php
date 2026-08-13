<?php

namespace Tests\Unit;

use App\Http\Presenters\ArtistNamePresenter;
use Tests\TestCase;

class ArtistNamePresenterTest extends TestCase
{
    public function test_it_uses_a_person_name_for_a_symbol_dominant_credit(): void
    {
        $presented = app(ArtistNamePresenter::class)->present(
            '⣎⡇ꉺლ༽இ•̛)ྀ◞ ༎ຶ ༽ৣৢ؞ৢ؞ؖ ꉺლ',
            'Person',
            'Kieran Hebden',
        );

        $this->assertSame('Kieran Hebden', $presented['name']);
        $this->assertSame('⣎⡇ꉺლ༽இ•̛)ྀ◞ ༎ຶ ༽ৣৢ؞ৢ؞ؖ ꉺლ', $presented['credited_name']);
    }

    public function test_it_does_not_treat_a_generic_disambiguation_as_a_name(): void
    {
        $presented = app(ArtistNamePresenter::class)->present('!!!', 'Group', 'American dance-punk band');

        $this->assertSame('!!!', $presented['name']);
        $this->assertNull($presented['credited_name']);
    }
}
