<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class CreditScheduleTest extends TestCase
{
    public function test_credit_enrichment_is_bounded_and_scheduled_outside_other_provider_work(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'disco:credits --limit=20'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('15 6 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
