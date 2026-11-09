<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Service;

use OCA\NgBackup\Service\HeavyJobPause as P;
use PHPUnit\Framework\TestCase;

class HeavyJobPauseTest extends TestCase {
	private const NOW = 1000000;

	public function testDuringABackupTheyArePaused(): void {
		$this->assertSame(P::PAUSE, P::decide(true, true, 0, 0, self::NOW));
		$this->assertSame(P::PAUSE, P::decide(true, true, self::NOW - 60, 0, self::NOW), 'jobs added since then too');
	}

	public function testWithoutABackupNothingHappensOrThePauseEnds(): void {
		$this->assertSame(P::NOTHING, P::decide(true, false, 0, 0, self::NOW));
		$this->assertSame(P::RELEASE, P::decide(true, false, self::NOW - 60, 0, self::NOW));
	}

	public function testTurningItOffEndsThePause(): void {
		$this->assertSame(P::NOTHING, P::decide(false, true, 0, 0, self::NOW));
		$this->assertSame(P::RELEASE, P::decide(false, true, self::NOW - 60, 0, self::NOW));
	}

	public function testAfterSixHoursTheyGetTheirTurnAndThenPauseAgain(): void {
		$this->assertSame(P::PAUSE, P::decide(true, true, self::NOW - P::MAX_SECONDS + 1, 0, self::NOW));
		$this->assertSame(P::REST, P::decide(true, true, self::NOW - P::MAX_SECONDS, 0, self::NOW));
		$rest = self::NOW + P::REST_SECONDS;
		$this->assertSame(P::NOTHING, P::decide(true, true, 0, $rest, $rest - 1));
		$this->assertSame(P::PAUSE, P::decide(true, true, 0, $rest, $rest));
	}
}
