<?php

declare(strict_types=1);

const UPLOAD_SETTINGS_FILE = __DIR__ . '/settings.json';

if (!defined('UPLOAD_DATABASE_NO_MAIN')) {
  main();
}

function main(): void
{
  try {
    $settings = uploadReadSettings(UPLOAD_SETTINGS_FILE);
    $database = uploadResolvePath(uploadRequiredString($settings, 'OUTPUT_PATH'));
    $uploadSettings = uploadRequiredObject($settings, 'DATABASE_UPLOAD');
    $url = uploadRequiredString($uploadSettings, 'URL', 'DATABASE_UPLOAD.URL');

    if (!is_file($database) || !is_readable($database)) {
      throw new RuntimeException("SQLite database is not readable: {$database}");
    }
    foreach (['pdo_sqlite', 'curl'] as $extension) {
      if (!extension_loaded($extension)) {
        throw new RuntimeException("Required PHP extension is unavailable: {$extension}");
      }
    }

    $snapshot = tempnam(sys_get_temp_dir(), 'web-contents-db-');
    if ($snapshot === false) {
      throw new RuntimeException('Cannot create a temporary database file');
    }
    // VACUUM INTO requires the destination not to exist.
    if (!unlink($snapshot)) {
      throw new RuntimeException("Cannot prepare the temporary database file: {$snapshot}");
    }

    try {
      uploadCreateSnapshot($database, $snapshot);
      uploadFile($snapshot, $url, $uploadSettings);
      fwrite(STDOUT, sprintf("Uploaded SQLite database (%d bytes) to %s\n", filesize($snapshot), uploadSafeUrl($url)));
    } finally {
      if (is_file($snapshot)) {
        @unlink($snapshot);
      }
    }
  } catch (Throwable $error) {
    fwrite(STDERR, "Database upload failed: {$error->getMessage()}\n");
    exit(1);
  }
}

function uploadCreateSnapshot(string $source, string $destination): void
{
  $pdo = new PDO('sqlite:' . $source, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 30,
  ]);
  $quotedDestination = $pdo->quote($destination);
  $pdo->exec("VACUUM INTO {$quotedDestination}");
  $pdo = null;

  if (!is_file($destination) || filesize($destination) === 0) {
    throw new RuntimeException('Failed to create a consistent SQLite snapshot');
  }
}

function uploadFile(string $file, string $url, array $settings): void
{
  $handle = fopen($file, 'rb');
  if ($handle === false) {
    throw new RuntimeException("Cannot open snapshot for upload: {$file}");
  }

  $curl = curl_init();
  if ($curl === false) {
    fclose($handle);
    throw new RuntimeException('Cannot initialize cURL');
  }

  $options = [
    CURLOPT_URL => $url,
    CURLOPT_UPLOAD => true,
    CURLOPT_INFILE => $handle,
    CURLOPT_INFILESIZE => filesize($file),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => uploadPositiveInteger($settings, 'TIMEOUT', 300, 'DATABASE_UPLOAD.TIMEOUT'),
    CURLOPT_FAILONERROR => true,
  ];

  $username = uploadOptionalString($settings, 'USERNAME');
  $password = uploadSecret($settings, 'PASSWORD_ENV', 'DATABASE_UPLOAD.PASSWORD_ENV');
  if ($username !== null) {
    $options[CURLOPT_USERNAME] = $username;
  }
  if ($password !== null) {
    $options[CURLOPT_PASSWORD] = $password;
  }

  $privateKey = uploadOptionalString($settings, 'PRIVATE_KEY');
  if ($privateKey !== null) {
    $options[CURLOPT_SSH_PRIVATE_KEYFILE] = uploadResolvePath($privateKey);
  }
  $publicKey = uploadOptionalString($settings, 'SSH_PUBLIC_KEY');
  if ($publicKey !== null) {
    $options[CURLOPT_SSH_PUBLIC_KEYFILE] = uploadResolvePath($publicKey);
  }

  curl_setopt_array($curl, $options);
  try {
    if (curl_exec($curl) === false) {
      throw new RuntimeException(curl_error($curl), curl_errno($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($status >= 400) {
      throw new RuntimeException("Remote server returned HTTP status {$status}");
    }
  } finally {
    curl_close($curl);
    fclose($handle);
  }
}

function uploadReadSettings(string $path): array
{
  $json = @file_get_contents($path);
  if ($json === false) {
    throw new RuntimeException("Cannot read configuration file: {$path}");
  }
  $settings = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  if (!is_array($settings)) {
    throw new RuntimeException('Configuration root must be an object');
  }
  return $settings;
}

function uploadRequiredObject(array $settings, string $key): array
{
  if (!isset($settings[$key]) || !is_array($settings[$key])) {
    throw new RuntimeException("Set {$key} as an object in settings.json");
  }
  return $settings[$key];
}

function uploadRequiredString(array $settings, string $key, ?string $displayKey = null): string
{
  $value = uploadOptionalString($settings, $key);
  if ($value === null) {
    throw new RuntimeException('Set ' . ($displayKey ?? $key) . ' in settings.json');
  }
  return $value;
}

function uploadOptionalString(array $settings, string $key): ?string
{
  if (!isset($settings[$key]) || !is_string($settings[$key]) || trim($settings[$key]) === '') {
    return null;
  }
  return trim($settings[$key]);
}

function uploadSecret(array $settings, string $key, ?string $displayKey = null): ?string
{
  $environmentName = uploadOptionalString($settings, $key);
  if ($environmentName === null) {
    return null;
  }
  $value = getenv($environmentName);
  if ($value === false || $value === '') {
    throw new RuntimeException('Environment variable ' . $environmentName . ' referenced by ' . ($displayKey ?? $key) . ' is empty');
  }
  return $value;
}

function uploadPositiveInteger(array $settings, string $key, int $default, ?string $displayKey = null): int
{
  if (!isset($settings[$key])) {
    return $default;
  }
  $value = filter_var($settings[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($value === false) {
    throw new RuntimeException(($displayKey ?? $key) . ' must be a positive integer');
  }
  return $value;
}

function uploadResolvePath(string $path): string
{
  if (!str_starts_with($path, '~')) {
    return $path;
  }
  $home = PHP_OS_FAMILY === 'Windows' ? getenv('USERPROFILE') : getenv('HOME');
  if ($home === false || $home === '') {
    throw new RuntimeException("Cannot expand home directory in path: {$path}");
  }
  return rtrim($home, '/\\') . DIRECTORY_SEPARATOR . ltrim(substr($path, 1), '/\\');
}

function uploadSafeUrl(string $url): string
{
  return preg_replace('~^(https?|sftp|scp|ftps?)://[^/@]+@~i', '$1://***@', $url) ?? $url;
}
