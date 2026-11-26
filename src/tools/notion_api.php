<?php

declare(strict_types=1);

const NOTION_API_VERSION = '2026-03-11';
const NOTION_API_BASE_URL = 'https://api.notion.com';

function notionSecret(string $environmentName): string
{
  $value = getenv($environmentName);
  if ($value === false || trim($value) === '') {
    throw new RuntimeException("Environment variable {$environmentName} is empty");
  }
  return trim($value);
}

function notionAllChildren(string $blockId, callable $request): array
{
  $results = [];
  $cursor = null;
  do {
    $path = '/v1/blocks/' . rawurlencode($blockId) . '/children?page_size=100';
    if ($cursor !== null) {
      $path .= '&start_cursor=' . rawurlencode($cursor);
    }
    $response = $request('GET', $path, null);
    if (!is_array($response['results'] ?? null)) {
      throw new RuntimeException('Notion block children response has no results array');
    }
    $results = array_merge($results, $response['results']);
    $cursor = ($response['has_more'] ?? false) ? ($response['next_cursor'] ?? null) : null;
    if (($response['has_more'] ?? false) && !is_string($cursor)) {
      throw new RuntimeException('Notion block children response omitted next_cursor');
    }
  } while ($cursor !== null);
  return $results;
}

function notionRichTextPlain(array $richText): string
{
  $text = '';
  foreach ($richText as $part) {
    if (is_array($part)) {
      $text .= is_string($part['plain_text'] ?? null)
        ? $part['plain_text']
        : (is_string($part['text']['content'] ?? null) ? $part['text']['content'] : '');
    }
  }
  return $text;
}

function notionApiRequest(string $method, string $path, ?array $body, string $token, array $configuration): array
{
  $url = $configuration['api_base_url'] . $path;
  for ($attempt = 1; $attempt <= 4; $attempt++) {
    $curl = curl_init($url);
    if ($curl === false) {
      throw new RuntimeException('Cannot initialize cURL');
    }
    $headers = [
      'Authorization: Bearer ' . $token,
      'Notion-Version: ' . $configuration['api_version'],
      'Accept: application/json',
      'Content-Type: application/json',
    ];
    $responseHeaders = [];
    $options = [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => $configuration['timeout'],
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
        $length = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
          $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $length;
      },
    ];
    if ($body !== null) {
      $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $options);
    try {
      $response = curl_exec($curl);
      if ($response === false) {
        throw new RuntimeException('Notion request failed: ' . curl_error($curl));
      }
      $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    } finally {
      curl_close($curl);
    }
    $decoded = $response === '' ? [] : json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if ($status >= 200 && $status < 300 && is_array($decoded)) {
      return $decoded;
    }
    if (($status === 429 || $status >= 500) && $attempt < 4) {
      $retryAfter = isset($responseHeaders['retry-after']) && ctype_digit($responseHeaders['retry-after'])
        ? max(1, (int)$responseHeaders['retry-after']) : $attempt;
      sleep(min($retryAfter, 10));
      continue;
    }
    $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : $response;
    throw new RuntimeException("Notion API {$method} {$path} returned HTTP {$status}: {$message}");
  }
  throw new RuntimeException("Notion API request exhausted retries: {$method} {$path}");
}
