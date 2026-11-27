<?php

declare(strict_types=1);

function writeArticlesToDatabase(string $databaseFile, array $articles, bool $includeFulltext = false): array
{
  if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('PDO SQLite extension is unavailable');
  }
  if (!is_dir(dirname($databaseFile))) {
    throw new RuntimeException('SQLite output directory does not exist: ' . dirname($databaseFile));
  }

  try {
    $pdo = new PDO('sqlite:' . $databaseFile, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $exists = $pdo->query(
      "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ARTICLE'"
    )->fetchColumn();
    if ($exists === false) {
      throw new RuntimeException('Required table is missing: ARTICLE');
    }

    $extraColumns = $includeFulltext ? ['fulltext'] : [];
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
      'INSERT INTO "ARTICLE" '
      . '("title", "url", "published_at", "cover_art", "summary", "hashtags", '
      . '"source_id", "content_id"' . ($includeFulltext ? ', "fulltext"' : '') . ') '
      . 'VALUES (:title, :url, :published_at, :cover_art, :summary, :hashtags, '
      . ':source_id, :content_id' . ($includeFulltext ? ', :fulltext' : '') . ')'
      . articleUpsertClause($extraColumns)
    );
    $changedTitles = [];
    foreach ($articles as $article) {
      $row = articleRow($article);
      if ($includeFulltext) {
        $row[':fulltext'] = $article['fulltext'];
      }
      $statement->execute($row);
      if ($statement->rowCount() > 0) {
        $changedTitles[] = $article['title'];
      }
    }
    $pdo->commit();
    return $changedTitles;
  } catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw new RuntimeException('Failed to write to SQLite: ' . $error->getMessage(), 0, $error);
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

function articleUpsertClause(array $extraColumns = []): string
{
  $columns = array_merge(['title', 'url', 'published_at', 'cover_art', 'summary', 'hashtags', 'source_id'], $extraColumns);
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
