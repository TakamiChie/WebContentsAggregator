<?php

declare(strict_types=1);

require_once __DIR__ . '/collection_settings.php';
require_once __DIR__ . '/tools/blog_feed.php';

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  try {
    exit(blogTextMain($argv));
  } catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
  }
}

function blogTextMain(array $argv): int
{
  $settingsPath = dirname(__DIR__) . '/settings.json';
  foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
      fwrite(STDOUT, "Usage: php src/get_blogtext.php [--settings=/path/to/settings.json]\n");
      return 0;
    }
    if (!str_starts_with($argument, '--settings=') || substr($argument, 11) === '') {
      throw new InvalidArgumentException("Invalid argument: {$argument}");
    }
    $settingsPath = substr($argument, 11);
  }

  $settings = readEntries($settingsPath);
  $blogs = readEntryGroup($settings['CONTENTS'] ?? [], 'blogs');
  $blogs = array_filter($blogs, static fn(array $blog): bool => ($blog['engine'] ?? null) === 'hatenablog');
  if ($blogs === []) {
    fwrite(STDOUT, "No hatenablog feeds configured.\n");
    return 0;
  }

  $path = resolveOutputPath(readString($settings, 'OUTPUT_PATH', 'configuration'));
  if (!is_file($path)) {
    throw new RuntimeException("SQLite database file does not exist: {$path}");
  }
  $db = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
  ]);
  $update = $db->prepare("UPDATE ARTICLE SET fulltext = :text
    WHERE source_id = :source_id AND content_id = :content_id
      AND (fulltext IS NULL OR trim(fulltext) = '')");
  $context = stream_context_create(['http' => [
    'timeout' => 30,
    'user_agent' => 'WebContentsAggregator/1.0',
  ]]);
  $saved = 0;
  $failed = 0;
  foreach ($blogs as $blog) {
    $sourceId = '(unknown)';
    try {
      $sourceId = readSourceId($blog, 'blogs');
      $url = readString($blog, 'url', 'blogs');
      $body = @file_get_contents($url, false, $context);
      if ($body === false) {
        throw new RuntimeException('Failed to fetch the configured feed');
      }
      $saved += saveBlogText($db, $update, parseBlogFeed($body), $sourceId);
    } catch (Throwable $error) {
      $failed++;
      fwrite(STDERR, "Blog text collection failed [{$sourceId}]: {$error->getMessage()}\n");
    }
  }
  fwrite(STDOUT, "Saved blog texts: {$saved} / Failed feeds: {$failed}\n");
  return $failed === 0 ? 0 : 1;
}

function saveBlogText(PDO $db, PDOStatement $update, SimpleXMLElement $feed, string $sourceId): int
{
  $atom = $feed->getName() === 'feed';
  $entries = $atom ? $feed->entry : $feed->channel->item;
  $articles = [];
  foreach ($entries as $entry) {
    $html = blogEntryBody($entry, $atom);
    if ($html === '') {
      continue;
    }
    $text = blogBodyToText($html);
    if ($text !== '') {
      $identity = blogEntryIdentity($entry, $sourceId, $atom);
      $articles[$identity['content_id']] = $text;
    }
  }

  if ($articles === []) {
    return 0;
  }
  $saved = 0;
  $db->beginTransaction();
  try {
    foreach ($articles as $contentId => $text) {
      $update->execute([':source_id' => $sourceId, ':content_id' => $contentId, ':text' => $text]);
      $saved += $update->rowCount();
    }
    $db->commit();
  } catch (Throwable $error) {
    $db->rollBack();
    throw $error;
  }
  return $saved;
}

function blogEntryBody(SimpleXMLElement $entry, bool $atom): string
{
  $body = $atom
    ? ($entry->content ?? null)
    : ($entry->children('http://purl.org/rss/1.0/modules/content/')->encoded ?? $entry->content ?? null);
  if ($body === null) {
    return '';
  }

  // Atom type="xhtml" can contain XML elements instead of escaped HTML.
  $element = dom_import_simplexml($body);
  if ($element->childElementCount === 0) {
    return trim((string)$body);
  }
  $html = '';
  foreach ($element->childNodes as $child) {
    $html .= $element->ownerDocument->saveXML($child);
  }
  return $html;
}

function blogBodyToText(string $html): string
{
  $document = new DOMDocument();
  $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
  $xpath = new DOMXPath($document);
  foreach ($xpath->query('//script | //style | //noscript | //iframe | //template') as $node) {
    $node->parentNode->removeChild($node);
  }

  $blocks = ['address', 'article', 'blockquote', 'dd', 'div', 'dl', 'dt', 'figcaption',
    'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'p', 'pre', 'section', 'tr'];
  foreach ($document->getElementsByTagName('*') as $node) {
    if (in_array($node->tagName, $blocks, true)) {
      $node->parentNode->insertBefore($document->createTextNode("\n"), $node);
      $node->parentNode->insertBefore($document->createTextNode("\n"), $node->nextSibling);
    } elseif ($node->tagName === 'br') {
      $node->parentNode->insertBefore($document->createTextNode("\n"), $node);
    } elseif ($node->tagName === 'img' && $node->getAttribute('alt') !== '') {
      $node->parentNode->insertBefore($document->createTextNode($node->getAttribute('alt')), $node);
    }
  }
  $body = $document->getElementsByTagName('body')->item(0);
  $text = str_replace("\u{00A0}", ' ', $body?->textContent ?? '');
  $text = preg_replace("/\r\n?/", "\n", $text);
  $text = preg_replace("/[ \t]+\n/", "\n", $text);
  return trim(preg_replace("/\n{3,}/", "\n\n", $text));
}
