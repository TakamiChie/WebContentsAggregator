<?php

declare(strict_types=1);

const DEFAULT_DATABASE_FILE = __DIR__ . '/../../user/data/mediadata.sqlite3';

main($argv);

function main(array $argv): void
{
  $options = parseArguments($argv);
  $playlistId = $options['playlist_id'];

  // 再生リストIDからYouTubeのRSS(Atom)フィードURLを構築
  $atomUrl = 'https://www.youtube.com/feeds/videos.xml?playlist_id='
    . rawurlencode($playlistId);
  $playlistUrl = 'https://www.youtube.com/playlist?list='
    . rawurlencode($playlistId);

  libxml_use_internal_errors(true);

  $feedContent = fetchUrlWithCurlFallback($atomUrl);
  if ($feedContent === false) {
    fwrite(STDERR, "すべての手段でYouTubeフィードの取得に失敗しました。\n");
    exit(1);
  }

  $xml = simplexml_load_string($feedContent);
  if ($xml === false) {
    fwrite(STDERR, "YouTubeフィードの解析に失敗しました。\n");
    foreach (libxml_get_errors() as $error) {
      fwrite(STDERR, trim($error->message) . "\n");
    }
    libxml_clear_errors();
    exit(1);
  }

  $channelTitle = isset($xml->title) ? trim((string)$xml->title) : 'Unknown Channel';

  $videos = [];
  $namespaces = $xml->getNamespaces(true);

  foreach ($xml->entry as $entry) {
    $videoTitle = isset($entry->title) ? trim((string)$entry->title) : 'Unknown Title';

    $videoUrl = '';
    if (isset($entry->link)) {
      foreach ($entry->link as $link) {
        $attributes = $link->attributes();
        if ($attributes && (string)$attributes['rel'] === 'alternate') {
          $videoUrl = (string)$attributes['href'];
          break;
        }
      }
    }

    $published = isset($entry->published)
      ? formatDateToJst((string)$entry->published)
      : '';

    $description = '';
    $coverArt = '';

    // media: 名前空間（Media RSS）から詳細情報を取得
    if (isset($namespaces['media'])) {
      $media = $entry->children($namespaces['media']);
      if (isset($media->group)) {
        $description = (string)($media->group->description ?? '');
        if (isset($media->group->thumbnail)) {
          $thumbAttr = $media->group->thumbnail->attributes();
          $coverArt = (string)($thumbAttr['url'] ?? '');
        }
      }
    }

    $videoId = getYouTubeVideoId($entry, $namespaces);
    $videos[] = [
      'content_id' => $videoId !== ''
        ? $videoId
        : getFallbackContentId($options['source_id'], $videoUrl, $videoTitle, $published),
      'title' => $videoTitle,
      'url' => $videoUrl,
      'published_at' => $published,
      'pub_date' => $published,
      'summary' => $description,
      'hashtags' => extractHashtags($description),
      'cover_art' => $coverArt,
      'source_id' => $options['source_id'],
    ];
  }

  $jsonData = [
    'source_id' => $options['source_id'],
    'site_title' => $channelTitle,
    'site_url' => $playlistUrl,
    'episodes' => $videos,
  ];

  $json = json_encode(
    $jsonData,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
  );

  if ($json === false) {
    fail('JSONの生成に失敗しました: ' . json_last_error_msg());
  }

  if ($options['mode'] === 'sql') {
    $sql = buildSqlScript($videos);
    if ($options['sql_output'] === null) {
      fwrite(STDOUT, $sql);
    } elseif (file_put_contents($options['sql_output'], $sql) === false) {
      fail("SQLファイルの書き込みに失敗しました: {$options['sql_output']}");
    } else {
      fwrite(STDOUT, $json . PHP_EOL);
      fwrite(STDERR, "SQLファイルを生成しました: {$options['sql_output']}\n");
    }
    return;
  }

  if ($options['mode'] === 'write') {
    writeVideosToDatabase($options['database'], $videos);
    fwrite(
      STDERR,
      count($videos) . "件をSQLiteへ書き込みました: {$options['database']}\n"
    );
  }

  fwrite(STDOUT, $json . PHP_EOL);

  if ($options['json_output'] !== null) {
    if (file_put_contents($options['json_output'], $json . PHP_EOL) === false) {
      fail("JSONファイルの書き込みに失敗しました: {$options['json_output']}");
    }
    fwrite(STDERR, "JSONファイルを生成しました: {$options['json_output']}\n");
  }
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
    usage('source_idとYouTube再生リストIDを指定してください');
  }
  $options['source_id'] = $positionals[0];
  $options['playlist_id'] = $positionals[1];
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
  php tools/youtube_load.php <source_id> <playlist_id> [オプション]

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

function getYouTubeVideoId(SimpleXMLElement $entry, array $namespaces): string
{
  if (isset($namespaces['yt'])) {
    $youtube = $entry->children($namespaces['yt']);
    $videoId = trim((string)($youtube->videoId ?? ''));
    if ($videoId !== '') {
      return $videoId;
    }
  }
  $entryId = trim((string)($entry->id ?? ''));
  return str_starts_with($entryId, 'yt:video:')
    ? substr($entryId, strlen('yt:video:'))
    : $entryId;
}

function getFallbackContentId(
  string $sourceId,
  string $url,
  string $title,
  string $publishedAt
): string {
  return $url !== ''
    ? $url
    : $sourceId . ':' . hash('sha256', $title . "\n" . $publishedAt);
}

function writeVideosToDatabase(string $databaseFile, array $videos): void
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
    if ($pdo->query(
      "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ARTICLE'"
    )->fetchColumn() === false) {
      throw new RuntimeException('必要なテーブルがありません: ARTICLE');
    }
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
      'INSERT INTO "ARTICLE" '
      . '("title", "url", "published_at", "cover_art", "summary", "hashtags", '
      . '"source_id", "content_id") '
      . 'VALUES (:title, :url, :published_at, :cover_art, :summary, :hashtags, '
      . ':source_id, :content_id)'
      . articleUpsertClause()
    );
    foreach ($videos as $video) {
      $statement->execute(articleRow($video));
    }
    $pdo->commit();
  } catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    fail('SQLiteへの書き込みに失敗しました: ' . $error->getMessage());
  }
}

function articleRow(array $video): array
{
  return [
    ':title' => $video['title'],
    ':url' => $video['url'],
    ':published_at' => $video['published_at'],
    ':cover_art' => $video['cover_art'],
    ':summary' => $video['summary'],
    ':hashtags' => implode(',', $video['hashtags']),
    ':source_id' => $video['source_id'],
    ':content_id' => $video['content_id'],
  ];
}

function buildSqlScript(array $videos): string
{
  $lines = ['BEGIN TRANSACTION;'];
  foreach ($videos as $video) {
    $lines[] = sprintf(
      'INSERT INTO "ARTICLE" '
      . '("title", "url", "published_at", "cover_art", "summary", "hashtags", '
      . '"source_id", "content_id") '
      . 'VALUES (%s, %s, %s, %s, %s, %s, %s, %s)' . articleUpsertClause() . ';',
      ...array_map('sqlLiteral', array_values(articleRow($video)))
    );
  }
  $lines[] = 'COMMIT;';
  return implode(PHP_EOL, $lines) . PHP_EOL;
}

function articleUpsertClause(): string
{
  $columns = ['title', 'url', 'published_at', 'cover_art', 'summary', 'hashtags', 'source_id'];
  $assignments = [];
  $changes = [];
  foreach ($columns as $column) {
    $assignments[] = '"' . $column . '" = excluded."' . $column . '"';
    $changes[] = '"ARTICLE"."' . $column . '" IS NOT excluded."' . $column . '"';
  }
  return ' ON CONFLICT ("content_id") DO UPDATE SET '
    . implode(', ', $assignments)
    . ' WHERE ' . implode(' OR ', $changes);
}

function sqlLiteral(mixed $value): string
{
  return $value === null
    ? 'NULL'
    : "'" . str_replace("'", "''", (string)$value) . "'";
}

function fail(string $message): never
{
  fwrite(STDERR, $message . PHP_EOL);
  exit(1);
}

/**
 * URLのコンテンツを取得する。file_get_contentsに失敗した場合はcURLを試みる。
 */
function fetchUrlWithCurlFallback(string $url): string|false
{
  $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

  // 1. まずは file_get_contents を試す
  $options = [
    'http' => [
      'method' => 'GET',
      'header' => ["User-Agent: {$userAgent}"],
      'timeout' => 10,
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
  ];
  $context = stream_context_create($options);

  fwrite(STDERR, "フィード取得を試みています (method: file_get_contents)...\n");
  $content = @file_get_contents($url, false, $context);

  if ($content !== false) {
    return $content;
  }

  $error = error_get_last();
  fwrite(STDERR, "file_get_contents で失敗しました: " . ($error['message'] ?? 'Unknown error') . "\n");

  // 2. cURLが利用可能であれば、cURLで再試行する
  if (!extension_loaded('curl')) {
    fwrite(STDERR, "cURL拡張がインストールされていないため、再試行できません。\n");
    return false;
  }

  fwrite(STDERR, "cURLを使用して再試行します (IPv4強制)...\n");

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => $userAgent,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    // IPv6のタイムアウト問題を避けるためIPv4を強制
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    // デバッグ環境用（本番ではtrueが望ましい）
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
  ]);

  $result = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($result !== false && $httpCode === 200) {
    fwrite(STDERR, "cURLで取得に成功しました。\n");
    return $result;
  }

  fwrite(STDERR, "cURLでの取得も失敗しました (HTTP {$httpCode}): {$curlError}\n");

  return false;
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
