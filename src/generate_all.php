<?php

declare(strict_types=1);

require_once __DIR__ . '/collection_settings.php';
require_once __DIR__ . '/tools/blog_notion.php';

const YOUTUBE_FEED_UNAVAILABLE = 75;

try {
  main($argv);
} catch (Throwable $error) {
  fail($error->getMessage());
}

function main(array $argv): void
{
  $options = parseCollectionOptions($argv);
  if ($options['help']) {
    fwrite(STDOUT, "Usage: php src/generate_all.php [--blog-id=ID [--blog-page=PAGE]]\n");
    return;
  }

  DiscordProgress::start(__FILE__, '配信元からの記事取得を開始');
  $entries = readEntries(aggregatorSettingsPath());
  $databaseFile = resolveOutputPath(readString($entries, 'OUTPUT_PATH', 'configuration'));
  $databaseOption = '--database=' . $databaseFile;
  if ($options['blog-id'] !== null) {
    $blog = selectBlog(readEntryGroup($entries['CONTENTS'], 'blogs'), $options['blog-id']);
    collectBlogMetadata($blog, $databaseFile, $options['blog-page']);
    fwrite(STDOUT, "Processed blog {$options['blog-id']} in SQLite: {$databaseFile}\n");
    DiscordProgress::send('✅', '指定ブログの処理完了', DiscordProgress::label($options['blog-id']));
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
    collectBlogMetadata($entry, $databaseFile);
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

  DiscordProgress::send('✅', '配信元の処理完了', "処理 {$processed} 件・取得できなかった YouTube フィード {$skipped} 件");
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

function collectBlogMetadata(array $blog, string $databaseFile, ?string $page = null): void
{
  if (($blog['engine'] ?? null) === 'notion') {
    if ($page !== null) {
      throw new RuntimeException('--blog-page is supported only for hatenablog feeds');
    }
    collectNotionBlog($blog, $databaseFile);
    return;
  }

  runPhpScript(__DIR__ . '/tools/blog_load.php', [
    readSourceId($blog, 'blogs'),
    blogFeedUrl($blog, $page),
    '--database=' . $databaseFile,
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
  DiscordProgress::send('❌', '記事収集バッチが失敗', "終了コード {$exitCode}。詳細は実行ログを参照してください。");
  fwrite(STDERR, "Error: {$message}\n");
  exit($exitCode);
}
