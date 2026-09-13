<?php

declare(strict_types=1);

const ENTRIES_FILE = __DIR__ . '/settings.json';

main();

function main(): void
{
  $entries = readEntries(ENTRIES_FILE);
  $databaseFile = resolveOutputPath(readString($entries, 'OUTPUT_PATH', 'configuration'));
  $databaseOption = '--database=' . $databaseFile;
  $processed = 0;
  foreach (readEntryGroup($entries['CONTENTS'], 'podcasts') as $entry) {
    runPhpScript(__DIR__ . '/tools/podcast_load.php', [
      readSourceId($entry, 'podcasts'),
      readString($entry, 'url', 'podcasts'),
      $databaseOption,
      '--limit=unlimited',
    ]);
    $processed++;
  }

  foreach (readEntryGroup($entries['CONTENTS'], 'blogs') as $entry) {
    runPhpScript(__DIR__ . '/tools/blog_load.php', [
      readSourceId($entry, 'blogs'),
      readString($entry, 'url', 'blogs'),
      $databaseOption,
    ]);
    $processed++;
  }

  foreach (readEntryGroup($entries['CONTENTS'], 'youtube') as $entry) {
    runPhpScript(__DIR__ . '/tools/youtube_load.php', [
      readSourceId($entry, 'youtube'),
      readString($entry, 'pid', 'youtube'),
      $databaseOption,
    ]);
    $processed++;
  }

  fwrite(
    STDOUT,
    sprintf("Processed %d web sources in SQLite: %s\n", $processed, $databaseFile)
  );
}

function resolveOutputPath(string $path): string
{
  if (!str_starts_with($path, '~')) {
    return $path;
  }

  if (PHP_OS_FAMILY === 'Windows') {
    $home = getenv('USERPROFILE');
    if ($home === false || $home === '') {
      $drive = getenv('HOMEDRIVE');
      $homePath = getenv('HOMEPATH');
      $home = ($drive !== false && $drive !== '' && $homePath !== false && $homePath !== '')
        ? $drive . $homePath
        : false;
    }
  } else {
    $home = getenv('HOME');
  }

  if ($home === false || $home === '') {
    fail('Cannot determine the home directory for expanding ~ in OUTPUT_PATH');
  }

  $relativePath = ltrim(substr($path, 1), '/\\');
  return rtrim($home, '/\\') . DIRECTORY_SEPARATOR . $relativePath;
}

function readEntries(string $path): array
{
  $json = @file_get_contents($path);
  if ($json === false) {
    fail("Cannot read configuration file: {$path}");
  }

  try {
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $error) {
    fail("Invalid configuration JSON: {$error->getMessage()}");
  }

  if (!is_array($data)) {
    fail('Configuration root must be an object or array');
  }

  return $data;
}

function readEntryGroup(array $entries, string $group): array
{
  if (!array_key_exists($group, $entries)) {
    return [];
  }
  if (!is_array($entries[$group])) {
    fail("{$group} must be an array");
  }

  $result = [];
  foreach ($entries[$group] as $entry) {
    if (!is_array($entry)) {
      fail("Each entry in {$group} must be an object");
    }
    $result[] = $entry;
  }

  return $result;
}

function readSourceId(array $entry, string $group): string
{
  if (!isset($entry['id']) || (!is_int($entry['id']) && !is_string($entry['id']))) {
    fail("Missing or invalid id in {$group}");
  }

  $sourceId = trim((string)$entry['id']);
  if ($sourceId === '') {
    fail("Missing or invalid id in {$group}");
  }

  return $sourceId;
}

function readString(array $entry, string $key, string $group): string
{
  if (!isset($entry[$key]) || !is_string($entry[$key])) {
    fail("Missing or invalid {$key} in {$group}");
  }

  $value = trim($entry[$key]);
  if ($value === '') {
    fail("Missing or invalid {$key} in {$group}");
  }

  return $value;
}

function runPhpScript(string $scriptPath, array $args): void
{
  if (!is_file($scriptPath)) {
    fail("Script not found: {$scriptPath}");
  }

  $command = array_merge(
    [PHP_BINARY, $scriptPath],
    array_map(static fn(mixed $arg): string => (string)$arg, $args)
  );

  $output = fopen('php://temp', 'w+');
  if ($output === false) {
    fail('Cannot create child process output stream');
  }

  $process = proc_open(
    $command,
    [0 => STDIN, 1 => $output, 2 => STDERR],
    $pipes,
    __DIR__
  );
  if (!is_resource($process)) {
    fclose($output);
    fail("Cannot start script: {$scriptPath}");
  }

  $exitCode = proc_close($process);
  fclose($output);

  if ($exitCode !== 0) {
    fail("Script failed: {$scriptPath}", $exitCode);
  }
}

function fail(string $message, int $exitCode = 1): never
{
  fwrite(STDERR, "Error: {$message}\n");
  exit($exitCode);
}
