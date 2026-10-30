<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit;

use OCA\NgBackup\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase {
	public function testDurationShowsDaysFromOneDayOn(): void {
		$this->assertSame('00:00:00', Format::duration(-5));
		$this->assertSame('12:10:13', Format::duration(12 * 3600 + 10 * 60 + 13));
		$this->assertSame('1d 00:00:00', Format::duration(86400));
		$this->assertSame('1d 12:10:13', Format::duration(86400 + 12 * 3600 + 10 * 60 + 13));
	}

	public function testTimeInZone(): void {
		$t = (int)strtotime('2026-10-19 13:12:00 UTC');
		$this->assertSame('2026-10-19 13:12', Format::time($t, 'UTC'));
		$this->assertSame('2026-10-19 15:12', Format::time($t, 'Europe/Berlin')); // summer time
		$this->assertSame('2026-12-01 01:00', Format::time((int)strtotime('2026-12-01 00:00:00 UTC'), 'Europe/Amsterdam'));
	}
}
