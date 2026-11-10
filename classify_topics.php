<?php

declare(strict_types=1);

// Reuse only file/path/JSON helpers. No summary generation or HTTP requests.
require_once __DIR__ . '/summarize.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  try { exit(classifyMain($argv)); }
  catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}

function classifyOptions(array $argv): array
{
  $options = ['content' => null, 'nodb' => false, 'force' => false, 'all-types' => false,
    'analyze' => false, 'limit' => 0, 'top' => 30, 'settings' => __DIR__ . '/settings.json', 'help' => false];
  for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-h' || $arg === '--help') { $options['help'] = true; continue; }
    if (in_array($arg, ['--nodb', '--force', '--all-types', '--analyze'], true)) {
      $options[substr($arg, 2)] = true;
      continue;
    }
    $name = explode('=', ltrim($arg, '-'), 2)[0];
    if (!str_starts_with($arg, '--') || !in_array($name, ['content', 'settings', 'limit', 'top'], true)) {
      throw new InvalidArgumentException("Unknown argument: {$arg}");
    }
    $value = str_contains($arg, '=') ? explode('=', $arg, 2)[1] : ($argv[++$i] ?? '');
    if (trim($value) === '' || str_starts_with($value, '--')) {
      throw new InvalidArgumentException("Missing value for --{$name}");
    }
    if (in_array($name, ['limit', 'top'], true)) {
      if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        || ($name === 'top' && (int)$value === 0)) {
        throw new InvalidArgumentException("Invalid --{$name}");
      }
      $value = (int)$value;
    }
    $options[$name] = $value;
  }
  return $options;
}

function classifyMain(array $argv): int
{
  $options = classifyOptions($argv);
  if ($options['help']) {
    fwrite(STDOUT, "Usage: php classify_topics.php [--analyze] [--nodb] [--force] [--content ID]\n"
      . "  --analyze       Read-only tag frequencies and topic co-occurrence report (JSON)\n"
      . "  --nodb          Preview classifications without any DB writes (JSON Lines)\n"
      . "  --force         Recompute all selected tag classifications\n"
      . "  --limit N       Maximum articles to process; 0 = unlimited (not used by --analyze)\n"
      . "  --top N         Number of tags/candidates per analysis section (default 30)\n"
      . "  --all-types     Include blogs/videos as well as podcasts\n"
      . "  --settings FILE Alternate settings.json (relative to script directory)\n"
      . "No AI or transcript processing; existing summary rejects incidental mentions.\n");
    return 0;
  }
  foreach (['pdo_sqlite', 'mbstring', 'intl'] as $extension) {
    if (!extension_loaded($extension)) { throw new RuntimeException("The PHP {$extension} extension is required"); }
  }
  require_once __DIR__ . '/vendor/autoload.php';
  $settings = json_decode(summarizeRead(summarizePath($options['settings'], 'settings')), true, 512, JSON_THROW_ON_ERROR);
  if (!is_array($settings)) { throw new RuntimeException('Settings must be an object'); }
  $catalog = classifyCatalog(summarizePath($settings['TAG_COLLECTION_DIR'] ?? null, 'TAG_COLLECTION_DIR'));
  $database = summarizePath($settings['OUTPUT_PATH'] ?? null, 'OUTPUT_PATH');
  if (!is_file($database)) { throw new RuntimeException("SQLite database does not exist: {$database}"); }
  $readonly = $options['nodb'] || $options['analyze'];
  $lock = null;
  try {
    if (!$readonly) {
      $lock = fopen($database . '.classify_topics.lock', 'c');
      if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another classifier is running or the lock file cannot be opened');
      }
    }
    $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_TIMEOUT => 5, PDO::SQLITE_ATTR_OPEN_FLAGS => $readonly ? PDO::SQLITE_OPEN_READONLY : PDO::SQLITE_OPEN_READWRITE]);
    if ($readonly) { $db->exec('PRAGMA query_only = ON'); }
    return classifyRun($db, $options, $catalog);
  } finally {
    if (is_resource($lock)) { fclose($lock); }
  }
}

function classifyNormalize(string $tag): string
{
  $normalized = Normalizer::normalize($tag, Normalizer::FORM_KC);
  if ($normalized === false) { throw new RuntimeException('Invalid UTF-8 tag'); }
  $normalized = preg_replace('/\s+/u', ' ', trim($normalized));
  return mb_strtolower(ltrim($normalized, '#'), 'UTF-8');
}

/** NULL/blank means not generated; [] is a valid generated empty tag list. */
function classifyTags(?string $raw): ?array
{
  if ($raw === null || trim($raw) === '') { return null; }
  $raw = trim($raw);
  if (str_starts_with($raw, '[') || str_starts_with($raw, '{')) {
    $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) { throw new RuntimeException('llm_tags must be a JSON string array'); }
    $values = $decoded;
  } else {
    $values = preg_split('/[,，、\r\n]+|\s+(?=[#＃])/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
  }
  $tags = [];
  foreach ($values as $value) {
    if (!is_string($value)) { throw new RuntimeException('llm_tags contains a non-string tag'); }
    $key = classifyNormalize($value);
    if ($key !== '') { $tags[$key] ??= trim($value); }
  }
  ksort($tags, SORT_STRING);
  return $tags;
}

/** Machine-readable tag mapping and optional summary relevance thresholds. */
function classifyCatalog(string $directory): array
{
  if (!is_dir($directory)) { throw new RuntimeException("TAG_COLLECTION_DIR does not exist: {$directory}"); }
  $files = [];
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'md') { $files[] = $file->getPathname(); }
  }
  sort($files, SORT_STRING);
  $catalog = [];
  foreach ($files as $file) {
    $markdown = str_replace(["\r\n", "\r"], "\n", summarizeRead($file));
    if (!preg_match('/\A(?:\xEF\xBB\xBF)?---[ \t]*\n(.*?)\n---[ \t]*(?:\n|$)/s', $markdown, $match)) { continue; }
    $metadata = Symfony\Component\Yaml\Yaml::parse($match[1]);
    $hashtags = $metadata['hashtags'] ?? [];
    if (is_string($hashtags)) { $hashtags = preg_split('/[,、\s]+/u', trim($hashtags), -1, PREG_SPLIT_NO_EMPTY); }
    if (!is_array($hashtags) || !array_is_list($hashtags)) { throw new RuntimeException("Invalid hashtags in {$file}"); }
    if ($hashtags === []) { continue; }
    $rules = $metadata['topic_classifier'] ?? [];
    if (!is_array($rules)) { throw new RuntimeException("Invalid topic_classifier in {$file}"); }
    foreach (['aliases', 'tags_any', 'tags_all', 'tags_none'] as $key) {
      $rules[$key] ??= [];
      if (!is_array($rules[$key]) || !array_is_list($rules[$key])) { throw new RuntimeException("{$key} must be a list in {$file}"); }
      foreach ($rules[$key] as $value) {
        if (!is_string($value) || classifyNormalize($value) === '') { throw new RuntimeException("Invalid {$key} in {$file}"); }
      }
    }
    $minimumChars = $rules['min_summary_chars'] ?? 30;
    $minimumRatio = $rules['min_summary_ratio'] ?? 0.2;
    if (!is_int($minimumChars) || $minimumChars < 1
      || (!is_int($minimumRatio) && !is_float($minimumRatio)) || $minimumRatio <= 0 || $minimumRatio > 1) {
      throw new RuntimeException("Invalid summary relevance thresholds in {$file}");
    }
    foreach ($hashtags as $hashtag) {
      if (!is_string($hashtag) || !preg_match('/\A[#＃]?([\p{L}\p{N}\p{M}_-]+)\z/u', trim($hashtag), $parts)) {
        throw new RuntimeException("Invalid hashtag in {$file}");
      }
      $hashtag = '#' . $parts[1];
      $any = array_merge([$hashtag], $rules['aliases'], $rules['tags_any']);
      $catalog[$hashtag]['hashtag'] = $hashtag;
      $catalog[$hashtag]['rules'][] = [
        'any' => array_values(array_unique(array_map('classifyNormalize', $any))),
        'all' => array_values(array_unique(array_map('classifyNormalize', $rules['tags_all']))),
        'none' => array_values(array_unique(array_map('classifyNormalize', $rules['tags_none']))),
        'min_summary_chars' => $minimumChars, 'min_summary_ratio' => $minimumRatio,
      ];
    }
  }
  if ($catalog === []) { throw new RuntimeException('No topic hashtags found; refusing to clear existing classifications'); }
  ksort($catalog, SORT_STRING);
  return array_values($catalog);
}

/** Conservative text evidence; this does not estimate spoken duration. */
function classifySummaryRelevance(string $summary, array $rule): array
{
  // Preserve newlines for sentence splitting; normalize each sentence separately.
  $sentences = preg_split('/(?<=[。！？!?])|[\r\n]+/u', $summary, -1, PREG_SPLIT_NO_EMPTY);
  $total = 0;
  $evidence = $administrative = [];
  $matchedChars = 0;
  foreach ($sentences as $raw) {
    $sentence = classifyNormalize($raw);
    $chars = mb_strlen(preg_replace('/\s+/u', '', $sentence), 'UTF-8');
    $total += $chars;
    $mentions = false;
    $context = $sentence;
    foreach (array_unique(array_merge($rule['any'], $rule['all'])) as $name) {
      if ($name !== '' && str_contains($sentence, $name)) {
        $mentions = true;
        $context = str_replace($name, '', $context);
      }
    }
    if (!$mentions) { continue; }
    $statusOnly = preg_match('/休み|休館|休業|休止|中止|延期|予定|開催日時|開催日|日程/u', $context)
      && !preg_match('/取り組|工夫|課題|振り返|見守|交流|実践|体験|遊ん|遊ぶ|遊び|学び|学ん|参加した|参加して|開催した|実施した|実施して|大切|意義/u', $context);
    if ($statusOnly) { $administrative[] = trim($raw); continue; }
    $matchedChars += $chars;
    $evidence[] = trim($raw);
  }
  $ratio = $total > 0 ? $matchedChars / $total : 0;
  $accepted = $matchedChars >= ($rule['min_summary_chars'] ?? 30) && $ratio >= ($rule['min_summary_ratio'] ?? 0.2);
  return ['accepted' => $accepted, 'summary_chars' => $total, 'substantive_chars' => $matchedChars,
    'summary_ratio' => round($ratio, 6), 'evidence' => $evidence, 'administrative_mentions' => $administrative,
    'reason' => $accepted ? 'substantive_summary' : ($total === 0 ? 'missing_summary' : ($matchedChars === 0 && $administrative !== [] ? 'administrative_only' : 'insufficient_summary_coverage'))];
}

function classifyArticle(array $tags, array $catalog, string $summary = ''): array
{
  $decisions = [];
  $hashtags = [];
  foreach ($catalog as $topic) {
    $matched = [];
    $candidates = [];
    $relevance = [];
    foreach ($topic['rules'] as $rule) {
      if (array_intersect($rule['none'], array_keys($tags)) !== []) { continue; }
      $any = array_intersect($rule['any'], array_keys($tags));
      $all = $rule['all'] !== [] && array_diff($rule['all'], array_keys($tags)) === [] ? $rule['all'] : [];
      $keys = array_unique(array_merge($any, $all));
      foreach ($keys as $key) { $candidates[$key] = $tags[$key]; }
      if ($keys === []) { continue; }
      $check = classifySummaryRelevance($summary, $rule);
      $relevance[] = $check;
      if ($check['accepted']) {
        foreach ($keys as $key) { $matched[$key] = $tags[$key]; }
      }
    }
    $decisions[$topic['hashtag']] = ['matched' => $matched !== [], 'matched_tags' => array_values($matched),
      'candidate_tags' => array_values($candidates), 'summary_relevance' => $relevance];
    if ($matched !== []) { $hashtags[] = $topic['hashtag']; }
  }
  return ['status' => 'complete', 'hashtags' => $hashtags, 'decisions' => $decisions];
}

/** Document frequencies, not raw occurrences; co-occurrence is a review hint only. */
function classifyDistribution(array $rows, array $catalog, int $top): array
{
  $frequency = $labels = $parsed = [];
  $missing = $invalid = [];
  $matchedCount = 0;
  $seeds = array_fill_keys(array_column($catalog, 'hashtag'), []);
  foreach ($rows as $row) {
    try { $tags = classifyTags($row['llm_tags']); }
    catch (Throwable $error) { $invalid[] = ['content_id' => $row['content_id'], 'error' => $error->getMessage()]; continue; }
    if ($tags === null) { $missing[] = $row['content_id']; continue; }
    $parsed[$row['content_id']] = $tags;
    foreach ($tags as $key => $label) { $frequency[$key] = ($frequency[$key] ?? 0) + 1; $labels[$key] ??= $label; }
    $result = classifyArticle($tags, $catalog, $row['llm_summary'] ?? '');
    if ($result['hashtags'] !== []) { $matchedCount++; }
    foreach ($result['hashtags'] as $hashtag) { $seeds[$hashtag][] = $row['content_id']; }
  }
  arsort($frequency, SORT_NUMERIC);
  $tagList = [];
  $histogram = [];
  foreach ($frequency as $key => $count) {
    $tagList[] = ['tag' => $labels[$key], 'normalized' => $key, 'documents' => $count];
    $histogram[$count] = ($histogram[$count] ?? 0) + 1;
  }
  ksort($histogram, SORT_NUMERIC);
  $histogramRows = [];
  foreach ($histogram as $documents => $tags) { $histogramRows[] = ['documents_per_tag' => $documents, 'unique_tags' => $tags]; }
  $topics = [];
  $usable = count($parsed);
  foreach ($catalog as $topic) {
    $ids = $seeds[$topic['hashtag']];
    $counts = [];
    foreach ($ids as $id) { foreach ($parsed[$id] as $key => $_) { $counts[$key] = ($counts[$key] ?? 0) + 1; } }
    $candidates = [];
    foreach ($counts as $key => $count) {
      $candidates[] = ['tag' => $labels[$key], 'normalized' => $key, 'cooccurring_documents' => $count,
        'total_documents' => $frequency[$key], 'within_topic_rate' => round($count / count($ids), 6),
        'lift' => round(($count / count($ids)) / ($frequency[$key] / $usable), 6)];
    }
    usort($candidates, static fn($a, $b) => ($b['cooccurring_documents'] <=> $a['cooccurring_documents'])
      ?: ($b['lift'] <=> $a['lift']) ?: strcmp($a['normalized'], $b['normalized']));
    $topics[] = ['hashtag' => $topic['hashtag'], 'matched_documents' => count($ids),
      'rules' => $topic['rules'], 'cooccurring_tags' => array_slice($candidates, 0, $top)];
  }
  return ['method' => 'tag_distribution_v3_summary_relevance', 'articles' => count($rows), 'generated_tag_articles' => $usable,
    'missing_tag_articles' => count($missing), 'invalid_tag_articles' => count($invalid),
    'matched_articles' => $matchedCount, 'unmatched_articles' => $usable - $matchedCount,
    'unique_tags' => count($frequency), 'frequency_histogram' => $histogramRows,
    'tag_frequencies' => array_slice($tagList, 0, $top),
    'topics' => $topics, 'missing_content_ids' => $missing, 'invalid_articles' => $invalid,
    'note' => 'Co-occurrence/lift suggests tags to review; it never creates a topic rule or confirms a mention automatically.'];
}

function classifyRun(PDO $db, array $options, array $catalog): int
{
  if ($catalog === []) { throw new RuntimeException('Empty topic catalog'); }
  $columns = $db->query('PRAGMA table_info(ARTICLE)')->fetchAll(PDO::FETCH_COLUMN, 1);
  $required = ['content_id', 'source_id', 'llm_tags', 'llm_hashtags', 'llm_summary'];
  if (array_diff($required, $columns) !== []) { throw new RuntimeException('Missing ARTICLE columns: ' . implode(', ', array_diff($required, $columns))); }
  $query = 'SELECT a.content_id, a.llm_tags, a.llm_hashtags, a.llm_summary FROM ARTICLE a JOIN SOURCE s ON s.id=a.source_id WHERE 1=1';
  $params = [];
  if (!$options['all-types']) { $query .= " AND s.source_type='podcast'"; }
  if ($options['content'] !== null) { $query .= ' AND a.content_id=?'; $params[] = $options['content']; }
  $statement = $db->prepare($query . ' ORDER BY a.content_id');
  $statement->execute($params);
  $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
  $statement->closeCursor();
  if ($rows === [] && $options['content'] !== null) { throw new RuntimeException('Article not found for the selected source type'); }
  if ($options['analyze']) {
    fwrite(STDOUT, json_encode(classifyDistribution($rows, $catalog, $options['top']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    return 0;
  }
  $policy = hash('sha256', summarizeJson(['version' => 'tags_summary_relevance_v3', 'catalog' => $catalog]));
  if (!$options['nodb']) {
    // Compatible with the previous classifier's table; a new policy invalidates its cache.
    $db->exec('CREATE TABLE IF NOT EXISTS TOPIC_CLASSIFICATION (
      content_id TEXT PRIMARY KEY, input_hash TEXT NOT NULL, policy_hash TEXT NOT NULL,
      status TEXT NOT NULL, result_json TEXT, attempts INTEGER NOT NULL,
      retry_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, error TEXT)');
  }
  $stateRead = $options['nodb'] ? null : $db->prepare('SELECT * FROM TOPIC_CLASSIFICATION WHERE content_id=?');
  $stateWrite = $options['nodb'] ? null : $db->prepare('INSERT INTO TOPIC_CLASSIFICATION
    (content_id,input_hash,policy_hash,status,result_json,attempts,retry_at,updated_at,error)
    VALUES (?,?,?,?,?,1,0,?,?) ON CONFLICT(content_id) DO UPDATE SET input_hash=excluded.input_hash,
    policy_hash=excluded.policy_hash,status=excluded.status,result_json=excluded.result_json,
    attempts=1,retry_at=0,updated_at=excluded.updated_at,error=excluded.error');
  $read = $db->prepare('SELECT content_id,llm_tags,llm_hashtags,llm_summary FROM ARTICLE WHERE content_id=?');
  $processed = $saved = $missing = $failed = $skipped = 0;
  foreach ($rows as $row) {
    if ($options['limit'] > 0 && $processed >= $options['limit']) { break; }
    $id = $row['content_id'];
    $result = null;
    $error = null;
    $inputHash = hash('sha256', summarizeJson([$row['llm_tags'], $row['llm_summary']]));
    try {
      $tags = classifyTags($row['llm_tags']);
      if ($tags === null) {
        $result = ['status' => 'pending', 'hashtags' => null, 'reason' => 'llm_tags not generated; existing hashtags retained'];
      } else {
        $inputHash = hash('sha256', summarizeJson([array_keys($tags), $row['llm_summary']]));
        $result = classifyArticle($tags, $catalog, $row['llm_summary'] ?? '');
      }
      if (!$options['nodb'] && !$options['force']) {
        $stateRead->execute([$id]);
        $state = $stateRead->fetch(PDO::FETCH_ASSOC);
        $stateRead->closeCursor();
        if ($state && $state['input_hash'] === $inputHash && $state['policy_hash'] === $policy
          && $state['status'] === 'complete' && $result['status'] === 'complete'
          && $row['llm_hashtags'] === summarizeJson($result['hashtags'])) { $skipped++; continue; }
      }
    } catch (Throwable $exception) { $error = $exception->getMessage(); }
    $processed++;
    if ($options['nodb']) {
      fwrite(STDOUT, summarizeJson(['content_id' => $id, 'error' => $error] + ($result ?? ['status' => 'error'])) . "\n");
    } else {
      try {
        $db->exec('BEGIN IMMEDIATE');
        $read->execute([$id]);
        $current = $read->fetch(PDO::FETCH_ASSOC);
        $read->closeCursor();
        if ($current !== $row) { throw new RuntimeException('Tags changed during classification; result discarded'); }
        if (($result['status'] ?? null) === 'complete') {
          $update = $db->prepare('UPDATE ARTICLE SET llm_hashtags=? WHERE content_id=?');
          $update->execute([summarizeJson($result['hashtags']), $id]);
        }
        $stateWrite->execute([$id,$inputHash,$policy,$result['status'] ?? 'error',
          $result === null ? null : summarizeJson($result),time(),$error]);
        $db->exec('COMMIT');
      } catch (Throwable $exception) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) {}
        $error = $exception->getMessage();
      }
    }
    if ($error !== null) { $failed++; fwrite(STDERR, "Failed [{$id}]: {$error}\n"); }
    elseif ($result['status'] === 'pending') { $missing++; }
    else { $saved++; }
  }
  fwrite(STDERR, "Complete: {$saved} / Missing tags: {$missing} / Failed: {$failed} / Skipped: {$skipped} / AI calls: 0\n");
  return $failed === 0 ? 0 : 1;
}
