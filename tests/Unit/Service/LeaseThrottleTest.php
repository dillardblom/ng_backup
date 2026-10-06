<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Tests\Unit\Service;

use OCA\NgBackup\Service\LeaseService;
use PHPUnit\Framework\TestCase;

final class LeaseThrottleTest extends TestCase {
	public function testRefreshesOnlyOncePerInterval(): void {
		$calls = 0;
		$beat = LeaseService::throttled(function () use (&$calls): void {
			$calls++;
		}, 3600);
		for ($i = 0; $i < 1000; $i++) {
			$beat();
		}
		$this->assertSame(0, $calls, 'no refresh before the interval has passed');
	}

	public function testRefreshesEveryCallWithZeroInterval(): void {
		$calls = 0;
		$beat = LeaseService::throttled(function () use (&$calls): void {
			$calls++;
		}, 0);
		for ($i = 0; $i < 5; $i++) {
			$beat();
		}
		$this->assertSame(5, $calls);
	}
}
