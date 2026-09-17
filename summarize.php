<?php

declare(strict_types=1);

// Required ARTICLE columns (apply manually; see SUMMARIZE.md):
// llm_summary TEXT DEFAULT NULL, llm_tags TEXT DEFAULT NULL, llm_hashtags TEXT DEFAULT NULL.
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  try {
    exit(summarizeMain($argv));
  } catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
  }
}

function summarizeMain(array $argv): int
{
  $options = summarizeOptions($argv);
  if ($options['help']) {
    fwrite(STDOUT, "Usage: php summarize.php [--content=<content_id>] [--nodb] [--with-hashtags] [--cooldown=seconds]\n"
      . "  --content <content_id>  Process one article (also accepts --content=<id>)\n"
      . "  --nodb                 Print JSON Lines without writing to SQLite\n"
      . "  --cooldown <seconds>   Wait between articles (default: 20; also --cooldown=N)\n"
      . "  --nohashtag            Explicitly use the default: no hashtag candidates\n"
      . "  --with-hashtags        Load selection hashtag candidates from TAG_COLLECTION_DIR\n"
      . "  -h, --help             Show this help\n"
      . "Configuration: settings.json; required schema: SUMMARIZE.md\n");
    return 0;
  }
  foreach (['pdo_sqlite', 'curl'] as $extension) {
    if (!extension_loaded($extension)) {
      throw new RuntimeException("The PHP {$extension} extension is required");
    }
  }
  require_once __DIR__ . '/vendor/autoload.php';
  $settings = json_decode(summarizeRead(__DIR__ . '/settings.json'), true, 512, JSON_THROW_ON_ERROR);
  if (!is_array($settings)) {
    throw new RuntimeException('settings.json must contain an object');
  }
  $database = summarizePath($settings['OUTPUT_PATH'] ?? null, 'OUTPUT_PATH');
  $model = $settings['LLM_MODEL'] ?? '';
  if (!is_string($model)) {
    throw new RuntimeException('LLM_MODEL must be a string');
  }
  $endpoint = $settings['LLM_API_URL'] ?? 'http://127.0.0.1:1234/v1/chat/completions';
  if (!is_string($endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL)
    || !in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true)) {
    throw new RuntimeException('LLM_API_URL must be an HTTP(S) chat/completions URL');
  }
  if (!is_file($database)) {
    throw new RuntimeException("SQLite database file does not exist: {$database}");
  }
  $db = new PDO('sqlite:' . $database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
    PDO::SQLITE_ATTR_OPEN_FLAGS => $options['nodb'] ? PDO::SQLITE_OPEN_READONLY : PDO::SQLITE_OPEN_READWRITE,
  ]);
  if ($options['nodb']) {
    $db->exec('PRAGMA query_only = ON');
  }
  summarizeCheckSchema($db, $options['nodb']);
  // Do not even resolve/read TAG_COLLECTION_DIR when candidates are disabled.
  $catalog = $options['nohashtag'] ? [] : summarizeTagCatalog(
    summarizePath($settings['TAG_COLLECTION_DIR'] ?? null, 'TAG_COLLECTION_DIR')
  );
  $system = summarizeRead(__DIR__ . '/prompt/summarize-system.txt');
  $user = summarizeRead(__DIR__ . '/prompt/summarize-user.txt');
  $allowed = array_column($catalog, 'hashtag');
  $schema = summarizeSchema($allowed);
  fwrite(STDERR, $options['nohashtag']
    ? "Selection hashtags disabled (default)\n"
    : 'Collected selection hashtags: ' . count($allowed) . "\n");
  return summarizeArticles($db, $options, $allowed, static function (array $row) use ($catalog, $system, $user, $schema, $model, $endpoint): string {
    $text = summarizeTranscript($row['transcript_vtt']);
    if ($text === '') {
      throw new RuntimeException('Transcript contains no cue text');
    }
    $payload = summarizePayload($model, $system, $user, $schema, $catalog, $row['title'], $text);
    return summarizeRequest($endpoint, $payload);
  });
}

function summarizeOptions(array $argv): array
{
  $options = ['content' => null, 'nodb' => false, 'cooldown' => 20, 'nohashtag' => true, 'help' => false];
  for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--help' || $arg === '-h') {
      $options['help'] = true;
    } elseif ($arg === '--nodb') {
      $options['nodb'] = true;
    } elseif ($arg === '--nohashtag') {
      $options['nohashtag'] = true;
    } elseif ($arg === '--with-hashtags') {
      $options['nohashtag'] = false;
    } elseif ($arg === '--cooldown' || str_starts_with($arg, '--cooldown=')) {
      $value = $arg === '--cooldown' ? ($argv[++$i] ?? '') : substr($arg, strlen('--cooldown='));
      if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
        throw new InvalidArgumentException('Specify --cooldown as a nonnegative integer number of seconds');
      }
      $options['cooldown'] = (int)$value;
    } elseif ($arg === '--content' || str_starts_with($arg, '--content=')) {
      $value = $arg === '--content' ? ($argv[++$i] ?? '') : substr($arg, 10);
      if (trim($value) === '' || str_starts_with($value, '--') || $options['content'] !== null) {
        throw new InvalidArgumentException('Specify --content once with a nonempty content_id');
      }
      $options['content'] = $value;
    } else {
      throw new InvalidArgumentException("Unknown argument: {$arg}");
    }
  }
  return $options;
}

function summarizeRead(string $path): string
{
  $text = @file_get_contents($path);
  if ($text === false) {
    throw new RuntimeException("Cannot read file: {$path}");
  }
  return $text;
}

function summarizePath(mixed $path, string $key): string
{
  if (!is_string($path) || trim($path) === '') {
    throw new RuntimeException("Set {$key} in settings.json");
  }
  $path = trim($path);
  if (str_starts_with($path, '~')) {
    $userDirectory = PHP_OS_FAMILY === 'Windows' ? getenv('USERPROFILE') : getenv('HOME');
    if (!$userDirectory && PHP_OS_FAMILY === 'Windows') {
      $drive = getenv('HOMEDRIVE');
      $relativeHome = getenv('HOMEPATH');
      $userDirectory = $drive && $relativeHome ? $drive . $relativeHome : false;
    }
    if (!$userDirectory) {
      throw new RuntimeException("Cannot expand ~ in {$key}");
    }
    return rtrim($userDirectory, '/\\') . DIRECTORY_SEPARATOR . ltrim(substr($path, 1), '/\\');
  }
  if ($path[0] !== '/' && $path[0] !== '\\' && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
    return __DIR__ . DIRECTORY_SEPARATOR . $path;
  }
  return $path;
}

/** Read the site's YAML hashtags field, not Markdown headings or URL fragments. */
function summarizeTagCatalog(string $directory): array
{
  if (!is_dir($directory)) {
    throw new RuntimeException("TAG_COLLECTION_DIR does not exist: {$directory}");
  }
  $files = [];
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
  foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
      $files[] = $file->getPathname();
    }
  }
  sort($files, SORT_STRING);
  $catalog = [];
  foreach ($files as $file) {
    $markdown = str_replace(["\r\n", "\r"], "\n", summarizeRead($file));
    if (!preg_match('/\A(?:\xEF\xBB\xBF)?---[ \t]*\n(.*?)\n---[ \t]*(?:\n|$)/s', $markdown, $match)) {
      continue;
    }
    try {
      $metadata = Symfony\Component\Yaml\Yaml::parse($match[1]);
    } catch (Throwable $error) {
      throw new RuntimeException("Invalid YAML in {$file}: {$error->getMessage()}", 0, $error);
    }
    $tags = is_array($metadata) ? ($metadata['hashtags'] ?? []) : [];
    if (is_string($tags)) {
      $tags = preg_split('/[,、\s]+/u', trim($tags), -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($tags) || !array_is_list($tags)) {
      throw new RuntimeException("hashtags must be a string or list: {$file}");
    }
    foreach ($tags as $tag) {
      if (!is_string($tag) || !preg_match('/\A[#＃]?([\p{L}\p{N}\p{M}_-]+)\z/u', trim($tag), $parts)) {
        throw new RuntimeException("Invalid hashtag in {$file}");
      }
      $tag = '#' . $parts[1];
      $catalog[$tag] ??= ['hashtag' => $tag, 'pages' => []];
      // Include page context so a tag can be selected by meaning, not just spelling.
      $catalog[$tag]['pages'][] = [
        'title' => (string)($metadata['title'] ?? ''),
        'description' => trim(substr($markdown, strlen($match[0]))),
      ];
    }
  }
  ksort($catalog, SORT_STRING);
  return array_values($catalog);
}

function summarizeCheckSchema(PDO $db, bool $nodb): void
{
  $columns = $db->query('PRAGMA table_info(ARTICLE)')->fetchAll(PDO::FETCH_COLUMN, 1);
  $required = ['content_id', 'title', 'transcript_vtt'];
  if (!$nodb) {
    $required = array_merge($required, ['llm_summary', 'llm_tags', 'llm_hashtags']);
  }
  $missing = array_diff($required, $columns);
  if ($missing !== []) {
    throw new RuntimeException('Missing ARTICLE columns: ' . implode(', ', $missing)
      . '. Apply the schema in SUMMARIZE.md manually, or use --nodb to preview without output columns.');
  }
}

/** Extract cue payloads, preserving order and ignoring WebVTT metadata. */
function summarizeTranscript(string $vtt): string
{
  $vtt = str_replace(["\r\n", "\r"], "\n", $vtt);
  $vtt = preg_replace('/\A\xEF\xBB\xBF/', '', $vtt);
  if (!preg_match('/\AWEBVTT(?:[ \t][^\n]*)?\n/', $vtt)) {
    throw new RuntimeException('Invalid WebVTT header');
  }
  $cues = [];
  foreach (preg_split('/\n[ \t]*\n/', trim($vtt)) as $block) {
    if (preg_match('/\A(?:WEBVTT|NOTE|STYLE|REGION)(?:\s|$)/', $block)) {
      continue;
    }
    $lines = explode("\n", $block);
    $timing = str_contains($lines[0], '-->') ? 0 : 1;
    if (!isset($lines[$timing]) || !preg_match('/\A(?:\d+:)?\d{2}:\d{2}\.\d{3}\s+-->\s+(?:\d+:)?\d{2}:\d{2}\.\d{3}(?:\s|$)/', $lines[$timing])) {
      continue;
    }
    $text = implode(' ', array_slice($lines, $timing + 1));
    $text = preg_replace('/<[^>]*>/', '', $text);
    $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($text !== '') {
      $cues[] = $text;
    }
  }
  return implode("\n", $cues);
}

function summarizeJson(mixed $value): string
{
  return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function summarizeSchema(array $allowed): array
{
  $schema = json_decode(summarizeRead(__DIR__ . '/prompt/summarize.schema.json'), true, 512, JSON_THROW_ON_ERROR);
  if ($allowed === []) {
    // Some LM Studio runtimes compile maxItems: 0 into an invalid {-1} grammar.
    // A constant empty array avoids that conversion and still forbids all hashtags.
    $schema['properties']['hashtags'] = ['type' => 'array', 'const' => []];
  } else {
    $schema['properties']['hashtags']['items']['enum'] = $allowed;
  }
  return $schema;
}

function summarizePayload(string $model, string $system, string $user, array $schema, array $catalog, string $title, string $transcript): array
{
  $payload = [
    'messages' => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user', 'content' => strtr($user, [
        '{{ARTICLE_JSON}}' => summarizeJson(['title' => $title, 'transcript' => $transcript]),
        '{{TAG_CATALOG_JSON}}' => summarizeJson($catalog),
      ])],
    ],
    'response_format' => ['type' => 'json_schema', 'json_schema' => [
      'name' => 'article_summary', 'strict' => true, 'schema' => $schema,
    ]],
    'temperature' => 0.2,
    'max_tokens' => 2048,
    'stream' => false,
  ];
  // Do not pick the first model in /models: leave selection to LM Studio.
  if (trim($model) !== '') {
    $payload['model'] = trim($model);
  }
  return $payload;
}

function summarizeRequest(string $endpoint, array $payload): string
{
  $curl = curl_init($endpoint);
  if ($curl === false) {
    throw new RuntimeException('Cannot initialize cURL');
  }
  try {
    curl_setopt_array($curl, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => summarizeJson($payload),
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 600,
    ]);
    $body = curl_exec($curl);
    if ($body === false) {
      throw new RuntimeException('LM Studio request failed: ' . curl_error($curl));
    }
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($status !== 200) {
      throw new RuntimeException("LM Studio HTTP {$status}: {$body}");
    }
    try {
      $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
      throw new RuntimeException('LM Studio HTTP response is not valid JSON: ' . $error->getMessage(), 0, $error);
    }
    $choice = $response['choices'][0] ?? null;
    if (!is_array($choice) || ($choice['finish_reason'] ?? null) !== 'stop'
      || !is_string($choice['message']['content'] ?? null)) {
      throw new RuntimeException('LM Studio returned an incomplete or invalid completion');
    }
    return $choice['message']['content'];
  } finally {
    curl_close($curl);
  }
}

/** Validate again locally even when the server offers structured output. */
function summarizeValidate(string $json, array $allowed): array
{
  try {
    $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $error) {
    $hint = str_starts_with(ltrim($json), '```') ? ' (response starts with a Markdown code fence)' : '';
    throw new RuntimeException('LLM generated content is not valid JSON: ' . $error->getMessage() . $hint, 0, $error);
  }
  if (!$decoded instanceof stdClass) {
    throw new RuntimeException('LLM result must be an object');
  }
  // Use a fixed-size array during inference; expose/store only a single string.
  if (isset($decoded->summary_lines)) {
    if (!is_array($decoded->summary_lines) || count($decoded->summary_lines) !== 5) {
      throw new RuntimeException('LLM summary_lines must contain exactly five strings');
    }
    foreach ($decoded->summary_lines as $line) {
      if (!is_string($line) || trim($line) === '' || preg_match('/[\r\n]/', $line)) {
        throw new RuntimeException('LLM summary_lines must contain nonempty single-line strings');
      }
    }
    $decoded->summary = implode("\n", $decoded->summary_lines);
  }
  if (!is_string($decoded->summary ?? null)) {
    throw new RuntimeException('LLM summary must be a string');
  }
  $result = get_object_vars($decoded);
  $summary = str_replace(["\r\n", "\r"], "\n", trim($result['summary']));
  $lines = array_map('trim', explode("\n", $summary));
  if (count($lines) !== 5 || in_array('', $lines, true)) {
    throw new RuntimeException('LLM summary must contain exactly five nonempty lines (received ' . count($lines) . ' lines)');
  }
  foreach (['tags' => 20, 'hashtags' => 3] as $key => $limit) {
    $values = $result[$key] ?? null;
    if (!is_array($values) || !array_is_list($values) || count($values) > $limit) {
      throw new RuntimeException("LLM {$key} must be an array with at most {$limit} items");
    }
    foreach ($values as $value) {
      if (!is_string($value) || trim($value) === '' || preg_match('/[\r\n]/', $value)) {
        throw new RuntimeException("LLM {$key} contains an invalid tag");
      }
      if ($key === 'hashtags' && !in_array($value, $allowed, true)) {
        throw new RuntimeException("LLM selected a hashtag outside the catalog: {$value}");
      }
    }
    $result[$key] = array_values(array_unique(array_map('trim', $values)));
  }
  return ['summary' => implode("\n", $lines), 'tags' => $result['tags'], 'hashtags' => $result['hashtags']];
}

function summarizeArticles(PDO $db, array $options, array $allowed, callable $generate, ?callable $wait = null): int
{
  summarizeCheckSchema($db, $options['nodb']);
  // Fetch only IDs up front: do not keep all transcripts or an active read cursor during inference.
  $query = "SELECT content_id FROM ARTICLE WHERE transcript_vtt IS NOT NULL AND trim(transcript_vtt) <> ''";
  $params = [];
  if ($options['content'] !== null) {
    $query .= ' AND content_id = :id';
    $params[':id'] = $options['content'];
  }
  $select = $db->prepare($query . ' ORDER BY content_id');
  $select->execute($params);
  $ids = $select->fetchAll(PDO::FETCH_COLUMN);
  $select->closeCursor();
  if ($ids === [] && $options['content'] !== null) {
    throw new RuntimeException('Article not found or transcript_vtt is empty: ' . $options['content']);
  }
  $read = $db->prepare('SELECT content_id, title, transcript_vtt FROM ARTICLE WHERE content_id = :id');
  $update = $options['nodb'] ? null : $db->prepare('UPDATE ARTICLE SET llm_summary = :summary,
    llm_tags = :tags, llm_hashtags = :hashtags
    WHERE content_id = :id AND transcript_vtt = :vtt AND title = :title');
  $processed = 0;
  $failed = 0;
  $cooldown = $options['cooldown'] ?? 20;
  $wait ??= static function (int $seconds): void { sleep($seconds); };
  foreach ($ids as $index => $id) {
    if ($index > 0 && $cooldown > 0) {
      fwrite(STDERR, "Waiting {$cooldown}s before next article\n");
      $wait($cooldown);
    }
    try {
      $read->execute([':id' => $id]);
      $row = $read->fetch(PDO::FETCH_ASSOC);
      $read->closeCursor();
      if ($row === false) {
        throw new RuntimeException('Article was removed during processing');
      }
      fwrite(STDERR, "Summarizing [{$id}] {$row['title']}\n");
      $result = summarizeValidate($generate($row), $allowed);
      if ($update !== null) {
        $update->execute([
          ':summary' => $result['summary'], ':tags' => summarizeJson($result['tags']),
          ':hashtags' => summarizeJson($result['hashtags']), ':id' => $id,
          ':vtt' => $row['transcript_vtt'], ':title' => $row['title'],
        ]);
        if ($update->rowCount() !== 1) {
          throw new RuntimeException('Article changed during generation; result was not saved');
        }
      } else {
        fwrite(STDOUT, summarizeJson(['content_id' => $id] + $result) . "\n");
      }
      $processed++;
    } catch (Throwable $error) {
      $failed++;
      fwrite(STDERR, "Generation/save failed [{$id}]: {$error->getMessage()}\n");
    }
  }
  fwrite(STDERR, ($options['nodb'] ? 'Generated: ' : 'Saved: ') . "{$processed} / Failed: {$failed}\n");
  return $failed === 0 ? 0 : 1;
}
