<?php

namespace Tests\Unit;

use App\Domain\Trips\TripStateMachine;
use App\Domain\Trips\TripStatus;
use App\Services\TripTimingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TripStateMachineTest extends TestCase
{
    #[DataProvider('validTransitions')]
    public function test_valid_transition_matrix(string $from, string $to): void
    {
        $machine = new TripStateMachine($this->createStub(TripTimingService::class));

        $this->assertTrue($machine->can($from, $to));
    }

    public function test_terminal_and_skipped_transitions_are_rejected(): void
    {
        $machine = new TripStateMachine($this->createStub(TripTimingService::class));

        $this->assertFalse($machine->can('scheduled', 'departed'));
        $this->assertFalse($machine->can('boarding', 'arrived'));
        $this->assertFalse($machine->can('arrived', 'boarding'));
        $this->assertFalse($machine->can('cancelled', 'scheduled'));
    }

    public function test_legacy_statuses_are_normalized_once(): void
    {
        $this->assertSame('boarding', TripStatus::normalize('embarquement'));
        $this->assertSame('departed', TripStatus::normalize('en_route'));
        $this->assertSame('arrived', TripStatus::normalize('arrivé'));
        $this->assertSame('delayed', TripStatus::normalize('retardé'));
    }

    public static function validTransitions(): array
    {
        return [
            ['scheduled', 'boarding'], ['scheduled', 'delayed'], ['scheduled', 'cancelled'],
            ['delayed', 'scheduled'], ['delayed', 'boarding'], ['delayed', 'cancelled'],
            ['boarding', 'delayed'], ['boarding', 'departed'], ['boarding', 'cancelled'],
            ['departed', 'arrived'],
        ];
    }
}
