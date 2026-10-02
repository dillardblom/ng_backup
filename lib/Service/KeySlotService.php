<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

/**
 * Passphrase slots (like LUKS): up to three passphrases, each opening the same master key, so
 * that one person leaving or dying does not lock the company out of its backups. Every change
 * is written to all locations and announced to all administrators; a new recovery kit must be
 * downloaded and confirmed afterwards (backups keep running meanwhile).
 */
class KeySlotService {
	public function __construct(
		private KeyService $keys,
		private TargetService $targets,
		private AlertService $alerts,
	) {
	}

	public function add(#[\SensitiveParameter] string $passphrase, string $label): int {
		$slot = $this->keys->addSlot($passphrase, $label);
		$this->apply('slot_added', $slot);
		return $slot;
	}

	public function replace(int $slot, #[\SensitiveParameter] string $passphrase, ?string $label = null): void {
		$this->keys->replaceSlot($slot, $passphrase, $label);
		$this->apply('slot_replaced', $slot);
	}

	public function remove(int $slot): void {
		$this->keys->removeSlot($slot);
		$this->apply('slot_removed', $slot);
	}

	private function apply(string $event, int $slot): void {
		$wrapped = $this->keys->wrappedKey();
		$failed = [];
		foreach ($this->targets->list() as $t) {
			try {
				$this->targets->repository($t)->updateWrappedKey($wrapped);
			} catch (\Throwable $e) {
				$failed[] = $t->getName();
			}
		}
		$label = '';
		foreach ($this->keys->slots() as $s) {
			if ($s['slot'] === $slot) {
				$label = $s['label'];
			}
		}
		$this->alerts->securityEvent($event, ['slot' => (string)$slot, 'label' => $label]);
		if ($failed !== []) {
			throw new \RuntimeException('Saved, but these locations could not be updated (they still accept the previous passphrases): ' . implode(', ', $failed));
		}
	}
}
