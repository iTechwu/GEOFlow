<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleConfigurationTest extends TestCase
{
    public function test_stateful_scheduled_commands_are_single_server_and_non_overlapping(): void
    {
        foreach (['geoflow:schedule-tasks', 'geoflow:prune-transient'] as $command) {
            $event = $this->scheduledCommand($command);

            $this->assertTrue($event->onOneServer, $command.' must run on one scheduler instance.');
            $this->assertTrue($event->withoutOverlapping, $command.' must not overlap a previous run.');
        }
    }

    private function scheduledCommand(string $command): Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail('Scheduled command not found: '.$command);
    }
}
