<?php

declare(strict_types=1);

// Stand-in for the files_external SFTP storage in unit tests and for psalm: the part of
// getConnection() that checks the host key, as in Nextcloud 32 to 35. The phpseclib client is
// made by $makeClient, so a test decides which key the "server" shows.

namespace OCA\Files_External\Lib\Storage;

if (!class_exists(SFTP::class)) {
	/** Not an IStorage here: only the connection part is tested. */
	class SFTP {
		/** @var (\Closure(string, int): object)|null */
		public static ?\Closure $makeClient = null;

		/** @var object|null the phpseclib client */
		protected $client;
		private string $host;
		private int $port;
		private string $user;
		/** @var list<mixed> */
		private array $auth = [];

		public function __construct(array $parameters) {
			$this->host = (string)$parameters['host'];
			$this->port = (int)($parameters['port'] ?? 22);
			$this->user = (string)($parameters['user'] ?? '');
			if (isset($parameters['password'])) {
				$this->auth[] = $parameters['password'];
			}
		}

		/** @return object */
		public function getConnection() {
			if (!is_null($this->client)) {
				return $this->client;
			}
			$hostKeys = $this->readHostKeys();
			$this->client = (self::$makeClient)($this->host, $this->port);
			$currentHostKey = $this->client->getServerPublicHostKey();
			if (array_key_exists($this->host, $hostKeys)) {
				if ($hostKeys[$this->host] !== $currentHostKey) {
					throw new \Exception('Host public key does not match known key');
				}
			} else {
				$hostKeys[$this->host] = $currentHostKey;
				$this->writeHostKeys($hostKeys);
			}
			$login = false;
			foreach ($this->auth as $auth) {
				$login = $this->client->login($this->user, $auth);
				if ($login === true) {
					break;
				}
			}
			if ($login === false) {
				throw new \Exception('Login failed');
			}
			return $this->client;
		}

		protected function writeHostKeys(array $keys): bool {
			return false;
		}

		protected function readHostKeys(): array {
			return [];
		}
	}
}
