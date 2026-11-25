<?php

declare(strict_types=1);

define('UPLOAD_NOTION_NO_MAIN', true);
require dirname(__DIR__) . '/src/upload_notion.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
  if ($expected !== $actual) {
    throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
  }
}

$options = notionOptions(['upload_notion.php', '--content=test-id', '--force', '--dry-run']);
assertSameValue('test-id', $options['content'], '--content should select one article');
assertSameValue(true, $options['force'], '--force should be parsed');
assertSameValue(true, $options['dry_run'], '--dry-run should be parsed');

$payload = notionCreatePagePayload([
  'content_id' => 'test-id',
  'title' => '2026/11/20 テスト記事',
  'url' => 'https://listen.style/p/takamichie/test',
  'published_at' => '2026-11-20T07:00:00+09:00',
  'llm_summary' => "概要1\n概要2",
  'llm_tags' => '["地域", "技術"]',
  'source_cn' => 'takamichie',
], 'data-source-id');
assertSameValue('テスト記事', $payload['properties']['名前']['title'][0]['text']['content'], 'Daily title date should be removed');
assertSameValue('2026-11-20', $payload['properties']['日付']['date']['start'], 'Published date should be preserved in JST offset');
assertSameValue('概要1' . "\n" . '概要2', $payload['properties']['LLM概要']['rich_text'][0]['text']['content'], 'Summary should be a property');
assertSameValue('地域, 技術', $payload['properties']['LLMタグ']['rich_text'][0]['text']['content'], 'Tags should be a text property');

$transcript = notionFulltext("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n最初の文\n\n00:00:01.000 --> 00:00:02.000\n次の文");
assertSameValue("最初の文\n\n次の文", $transcript, 'WebVTT cues should become readable paragraphs');
assertSameValue("本文\n\n次の段落", notionFulltext("  本文\n\n次の段落  "), 'Plain text should retain paragraph breaks');

$calls = [];
$topLevel = [
  ['id' => 'intro', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => '導入']]]],
  ['id' => 'outline-heading', 'type' => 'heading_1', 'heading_1' => ['rich_text' => [['plain_text' => '見出し']]]],
  ['id' => 'old-1', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => '古い本文']]]],
  ['id' => 'old-2', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => [['plain_text' => '古い箇条書き']]]],
  ['id' => 'reference-heading', 'type' => 'heading_1', 'heading_1' => ['rich_text' => [['plain_text' => '参照URL']]]],
  ['id' => 'reference-link', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'https://example.com']]]],
];
$deleted = [];
$inserted = [];
$updatedProperties = [];
$renamedHeading = null;
$request = static function (string $method, string $path, ?array $body) use (&$calls, &$topLevel, &$deleted, &$inserted, &$updatedProperties, &$renamedHeading): array {
  $calls[] = [$method, $path, $body];
  if ($method === 'POST' && str_ends_with($path, '/query')) {
    return ['results' => [[
      'id' => 'page-id',
      'url' => 'https://www.notion.so/page-id',
    ]]];
  }
  if ($method === 'GET' && str_starts_with($path, '/v1/blocks/page-id/children')) {
    return ['results' => $topLevel, 'has_more' => false, 'next_cursor' => null];
  }
  if ($method === 'PATCH' && $path === '/v1/pages/page-id') {
    $updatedProperties = $body['properties'];
    return ['id' => 'page-id'];
  }
  if ($method === 'PATCH' && $path === '/v1/blocks/outline-heading') {
    $renamedHeading = $body['heading_1']['rich_text'][0]['text']['content'] ?? null;
    return ['id' => 'outline-heading'];
  }
  if ($method === 'PATCH' && $path === '/v1/blocks/page-id/children') {
    $inserted = array_merge($inserted, $body['children']);
    $results = [];
    foreach ($body['children'] as $index => $child) {
      $results[] = ['id' => 'inserted-' . (count($inserted) + $index)];
    }
    return ['results' => $results];
  }
  if ($method === 'DELETE') {
    $deleted[] = basename($path);
    return ['archived' => true];
  }
  throw new RuntimeException("Unexpected mock request: {$method} {$path}");
};

$article = [
  'content_id' => 'test-id',
  'title' => '2026/11/20 テスト記事',
  'url' => 'https://listen.style/p/takamichie/test',
  'published_at' => '2026-11-20T07:00:00+09:00',
  'fulltext' => "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n文字起こし",
  'llm_summary' => "概要1\n概要2\n概要3\n概要4\n概要5",
  'llm_tags' => '[]',
  'llm_hashtags' => '[]',
  'source_cn' => 'takamichie',
];
$result = notionUploadArticle($article, ['data_source_id' => 'data-source-id'], 'unused', $request);
assertSameValue(false, $result['created'], 'Existing URL should update its page');
assertSameValue("概要1\n概要2\n概要3\n概要4\n概要5", $updatedProperties['LLM概要']['rich_text'][0]['text']['content'], 'Summary should update the property');
assertSameValue('', notionRichTextPlain($updatedProperties['LLMタグ']['rich_text']), 'Empty tag array should clear the property');
assertSameValue('文字起こし', $renamedHeading, 'Legacy 見出し caption should be renamed');
assertSameValue(['old-1', 'old-2'], $deleted, 'Only blocks below 文字起こし and before the next heading should be deleted');
assertSameValue('文字起こし', $inserted[0]['paragraph']['rich_text'][0]['text']['content'], 'Transcript should replace the heading section');
$insertCall = array_values(array_filter($calls, static fn(array $call): bool => $call[0] === 'PATCH' && $call[1] === '/v1/blocks/page-id/children'))[0];
assertSameValue('outline-heading', $insertCall[2]['position']['after_block']['id'], 'Transcript should be inserted immediately after 文字起こし');

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upload-notion-test-' . bin2hex(random_bytes(6));
if (!mkdir($directory)) {
  throw new RuntimeException("Cannot create test directory: {$directory}");
}
$database = $directory . DIRECTORY_SEPARATOR . 'test.sqlite3';
try {
  $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $db->exec('CREATE TABLE SOURCE (id INTEGER PRIMARY KEY, source_cn TEXT NOT NULL)');
  $db->exec('CREATE TABLE ARTICLE (
    content_id TEXT PRIMARY KEY, source_id INTEGER, title TEXT, url TEXT, published_at TEXT,
    fulltext TEXT, llm_summary TEXT, llm_tags TEXT, llm_hashtags TEXT
  )');
  $db->exec("INSERT INTO SOURCE VALUES (1, 'takamichie'), (2, 'other')");
  $insert = $db->prepare('INSERT INTO ARTICLE VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
  $insert->execute(['chosen', 1, 'Chosen', 'https://example.com/chosen', '2020-01-01T00:00:00+09:00', 'WEBVTT', 'summary', '[]', '[]']);
  $insert->execute(['other', 2, 'Other', 'https://example.com/other', '2026-11-20T00:00:00+09:00', 'WEBVTT', 'summary', '[]', '[]']);
  notionEnsureSyncTable($db);
  $selected = notionSelectArticles($db, ['content' => 'chosen', 'all' => false], 30);
  assertSameValue(1, count($selected), '--content should bypass lookback and exclude unsupported sources');
  notionRememberSync($db, 'chosen', 'hash', 'page-id', 'https://www.notion.so/page-id');
  assertSameValue(true, notionIsUnchanged($db, 'chosen', 'hash'), 'Sync hash should be remembered');
} finally {
  $insert = null;
  $selected = null;
  $db = null;
  if (is_file($database)) {
    unlink($database);
  }
  if (is_dir($directory)) {
    rmdir($directory);
  }
}

fwrite(STDOUT, "upload_notion_test: OK\n");

