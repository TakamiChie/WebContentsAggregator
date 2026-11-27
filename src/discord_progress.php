<?php

declare(strict_types=1);

function aggregatorSettingsPath(): string
{
  $root = dirname(__DIR__);
  return is_file($root . '/settings.json') ? $root . '/settings.json' : $root . '/setting.json';
}

final class DiscordProgress
{
  private static ?string $url = null;
  private static string $script = '';

  public static function start(string $script, string $message, ?string $settingsPath = null): void
  {
    if (PHP_SAPI !== 'cli') {
      return;
    }

    self::$script = basename($script);
    self::$url = null;
    $root = dirname(__DIR__);
    $paths = $settingsPath === null
      ? [$root . '/setting.json', $root . '/settings.json']
      : [$settingsPath];

    foreach ($paths as $path) {
      $json = @file_get_contents($path);
      $settings = $json === false ? null : json_decode($json, true);
      if (!is_array($settings) || !array_key_exists('DISCORD_WEBHOOK_URL', $settings)) {
        continue;
      }
      $url = $settings['DISCORD_WEBHOOK_URL'];
      if ($url === null || (is_string($url) && trim($url) === '')) {
        return;
      }
      if (!is_string($url) || !preg_match(
        '~\Ahttps://(?:(?:canary|ptb)\.)?discord(?:app)?\.com/api(?:/v[0-9]+)?/webhooks/[0-9]+/[A-Za-z0-9_-]+(?:\?[^#\s]*)?\z~i',
        trim($url)
      )) {
        self::warn('DISCORD_WEBHOOK_URL must be a Discord HTTPS webhook URL');
        return;
      }
      if (!extension_loaded('curl')) {
        self::warn('the PHP curl extension is required');
        return;
      }
      // Preserve Discord options such as thread_id and request delivery confirmation.
      $parts = explode('?', trim($url), 2);
      parse_str($parts[1] ?? '', $query);
      $query['wait'] = 'true';
      self::$url = $parts[0] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
      self::send('▶️', $message);
      return;
    }
  }

  public static function label(string $value): string
  {
    $value = str_replace(["\r", "\n"], ' ', trim($value));
    if (preg_match('/\A.{200}(?=.)/su', $value, $match)) {
      $value = $match[0] . '…';
    }
    return addcslashes($value, "\\*_~\x60|<>[]");
  }

  public static function articles(string $source, int $fetched, array $titles): void
  {
    if (self::$url === null) {
      return;
    }
    $saved = count($titles);
    $lines = array_map(static fn(string $title): string => '• ' . self::label($title), $titles);
    self::send('💾', '記事データの取得・DB保存完了',
      self::label($source) . "｜取得 {$fetched} 件・DB変更 {$saved} 件"
      . ($lines === [] ? '' : "\n" . implode("\n", $lines)));
  }

  public static function send(string $icon, string $message, string $detail = ''): void
  {
    if (self::$url === null) {
      return;
    }

    try {
      $heading = "{$icon} **{$message}** · " . self::$script;
      if (!preg_match_all('/.{1,900}(?:\n|$)|.{1,900}/su', $detail, $matches)) {
        $matches = [[$detail === '' ? '' : '[表示できない文字列]']];
      }
      // 900 Unicode code points fit even when every character uses two UTF-16 units.
      foreach ($matches[0] as $index => $chunk) {
        $content = $heading . ($index === 0 ? '' : '（続き）');
        $content .= $chunk === '' ? '' : "\n" . $chunk;
        self::deliver(json_encode([
          'content' => $content,
          'allowed_mentions' => ['parse' => []],
          'flags' => 4, // SUPPRESS_EMBEDS
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
      }
    } catch (Throwable $error) {
      self::$url = null;
      self::warn($error instanceof RuntimeException ? $error->getMessage() : 'delivery failed');
    }
  }

  private static function deliver(string $payload): void
  {
    $curl = curl_init(self::$url);
    curl_setopt_array($curl, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_TIMEOUT => 5,
    ]);
    for ($attempt = 0; $attempt < 3; $attempt++) {
      $body = curl_exec($curl);
      $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
      if ($body === false) {
        throw new RuntimeException('transport error ' . curl_errno($curl));
      }
      if ($status >= 200 && $status < 300) {
        return;
      }
      $retryAfter = $status === 429 ? (json_decode($body, true)['retry_after'] ?? null) : null;
      if ($attempt === 2 || !is_numeric($retryAfter) || $retryAfter < 0 || $retryAfter > 5) {
        throw new RuntimeException("HTTP {$status}");
      }
      usleep((int)ceil($retryAfter * 1000000) + 50000);
    }
  }

  private static function warn(string $reason): void
  {
    fwrite(STDERR, "Discord webhook warning [" . self::$script . "]: {$reason}.\n");
  }
}
