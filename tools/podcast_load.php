<?php

declare(strict_types=1);

const DEFAULT_DATABASE_FILE = __DIR__ . '/../../user/data/mediadata.sqlite3';
const DEFAULT_LIMIT = '60';

main($argv);

function main(array $argv): void
{
  $options = parseArguments($argv);
  $limitDate = parseLimitDate($options['limit']);
  $feedXml = loadFeedXml($options['rss_url']);
  $podcast = readPodcast($feedXml, $options['source_id'], $options['rss_url'], $limitDate);

  if ($options['mode'] === 'dry-run') {
    outputJson($podcast);
    return;
  }

  if ($options['mode'] === 'sql') {
    $sql = buildSqlScript($podcast);
    if ($options['sql_output'] === null) {
      fwrite(STDOUT, $sql);
    } elseif (file_put_contents($options['sql_output'], $sql) === false) {
      fail("SQLファイルの書き込みに失敗しました: {$options['sql_output']}");
    } else {
      fwrite(STDERR, "SQLファイルを生成しました: {$options['sql_output']}\n");
    }
    return;
  }

  writePodcastToDatabase($options['database'], $podcast);
  outputJson($podcast);
  fwrite(
    STDERR,
    sprintf(
      "%d件のエピソードをSQLiteへ書き込みました: %s\n",
      count($podcast['episodes']),
      $options['database']
    )
  );
}

function parseArguments(array $argv): array
{
  $positionals = [];
  $options = [
    'database' => DEFAULT_DATABASE_FILE,
    'limit' => DEFAULT_LIMIT,
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
      $options['sql_output'] = substr($arg, strlen('--sql-output='));
      if ($options['sql_output'] === '') {
        usage('--sql-outputには出力先を指定してください');
      }
    } elseif (str_starts_with($arg, '--database=')) {
      $options['database'] = substr($arg, strlen('--database='));
      if ($options['database'] === '') {
        usage('--databaseにはSQLiteファイルを指定してください');
      }
    } elseif (str_starts_with($arg, '--limit=')) {
      $options['limit'] = substr($arg, strlen('--limit='));
    } elseif ($arg === '--help' || $arg === '-h') {
      usage(null, 0);
    } elseif (str_starts_with($arg, '--')) {
      usage("不明なオプションです: {$arg}");
    } else {
      $positionals[] = $arg;
    }
  }

  if (count($positionals) !== 2) {
    usage('source_idとRSS URLを指定してください');
  }

  $options['source_id'] = $positionals[0];
  $options['rss_url'] = $positionals[1];

  return $options;
}

function setMode(string $current, string $new): string
{
  if ($current !== 'write' && $current !== $new) {
    usage('--dry-runと--sqlは同時に指定できません');
  }
  return $new;
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
  php tools/podcast_load.php <source_id> <rss_url> [オプション]

オプション:
  --database=<file>   SQLiteファイル（既定: user/data/mediadata.sqlite3）
  --limit=<days>      過去何日分を読むか。unlimitedも指定可能（既定: 60）
  --dry-run           JSONを標準出力し、SQLiteへは書き込まない
  --sql               等価なSQLを標準出力し、SQLiteへは書き込まない
  --sql-output=<file> 等価なSQLをファイルへ出力し、SQLiteへは書き込まない
  -h, --help          このヘルプを表示

TEXT
  );
  exit($exitCode);
}

function parseLimitDate(string $limitArg): ?DateTimeImmutable
{
  if ($limitArg === 'unlimited') {
    return null;
  }
  if (!ctype_digit($limitArg)) {
    usage('limitは0以上の整数またはunlimitedで指定してください');
  }

  return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->sub(new DateInterval("P{$limitArg}D"));
}

function loadFeedXml(string $url): SimpleXMLElement
{
  libxml_use_internal_errors(true);
  $xmlString = @file_get_contents($url);
  if ($xmlString === false) {
    fail("RSS取得に失敗しました: {$url}");
  }

  $xml = simplexml_load_string($xmlString);
  if ($xml === false) {
    $messages = array_map(
      static fn(LibXMLError $error): string => trim($error->message),
      libxml_get_errors()
    );
    libxml_clear_errors();
    fail("XML解析に失敗しました\n" . implode("\n", $messages));
  }

  return $xml;
}

function readPodcast(
  SimpleXMLElement $feedXml,
  string $sourceId,
  string $rssUrl,
  ?DateTimeImmutable $limitDate
): array {
  $channel = $feedXml->channel ?? null;
  if ($channel === null) {
    fail('RSSのchannel要素が見つかりませんでした');
  }

  $podcast = [
    'source_id' => $sourceId,
    'source_name' => trim((string)($channel->title ?? '')),
    'site_title' => trim((string)($channel->title ?? '')),
    'source_type' => 'podcast',
    'site_subtitle' => trim((string)($channel->description ?? '')),
    'site_url' => trim((string)($channel->link ?? '')),
    'cover_art' => getChannelImage($channel),
    'rss_url' => $rssUrl,
    'episodes' => [],
  ];

  foreach ($channel->item ?? [] as $item) {
    $pubDateRaw = trim((string)($item->pubDate ?? ''));
    $pubDate = parseDateToUtc($pubDateRaw);
    if ($pubDate === null || ($limitDate !== null && $pubDate < $limitDate)) {
      continue;
    }

    $summary = getItemSummary($item);
    if (containsHtml($summary)) {
      $summary = htmlToText($summary);
    }
    $url = trim((string)($item->link ?? ''));

    $podcast['episodes'][] = [
      'content_id' => getContentId($item, $sourceId, $url),
      'title' => trim((string)($item->title ?? '')),
      'url' => $url,
      'published_at' => $pubDate
        ->setTimezone(new DateTimeZone('Asia/Tokyo'))
        ->format(DateTimeInterface::ATOM),
      'pub_date' => $pubDate->format(DateTimeInterface::ATOM),
      'cover_art' => getItunesImage($item, $podcast['cover_art']),
      'summary' => $summary,
      'duration' => getItunesText($item, 'duration'),
      'hashtags' => extractHashtags($summary),
      'source_id' => $sourceId,
    ];
  }

  return $podcast;
}

function getContentId(SimpleXMLElement $item, string $sourceId, string $url): string
{
  $guid = trim((string)($item->guid ?? ''));
  if ($guid !== '') {
    return $guid;
  }
  if ($url !== '') {
    return $url;
  }

  return $sourceId . ':' . hash(
    'sha256',
    trim((string)($item->title ?? '')) . "\n" . trim((string)($item->pubDate ?? ''))
  );
}

function writePodcastToDatabase(string $databaseFile, array $podcast): void
{
  if (!extension_loaded('pdo_sqlite')) {
    fail('PDO SQLite拡張が利用できません。php.iniでpdo_sqliteを有効にしてください');
  }

  $directory = dirname($databaseFile);
  if (!is_dir($directory)) {
    fail("SQLiteファイルの保存先ディレクトリがありません: {$directory}");
  }

  try {
    $pdo = new PDO('sqlite:' . $databaseFile, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    assertSchema($pdo);
    $pdo->beginTransaction();

    $insertEpisode = $pdo->prepare(
      'INSERT OR REPLACE INTO ARTICLE '
      . '(title, url, published_at, cover_art, summary, hashtags, source_id, content_id) '
      . 'VALUES (:title, :url, :published_at, :cover_art, :summary, :hashtags, :source_id, :content_id)'
    );
    foreach ($podcast['episodes'] as $episode) {
      $insertEpisode->execute(articleRow($episode));
    }

    $pdo->commit();
  } catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    fail('SQLiteへの書き込みに失敗しました: ' . $error->getMessage());
  }
}

function assertSchema(PDO $pdo): void
{
  foreach (['SOURCE', 'ARTICLE'] as $table) {
    $statement = $pdo->prepare(
      "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $statement->execute([':name' => $table]);
    if ($statement->fetchColumn() === false) {
      throw new RuntimeException("必要なテーブルがありません: {$table}");
    }
  }
}

function articleRow(array $episode): array
{
  return [
    ':title' => $episode['title'],
    ':url' => $episode['url'],
    ':published_at' => $episode['published_at'],
    ':cover_art' => $episode['cover_art'],
    ':summary' => $episode['summary'],
    ':hashtags' => implode(',', $episode['hashtags']),
    ':source_id' => $episode['source_id'],
    ':content_id' => $episode['content_id'],
  ];
}

function buildSqlScript(array $podcast): string
{
  $lines = [];

  foreach ($podcast['episodes'] as $episode) {
    $lines[] = sprintf(
      'INSERT OR REPLACE INTO "ARTICLE" '
      . '("title", "url", "published_at", "cover_art", "summary", "hashtags", "source_id", "content_id") '
      . 'VALUES (%s, %s, %s, %s, %s, %s, %s, %s);',
      sqlLiteral($episode['title']),
      sqlLiteral($episode['url']),
      sqlLiteral($episode['published_at']),
      sqlLiteral($episode['cover_art']),
      sqlLiteral($episode['summary']),
      sqlLiteral(implode(',', $episode['hashtags'])),
      sqlLiteral($episode['source_id']),
      sqlLiteral($episode['content_id'])
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
  if (is_int($value) || is_float($value)) {
    return (string)$value;
  }
  return "'" . str_replace("'", "''", (string)$value) . "'";
}

function outputJson(array $podcast): void
{
  $json = json_encode(
    $podcast,
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
  );
  if ($json === false) {
    fail('JSON変換に失敗しました: ' . json_last_error_msg());
  }
  fwrite(STDOUT, $json . PHP_EOL);
}

function getChannelImage(SimpleXMLElement $channel): string
{
  $itunes = $channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
  if (isset($itunes->image)) {
    $attributes = $itunes->image->attributes();
    $image = trim((string)($attributes['href'] ?? ''));
    if ($image !== '') {
      return $image;
    }
  }
  return trim((string)($channel->image->url ?? ''));
}

function getItemSummary(SimpleXMLElement $item): string
{
  $itunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
  return trim((string)($itunes->summary ?? $item->description ?? ''));
}

function getItunesText(SimpleXMLElement $item, string $key): string
{
  $itunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
  return trim((string)($itunes->{$key} ?? ''));
}

function getItunesImage(SimpleXMLElement $item, string $defaultImage): string
{
  $episodeImage = '';
  $itunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
  if (isset($itunes->image)) {
    $episodeImage = trim((string)($itunes->image->attributes()['href'] ?? ''));
  }
  return $episodeImage !== '' ? $episodeImage : $defaultImage;
}

function parseDateToUtc(string $date): ?DateTimeImmutable
{
  if ($date === '') {
    return null;
  }
  try {
    return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('UTC'));
  } catch (Throwable) {
    return null;
  }
}

function containsHtml(string $text): bool
{
  return preg_match('/<[a-z]/i', $text) === 1;
}

function htmlToText(string $html): string
{
  $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
  $html = preg_replace('/<\s*\/p\s*>/i', "\n\n", $html);
  $html = preg_replace('/<\s*p\s*>/i', '', $html);
  $html = preg_replace('/<\s*li\s*>/i', '- ', $html);
  $html = preg_replace('/<\s*\/li\s*>/i', "\n", $html);
  $html = preg_replace_callback(
    '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
    static function (array $matches): string {
      $text = strip_tags($matches[2]);
      return $text !== '' ? "[{$text}]({$matches[1]})" : $matches[1];
    },
    $html
  );
  $html = preg_replace_callback(
    '/<img\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/is',
    static fn(array $matches): string => "![]({$matches[1]})",
    $html
  );
  $text = strip_tags($html);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  return trim((string)preg_replace("/\n{3,}/", "\n\n", $text));
}

function extractHashtags(string $text): array
{
  preg_match_all('/#(\w+)/u', $text, $matches);
  $tags = array_filter(
    $matches[1],
    static fn(string $tag): bool => !ctype_digit($tag)
  );
  return array_values(array_unique(array_map(
    static fn(string $tag): string => '#' . $tag,
    $tags
  )));
}

function fail(string $message): never
{
  fwrite(STDERR, $message . PHP_EOL);
  exit(1);
}
