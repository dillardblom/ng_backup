<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Job;

use OCA\NgBackup\Job\TreeWalker;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

class TreeWalkerTest extends TestCase {
	use TempDirTrait;

	private function tree(): string {
		$d = $this->tempDir();
		foreach (['a.txt', 'a/x', 'a/b/y', 'a-b', 'b', 'skip/me', 'Z'] as $f) {
			@mkdir(dirname("$d/$f"), 0700, true);
			file_put_contents("$d/$f", $f);
		}
		return $d;
	}

	public function testOrderMatchesCompare(): void {
		$paths = array_keys(iterator_to_array((new TreeWalker(['' => $this->tree()]))->walk()));
		$sorted = $paths;
		usort($sorted, [TreeWalker::class, 'compare']);
		$this->assertSame($sorted, $paths);
		$this->assertSame(['Z', 'a', 'a-b', 'a.txt', 'b', 'skip'], array_values(array_unique(array_map(fn ($p) => explode('/', $p)[0], $paths))));
	}

	public function testResumeAfterCursorYieldsTheRest(): void {
		$w = new TreeWalker(['' => $this->tree()]);
		$all = array_keys(iterator_to_array($w->walk()));
		foreach ($all as $i => $cursor) {
			$this->assertSame(array_slice($all, $i + 1), array_keys(iterator_to_array($w->walk($cursor))), "after $cursor");
		}
	}

	public function testExcludeAndMultipleRoots(): void {
		$d = $this->tree();
		$c = $this->tempDir();
		file_put_contents("$c/config.php", '<?php');
		$paths = array_keys(iterator_to_array((new TreeWalker(['config' => $c, 'data' => $d], ['data/skip', 'data/b']))->walk()));
		$this->assertContains('config/config.php', $paths);
		$this->assertContains('data/a/b/y', $paths);
		$this->assertNotContains('data/skip/me', $paths);
		$this->assertNotContains('data/b', $paths);
	}
}
