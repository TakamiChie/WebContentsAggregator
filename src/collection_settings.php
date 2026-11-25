<?php

declare(strict_types=1);

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
    throw new RuntimeException('Cannot determine the home directory for expanding ~ in OUTPUT_PATH');
  }

  $relativePath = ltrim(substr($path, 1), '/\\');
  return rtrim($home, '/\\') . DIRECTORY_SEPARATOR . $relativePath;
}

function readEntries(string $path): array
{
  $json = @file_get_contents($path);
  if ($json === false) {
    throw new RuntimeException("Cannot read configuration file: {$path}");
  }

  try {
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $error) {
    throw new RuntimeException("Invalid configuration JSON: {$error->getMessage()}");
  }

  if (!is_array($data)) {
    throw new RuntimeException('Configuration root must be an object or array');
  }

  return $data;
}

function readEntryGroup(array $entries, string $group): array
{
  if (!array_key_exists($group, $entries)) {
    return [];
  }
  if (!is_array($entries[$group])) {
    throw new RuntimeException("{$group} must be an array");
  }

  $result = [];
  foreach ($entries[$group] as $entry) {
    if (!is_array($entry)) {
      throw new RuntimeException("Each entry in {$group} must be an object");
    }
    $result[] = $entry;
  }

  return $result;
}

function readSourceId(array $entry, string $group): string
{
  if (!isset($entry['id']) || (!is_int($entry['id']) && !is_string($entry['id']))) {
    throw new RuntimeException("Missing or invalid id in {$group}");
  }

  $sourceId = trim((string)$entry['id']);
  if ($sourceId === '') {
    throw new RuntimeException("Missing or invalid id in {$group}");
  }

  return $sourceId;
}

function readString(array $entry, string $key, string $group): string
{
  if (!isset($entry[$key]) || !is_string($entry[$key])) {
    throw new RuntimeException("Missing or invalid {$key} in {$group}");
  }

  $value = trim($entry[$key]);
  if ($value === '') {
    throw new RuntimeException("Missing or invalid {$key} in {$group}");
  }

  return $value;
}
