<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Job;

use OCA\NgBackup\Job\BackupRun;
use PHPUnit\Framework\TestCase;

class BackupProgressTest extends TestCase {
	public function testCountsFinishedFilesAndTheFileBeingRead(): void {
		$state = ['phase' => 'files', 'estimate' => ['bytes' => 1000, 'approximate' => false],
			'stats' => ['bytes' => 500], 'cur' => ['offset' => 180]];
		$this->assertSame(['fraction' => 0.68, 'approximate' => false], BackupRun::progress($state));
	}

	public function testStaysBelowHundredPercentWhenTheEstimateIsTooLow(): void {
		$state = ['phase' => 'files', 'estimate' => ['bytes' => 1000, 'approximate' => true], 'stats' => ['bytes' => 5000], 'cur' => null];
		$this->assertSame(['fraction' => 0.99, 'approximate' => true], BackupRun::progress($state));
	}

	public function testNoProgressWithoutEstimateOrOutsideTheFilesPhase(): void {
		$this->assertNull(BackupRun::progress(['phase' => 'files', 'stats' => ['bytes' => 1]]));
		$this->assertNull(BackupRun::progress(['phase' => 'files', 'estimate' => ['bytes' => 0, 'approximate' => true], 'stats' => ['bytes' => 1]]));
		$this->assertNull(BackupRun::progress(['phase' => 'commit', 'estimate' => ['bytes' => 10, 'approximate' => false], 'stats' => ['bytes' => 1]]));
	}
}
