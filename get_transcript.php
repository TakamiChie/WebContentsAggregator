<?php

declare(strict_types=1);

// Required schema (apply manually): ARTICLE.transcript_vtt TEXT NULL DEFAULT NULL.
// Stores the complete WebVTT document; NULL or an empty string means not downloaded.
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  try {
    exit(transcriptMain($argv));
  } catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
  }
}

function transcriptMain(array $argv): int
{
  $database = null;
  foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
      fwrite(STDOUT, "Usage: php get_transcript.php [--database=/path/to/mediadata.sqlite3]\nDB: OUTPUT_PATH in setting.json (fallback: settings.json)\n");
      return 0;
    }
    if (!str_starts_with($arg, '--database=') || substr($arg, 11) === '') {
      throw new InvalidArgumentException("Invalid argument: {$arg}");
    }
    $database = substr($arg, 11);
  }
  $database ??= transcriptDatabasePath(__DIR__);
  if (!is_file($database)) {
    throw new RuntimeException("SQLite database file does not exist: {$database}");
  }
  if (!extension_loaded('curl')) {
    throw new RuntimeException('The PHP curl extension is required');
  }
  $db = new PDO('sqlite:' . $database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
  ]);
  $columns = $db->query('PRAGMA table_info(ARTICLE)')->fetchAll(PDO::FETCH_COLUMN, 1);
  if (!in_array('transcript_vtt', $columns, true)) {
    throw new RuntimeException('Required column missing: ARTICLE.transcript_vtt (TEXT NULL DEFAULT NULL). Add it before running this script.');
  }
  return collectTranscripts($db, 'downloadTranscript');
}

function transcriptDatabasePath(string $directory): string
{
  $file = $directory . '/setting.json';
  if (!is_file($file)) {
    // The existing aggregator uses the plural filename.
    $file = $directory . '/settings.json';
  }
  $json = @file_get_contents($file);
  if ($json === false) {
    throw new RuntimeException("Cannot read configuration file: {$file}");
  }
  $settings = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  $path = is_array($settings) ? ($settings['OUTPUT_PATH'] ?? null) : null;
  if (!is_string($path) || trim($path) === '') {
    throw new RuntimeException('Set OUTPUT_PATH to the database path in the configuration file');
  }
  $path = trim($path);
  if (str_starts_with($path, '~')) {
    $userDirectory = PHP_OS_FAMILY === 'Windows' ? getenv('USERPROFILE') : getenv('HOME');
    if (PHP_OS_FAMILY === 'Windows' && !$userDirectory) {
      $drive = getenv('HOMEDRIVE');
      $relativeHome = getenv('HOMEPATH');
      $userDirectory = $drive && $relativeHome ? $drive . $relativeHome : false;
    }
    if (!$userDirectory) {
      throw new RuntimeException('Cannot determine the home directory for expanding ~ in OUTPUT_PATH');
    }
    return rtrim($userDirectory, '/\\') . DIRECTORY_SEPARATOR . ltrim(substr($path, 1), '/\\');
  }
  // Resolve relative paths beside the configuration, independently of the caller's cwd.
  if ($path[0] !== '/' && $path[0] !== '\\' && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
    return $directory . DIRECTORY_SEPARATOR . $path;
  }
  return $path;
}

function transcriptUrl(string $url): ?string
{
  $parts = parse_url(trim($url));
  if ($parts === false
    || strtolower($parts['host'] ?? '') !== 'listen.style'
    || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
    || isset($parts['user']) || isset($parts['pass'])
    || (isset($parts['port']) && $parts['port'] !== 443 && $parts['port'] !== 80)) {
    return null;
  }
  // Query strings and fragments are not part of the episode path.
  return 'https://listen.style' . rtrim($parts['path'] ?? '', '/') . '/transcript.vtt';
}

function collectTranscripts(PDO $db, callable $download): int
{
  $rows = $db->query("SELECT a.content_id, a.url FROM ARTICLE a
    INNER JOIN SOURCE s ON s.id = a.source_id
    WHERE s.source_type = 'podcast'
      AND (a.transcript_vtt IS NULL)
    ORDER BY a.content_id")->fetchAll(PDO::FETCH_ASSOC);
  $update = $db->prepare("UPDATE ARTICLE SET transcript_vtt = :vtt
    WHERE content_id = :id AND url = :url
      AND (transcript_vtt IS NULL OR transcript_vtt = '')");
  $saved = 0;
  $failed = 0;
  foreach ($rows as $row) {
    $url = transcriptUrl($row['url']);
    if ($url === null) {
      continue;
    }
    try {
      try {
        $vtt = $download($url);
      } finally {
        // Throttle failed requests as well as successful downloads.
        usleep(1000000);
      }
      if (!is_string($vtt) || !preg_match('/\A(?:\xEF\xBB\xBF)?WEBVTT(?:[ \t][^\r\n]*)?(?:\r\n|\r|\n)/', $vtt)) {
        throw new RuntimeException('Invalid WebVTT format');
      }
      $update->execute([':vtt' => $vtt, ':id' => $row['content_id'], ':url' => $row['url']]);
      $saved += $update->rowCount();
    } catch (Throwable $error) {
      $failed++;
      fwrite(STDERR, "Download/save failed [{$row['content_id']}]: {$error->getMessage()}\n");
    }
  }
  fwrite(STDOUT, "Saved: {$saved} / Failed: {$failed}\n");
  return $failed === 0 ? 0 : 1;
}

function downloadTranscript(string $url): string
{
  echo "Downloading transcript: {$url}\n";
  $curl = curl_init($url);
  if ($curl === false) {
    throw new RuntimeException('Cannot initialize cURL');
  }
  try {
    curl_setopt_array($curl, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_USERAGENT => 'WebContentsAggregator/1.0',
      CURLOPT_HTTPHEADER => ['Accept: text/vtt'],
    ]);
    $body = curl_exec($curl);
    if ($body === false) {
      throw new RuntimeException('Download failed: ' . curl_error($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($status !== 200) {
      throw new RuntimeException("HTTP {$status}: {$url}");
    }
    return $body;
  } finally {
    curl_close($curl);
  }
}
