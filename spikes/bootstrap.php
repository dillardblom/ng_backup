<?php

declare(strict_types=1);

// Minimal PSR-4 autoloader so the spikes run without Nextcloud or composer.
spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\NgBackup\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
