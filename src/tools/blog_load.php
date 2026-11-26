<?php

declare(strict_types=1);

require_once __DIR__ . '/blog_feed.php';
require_once __DIR__ . '/blog_article.php';

const DEFAULT_DATABASE_FILE = __DIR__ . '/../../../user/data/mediadata.sqlite3';

$options = parseArguments($argv);
$atomUrl = $options['feed_url'];

$feedContent = @file_get_contents($atomUrl);
if ($feedContent === false) {
  fail("Failed to fetch blog feed: {$atomUrl}");
}

try {
  $xml = parseBlogFeed($feedContent);
} catch (RuntimeException $error) {
  fail($error->getMessage());
}
$rootName = $xml->getName();

$articles = [];
$siteTitle = 'Unknown Site';
$siteSubtitle = '';
$siteUrl = '';

if ($rootName === 'feed') {
  // Atom
  $siteTitle = isset($xml->title) ? trim((string)$xml->title) : 'Unknown Site';
  $siteSubtitle = isset($xml->subtitle) ? trim((string)$xml->subtitle) : '';
  $siteUrl = findAtomLinkHref($xml, 'alternate') ?: findAtomLinkHref($xml, null);

  foreach ($xml->entry as $entry) {
    $identity = blogEntryIdentity($entry, $options['source_id'], true);
    $summary = isset($entry->summary) ? trim((string)$entry->summary) : '';
    if (containsHtml($summary)) {
      $summary = htmlToText($summary);
    }
    $summary = html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $hashtags = extractHashtags($summary);

    $articles[] = $identity + [
      'published_at' => formatDateToJst($identity['pub_date']),
      'cover_art' => findAtomLinkHref($entry, 'enclosure', 'image/') ?: findAtomLinkHref($entry, 'enclosure'),
      'summary' => $summary,
      'hashtags' => $hashtags,
      'source_id' => $options['source_id'],
    ];
  }
} else {
  // RSS 2.0
  $channel = $xml->channel;
  $siteTitle = isset($channel->title) ? trim((string)$channel->title) : 'Unknown Site';
  $siteSubtitle = isset($channel->description) ? trim((string)$channel->description) : '';
  $siteUrl = isset($channel->link) ? trim((string)$channel->link) : '';

  $namespaces = $xml->getNamespaces(true);

  foreach ($channel->item as $item) {
    $identity = blogEntryIdentity($item, $options['source_id'], false);
    $coverArt = '';
    if (isset($item->enclosure)) {
      foreach ($item->enclosure as $enclosure) {
        $attrs = $enclosure->attributes();
        $enclosureType = strtolower(trim((string)($attrs['type'] ?? '')));
        $enclosureUrl = trim((string)($attrs['url'] ?? ''));
        if (str_starts_with($enclosureType, 'image/') && $enclosureUrl !== '') {
          $coverArt = $enclosureUrl;
          break;
        }
      }
    }

    if ($coverArt === '' && isset($namespaces['media'])) {
      $media = $item->children($namespaces['media']);
      if (isset($media->content)) {
        $attrs = $media->content->attributes();
        if (isset($attrs['url'])) {
          $coverArt = (string)$attrs['url'];
        }
      }
      if ($coverArt === '' && isset($media->thumbnail)) {
        $attrs = $media->thumbnail->attributes();
        $coverArt = (string)($attrs['url'] ?? '');
        if ($coverArt === '') {
          $coverArt = trim((string)$media->thumbnail);
        }
      }
    }

    $summary = isset($item->description) ? trim((string)$item->description) : '';
    if (containsHtml($summary)) {
      $summary = htmlToText($summary);
    }
    $summary = html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $hashtags = extractHashtags($summary);

    $articles[] = $identity + [
      'published_at' => formatDateToJst($identity['pub_date']),
      'cover_art' => $coverArt,
      'summary' => $summary,
      'hashtags' => $hashtags,
      'source_id' => $options['source_id'],
    ];
  }
}

$jsonData = [
  'source_id' => $options['source_id'],
  'site_title' => $siteTitle,
  'site_subtitle' => $siteSubtitle,
  'site_url' => $siteUrl,
  'episodes' => $articles,
];

$json = json_encode(
  $jsonData,
  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

if ($json === false) {
  fail('Failed to generate JSON: ' . json_last_error_msg());
}

function formatDateToJst(string $date): string
{
  $date = trim($date);
  if ($date === '') {
    return '';
  }

  try {
    return (new DateTimeImmutable($date))
      ->setTimezone(new DateTimeZone('Asia/Tokyo'))
      ->format(DateTimeInterface::ATOM);
  } catch (Exception $exception) {
    return '';
  }
}

if ($options['mode'] === 'sql') {
  $sql = buildSqlScript($articles);
  if ($options['sql_output'] === null) {
    fwrite(STDOUT, $sql);
  } elseif (file_put_contents($options['sql_output'], $sql) === false) {
    fail("Failed to write SQL file: {$options['sql_output']}");
  } else {
    fwrite(STDOUT, $json . PHP_EOL);
    fwrite(STDERR, "SQL file generated: {$options['sql_output']}\n");
  }
  exit(0);
}

if ($options['mode'] === 'write') {
  try {
    writeArticlesToDatabase($options['database'], $articles);
  } catch (RuntimeException $error) {
    fail($error->getMessage());
  }
  fwrite(
    STDERR,
    count($articles) . " records written to SQLite: {$options['database']}\n"
  );
}

fwrite(STDOUT, $json . PHP_EOL);

if ($options['json_output'] !== null) {
  if (file_put_contents($options['json_output'], $json . PHP_EOL) === false) {
    fail("Failed to write JSON file: {$options['json_output']}");
  }
  fwrite(STDERR, "JSON file generated: {$options['json_output']}\n");
}

function parseArguments(array $argv): array
{
  $positionals = [];
  $options = [
    'database' => DEFAULT_DATABASE_FILE,
    'json_output' => null,
    'mode' => 'write',
    'sql_output' => null,
  ];

  foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
      $options['mode'] = setMode($options['mode'], 'dry-run');
    } elseif ($arg === '--sql') {
      $options['mode'] = setMode($options['mode'], 'sql');
    } elseif (str_starts_with($arg, '--sql-output=')) {
      $options['mode'] = setMode($options['mode'], 'sql');
      $options['sql_output'] = optionValue($arg, '--sql-output=');
    } elseif (str_starts_with($arg, '--database=')) {
      $options['database'] = optionValue($arg, '--database=');
    } elseif (str_starts_with($arg, '--json-output=')) {
      $options['json_output'] = optionValue($arg, '--json-output=');
    } elseif ($arg === '--help' || $arg === '-h') {
      usage(null, 0);
    } elseif (str_starts_with($arg, '--')) {
      usage("Unknown option: {$arg}");
    } else {
      $positionals[] = $arg;
    }
  }

  if (count($positionals) !== 2) {
    usage('Specify source_id and feed URL');
  }
  $options['source_id'] = $positionals[0];
  $options['feed_url'] = $positionals[1];
  return $options;
}

function setMode(string $current, string $new): string
{
  if ($current !== 'write' && $current !== $new) {
    usage('--dry-run and --sql cannot be used together');
  }
  return $new;
}

function optionValue(string $argument, string $prefix): string
{
  $value = substr($argument, strlen($prefix));
  if ($value === '') {
    usage("Specify a value for {$prefix}");
  }
  return $value;
}

function usage(?string $error = null, int $exitCode = 2): never
{
  if ($error !== null) {
    fwrite(STDERR, "Error: {$error}\n\n");
  }
  fwrite(
    $exitCode === 0 ? STDOUT : STDERR,
    <<<TEXT
Usage:
  php src/tools/blog_load.php <source_id> <feed_url> [options]

Options:
  --database=<file>   SQLite file (default: user/data/mediadata.sqlite3)
  --dry-run           Print JSON to stdout without writing to SQLite
  --sql               Print SQL to stdout without writing to SQLite
  --sql-output=<file> Write SQL to file and JSON to stdout without writing to SQLite
  --json-output=<file> Save JSON to file as well as stdout
  -h, --help          Show this help

TEXT
  );
  exit($exitCode);
}

function buildSqlScript(array $articles): string
{
  $lines = ['BEGIN TRANSACTION;'];
  foreach ($articles as $article) {
    $row = articleRow($article);
    $lines[] = sprintf(
      'INSERT INTO "ARTICLE" '
      . '("title", "url", "published_at", "cover_art", "summary", "hashtags", '
      . '"source_id", "content_id") '
      . 'VALUES (%s, %s, %s, %s, %s, %s, %s, %s)' . articleUpsertClause() . ';',
      ...array_map('sqlLiteral', array_values($row))
    );
  }
  $lines[] = 'COMMIT;';
  return implode(PHP_EOL, $lines) . PHP_EOL;
}

function sqlLiteral(mixed $value): string
{
  if ($value === null) {
    return 'NULL';
  }
  return "'" . str_replace("'", "''", (string)$value) . "'";
}

function fail(string $message): never
{
  fwrite(STDERR, $message . PHP_EOL);
  exit(1);
}

function containsHtml(string $text): bool
{
  return preg_match('/<[a-z]/i', $text) === 1;
}

function htmlToText(string $html): string
{
  // 改行系タグを先に変換
  $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
  $html = preg_replace('/<\s*\/p\s*>/i', "\n\n", $html);
  $html = preg_replace('/<\s*p\s*>/i', "", $html);

  // リスト
  $html = preg_replace('/<\s*li\s*>/i', "- ", $html);
  $html = preg_replace('/<\s*\/li\s*>/i', "\n", $html);

  // リンクをMarkdown風に
  $html = preg_replace_callback(
    '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
    function ($m) {
      $url = $m[1];
      $text = strip_tags($m[2]);
      return $text !== '' ? "[{$text}]({$url})" : $url;
    },
    $html
  );

  // 画像
  $html = preg_replace_callback(
    '/<img\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/is',
    function ($m) {
      return "![]({$m[1]})";
    },
    $html
  );

  // 残りのタグ除去
  $text = strip_tags($html);

  // エンティティデコード
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

  // 改行整理（3行以上→2行）
  $text = preg_replace("/\n{3,}/", "\n\n", $text);

  return trim($text);
}
