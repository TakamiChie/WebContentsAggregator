<?php

declare(strict_types=1);

require_once __DIR__ . '/../collection_settings.php';
require_once __DIR__ . '/blog_article.php';
require_once __DIR__ . '/notion_api.php';

function collectNotionBlog(array $blog, string $databaseFile): void
{
  $sourceId = readSourceId($blog, 'blogs');
  $databaseId = readString($blog, 'database_id', "Notion blog {$sourceId}");
  $url = readString($blog, 'url', "Notion blog {$sourceId}");
  $tokenEnv = readString($blog + ['token_env' => 'NOTION_TOKEN'], 'token_env', "Notion blog {$sourceId}");
  $token = notionSecret($tokenEnv);
  $configuration = [
    'api_base_url' => NOTION_API_BASE_URL,
    'api_version' => NOTION_API_VERSION,
    'timeout' => 60,
  ];
  $request = static fn(string $method, string $path, ?array $body): array =>
    notionApiRequest($method, $path, $body, $token, $configuration);

  $articles = notionBlogArticles($sourceId, $databaseId, $url, $request, new DateTimeImmutable());
  if ($articles !== []) {
    writeArticlesToDatabase($databaseFile, $articles, includeFulltext: true);
  }
  fwrite(STDERR, sprintf("Notion blog %s: %d articles processed\n", $sourceId, count($articles)));
}

function notionBlogArticles(
  string $sourceId,
  string $databaseId,
  string $siteUrl,
  callable $request,
  DateTimeImmutable $now
): array {
  $database = $request('GET', '/v1/databases/' . rawurlencode($databaseId), null);
  $dataSources = $database['data_sources'] ?? [];
  if (!is_array($dataSources) || count($dataSources) !== 1 || !is_string($dataSources[0]['id'] ?? null)) {
    throw new RuntimeException("Expected one Notion data source in database {$databaseId}");
  }
  $queryPath = '/v1/data_sources/' . rawurlencode($dataSources[0]['id']) . '/query';
  $query = [
    'page_size' => 100,
    'filter' => ['and' => [
      ['property' => '投稿形式', 'select' => ['equals' => 'Notes']],
      ['property' => '日付', 'date' => ['is_not_empty' => true]],
      ['property' => '時刻', 'select' => ['is_not_empty' => true]],
    ]],
  ];

  $articles = [];
  $timezone = new DateTimeZone('Asia/Tokyo');
  do {
    $response = $request('POST', $queryPath, $query);
    if (!is_array($response['results'] ?? null) || ($response['request_status']['type'] ?? null) === 'incomplete') {
      throw new RuntimeException('Notion returned incomplete blog query results');
    }
    foreach ($response['results'] as $page) {
      $properties = $page['properties'] ?? [];
      $publishedAt = notionNotePublishedAt($properties, $timezone);
      if ($publishedAt === null || $publishedAt >= $now) {
        continue;
      }

      $pageId = $page['id'] ?? null;
      if (!is_string($pageId) || $pageId === '') {
        throw new RuntimeException('Notion Notes page response did not include an id');
      }
      $title = trim(notionRichTextPlain($properties['名前']['title'] ?? []));
      $url = $properties['URL']['url'] ?? '';
      $publicUrl = is_string($url) && preg_match('~\Ahttps?://~i', $url) ? $url : $siteUrl;
      $fulltext = implode("\n\n", iterator_to_array(notionNoteBody($pageId, $request), false));
      $articles[] = [
        'content_id' => 'notion:' . $pageId,
        'source_id' => $sourceId,
        'title' => $title !== '' ? $title : 'Untitled Note',
        'url' => $publicUrl,
        'published_at' => $publishedAt->format(DateTimeInterface::ATOM),
        'cover_art' => $page['cover']['external']['url'] ?? '',
        'summary' => $fulltext,
        'hashtags' => extractHashtags($fulltext),
        'fulltext' => $fulltext,
      ];
    }

    if (!($response['has_more'] ?? false)) {
      break;
    }
    $cursor = $response['next_cursor'] ?? null;
    if (!is_string($cursor) || $cursor === '' || $cursor === ($query['start_cursor'] ?? null)) {
      throw new RuntimeException('Notion blog query did not return a new pagination cursor');
    }
    $query['start_cursor'] = $cursor;
  } while (true);

  return $articles;
}

function notionNotePublishedAt(array $properties, DateTimeZone $timezone): ?DateTimeImmutable
{
  $date = $properties['日付']['date']['start'] ?? '';
  $time = $properties['時刻']['select']['name'] ?? '';
  if (($properties['投稿形式']['select']['name'] ?? null) !== 'Notes'
      || !preg_match('/\A(\d{4}-\d{2}-\d{2})(?:T|\z)/', $date, $dateParts)
      || !preg_match('/\A(?:[01]?\d|2[0-3]):[0-5]\d\z/', $time)) {
    return null;
  }

  $publishedAt = DateTimeImmutable::createFromFormat('!Y-m-d G:i', $dateParts[1] . ' ' . $time, $timezone);
  $errors = DateTimeImmutable::getLastErrors();
  return $publishedAt !== false && ($errors['warning_count'] ?? 0) === 0 ? $publishedAt : null;
}

function notionNoteBody(string $blockId, callable $request): Generator
{
  foreach (notionAllChildren($blockId, $request) as $block) {
    $type = $block['type'];
    $text = trim(notionRichTextPlain($block[$type]['rich_text'] ?? []));
    if ($text !== '') {
      yield $text;
    }
    if (($block['has_children'] ?? false) && !in_array($type, ['child_page', 'child_database'], true)) {
      yield from notionNoteBody($block['id'], $request);
    }
  }
}
