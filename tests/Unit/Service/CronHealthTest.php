<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Service;

use OCA\NgBackup\Db\Run;
use OCA\NgBackup\Service\CronHealth;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class CronHealthTest extends TestCase {
	private const NOW = 1_800_000_000;
	private const DOCS = 'https://docs.example.com/background-jobs';

	private function health(string $mode, ?int $lastCron, bool $lastCronIsInt = false): CronHealth {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default) use ($mode, $lastCron, $lastCronIsInt): string {
			if ($key === 'backgroundjobs_mode') {
				return $mode;
			}
			if ($lastCronIsInt) {
				throw new AppConfigTypeConflictException('stored as int');
			}
			return $lastCron === null ? $default : (string)$lastCron;
		});
		$config->method('getValueInt')->willReturn($lastCron ?? 0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToDocs')->with('admin-background-jobs')->willReturn(self::DOCS);
		return new CronHealth($config, $urls);
	}

	public function testSystemCronThatRanRecentlyIsFine(): void {
		$check = $this->health('cron', self::NOW - 120)->check(self::NOW);
		$this->assertTrue($check['ok']);
		$this->assertFalse($check['stopped']);
		$this->assertNull(CronHealth::describe($check, self::NOW));
	}

	public function testAjaxAndWebcronAreNotEnough(): void {
		foreach (['ajax' => 'AJAX', 'webcron' => 'Webcron'] as $mode => $name) {
			$check = $this->health($mode, self::NOW - 60)->check(self::NOW);
			$this->assertFalse($check['ok'], $mode);
			$this->assertFalse($check['stopped'], $mode);
			$this->assertStringContainsString("they are set to \"$name\" and last ran 1 min ago.", (string)CronHealth::describe($check, self::NOW));
			$this->assertStringContainsString(self::DOCS, (string)CronHealth::describe($check, self::NOW));
		}
	}

	public function testSystemCronThatStoppedRunning(): void {
		$check = $this->health('cron', self::NOW - 2 * 3600)->check(self::NOW);
		$this->assertFalse($check['ok']);
		$this->assertTrue($check['stopped']);
		$this->assertStringContainsString('has not run for over an hour (last ran 2 h ago)', (string)CronHealth::describe($check, self::NOW));
	}

	public function testDefaultsToAjaxThatNeverRan(): void {
		$check = $this->health('ajax', null)->check(self::NOW);
		$this->assertSame(0, $check['lastRun']);
		$this->assertStringContainsString('and never ran.', (string)CronHealth::describe($check, self::NOW));
	}

	public function testReadsALastRunStoredAsAnInteger(): void {
		$check = $this->health('cron', self::NOW - 60, true)->check(self::NOW);
		$this->assertSame(self::NOW - 60, $check['lastRun']);
		$this->assertTrue($check['ok']);
	}

	public function testARunningBackupWaitsForBackgroundJobsWhenItDidNotMoveForAWhile(): void {
		$run = new Run();
		$run->setStatus(Run::RUNNING);
		$run->setUpdatedAt(self::NOW - 12 * 60);
		$this->assertNull(CronHealth::waitingFor($run, self::NOW));
		$run->setUpdatedAt(self::NOW - CronHealth::WAITING_AFTER);
		$this->assertSame(CronHealth::WAITING_AFTER, CronHealth::waitingFor($run, self::NOW));
		$run->setStatus(Run::DONE);
		$this->assertNull(CronHealth::waitingFor($run, self::NOW));
	}

	public function testAgo(): void {
		$this->assertSame('1 min ago', CronHealth::ago(10));
		$this->assertSame('12 min ago', CronHealth::ago(12 * 60));
		$this->assertSame('3 h ago', CronHealth::ago(3 * 3600 + 5));
		$this->assertSame('4 days ago', CronHealth::ago(4 * 86400));
	}
}
