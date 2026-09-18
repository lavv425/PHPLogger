<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Support;

use Logger\Support\ReentrancyGuard;
use PHPUnit\Framework\TestCase;

final class ReentrancyGuardTest extends TestCase
{
    public function test_starts_idle(): void
    {
        self::assertFalse((new ReentrancyGuard())->isBusy());
    }

    public function test_reports_busy_between_enter_and_leave(): void
    {
        $guard = new ReentrancyGuard();

        $guard->enter();
        self::assertTrue($guard->isBusy());

        $guard->leave();
        self::assertFalse($guard->isBusy());
    }

    public function test_counts_depth_so_nested_sections_do_not_unlock_early(): void
    {
        $guard = new ReentrancyGuard();

        $guard->enter();
        $guard->enter();
        $guard->leave();

        self::assertTrue($guard->isBusy(), 'the outer section is still running');

        $guard->leave();
        self::assertFalse($guard->isBusy());
    }

    public function test_an_unbalanced_leave_cannot_push_the_depth_negative(): void
    {
        $guard = new ReentrancyGuard();

        $guard->leave();
        $guard->leave();
        self::assertFalse($guard->isBusy());

        // A single enter must still make it busy: if leave() had underflowed,
        // the guard would silently stop guarding.
        $guard->enter();
        self::assertTrue($guard->isBusy());
    }
}
