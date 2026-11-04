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

	private static function runIn(string $phase, array $state, string $status = \OCA\NgBackup\Db\Run::RUNNING): \OCA\NgBackup\Db\Run {
		$run = new \OCA\NgBackup\Db\Run();
		$run->setStatus($status);
		$run->setPhase($phase);
		$run->setState(json_encode($state, JSON_THROW_ON_ERROR));
		return $run;
	}

	public function testDatabasePhaseCountsTheTablesDumped(): void {
		$run = self::runIn('db', ['dump' => ['tables' => array_fill(0, 183, 'oc_t'), 'i' => 47]]);
		$this->assertSame(['fraction' => 47 / 183, 'approximate' => false, 'tables' => 47, 'totalTables' => 183], BackupRun::progressOf($run));
	}

	public function testDatabasePhaseHasNoFigureBeforeItsFirstStep(): void {
		$this->assertNull(BackupRun::progressOf(self::runIn('db', ['files' => []])));
	}

	public function testNoProgressForAFinishedRun(): void {
		$this->assertNull(BackupRun::progressOf(self::runIn('db', ['dump' => ['tables' => ['oc_a'], 'i' => 0]], \OCA\NgBackup\Db\Run::DONE)));
	}
}
