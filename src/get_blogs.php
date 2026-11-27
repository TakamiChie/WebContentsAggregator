<?php

declare(strict_types=1);

require_once __DIR__ . '/collection_settings.php';

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only');
}

try {
  exit(collectBlogPages($argv));
} catch (Throwable $error) {
  DiscordProgress::send('❌', 'ブログのページ範囲取得が失敗');
  fwrite(STDERR, "Error: {$error->getMessage()}\n");
  exit(1);
}

function collectBlogPages(array $argv): int
{
  $options = parseCollectionOptions($argv, allowPageRange: true);
  if ($options['help']) {
    fwrite(STDOUT, "Usage: php src/get_blogs.php --blog-id=ID --blog-page=FIRST[-LAST]\n");
    return 0;
  }

  DiscordProgress::start(__FILE__, 'ブログのページ範囲取得を開始');
  $blogId = $options['blog-id'];
  $pageRange = $options['blog-page'];
  if ($blogId === null || $pageRange === null) {
    throw new RuntimeException('--blog-id and --blog-page are both required');
  }

  [$first, $last] = array_pad(explode('-', $pageRange, 2), 2, null);
  $firstPage = filter_var($first, FILTER_VALIDATE_INT);
  $lastPage = filter_var($last ?? $first, FILTER_VALIDATE_INT);
  if ($firstPage === false || $lastPage === false || $lastPage < $firstPage) {
    throw new RuntimeException('--blog-page must be an ascending page range within the supported integer size');
  }

  for ($page = $firstPage; ; $page++) {
    DiscordProgress::send('📄', 'ブログのページを処理中', DiscordProgress::label($blogId) . "｜ページ {$page}");
    fwrite(STDOUT, "Processing blog {$blogId}, page {$page}\n");

    foreach (['generate_all.php', 'get_blogtext.php'] as $script) {
      $command = [PHP_BINARY, __DIR__ . '/' . $script, '--blog-id=' . $blogId, '--blog-page=' . $page];
      $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
      if ($process === false) {
        throw new RuntimeException("Could not start {$script} for blog {$blogId}, page {$page}");
      }
      $status = proc_close($process);
      if ($status !== 0) {
        DiscordProgress::send('❌', 'ブログのページ処理が失敗', "ページ {$page}｜{$script}｜終了コード {$status}");
        fwrite(STDERR, "Failed: {$script} for blog {$blogId}, page {$page} (exit {$status})\n");
        return $status > 0 ? $status : 1;
      }
    }
    if ($page === $lastPage) {
      break;
    }
  }

  DiscordProgress::send('✅', 'ブログのページ範囲取得が完了', "ページ {$firstPage}〜{$lastPage}");
  fwrite(STDOUT, "Completed blog {$blogId}, pages {$firstPage}-{$lastPage}\n");
  return 0;
}
