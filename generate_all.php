<?php

declare(strict_types=1);

const ENTRIES_FILE = __DIR__ . '/settings.json';

main();

function main(): void
{
  $entries = readEntries(ENTRIES_FILE);
  $databaseFile = resolveOutputPath(readString($entries, 'OUTPUT_PATH', '設定ファイル'));
  $databaseOption = '--database=' . $databaseFile;
  $processed = 0;
  foreach (readEntryGroup($entries['CONTENTS'], 'podcasts') as $entry) {
    runPhpScript(__DIR__ . '/tools/podcast_load.php', [
      readSourceId($entry, 'podcasts'),
      readString($entry, 'url', 'podcasts'),
      $databaseOption,
      '--limit=unlimited',
    ]);
    $processed++;
  }

  foreach (readEntryGroup($entries['CONTENTS'], 'blogs') as $entry) {
    runPhpScript(__DIR__ . '/tools/blog_load.php', [
      readSourceId($entry, 'blogs'),
      readString($entry, 'url', 'blogs'),
      $databaseOption,
    ]);
    $processed++;
  }

  foreach (readEntryGroup($entries['CONTENTS'], 'youtube') as $entry) {
    runPhpScript(__DIR__ . '/tools/youtube_load.php', [
      readSourceId($entry, 'youtube'),
      readString($entry, 'pid', 'youtube'),
      $databaseOption,
    ]);
    $processed++;
  }

  fwrite(
    STDOUT,
    sprintf("%d件のWebメディアをSQLiteへ書き込みました: %s\n", $processed, $databaseFile)
  );
}

function resolveOutputPath(string $path): string
{
  if (!str_starts_with($path, '~')) {
    return $path;
  }

  if (PHP_OS_FAMILY === 'Windows') {
    $home = getenv('USERPROFILE');
    if ($home === false || $home === '') {
      $drive = getenv('HOMEDRIVE');
      $homePath = getenv('HOMEPATH');
      $home = ($drive !== false && $drive !== '' && $homePath !== false && $homePath !== '')
        ? $drive . $homePath
        : false;
    }
  } else {
    $home = getenv('HOME');
  }

  if ($home === false || $home === '') {
    fail('OUTPUT_PATHの~を展開するホームディレクトリを取得できませんでした');
  }

  $relativePath = ltrim(substr($path, 1), '/\\');
  return rtrim($home, '/\\') . DIRECTORY_SEPARATOR . $relativePath;
}

function readEntries(string $path): array
{
  $json = @file_get_contents($path);
  if ($json === false) {
    fail("設定ファイルを読み込めませんでした: {$path}");
  }

  try {
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $error) {
    fail("設定ファイルのJSONが不正です: {$error->getMessage()}");
  }

  if (!is_array($data)) {
    fail('設定ファイルのルートは配列である必要があります');
  }

  return $data;
}

function readEntryGroup(array $entries, string $group): array
{
  if (!array_key_exists($group, $entries)) {
    return [];
  }
  if (!is_array($entries[$group])) {
    fail("{$group} は配列である必要があります");
  }

  $result = [];
  foreach ($entries[$group] as $entry) {
    if (!is_array($entry)) {
      fail("{$group} の各要素はオブジェクトである必要があります");
    }
    $result[] = $entry;
  }

  return $result;
}

function readSourceId(array $entry, string $group): string
{
  if (!isset($entry['id']) || (!is_int($entry['id']) && !is_string($entry['id']))) {
    fail("{$group} の項目に有効なidがありません");
  }

  $sourceId = trim((string)$entry['id']);
  if ($sourceId === '') {
    fail("{$group} の項目に有効なidがありません");
  }

  return $sourceId;
}

function readString(array $entry, string $key, string $group): string
{
  if (!isset($entry[$key]) || !is_string($entry[$key])) {
    fail("{$group} の項目に有効な{$key}がありません");
  }

  $value = trim($entry[$key]);
  if ($value === '') {
    fail("{$group} の項目に有効な{$key}がありません");
  }

  return $value;
}

function runPhpScript(string $scriptPath, array $args): void
{
  if (!is_file($scriptPath)) {
    fail("スクリプトが見つかりません: {$scriptPath}");
  }

  $command = array_merge(
    [PHP_BINARY, $scriptPath],
    array_map(static fn(mixed $arg): string => (string)$arg, $args)
  );

  $output = fopen('php://temp', 'w+');
  if ($output === false) {
    fail('子スクリプトの出力先を作成できませんでした');
  }

  $process = proc_open(
    $command,
    [0 => STDIN, 1 => $output, 2 => STDERR],
    $pipes,
    __DIR__
  );
  if (!is_resource($process)) {
    fclose($output);
    fail("スクリプトを起動できませんでした: {$scriptPath}");
  }

  $exitCode = proc_close($process);
  fclose($output);

  if ($exitCode !== 0) {
    fail("スクリプトの実行に失敗しました: {$scriptPath}", $exitCode);
  }
}

function fail(string $message, int $exitCode = 1): never
{
  fwrite(STDERR, "エラー: {$message}\n");
  exit($exitCode);
}
