<?php

declare(strict_types=1);

const DEFAULT_DATABASE_FILE = __DIR__ . '/../user/data/mediadata.sqlite3';

$options = parseArguments($argv);
$atomUrl = $options['feed_url'];

libxml_use_internal_errors(true);

$feedContent = @file_get_contents($atomUrl);
if ($feedContent === false) {
  fwrite(STDERR, "Atomフィードの取得に失敗しました: {$atomUrl}\n");
  exit(1);
}

$xml = simplexml_load_string($feedContent);
if ($xml === false) {
  fwrite(STDERR, "Atomフィードの解析に失敗しました。\n");
  foreach (libxml_get_errors() as $error) {
    fwrite(STDERR, trim($error->message) . "\n");
  }
  libxml_clear_errors();
  exit(1);
}

$rootName = $xml->getName();
if ($rootName !== 'feed' && $rootName !== 'rss') {
  fwrite(STDERR, "このプログラムはAtomフィードまたはRSS 2.0を想定しています。\n");
  exit(1);
}

/**
 * Atomのlink要素群から、条件に合うhrefを取得する
 *
 * @param SimpleXMLElement $parent
 * @param string|null $rel
 * @param string|null $typePrefix
 * @return string
 */
function findAtomLinkHref(SimpleXMLElement $parent, ?string $rel = null, ?string $typePrefix = null): string
{
  if (!isset($parent->link)) {
    return '';
  }

  foreach ($parent->link as $link) {
    $attributes = $link->attributes();
    if ($attributes === null) {
      continue;
    }

    $href = isset($attributes['href']) ? (string)$attributes['href'] : '';
    $linkRel = isset($attributes['rel']) ? (string)$attributes['rel'] : 'alternate';
    $linkType = isset($attributes['type']) ? (string)$attributes['type'] : '';

    if ($href === '') {
      continue;
    }

    if ($rel !== null && $linkRel !== $rel) {
      continue;
    }

    if ($typePrefix !== null && !str_starts_with($linkType, $typePrefix)) {
      continue;
    }

    return $href;
  }

  return '';
}

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
    $publishedAt = formatDateToJst(
      isset($entry->published)
        ? (string)$entry->published
        : (isset($entry->updated) ? (string)$entry->updated : '')
    );
    $summary = isset($entry->summary) ? trim((string)$entry->summary) : '';
    if (containsHtml($summary)) {
      $summary = htmlToText($summary);
    }
    $summary = html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $hashtags = extractHashtags($summary);

    $articles[] = [
      'content_id' => getContentId(
        trim((string)($entry->id ?? '')),
        $options['source_id'],
        findAtomLinkHref($entry, 'alternate') ?: findAtomLinkHref($entry, null),
        trim((string)($entry->title ?? '')),
        isset($entry->published) ? trim((string)$entry->published) : (isset($entry->updated) ? trim((string)$entry->updated) : '')
      ),
      'title' => isset($entry->title) ? trim((string)$entry->title) : 'Unknown Title',
      'url' => findAtomLinkHref($entry, 'alternate') ?: findAtomLinkHref($entry, null),
      'published_at' => $publishedAt,
      'pub_date' => isset($entry->published) ? trim((string)$entry->published) : (isset($entry->updated) ? trim((string)$entry->updated) : ''),
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
    $publishedAt = formatDateToJst(isset($item->pubDate) ? (string)$item->pubDate : '');
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

    $articles[] = [
      'content_id' => getContentId(
        trim((string)($item->guid ?? '')),
        $options['source_id'],
        isset($item->link) ? trim((string)$item->link) : '',
        isset($item->title) ? trim((string)$item->title) : '',
        isset($item->pubDate) ? trim((string)$item->pubDate) : ''
      ),
      'title' => isset($item->title) ? trim((string)$item->title) : 'Unknown Title',
      'url' => isset($item->link) ? trim((string)$item->link) : '',
      'published_at' => $publishedAt,
      'pub_date' => isset($item->pubDate) ? trim((string)$item->pubDate) : '',
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
  fail('JSONの生成に失敗しました: ' . json_last_error_msg());
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
    fail("SQLファイルの書き込みに失敗しました: {$options['sql_output']}");
  } else {
    fwrite(STDOUT, $json . PHP_EOL);
    fwrite(STDERR, "SQLファイルを生成しました: {$options['sql_output']}\n");
  }
  exit(0);
}

if ($options['mode'] === 'write') {
  writeArticlesToDatabase($options['database'], $articles);
  fwrite(
    STDERR,
    count($articles) . "件をSQLiteへ書き込みました: {$options['database']}\n"
  );
}

fwrite(STDOUT, $json . PHP_EOL);

if ($options['json_output'] !== null) {
  if (file_put_contents($options['json_output'], $json . PHP_EOL) === false) {
    fail("JSONファイルの書き込みに失敗しました: {$options['json_output']}");
  }
  fwrite(STDERR, "JSONファイルを生成しました: {$options['json_output']}\n");
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
      usage("不明なオプションです: {$arg}");
    } else {
      $positionals[] = $arg;
    }
  }

  if (count($positionals) !== 2) {
    usage('source_idとフィードURLを指定してください');
  }
  $options['source_id'] = $positionals[0];
  $options['feed_url'] = $positionals[1];
  return $options;
}

function setMode(string $current, string $new): string
{
  if ($current !== 'write' && $current !== $new) {
    usage('--dry-runと--sqlは同時に指定できません');
  }
  return $new;
}

function optionValue(string $argument, string $prefix): string
{
  $value = substr($argument, strlen($prefix));
  if ($value === '') {
    usage("{$prefix}には値を指定してください");
  }
  return $value;
}

function usage(?string $error = null, int $exitCode = 2): never
{
  if ($error !== null) {
    fwrite(STDERR, "エラー: {$error}\n\n");
  }
  fwrite(
    $exitCode === 0 ? STDOUT : STDERR,
    <<<TEXT
使用方法:
  php blog2json.php <source_id> <feed_url> [オプション]

オプション:
  --database=<file>   SQLiteファイル（既定: user/data/mediadata.sqlite3）
  --dry-run           読み込んだJSONを標準出力し、SQLiteへ書き込まない
  --sql               等価なSQLを標準出力し、SQLiteへ書き込まない
  --sql-output=<file> SQLをファイルへ、JSONを標準出力し、SQLiteへ書き込まない
  --json-output=<file> JSONを標準出力に加えてファイルにも保存
  -h, --help          このヘルプを表示

TEXT
  );
  exit($exitCode);
}

function getContentId(
  string $feedId,
  string $sourceId,
  string $url,
  string $title,
  string $publishedAt
): string {
  if ($feedId !== '') {
    return $feedId;
  }
  if ($url !== '') {
    return $url;
  }
  return $sourceId . ':' . hash('sha256', $title . "\n" . $publishedAt);
}

function writeArticlesToDatabase(string $databaseFile, array $articles): void
{
  if (!extension_loaded('pdo_sqlite')) {
    fail('PDO SQLite拡張が利用できません');
  }
  if (!is_dir(dirname($databaseFile))) {
    fail('SQLiteファイルの保存先ディレクトリがありません: ' . dirname($databaseFile));
  }

  try {
    $pdo = new PDO('sqlite:' . $databaseFile, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $exists = $pdo->query(
      "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ARTICLE'"
    )->fetchColumn();
    if ($exists === false) {
      throw new RuntimeException('必要なテーブルがありません: ARTICLE');
    }

    $pdo->beginTransaction();
    $statement = $pdo->prepare(
      'INSERT OR REPLACE INTO "ARTICLE" '
      . '("title", "url", "published_at", "cover_art", "summary", "hashtags", '
      . '"source_id", "content_id") '
      . 'VALUES (:title, :url, :published_at, :cover_art, :summary, :hashtags, '
      . ':source_id, :content_id)'
    );
    foreach ($articles as $article) {
      $statement->execute(articleRow($article));
    }
    $pdo->commit();
  } catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    fail('SQLiteへの書き込みに失敗しました: ' . $error->getMessage());
  }
}

function articleRow(array $article): array
{
  return [
    ':title' => $article['title'],
    ':url' => $article['url'],
    ':published_at' => $article['published_at'],
    ':cover_art' => $article['cover_art'],
    ':summary' => $article['summary'],
    ':hashtags' => implode(',', $article['hashtags']),
    ':source_id' => $article['source_id'],
    ':content_id' => $article['content_id'],
  ];
}

function buildSqlScript(array $articles): string
{
  $lines = ['BEGIN TRANSACTION;'];
  foreach ($articles as $article) {
    $row = articleRow($article);
    $lines[] = sprintf(
      'INSERT OR REPLACE INTO "ARTICLE" '
      . '("title", "url", "published_at", "cover_art", "summary", "hashtags", '
      . '"source_id", "content_id") '
      . 'VALUES (%s, %s, %s, %s, %s, %s, %s, %s);',
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

function extractHashtags(string $text): array
{
  preg_match_all('/#(\w+)/u', $text, $matches);

  $tags = [];
  foreach ($matches[1] as $tag) {
    if (!ctype_digit($tag)) {
      $tags[] = '#' . $tag;
    }
  }

  return array_values(array_unique($tags));
}
