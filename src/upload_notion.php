<?php

declare(strict_types=1);

require_once __DIR__ . '/webvtt.php';
require_once __DIR__ . '/tools/notion_api.php';
require_once __DIR__ . '/discord_progress.php';

const NOTION_UPLOAD_HASH_VERSION = 3;
const NOTION_UPLOAD_SOURCES = ['takamichie', 'takamichie_gameonly'];

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only');
}

if (!defined('UPLOAD_NOTION_NO_MAIN')) {
  try {
    exit(notionMain($argv));
  } catch (Throwable $error) {
    DiscordProgress::send('❌', 'Notion アップロードバッチが失敗');
    fwrite(STDERR, "Notion upload failed: {$error->getMessage()}\n");
    exit(1);
  }
}

function notionMain(array $argv): int
{
  $options = notionOptions($argv);
  if ($options['help']) {
    fwrite(STDOUT,
      "Usage: php src/upload_notion.php [--content=<content_id>] [--all] [--force] [--dry-run]\n"
      . "  --content <content_id>  Upload exactly one article, regardless of the lookback period\n"
      . "  --all                   Backfill every eligible article from the two supported podcasts\n"
      . "  --force                 Upload even when the stored content hash is unchanged\n"
      . "  --dry-run               Show selected articles without calling Notion or recording a sync\n"
      . "  --database <path>       Override OUTPUT_PATH from settings.json\n"
      . "  --settings <path>       Override settings.json\n"
      . "  -h, --help              Show this help\n"
    );
    return 0;
  }

  DiscordProgress::start(__FILE__, $options['dry_run'] ? 'Notion 同期対象の確認を開始' : 'Notion への記事同期を開始', $options['settings']);
  foreach (['pdo_sqlite', 'curl'] as $extension) {
    if (!extension_loaded($extension)) {
      throw new RuntimeException("Required PHP extension is unavailable: {$extension}");
    }
  }

  $settingsPath = $options['settings'] ?? aggregatorSettingsPath();
  $settings = notionReadJson($settingsPath);
  $configuration = notionConfiguration($settings);
  $database = $options['database'] ?? notionResolvePath(notionRequiredString($settings, 'OUTPUT_PATH'));
  if (!is_file($database)) {
    throw new RuntimeException("SQLite database file does not exist: {$database}");
  }

  $db = new PDO('sqlite:' . $database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 30,
  ]);
  notionCheckSchema($db);
  notionEnsureSyncTable($db);
  $articles = notionSelectArticles($db, $options, $configuration['lookback_days']);
  if ($articles === [] && $options['content'] !== null) {
    throw new RuntimeException('Article not found, unsupported, or missing full text/LLM summary: ' . $options['content']);
  }

  $token = '';
  if (!$options['dry_run']) {
    $token = notionSecret($configuration['token_env']);
  }

  $uploaded = 0;
  $skipped = 0;
  $failed = 0;
  foreach ($articles as $article) {
    $hash = notionArticleHash($article);
    if (!$options['force'] && notionIsUnchanged($db, $article['content_id'], $hash)) {
      fwrite(STDERR, "Skipping unchanged [{$article['content_id']}] {$article['title']}\n");
      $skipped++;
      continue;
    }
    if ($options['dry_run']) {
      fwrite(STDOUT, "Would upload [{$article['content_id']}] {$article['title']}\n");
      $uploaded++;
      continue;
    }

    try {
      $title = DiscordProgress::label((string)$article['title']);
      DiscordProgress::send('📤', '記事を Notion に同期中', $title);
      fwrite(STDERR, "Uploading [{$article['content_id']}] {$article['title']}\n");
      $result = notionUploadArticle($article, $configuration, $token);
      notionRememberSync($db, $article['content_id'], $hash, $result['id'], $result['url']);
      fwrite(STDOUT, ($result['created'] ? 'Created' : 'Updated') . " Notion page: {$result['url']}\n");
      DiscordProgress::send('✅', $result['created'] ? 'Notion ページを作成' : 'Notion ページを更新', $title);
      $uploaded++;
    } catch (Throwable $error) {
      DiscordProgress::send('❌', '記事の Notion 同期が失敗', DiscordProgress::label((string)$article['content_id']));
      fwrite(STDERR, "Upload failed [{$article['content_id']}]: {$error->getMessage()}\n");
      $failed++;
    }
  }

  $completed = $options['dry_run'] ? '同期候補' : '同期';
  DiscordProgress::send($failed === 0 ? '✅' : '⚠️', 'Notion 同期の実行結果',
    "{$completed} {$uploaded} 件・スキップ {$skipped} 件・失敗 {$failed} 件");
  $completedLabel = $options['dry_run'] ? 'Selected' : 'Uploaded';
  fwrite(STDERR, "{$completedLabel}: {$uploaded} / Skipped: {$skipped} / Failed: {$failed}\n");
  return $failed === 0 ? 0 : 1;
}

function notionOptions(array $argv): array
{
  $options = [
    'content' => null, 'all' => false, 'force' => false, 'dry_run' => false,
    'database' => null, 'settings' => null, 'help' => false,
  ];
  for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-h' || $arg === '--help') {
      $options['help'] = true;
    } elseif ($arg === '--all') {
      $options['all'] = true;
    } elseif ($arg === '--force') {
      $options['force'] = true;
    } elseif ($arg === '--dry-run') {
      $options['dry_run'] = true;
    } elseif ($arg === '--content' || str_starts_with($arg, '--content=')) {
      $value = $arg === '--content' ? ($argv[++$i] ?? '') : substr($arg, 10);
      if (trim($value) === '' || str_starts_with($value, '--') || $options['content'] !== null) {
        throw new InvalidArgumentException('Specify --content once with a nonempty content_id');
      }
      $options['content'] = trim($value);
    } elseif ($arg === '--database' || str_starts_with($arg, '--database=')) {
      $value = $arg === '--database' ? ($argv[++$i] ?? '') : substr($arg, 11);
      if (trim($value) === '' || str_starts_with($value, '--')) {
        throw new InvalidArgumentException('Specify a nonempty path for --database');
      }
      $options['database'] = notionResolvePath(trim($value));
    } elseif ($arg === '--settings' || str_starts_with($arg, '--settings=')) {
      $value = $arg === '--settings' ? ($argv[++$i] ?? '') : substr($arg, 11);
      if (trim($value) === '' || str_starts_with($value, '--')) {
        throw new InvalidArgumentException('Specify a nonempty path for --settings');
      }
      $options['settings'] = notionResolvePath(trim($value));
    } else {
      throw new InvalidArgumentException("Unknown argument: {$arg}");
    }
  }
  if ($options['all'] && $options['content'] !== null) {
    throw new InvalidArgumentException('--all and --content cannot be used together');
  }
  return $options;
}

function notionReadJson(string $path): array
{
  $json = @file_get_contents($path);
  if ($json === false) {
    throw new RuntimeException("Cannot read configuration file: {$path}");
  }
  $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  if (!is_array($value)) {
    throw new RuntimeException('Configuration root must be an object');
  }
  return $value;
}

function notionConfiguration(array $settings): array
{
  if (!isset($settings['NOTION_UPLOAD']) || !is_array($settings['NOTION_UPLOAD'])) {
    throw new RuntimeException('Set NOTION_UPLOAD as an object in settings.json');
  }
  $config = $settings['NOTION_UPLOAD'];
  $lookback = $config['LOOKBACK_DAYS'] ?? 30;
  if (filter_var($lookback, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
    throw new RuntimeException('NOTION_UPLOAD.LOOKBACK_DAYS must be a positive integer');
  }
  $baseUrl = notionOptionalString($config, 'API_BASE_URL') ?? NOTION_API_BASE_URL;
  if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
    throw new RuntimeException('NOTION_UPLOAD.API_BASE_URL must be an HTTP(S) URL');
  }
  return [
    'data_source_id' => notionRequiredString($config, 'DATA_SOURCE_ID', 'NOTION_UPLOAD.DATA_SOURCE_ID'),
    'token_env' => notionOptionalString($config, 'TOKEN_ENV') ?? 'NOTION_TOKEN',
    'api_version' => notionOptionalString($config, 'API_VERSION') ?? NOTION_API_VERSION,
    'api_base_url' => rtrim($baseUrl, '/'),
    'lookback_days' => (int)$lookback,
    'timeout' => notionPositiveInteger($config, 'TIMEOUT', 60, 'NOTION_UPLOAD.TIMEOUT'),
  ];
}

function notionRequiredString(array $values, string $key, ?string $displayKey = null): string
{
  $value = notionOptionalString($values, $key);
  if ($value === null) {
    throw new RuntimeException('Set ' . ($displayKey ?? $key) . ' in settings.json');
  }
  return $value;
}

function notionOptionalString(array $values, string $key): ?string
{
  if (!isset($values[$key]) || !is_string($values[$key]) || trim($values[$key]) === '') {
    return null;
  }
  return trim($values[$key]);
}

function notionPositiveInteger(array $values, string $key, int $default, string $displayKey): int
{
  if (!isset($values[$key])) {
    return $default;
  }
  $value = filter_var($values[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($value === false) {
    throw new RuntimeException("{$displayKey} must be a positive integer");
  }
  return (int)$value;
}

function notionResolvePath(string $path): string
{
  if (!str_starts_with($path, '~')) {
    return $path;
  }
  $userDirectory = PHP_OS_FAMILY === 'Windows' ? getenv('USERPROFILE') : getenv('HOME');
  if (!$userDirectory && PHP_OS_FAMILY === 'Windows') {
    $drive = getenv('HOMEDRIVE');
    $relativeHome = getenv('HOMEPATH');
    $userDirectory = $drive && $relativeHome ? $drive . $relativeHome : false;
  }
  if (!$userDirectory) {
    throw new RuntimeException("Cannot expand home directory in path: {$path}");
  }
  return rtrim($userDirectory, '/\\') . DIRECTORY_SEPARATOR . ltrim(substr($path, 1), '/\\');
}

function notionCheckSchema(PDO $db): void
{
  $columns = $db->query('PRAGMA table_info(ARTICLE)')->fetchAll(PDO::FETCH_COLUMN, 1);
  $required = ['content_id', 'source_id', 'title', 'url', 'published_at', 'fulltext', 'llm_summary'];
  $missing = array_diff($required, $columns);
  if ($missing !== []) {
    throw new RuntimeException('Missing ARTICLE columns: ' . implode(', ', $missing));
  }
}

function notionEnsureSyncTable(PDO $db): void
{
  $db->exec('CREATE TABLE IF NOT EXISTS NOTION_SYNC (
    content_id TEXT PRIMARY KEY,
    content_hash TEXT NOT NULL,
    notion_page_id TEXT NOT NULL,
    notion_url TEXT NOT NULL,
    synced_at TEXT NOT NULL
  )');
}

function notionSelectArticles(PDO $db, array $options, int $lookbackDays): array
{
  $sql = "SELECT a.content_id, a.title, a.url, a.published_at, a.fulltext, a.llm_summary,
      a.llm_tags, a.llm_hashtags, s.source_cn
    FROM ARTICLE a INNER JOIN SOURCE s ON s.id = a.source_id
    WHERE s.source_cn IN ('takamichie', 'takamichie_gameonly')
      AND ((a.fulltext IS NOT NULL AND trim(a.fulltext) <> '')
        OR (a.llm_summary IS NOT NULL AND trim(a.llm_summary) <> ''))";
  $parameters = [];
  if ($options['content'] !== null) {
    $sql .= ' AND a.content_id = :content_id';
    $parameters[':content_id'] = $options['content'];
  } elseif (!$options['all']) {
    $cutoff = (new DateTimeImmutable('now'))->sub(new DateInterval('P' . $lookbackDays . 'D'))->format(DateTimeInterface::ATOM);
    $sql .= ' AND a.published_at >= :cutoff';
    $parameters[':cutoff'] = $cutoff;
  }
  $query = $db->prepare($sql . ' ORDER BY a.published_at, a.content_id');
  $query->execute($parameters);
  return $query->fetchAll(PDO::FETCH_ASSOC);
}

function notionArticleHash(array $article): string
{
  return hash('sha256', json_encode([
    NOTION_UPLOAD_HASH_VERSION,
    $article['source_cn'], $article['title'], $article['url'], $article['published_at'],
    $article['fulltext'], $article['llm_summary'], $article['llm_tags'], $article['llm_hashtags'],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function notionIsUnchanged(PDO $db, string $contentId, string $hash): bool
{
  $query = $db->prepare('SELECT 1 FROM NOTION_SYNC WHERE content_id = :id AND content_hash = :hash');
  $query->execute([':id' => $contentId, ':hash' => $hash]);
  return $query->fetchColumn() !== false;
}

function notionRememberSync(PDO $db, string $contentId, string $hash, string $pageId, string $url): void
{
  $query = $db->prepare('INSERT INTO NOTION_SYNC (content_id, content_hash, notion_page_id, notion_url, synced_at)
    VALUES (:id, :hash, :page_id, :url, :synced_at)
    ON CONFLICT(content_id) DO UPDATE SET content_hash = excluded.content_hash,
      notion_page_id = excluded.notion_page_id, notion_url = excluded.notion_url, synced_at = excluded.synced_at');
  $query->execute([
    ':id' => $contentId, ':hash' => $hash, ':page_id' => $pageId, ':url' => $url,
    ':synced_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
  ]);
}

function notionUploadArticle(array $article, array $configuration, string $token, ?callable $request = null): array
{
  $request ??= static fn(string $method, string $path, ?array $body): array =>
    notionApiRequest($method, $path, $body, $token, $configuration);

  $query = $request('POST', '/v1/data_sources/' . rawurlencode($configuration['data_source_id']) . '/query', [
    'filter' => ['property' => 'URL', 'url' => ['equals' => $article['url']]],
    'page_size' => 2,
  ]);
  $results = $query['results'] ?? null;
  if (!is_array($results)) {
    throw new RuntimeException('Notion data source query returned no results array');
  }
  if (count($results) > 1) {
    throw new RuntimeException('Multiple Notion pages have the same URL: ' . $article['url']);
  }

  $created = false;
  if ($results === []) {
    $page = $request('POST', '/v1/pages', notionCreatePagePayload($article, $configuration['data_source_id']));
    $created = true;
  } else {
    $page = $results[0];
  }
  if (!is_array($page) || !is_string($page['id'] ?? null)) {
    throw new RuntimeException('Notion page response did not include an id');
  }
  $pageId = $page['id'];

  $request('PATCH', '/v1/pages/' . rawurlencode($pageId), [
    'properties' => notionGeneratedProperties($article),
  ]);
  $fulltext = notionFulltext((string)($article['fulltext'] ?? ''));
  if ($fulltext !== '') {
    notionReplaceHeadingSection($pageId, '文字起こし', notionParagraphBlocks($fulltext), $request, ['見出し']);
  }

  $url = is_string($page['url'] ?? null) ? $page['url'] : 'https://www.notion.so/' . str_replace('-', '', $pageId);
  return ['id' => $pageId, 'url' => $url, 'created' => $created];
}

function notionCreatePagePayload(array $article, string $dataSourceId): array
{
  $title = notionPageTitle($article['title'], $article['source_cn']);
  $date = (new DateTimeImmutable($article['published_at']))->format('Y-m-d');
  $properties = [
    '名前' => ['title' => [['type' => 'text', 'text' => ['content' => $title]]]],
    'URL' => ['url' => $article['url']],
    '日付' => ['date' => ['start' => $date]],
  ] + notionGeneratedProperties($article);
  if ($article['source_cn'] === 'takamichie_gameonly') {
    $properties['タグ'] = ['multi_select' => [['name' => 'ゲームの話しかしないポッドキャスト']]];
  }
  return ['parent' => ['type' => 'data_source_id', 'data_source_id' => $dataSourceId], 'properties' => $properties];
}

function notionGeneratedProperties(array $article): array
{
  return [
    'コンテンツID' => ['rich_text' => notionRichTextProperty((string)$article['content_id'])],
    'LLM概要' => ['rich_text' => notionRichTextProperty(trim((string)($article['llm_summary'] ?? '')))],
    'LLMタグ' => ['rich_text' => notionRichTextProperty(notionLlmTagsText($article['llm_tags'] ?? null))],
    '自動同期日時' => ['date' => ['start' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM)]],
  ];
}

function notionLlmTagsText(mixed $value): string
{
  if ($value === null || $value === '') {
    return '';
  }
  if (is_string($value)) {
    try {
      $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
      throw new RuntimeException('ARTICLE.llm_tags is not valid JSON: ' . $error->getMessage(), 0, $error);
    }
  } else {
    $decoded = $value;
  }
  if (!is_array($decoded) || !array_is_list($decoded)) {
    throw new RuntimeException('ARTICLE.llm_tags must be a JSON array');
  }
  $tags = [];
  foreach ($decoded as $tag) {
    if (!is_string($tag) || trim($tag) === '' || preg_match('/[\r\n]/', $tag)) {
      throw new RuntimeException('ARTICLE.llm_tags contains an invalid tag');
    }
    $tags[] = trim($tag);
  }
  return implode(', ', array_values(array_unique($tags)));
}

function notionPageTitle(string $title, string $source): string
{
  if ($source === 'takamichie') {
    $title = preg_replace('/\A\d{4}\/\d{2}\/\d{2}\s+/u', '', $title) ?? $title;
  }
  return trim($title);
}

function notionReplaceHeadingSection(
  string $pageId,
  string $heading,
  array $children,
  callable $request,
  array $legacyHeadings = []
): void
{
  $blocks = notionAllChildren($pageId, $request);
  $headingIndex = null;
  $matchedHeading = null;
  foreach ($blocks as $index => $block) {
    $blockHeading = ($block['type'] ?? null) === 'heading_1'
      ? notionRichTextPlain($block['heading_1']['rich_text'] ?? []) : null;
    if ($blockHeading === $heading || in_array($blockHeading, $legacyHeadings, true)) {
      $headingIndex = $index;
      $matchedHeading = $blockHeading;
      break;
    }
  }

  if ($headingIndex === null) {
    $response = $request('PATCH', '/v1/blocks/' . rawurlencode($pageId) . '/children', [
      'children' => [[
        'object' => 'block', 'type' => 'heading_1',
        'heading_1' => ['rich_text' => notionRichText($heading), 'is_toggleable' => false],
      ]],
    ]);
    $headingId = $response['results'][0]['id'] ?? null;
    if (!is_string($headingId)) {
      throw new RuntimeException("Notion did not return the created heading: {$heading}");
    }
    notionInsertChildrenAfter($pageId, $children, $headingId, $request);
    return;
  }

  $headingBlock = $blocks[$headingIndex];
  $headingId = $headingBlock['id'] ?? null;
  if (!is_string($headingId)) {
    throw new RuntimeException("Heading has no block id: {$heading}");
  }
  if ($matchedHeading !== $heading) {
    $request('PATCH', '/v1/blocks/' . rawurlencode($headingId), [
      'heading_1' => ['rich_text' => notionRichText($heading), 'is_toggleable' => false],
    ]);
  }

  for ($index = $headingIndex + 1; $index < count($blocks); $index++) {
    $block = $blocks[$index];
    if (($block['type'] ?? null) === 'heading_1') {
      break;
    }
    if (is_string($block['id'] ?? null)) {
      $request('DELETE', '/v1/blocks/' . rawurlencode($block['id']), null);
    }
  }
  notionInsertChildrenAfter($pageId, $children, $headingId, $request);
}

function notionInsertChildrenAfter(string $pageId, array $children, string $afterBlockId, callable $request): void
{
  foreach (array_chunk($children, 100) as $chunk) {
    $response = $request('PATCH', '/v1/blocks/' . rawurlencode($pageId) . '/children', [
      'children' => $chunk,
      'position' => ['type' => 'after_block', 'after_block' => ['id' => $afterBlockId]],
    ]);
    $created = $response['results'] ?? null;
    if (!is_array($created) || count($created) !== count($chunk)) {
      throw new RuntimeException('Notion did not return all inserted transcript blocks');
    }
    $lastId = $created[array_key_last($created)]['id'] ?? null;
    if (!is_string($lastId)) {
      throw new RuntimeException('Notion inserted transcript block has no id');
    }
    $afterBlockId = $lastId;
  }
}

function notionParagraphBlocks(string $text): array
{
  $blocks = [];
  $paragraphs = preg_split('/\n[ \t]*\n/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  foreach ($paragraphs as $paragraph) {
    foreach (notionTextChunks(trim($paragraph)) as $chunk) {
      $blocks[] = ['object' => 'block', 'type' => 'paragraph',
        'paragraph' => ['rich_text' => notionRichText($chunk)]];
    }
  }
  return $blocks;
}

function notionTextChunks(string $text): array
{
  if ($text === '') {
    return [];
  }
  preg_match_all('/.{1,1800}/us', $text, $matches);
  return $matches[0] ?? [];
}

function notionRichText(string $text): array
{
  return [['type' => 'text', 'text' => ['content' => $text]]];
}

function notionRichTextProperty(string $text): array
{
  $richText = [];
  foreach (notionTextChunks($text) as $chunk) {
    $richText[] = ['type' => 'text', 'text' => ['content' => $chunk]];
  }
  return $richText;
}

function notionFulltext(string $text): string
{
  return hasWebVttHeader($text) ? notionTranscriptText($text) : trim($text);
}

function notionTranscriptText(string $vtt): string
{
  $vtt = str_replace(["\r\n", "\r"], "\n", trim($vtt));
  $vtt = preg_replace('/\A\xEF\xBB\xBF/', '', $vtt) ?? $vtt;
  if ($vtt === '') {
    return '';
  }
  if (!preg_match('/\AWEBVTT(?:[ \t][^\n]*)?\n/', $vtt)) {
    throw new RuntimeException('Invalid WebVTT header');
  }
  $cues = [];
  foreach (preg_split('/\n[ \t]*\n/', $vtt) ?: [] as $block) {
    if (preg_match('/\A(?:WEBVTT|NOTE|STYLE|REGION)(?:\s|$)/', $block)) {
      continue;
    }
    $lines = explode("\n", $block);
    $timing = str_contains($lines[0] ?? '', '-->') ? 0 : 1;
    if (!isset($lines[$timing]) || !str_contains($lines[$timing], '-->')) {
      continue;
    }
    $text = implode(' ', array_slice($lines, $timing + 1));
    $text = preg_replace('/<[^>]*>/', '', $text) ?? $text;
    $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($text !== '') {
      $cues[] = $text;
    }
  }
  return implode("\n\n", $cues);
}

