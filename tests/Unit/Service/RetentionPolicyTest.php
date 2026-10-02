<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Service;

use OCA\NgBackup\Service\RetentionPolicy;
use PHPUnit\Framework\TestCase;

class RetentionPolicyTest extends TestCase {
	public function testDailyWeeklyMonthly(): void {
		$snaps = [];
		$start = strtotime('2026-01-01 03:00:00');
		for ($d = 0; $d < 120; $d++) {
			$snaps["d$d"] = $start + $d * 86400;          // one per day for 120 days
			$snaps["d$d-b"] = $start + $d * 86400 + 3600; // and a second one an hour later
		}
		$keep = (new RetentionPolicy(2, 7, 4, 3))->keep($snaps);
		$this->assertArrayHasKey('d119-b', $keep, 'newest is always kept');
		$this->assertArrayHasKey('d119', $keep, 'last=2');
		$this->assertContains('daily', $keep['d113-b']);
		$this->assertArrayNotHasKey('d113', $keep, 'only the newest of a day');
		$this->assertLessThanOrEqual(2 + 7 + 4 + 3, count($keep));
		$months = array_unique(array_map(fn ($id) => date('Y-m', $snaps[$id]), array_keys(array_filter($keep, fn ($r) => in_array('monthly', $r, true)))));
		$this->assertCount(3, $months);
	}

	public function testKeepsEverythingWhenFewSnapshots(): void {
		$keep = (new RetentionPolicy())->keep(['a' => 100, 'b' => 200]);
		$this->assertSame(['a', 'b'], array_values(array_intersect(['a', 'b'], array_keys($keep))));
	}
}
