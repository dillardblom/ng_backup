<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// nextcloud/ocp ships the public interfaces without an autoloader; tests that mock them need one.
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'OCP\\')) {
		$file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
