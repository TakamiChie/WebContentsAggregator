<?php

declare(strict_types=1);

require_once __DIR__ . '/collection_settings.php';

const ENTRIES_FILE = __DIR__ . '/../settings.json';
const YOUTUBE_FEED_UNAVAILABLE = 75;

try {
  main($argv);
} catch (RuntimeException $error) {
  fail($error->getMessage());
}

function main(array $argv): void
{
  $options = parseCollectionOptions($argv);
  if ($options['help']) {
    fwrite(STDOUT, "Usage: php src/generate_all.php [--blog-id=ID [--blog-page=PAGE]]\n");
    return;
  }

  $entries = readEntries(ENTRIES_FILE);
  $databaseFile = resolveOutputPath(readString($entries, 'OUTPUT_PATH', 'configuration'));
  $databaseOption = '--database=' . $databaseFile;
  if ($options['blog-id'] !== null) {
    $blog = selectBlog(readEntryGroup($entries['CONTENTS'], 'blogs'), $options['blog-id']);
    collectBlogMetadata($blog, $databaseOption, $options['blog-page']);
    fwrite(STDOUT, "Processed blog {$options['blog-id']} in SQLite: {$databaseFile}\n");
    return;
  }
  $processed = 0;
  $skipped = 0;
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
    collectBlogMetadata($entry, $databaseOption);
    $processed++;
  }

  foreach (readEntryGroup($entries['CONTENTS'], 'youtube') as $entry) {
    $completed = runPhpScript(__DIR__ . '/tools/youtube_load.php', [
      readSourceId($entry, 'youtube'),
      readString($entry, 'pid', 'youtube'),
      $databaseOption,
    ], [YOUTUBE_FEED_UNAVAILABLE]);
    if ($completed) {
      $processed++;
    } else {
      $skipped++;
    }
  }

  fwrite(
    STDOUT,
    sprintf(
      "Processed %d web sources in SQLite; skipped %d unavailable YouTube feeds: %s\n",
      $processed,
      $skipped,
      $databaseFile
    )
  );
}

function collectBlogMetadata(array $blog, string $databaseOption, ?string $page = null): void
{
  runPhpScript(__DIR__ . '/tools/blog_load.php', [
    readSourceId($blog, 'blogs'),
    blogFeedUrl($blog, $page),
    $databaseOption,
  ]);
}

function runPhpScript(
  string $scriptPath,
  array $args,
  array $skippableExitCodes = []
): bool
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
    dirname(__DIR__)
  );
  if (!is_resource($process)) {
    fclose($output);
    fail("Cannot start script: {$scriptPath}");
  }

  $exitCode = proc_close($process);
  fclose($output);

  if (in_array($exitCode, $skippableExitCodes, true)) {
    fwrite(STDERR, "Warning: Skipped temporarily unavailable source: {$scriptPath}\n");
    return false;
  }

  if ($exitCode !== 0) {
    fail("Script failed: {$scriptPath}", $exitCode);
  }

  return true;
}

function fail(string $message, int $exitCode = 1): never
{
  fwrite(STDERR, "Error: {$message}\n");
  exit($exitCode);
}
