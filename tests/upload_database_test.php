<?php

declare(strict_types=1);

define('UPLOAD_DATABASE_NO_MAIN', true);
require dirname(__DIR__) . '/upload_database.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'upload-database-test-' . bin2hex(random_bytes(6));
if (!mkdir($directory)) {
  throw new RuntimeException("Cannot create test directory: {$directory}");
}

$source = $directory . DIRECTORY_SEPARATOR . 'source.sqlite3';
$snapshot = $directory . DIRECTORY_SEPARATOR . 'snapshot.sqlite3';
$uploaded = $directory . DIRECTORY_SEPARATOR . 'uploaded.sqlite3';

try {
  $db = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $db->exec('CREATE TABLE sample (value TEXT NOT NULL)');
  $db->exec("INSERT INTO sample VALUES ('verified')");
  $db = null;

  uploadCreateSnapshot($source, $snapshot);
  uploadFile($snapshot, uploadFileUrl($uploaded), ['TIMEOUT' => 30]);

  $result = new PDO('sqlite:' . $uploaded, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  if ($result->query('SELECT value FROM sample')->fetchColumn() !== 'verified') {
    throw new RuntimeException('Uploaded database content did not match');
  }
  $result = null;
  fwrite(STDOUT, "upload_database_test: OK\n");
} finally {
  foreach ([$source, $snapshot, $uploaded] as $file) {
    if (is_file($file)) {
      unlink($file);
    }
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
