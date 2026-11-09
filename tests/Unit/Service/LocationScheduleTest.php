<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Service;

use OCA\NgBackup\Service\LocationSchedule as S;
use PHPUnit\Framework\TestCase;

class LocationScheduleTest extends TestCase {
	private const BERLIN = 'Europe/Berlin';

	private static function at(string $time): int {
		return (int)strtotime($time);
	}

	public function testWhatCanBeSaved(): void {
		$this->assertSame('', S::normalize('off'));
		$this->assertSame('', S::normalize(' '));
		$this->assertSame('02:30', S::normalize('02:30'));
		$this->assertSame('02:30', S::normalize('02:30/24'));
		$this->assertSame('00:00/6', S::normalize('00:00/6'));
		foreach (['2:30', '24:00', '02:30/0', '02:30/25', 'daily', '02:30/x'] as $bad) {
			try {
				S::normalize($bad);
				$this->fail("$bad accepted");
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testOffHasNoMoment(): void {
		$this->assertNull(S::lastMoment('', self::BERLIN, self::at('2026-11-10 12:00 UTC')));
	}

	public function testDailyIsInTheZoneOfTheLocation(): void {
		$this->assertSame(self::at('2026-11-10 02:00 UTC'), S::lastMoment('03:00', self::BERLIN, self::at('2026-11-10 12:00 UTC')));
		$this->assertSame(self::at('2026-11-09 02:00 UTC'), S::lastMoment('03:00', self::BERLIN, self::at('2026-11-10 01:00 UTC')), 'not yet today');
	}

	public function testEveryNHoursStartsOverTheNextDay(): void {
		$zone = 'UTC';
		$this->assertSame(self::at('2026-11-10 12:00 UTC'), S::lastMoment('02:00/5', $zone, self::at('2026-11-10 13:00 UTC')));
		$this->assertSame(self::at('2026-11-09 22:00 UTC'), S::lastMoment('02:00/5', $zone, self::at('2026-11-10 01:59 UTC')), '02, 07, 12, 17, 22, then 02 again');
		$this->assertSame(self::at('2026-11-10 02:00 UTC'), S::lastMoment('02:00/5', $zone, self::at('2026-11-10 02:00 UTC')), 'at the moment itself');
	}

	public function testAClockChangeInTheNightStillGivesOneMoment(): void {
		$this->assertSame(self::at('2026-03-29 01:30 UTC'), S::lastMoment('02:30', self::BERLIN, self::at('2026-03-29 12:00 UTC')), '02:30 does not exist that night');
		$autumn = S::lastMoment('02:30', self::BERLIN, self::at('2026-10-25 12:00 UTC'));
		$this->assertNotNull($autumn);
		$local = (new \DateTimeImmutable('@' . $autumn))->setTimezone(new \DateTimeZone(self::BERLIN));
		$this->assertSame('2026-10-25 02:30', $local->format('Y-m-d H:i'), '02:30 happens twice that night: one of them, on that day');
	}

	public function testChoosingATimeDoesNotStartABackup(): void {
		$moment = self::at('2026-11-10 02:00 UTC');
		$this->assertFalse(S::isDue($moment, $moment + 1, null, 0, null, $moment + 3600));
		$this->assertTrue(S::isDue($moment, $moment - 1, null, 0, null, $moment + 3600));
		$this->assertTrue(S::isDue($moment, 0, null, 0, null, $moment), 'a location from before schedules per location');
	}

	public function testABackupSinceTheMomentIsEnough(): void {
		$moment = 1000000;
		$this->assertFalse(S::isDue($moment, 0, $moment, 1, $moment + 10, $moment + 20));
		$this->assertTrue(S::isDue($moment, 0, $moment - 1, 0, null, $moment + 20), 'it started before this moment: one more');
	}

	public function testAfterAFailureOnlyAFewAttemptsAnHourApart(): void {
		$moment = 1000000;
		$finished = $moment + 60;
		$this->assertFalse(S::isDue($moment, 0, null, 1, $finished, $finished + S::RETRY_AFTER - 1));
		$this->assertTrue(S::isDue($moment, 0, null, 1, $finished, $finished + S::RETRY_AFTER));
		$this->assertFalse(S::isDue($moment, 0, null, S::MAX_ATTEMPTS, $finished, $finished + 86400));
	}
}
