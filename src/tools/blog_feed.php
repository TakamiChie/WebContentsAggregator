<?php

declare(strict_types=1);

function parseBlogFeed(string $body): SimpleXMLElement
{
  $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
  if ($xml === false || !in_array($xml->getName(), ['feed', 'rss'], true)) {
    throw new RuntimeException('Expected a valid Atom or RSS 2.0 feed.');
  }
  return $xml;
}

/**
 * Atomのlink要素群から、条件に合うhrefを取得する
 *
 * @param SimpleXMLElement $parent
 * @param string|null $rel
 * @param string|null $typePrefix
 * @return string
 */
function findAtomLinkHref(SimpleXMLElement $parent, ?string $rel = null, ?string $typePrefix = null): string
{
  if (!isset($parent->link)) {
    return '';
  }

  foreach ($parent->link as $link) {
    $attributes = $link->attributes();
    if ($attributes === null) {
      continue;
    }

    $href = isset($attributes['href']) ? (string)$attributes['href'] : '';
    $linkRel = isset($attributes['rel']) ? (string)$attributes['rel'] : 'alternate';
    $linkType = isset($attributes['type']) ? (string)$attributes['type'] : '';

    if ($href === '') {
      continue;
    }

    if ($rel !== null && $linkRel !== $rel) {
      continue;
    }

    if ($typePrefix !== null && !str_starts_with($linkType, $typePrefix)) {
      continue;
    }

    return $href;
  }

  return '';
}

function getContentId(
  string $feedId,
  string $sourceId,
  string $url,
  string $title,
  string $publishedAt
): string {
  if ($feedId !== '') {
    return $feedId;
  }
  if ($url !== '') {
    return $url;
  }
  return $sourceId . ':' . hash('sha256', $title . "\n" . $publishedAt);
}

function blogEntryIdentity(SimpleXMLElement $entry, string $sourceId, bool $atom): array
{
  $url = $atom
    ? (findAtomLinkHref($entry, 'alternate') ?: findAtomLinkHref($entry, null))
    : trim((string)($entry->link ?? ''));
  $date = $atom ? ($entry->published ?? $entry->updated ?? '') : ($entry->pubDate ?? '');
  $date = trim((string)$date);
  $id = trim((string)($atom ? ($entry->id ?? '') : ($entry->guid ?? '')));
  $title = trim((string)($entry->title ?? ''));

  return [
    'content_id' => getContentId($id, $sourceId, $url, $title, $date),
    'title' => isset($entry->title) ? $title : 'Unknown Title',
    'url' => $url,
    'pub_date' => $date,
  ];
}
