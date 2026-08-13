<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class PlexPlaybackScheduleTest extends TestCase
{
    public function test_playback_context_poll_is_bounded_and_single_server(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'disco:plex-playback-context'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
