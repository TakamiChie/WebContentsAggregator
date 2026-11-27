<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/summarize.php';

try {
  $args = array_slice($argv, 1);
  if ($args === ['--help'] || $args === ['-h']) {
    fwrite(STDOUT, "Usage: php subtool/list_pending_summaries.php [--limit=N]\n"
      . "  --limit N  Show up to N pending articles (default: 30; also --limit=N)\n"
      . "  -h, --help Show this help\n");
    exit(0);
  }

  $limit = 30;
  if ($args !== []) {
    $value = match (true) {
      count($args) === 2 && $args[0] === '--limit' => $args[1],
      count($args) === 1 && str_starts_with($args[0], '--limit=') => substr($args[0], strlen('--limit=')),
      default => '',
    };
    $limit = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!preg_match('/\A[1-9][0-9]*\z/', $value) || $limit === false) {
      throw new InvalidArgumentException('Specify --limit with a positive integer, or use --help');
    }
  }

  if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('The PHP pdo_sqlite extension is required');
  }
  $settings = json_decode(summarizeRead(aggregatorSettingsPath()), true, 512, JSON_THROW_ON_ERROR);
  if (!is_array($settings)) {
    throw new RuntimeException('settings.json must contain an object');
  }
  $database = summarizePath($settings['OUTPUT_PATH'] ?? null, 'OUTPUT_PATH');
  $db = summarizeOpenDatabase($database, true);
  summarizeCheckSchema($db, true, true);
  $articles = summarizePendingArticles($db, $limit, true);

  fwrite(STDOUT, "content_id\tpublished_at\ttitle\tfirst_line\n");
  while (($article = $articles->fetch(PDO::FETCH_ASSOC)) !== false) {
    $text = summarizeFulltext($article['fulltext']);
    $firstLine = substr($text, 0, strcspn($text, "\r\n"));
    $fields = [$article['content_id'], $article['published_at'], $article['title'], $firstLine];
    $fields = array_map(static fn ($field): string => preg_replace('/[\x00-\x1F\x7F]/', ' ', (string)$field), $fields);
    fwrite(STDOUT, implode("\t", $fields) . "\n");
  }
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
