<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Service;

use OCA\NgBackup\Service\AlertService;
use PHPUnit\Framework\TestCase;

/** A location without a schedule: a weekly reminder while another is backed up, else a daily one. */
class ScheduleOffReminderTest extends TestCase {
	public function testOnceAWeekWhileAnotherLocationIsActive(): void {
		$this->assertFalse(AlertService::offReminderDue(0, 6 * 86400, true));
		$this->assertTrue(AlertService::offReminderDue(0, 7 * 86400, true));
	}

	public function testEveryDayWhenNoLocationIsActive(): void {
		$this->assertFalse(AlertService::offReminderDue(0, 3600, false));
		$this->assertTrue(AlertService::offReminderDue(0, 86400, false));
		$this->assertTrue(AlertService::offReminderDue(0, 86400 - 60, false), 'a cron run a minute earlier than yesterday');
	}
}
