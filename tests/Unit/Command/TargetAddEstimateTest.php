<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Command;

use OCA\NgBackup\Command\TargetAdd;
use PHPUnit\Framework\TestCase;

class TargetAddEstimateTest extends TestCase {
	public function testNoEstimateFromTheFirstFewFiles(): void {
		// 4,568 index files, 10 in after 6 s: too few to say anything
		$this->assertNull(TargetAdd::minutesLeft(6.0, 10, 4558, 4568));
	}

	public function testEstimateOnceEnoughFilesCameIn(): void {
		// 300 files in 10 s: 4,268 left at 30 a second is about 2.4 minutes
		$this->assertSame(3, TargetAdd::minutesLeft(10.0, 300, 4268, 4568));
	}

	public function testFivePercentOfASmallIndexIsEnough(): void {
		// 6 of 100 files in 6 s: 94 left at one a second is about 1.6 minutes
		$this->assertSame(2, TargetAdd::minutesLeft(6.0, 6, 94, 100));
	}

	public function testNoEstimateInTheFirstSecondsOrWhenDone(): void {
		$this->assertNull(TargetAdd::minutesLeft(2.0, 500, 4000, 4500));
		$this->assertNull(TargetAdd::minutesLeft(60.0, 4568, 0, 4568));
	}
}
