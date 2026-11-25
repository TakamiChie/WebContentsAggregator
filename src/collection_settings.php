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

function parseCollectionOptions(array $argv, ?string $settings = null, bool $allowPageRange = false): array
{
  $options = ['blog-id' => null, 'blog-page' => null];
  if ($settings !== null) {
    $options['settings'] = null;
  }

  for ($index = 1; $index < count($argv); $index++) {
    $argument = $argv[$index];
    if ($argument === '--help' || $argument === '-h') {
      return ['help' => true, 'settings' => $settings] + $options;
    }
    [$flag, $value] = array_pad(explode('=', $argument, 2), 2, null);
    $key = substr($flag, 2);
    if (!str_starts_with($flag, '--') || !array_key_exists($key, $options)) {
      throw new RuntimeException("Invalid argument: {$argument}");
    }
    if ($value === null && isset($argv[$index + 1]) && !str_starts_with($argv[$index + 1], '-')) {
      $value = $argv[++$index];
    }
    if ($value === null || trim($value) === '') {
      throw new RuntimeException("Missing value for {$flag}");
    }
    if ($options[$key] !== null) {
      throw new RuntimeException("Duplicate argument: {$flag}");
    }
    $options[$key] = trim($value);
  }

  if ($options['blog-page'] !== null) {
    if ($options['blog-id'] === null) {
      throw new RuntimeException('--blog-page requires --blog-id');
    }
    $pagePattern = $allowPageRange ? '/\A[1-9][0-9]*(?:-[1-9][0-9]*)?\z/' : '/\A[1-9][0-9]*\z/';
    if (!preg_match($pagePattern, $options['blog-page'])) {
      $expected = $allowPageRange ? 'a positive page number or a range like 3-10' : 'a positive integer';
      throw new RuntimeException("--blog-page must be {$expected}");
    }
  }
  $options['settings'] ??= $settings;
  return ['help' => false] + $options;
}

function selectBlog(array $blogs, string $id): array
{
  $matches = array_values(array_filter(
    $blogs,
    static fn(array $blog): bool => readSourceId($blog, 'blogs') === $id
  ));
  if (count($matches) !== 1) {
    throw new RuntimeException("Expected one configured blog with id {$id}, found " . count($matches));
  }
  return $matches[0];
}

function blogFeedUrl(array $blog, ?string $page): string
{
  $url = readString($blog, 'url', 'blogs');
  if ($page === null) {
    return $url;
  }
  if (($blog['engine'] ?? null) !== 'hatenablog') {
    throw new RuntimeException('--blog-page is supported only for hatenablog feeds');
  }

  [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
  [$path, $query] = array_pad(explode('?', $base, 2), 2, '');
  $parameters = array_filter(
    explode('&', $query),
    static fn(string $parameter): bool => $parameter !== ''
      && urldecode(explode('=', $parameter, 2)[0]) !== 'page'
  );
  $parameters[] = 'page=' . $page;
  return $path . '?' . implode('&', $parameters) . ($fragment === null ? '' : '#' . $fragment);
}
