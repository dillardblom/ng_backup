<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Service;

use OCA\NgBackup\Service\Schedule;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/** The daily time in a time zone (issue #19), and the conversion of a time saved without one. */
class ScheduleTest extends TestCase {
	private array $values = [];

	private function schedule(string $serverZone = '', string $adminZone = ''): Schedule {
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueString')->willReturnCallback(fn (string $a, string $k, string $d = '') => $this->values[$k] ?? $d);
		$app->method('setValueString')->willReturnCallback(function (string $a, string $k, string $v): bool {
			$this->values[$k] = $v;
			return true;
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($serverZone);
		$config->method('getUserValue')->willReturn($adminZone);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);
		return new Schedule($app, $config, $groups);
	}

	public function testATimeSavedWithoutAZoneShowsAsItRanInTheAdministratorsZone(): void {
		$this->values = [Schedule::TIME_KEY => '02:30'];

		$s = $this->schedule(adminZone: 'Europe/Berlin')->get();

		// it ran at 02:30 UTC: in summer that is 04:30 in Berlin, in winter 03:30
		$summer = Schedule::convert('02:30', 'UTC', 'Europe/Berlin', strtotime('2026-07-01 12:00 UTC'));
		$this->assertSame('04:30', $summer);
		$this->assertSame('Europe/Berlin', $s['timezone']);
		$this->assertSame(Schedule::convert('02:30', 'UTC', 'Europe/Berlin'), $s['time']);
	}

	public function testTheServersDefaultZoneComesFirst(): void {
		$this->values = [Schedule::TIME_KEY => '01:00'];
		$this->assertSame('America/Toronto', $this->schedule('America/Toronto', 'Europe/Berlin')->get()['timezone']);
	}

	public function testWithoutAnyZoneItStaysUtc(): void {
		$this->values = [Schedule::TIME_KEY => '02:30'];
		$this->assertSame(['time' => '02:30', 'timezone' => 'UTC'], $this->schedule()->get());
	}

	public function testTodayAtIsTheMomentInTheSchedulesZone(): void {
		$this->values = [Schedule::TIME_KEY => '03:00', Schedule::ZONE_KEY => 'Europe/Berlin'];
		$at = $this->schedule()->todayAt(strtotime('2026-10-22 12:00 UTC'));
		$this->assertSame(strtotime('2026-10-22 01:00 UTC'), $at, '03:00 in Berlin (summer time) is 01:00 UTC');
	}

	public function testAnUnknownZoneOrABadTimeIsRefused(): void {
		$s = $this->schedule();
		$this->expectException(\InvalidArgumentException::class);
		$s->set('03:00', 'Mars/Olympus');
	}
}
