<?php

declare(strict_types=1);

define('UPLOAD_DATABASE_NO_MAIN', true);
require dirname(__DIR__) . '/src/upload_database.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upload-database-test-' . bin2hex(random_bytes(6));
if (!mkdir($directory)) {
  throw new RuntimeException("Cannot create test directory: {$directory}");
}

$source = $directory . DIRECTORY_SEPARATOR . 'source.sqlite3';
$snapshot = $directory . DIRECTORY_SEPARATOR . 'snapshot.sqlite3';
$uploaded = $directory . DIRECTORY_SEPARATOR . 'uploaded.sqlite3';
$exportDirectory = $directory . DIRECTORY_SEPARATOR . 'sql';
$sqlFile = $exportDirectory . DIRECTORY_SEPARATOR . 'ARTICLE.sql';

try {
  $db = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $schema = 'CREATE TABLE ARTICLE (content_id TEXT PRIMARY KEY, source_id INTEGER, summary TEXT, llm_tags TEXT)';
  $db->exec($schema);
  $article = ['content_id' => 'verified', 'source_id' => 42, 'summary' => "日本語 'quote'\r\nline\0end", 'llm_tags' => null];
  $insert = $db->prepare('INSERT INTO ARTICLE VALUES (?, ?, ?, ?)');
  $insert->execute(array_values($article));
  $insert = null;
  $db = null;

  uploadCreateSnapshot($source, $snapshot);
  if (uploadExportArticles($snapshot, $exportDirectory) !== $sqlFile) {
    throw new RuntimeException('SQL export did not use the expected path');
  }
  file_put_contents($sqlFile, 'old export');
  uploadExportArticles($snapshot, $exportDirectory);
  uploadFile($snapshot, uploadFileUrl($uploaded), ['TIMEOUT' => 30]);

  $result = new PDO('sqlite:' . $uploaded, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $uploadedArticle = $result->query('SELECT * FROM ARTICLE')->fetch(PDO::FETCH_ASSOC);
  if ($uploadedArticle !== $article) {
    throw new RuntimeException('Uploaded database content did not match');
  }
  $result = null;

  $restored = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $restored->exec($schema);
  $restored->exec(file_get_contents($sqlFile));
  if ($restored->query('SELECT * FROM ARTICLE')->fetchAll(PDO::FETCH_ASSOC) !== [$article]) {
    throw new RuntimeException('Exported SQL did not restore the original article');
  }
  fwrite(STDOUT, "upload_database_test: OK\n");
} finally {
  foreach ([$source, $snapshot, $uploaded, $sqlFile] as $file) {
    if (is_file($file)) {
      unlink($file);
    }
  }
  if (is_dir($exportDirectory)) {
    rmdir($exportDirectory);
  }
  if (is_dir($directory)) {
    rmdir($directory);
  }
}

function uploadFileUrl(string $path): string
{
  $normalized = str_replace('\\', '/', $path);
  return PHP_OS_FAMILY === 'Windows' ? 'file:///' . $normalized : 'file://' . $normalized;
}
