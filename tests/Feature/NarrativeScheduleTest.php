<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class NarrativeScheduleTest extends TestCase
{
    public function test_narrative_jobs_are_bounded_sequential_and_share_a_mutex(): void
    {
        $expected = [
            'disco:album-narratives --scope=beyond --limit=30' => '45 4 * * *',
            'disco:album-narratives --scope=owned --limit=20' => '15 5 * * *',
            'disco:artist-biographies --limit=20' => '45 5 * * *',
        ];

        foreach ($expected as $command => $expression) {
            $event = collect($this->app->make(Schedule::class)->events())
                ->first(fn (Event $event): bool => str_contains($event->command, $command));

            $this->assertInstanceOf(Event::class, $event, "Missing scheduled command: {$command}");
            $this->assertSame($expression, $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
            $this->assertSame('disco:narrative-enrichment', $event->mutexName());
        }
    }
}
