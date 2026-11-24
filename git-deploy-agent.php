<?php

declare(strict_types=1);

/**
 * Server side of the git-deploy staged mode. git-deploy uploads this file to <root>/.git-deploy/agent.php
 * on every staged deploy and runs it over SSH (`phpCommand`, by default as the SFTP user):
 *
 *   php .git-deploy/agent.php verify <rev>                              prints {"bad": [paths], "unwritable": [dirs]} -
 *                                                                       missing or corrupt staged files, live dirs it cannot write
 *   php .git-deploy/agent.php switch <rev> [--parked=<path>=<target>]   backs up the live files, copies the staged ones in
 *   php .git-deploy/agent.php rollback <rev> [--parked=<path>=<target>] restores the backup of a switched release
 *   php .git-deploy/agent.php cleanup <keep> <currentRev>               deletes all but the newest <keep> releases
 *
 * <root>/.git-deploy/current holds the revision whose files the live tree has - set by a switch and a rollback, so it
 * stays right when a later deploy step (composer install, after hooks) fails before REVISION is written.
 *
 * Release layout: <root>/.git-deploy/<rev>/
 *   manifest.json  {"from": rev|null, "to": rev, "created": ISO date, "upload": {path: {"size", "sha1"}}, "delete": [path]}
 *   files/<path>   the staged new versions (copied into the live tree by the switch)
 *   backup/<path>  the live versions the switch overwrote or deleted
 *   state.json     {"state": "backedUp"|"switched"|"failed"|"rolledBack", "from": rev|null, "existed": [path], "deleted": [path]}
 *                  "existed" = uploaded paths that had a live file (backed up), "deleted" = removed paths that existed
 *
 * --parked maps a manifest path to the live file that currently holds it - with the maintenance page up, git-deploy
 * parks the real index.php under index.php.tmp, so a new (or restored) index.php has to go there.
 */

const STATE_BACKED_UP = 'backedUp';
const STATE_SWITCHED = 'switched';
const STATE_FAILED = 'failed';
const STATE_ROLLED_BACK = 'rolledBack';
/** group-writable like the rest of the deploy tree, so a deploy over SFTP (another user in the group) can overwrite it */
const LIVE_FILE_MODE = 0664;
const CURRENT_FILE = 'current';

$stageRoot = __DIR__;
$root = dirname(__DIR__);

ensureNotWebReadable($stageRoot);

$args = array_slice($argv, 1);
$command = array_shift($args);
try {
	match ($command) {
		'verify' => commandVerify($root, $stageRoot, releaseName($args[0] ?? '')),
		'switch' => withLock($stageRoot, fn() => commandSwitch($root, $stageRoot, releaseName($args[0] ?? ''), parseParked(array_slice($args, 1)))),
		'rollback' => withLock($stageRoot, fn() => commandRollback($root, $stageRoot, releaseName($args[0] ?? ''), parseParked(array_slice($args, 1)))),
		'cleanup' => commandCleanup($stageRoot, (int) ($args[0] ?? 0), releaseName($args[1] ?? '')),
		default => throw new RuntimeException('Usage: agent.php verify|switch|rollback|cleanup ...'),
	};
} catch (Throwable $e) {
	fwrite(STDOUT, 'DEPLOY FAILED: ' . $e->getMessage() . "\n");
	exit(1);
}
exit(0);


function commandVerify(string $root, string $stageRoot, string $rev): void
{
	$manifest = readManifest($stageRoot, $rev);
	echo json_encode(
		[
			'bad' => findBadFiles($stageRoot . '/' . $rev . '/files', $manifest),
			'unwritable' => findUnwritableDirs($root, $manifest),
		],
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
	) . "\n";
}


/**
 * @param array<string, string> $parked manifest path => live path holding it
 */
function commandSwitch(string $root, string $stageRoot, string $rev, array $parked): void
{
	$releaseDir = $stageRoot . '/' . $rev;
	$manifest = readManifest($stageRoot, $rev);
	$state = readState($releaseDir);
	if ($state !== null && $state['state'] === STATE_SWITCHED && $state['from'] === $manifest['from']) {
		// a repeated deploy after a later step failed (composer install, ...): the live tree already is this release
		writeCurrent($stageRoot, $rev);
		echo "Release {$rev} is already switched in - nothing to do.\n";
		return;
	}
	// any other state (failed, rolled back, switched in from another revision) means a new switch with a new backup
	$bad = findBadFiles($releaseDir . '/files', $manifest);
	if ($bad !== []) {
		throw new RuntimeException('Staged files missing or corrupt, the live tree was not touched: ' . implode(', ', $bad));
	}

	$started = microtime(true);
	// back up everything first, so a failed backup leaves the live tree untouched
	$existed = [];
	foreach (array_keys($manifest['upload']) as $path) {
		$live = $root . '/' . ($parked[$path] ?? $path);
		if (is_file($live)) {
			copyFile($live, $releaseDir . '/backup/' . $path);
			$existed[] = $path;
		}
	}
	$deleted = [];
	foreach ($manifest['delete'] as $path) {
		if (is_file($root . '/' . $path)) {
			copyFile($root . '/' . $path, $releaseDir . '/backup/' . $path);
			$deleted[] = $path;
		}
	}
	writeState($releaseDir, STATE_BACKED_UP, $manifest['from'], $existed, $deleted);
	echo sprintf("Backed up %d overwritten and %d deleted file(s).\n", count($existed), count($deleted));

	$copied = [];
	$removed = [];
	try {
		foreach (array_keys($manifest['upload']) as $path) {
			// a copy (not a move) leaves the file owned by this user with the tree's group-writable mode, whoever
			// uploaded the staged one; copyFile() swaps it in by rename(), so a request never sees it half written
			copyFile($releaseDir . '/files/' . $path, $root . '/' . ($parked[$path] ?? $path), LIVE_FILE_MODE);
			$copied[] = $path;
		}
		foreach ($deleted as $path) {
			if (!@unlink($root . '/' . $path)) {
				throw new RuntimeException("Cannot delete {$path}: " . lastError());
			}
			$removed[] = $path;
		}
	} catch (Throwable $e) {
		// put back what was already switched, so the live tree is the old release again
		$existedSet = array_flip($existed);
		foreach ($copied as $path) {
			$live = $root . '/' . ($parked[$path] ?? $path);
			if (isset($existedSet[$path])) {
				copyFile($releaseDir . '/backup/' . $path, $live);
			} else {
				@unlink($live);
			}
		}
		foreach ($removed as $path) {
			copyFile($releaseDir . '/backup/' . $path, $root . '/' . $path);
		}
		writeState($releaseDir, STATE_FAILED, $manifest['from'], $existed, $deleted);
		throw new RuntimeException($e->getMessage() . ' - the switched files were restored, the live tree is the old release.', 0, $e);
	}

	writeState($releaseDir, STATE_SWITCHED, $manifest['from'], $existed, $deleted);
	writeCurrent($stageRoot, $rev);
	echo sprintf(
		"Switched to %s: %d file(s) copied in, %d deleted in %.2f s.\n",
		$rev,
		count($copied),
		count($removed),
		microtime(true) - $started,
	);
}


/**
 * @param array<string, string> $parked manifest path => live path holding it
 */
function commandRollback(string $root, string $stageRoot, string $rev, array $parked): void
{
	$releaseDir = $stageRoot . '/' . $rev;
	$manifest = readManifest($stageRoot, $rev);
	$state = readState($releaseDir);
	if ($state === null || $state['state'] !== STATE_SWITCHED) {
		throw new RuntimeException("Release {$rev} cannot be rolled back, its state is '" . ($state['state'] ?? 'none') . "'.");
	}
	if ($state['from'] !== $manifest['from']) {
		throw new RuntimeException("The manifest of release {$rev} was rewritten after its switch, its backup no longer fits.");
	}
	$existedSet = array_flip($state['existed']);
	$restored = 0;
	$removed = 0;
	foreach (array_keys($manifest['upload']) as $path) {
		$live = $root . '/' . ($parked[$path] ?? $path);
		if (isset($existedSet[$path])) {
			copyFile($releaseDir . '/backup/' . $path, $live);
			$restored++;
		} elseif (is_file($live)) {
			if (!@unlink($live)) {
				throw new RuntimeException("Cannot delete {$path} added by the release: " . lastError());
			}
			$removed++;
		}
	}
	foreach ($state['deleted'] as $path) {
		copyFile($releaseDir . '/backup/' . $path, $root . '/' . $path);
		$restored++;
	}
	writeState($releaseDir, STATE_ROLLED_BACK, $manifest['from'], $state['existed'], $state['deleted']);
	writeCurrent($stageRoot, $manifest['from'] ?? '');
	echo sprintf("Rolled back %s to %s: %d file(s) restored, %d added file(s) deleted.\n", $rev, $manifest['from'] ?? '?', $restored, $removed);
}


function commandCleanup(string $stageRoot, int $keep, string $currentRev): void
{
	if ($keep < 1) {
		throw new RuntimeException('Keep at least one release.');
	}
	$releases = [];
	foreach (glob($stageRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
		$manifestFile = $dir . '/manifest.json';
		$releases[basename($dir)] = is_file($manifestFile) ? (int) filemtime($manifestFile) : (int) filemtime($dir);
	}
	arsort($releases);
	$deleted = [];
	foreach (array_slice(array_keys($releases), $keep) as $rev) {
		if ($rev === $currentRev) {
			continue;
		}
		removeDir($stageRoot . '/' . $rev);
		$deleted[] = $rev;
	}
	echo sprintf("Kept %d release(s), deleted %d.\n", count($releases) - count($deleted), count($deleted));
}


/**
 * @param array{upload: array<string, array{size: int, sha1: string}>} $manifest
 * @return list<string> manifest paths whose staged file is missing or differs
 */
function findBadFiles(string $filesDir, array $manifest): array
{
	$bad = [];
	foreach ($manifest['upload'] as $path => $expected) {
		$file = $filesDir . '/' . $path;
		if (!is_file($file) || filesize($file) !== $expected['size'] || sha1_file($file) !== $expected['sha1']) {
			$bad[] = $path;
		}
	}
	return $bad;
}


/**
 * The switch writes a temporary sibling and renames it over the live file (and creates missing directories), so it
 * needs write access to each target directory - or to its nearest existing ancestor.
 * @param array{upload: array<string, array{size: int, sha1: string}>, delete: list<string>} $manifest
 * @return list<string> live directories (relative to the root) the switch could not write into
 */
function findUnwritableDirs(string $root, array $manifest): array
{
	$unwritable = [];
	foreach ([...array_keys($manifest['upload']), ...$manifest['delete']] as $path) {
		$dir = dirname($path);
		while ($dir !== '.' && !is_dir($root . '/' . $dir)) {
			$dir = dirname($dir);
		}
		if (!is_writable($root . '/' . $dir)) {
			$unwritable[] = $dir;
		}
	}
	return array_values(array_unique($unwritable));
}


/**
 * @return array{from: ?string, to: string, upload: array<string, array{size: int, sha1: string}>, delete: list<string>}
 */
function readManifest(string $stageRoot, string $rev): array
{
	$file = $stageRoot . '/' . $rev . '/manifest.json';
	$manifest = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
	if (!is_array($manifest) || !isset($manifest['upload'], $manifest['delete']) || ($manifest['to'] ?? null) !== $rev) {
		throw new RuntimeException("Missing or invalid manifest of release {$rev}.");
	}
	foreach ([...array_keys($manifest['upload']), ...$manifest['delete']] as $path) {
		assertSafePath((string) $path);
	}
	return $manifest;
}


/**
 * @return array{state: string, from: ?string, existed: list<string>, deleted: list<string>}|null
 */
function readState(string $releaseDir): ?array
{
	$file = $releaseDir . '/state.json';
	if (!is_file($file)) {
		return null;
	}
	$state = json_decode((string) file_get_contents($file), true);
	if (!is_array($state) || !isset($state['state'], $state['existed'], $state['deleted'])) {
		throw new RuntimeException("Invalid {$file}.");
	}
	return $state + [
		'from' => null,
	];
}


/**
 * @param ?string $from the revision the release was switched in over - git-deploy rewrites the manifest when the same
 *                      revision is deployed again over another one, and the backup only fits the original transition
 * @param list<string> $existed
 * @param list<string> $deleted
 */
function writeState(string $releaseDir, string $state, ?string $from, array $existed, array $deleted): void
{
	$json = json_encode(
		[
			'state' => $state,
			'at' => date('c'),
			'from' => $from,
			'existed' => $existed,
			'deleted' => $deleted,
		],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
	);
	writeAtomically($releaseDir . '/state.json', static fn(string $tmp): bool => file_put_contents($tmp, $json) !== false);
}


function writeCurrent(string $stageRoot, string $rev): void
{
	writeAtomically($stageRoot . '/' . CURRENT_FILE, static fn(string $tmp): bool => file_put_contents($tmp, $rev) !== false);
}


/**
 * Copies via a temporary sibling and rename(), so the destination is never seen half written. Sets $mode, by default
 * the source's - the umask would strip the group-write bit the other user of the tree (web server or deploy) relies on.
 */
function copyFile(string $source, string $destination, ?int $mode = null): void
{
	ensureDir(dirname($destination));
	writeAtomically(
		$destination,
		static fn(string $tmp): bool => @copy($source, $tmp) && @chmod($tmp, $mode ?? (fileperms($source) & 0777)),
	);
}


/**
 * @param callable(string): bool $write writes the content to the given temporary path
 */
function writeAtomically(string $destination, callable $write): void
{
	$tmp = $destination . '.git-deploy-tmp';
	if (!$write($tmp) || !@rename($tmp, $destination)) {
		$error = lastError();
		@unlink($tmp);
		throw new RuntimeException("Cannot write {$destination}: {$error}");
	}
}


/**
 * Creates missing directories group-writable (despite the umask) and keeps the parent's setgid bit, so the deploy
 * user can still write into them.
 */
function ensureDir(string $dir): void
{
	if (is_dir($dir)) {
		return;
	}
	ensureDir(dirname($dir));
	if (!@mkdir($dir) && !is_dir($dir)) {
		throw new RuntimeException("Cannot create directory {$dir}: " . lastError());
	}
	@chmod($dir, 0775 | (fileperms(dirname($dir)) & 02000));
}


function removeDir(string $dir): void
{
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach ($items as $item) {
		$item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
	}
	if (!@rmdir($dir)) {
		throw new RuntimeException("Cannot delete {$dir}: " . lastError());
	}
}


/**
 * @param list<string> $args
 * @return array<string, string>
 */
function parseParked(array $args): array
{
	$parked = [];
	foreach ($args as $arg) {
		if (!preg_match('/^--parked=([^=]+)=(.+)$/', $arg, $m)) {
			throw new RuntimeException("Unknown argument {$arg}.");
		}
		assertSafePath($m[1]);
		assertSafePath($m[2]);
		$parked[$m[1]] = $m[2];
	}
	return $parked;
}


function releaseName(string $rev): string
{
	if (!preg_match('/^[0-9a-f]{7,64}$/', $rev)) {
		throw new RuntimeException("Invalid revision '{$rev}'.");
	}
	return $rev;
}


function assertSafePath(string $path): void
{
	if ($path === '' || str_starts_with($path, '/') || preg_match('~(^|/)\.\.?(/|$)|\\\\|\0~', $path)) {
		throw new RuntimeException("Unsafe path '{$path}' in the manifest.");
	}
}


/**
 * Serializes switch and rollback, so two deploys never move files at the same time.
 */
function withLock(string $stageRoot, callable $fn): void
{
	$lock = fopen($stageRoot . '/.lock', 'c');
	if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
		throw new RuntimeException('Another deploy is switching or rolling back right now.');
	}
	try {
		$fn();
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}


/**
 * The stage directory normally sits outside the document root; this covers setups that serve the project root.
 */
function ensureNotWebReadable(string $stageRoot): void
{
	$htaccess = $stageRoot . '/.htaccess';
	if (!is_file($htaccess)) {
		@file_put_contents($htaccess, "Require all denied\n");
	}
}


function lastError(): string
{
	return error_get_last()['message'] ?? 'unknown error';
}
