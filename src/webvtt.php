<?php

declare(strict_types=1);

function hasWebVttHeader(string $text): bool
{
  return preg_match('/\A(?:\xEF\xBB\xBF)?WEBVTT(?:[ \t][^\r\n]*)?(?:\r\n|\r|\n)/', $text) === 1;
}
