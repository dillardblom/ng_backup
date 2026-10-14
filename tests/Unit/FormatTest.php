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
}
