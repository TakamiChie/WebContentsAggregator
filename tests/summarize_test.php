<?php

declare(strict_types=1);

require_once __DIR__ . '/../summarize.php';
require_once __DIR__ . '/../vendor/autoload.php';

$checks = 0;
function check(bool $condition, string $message): void
{
  global $checks;
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
}

function rejects(callable $action, string $message): void
{
  try {
    $action();
  } catch (Throwable) {
    check(true, $message);
    return;
  }
  throw new RuntimeException($message);
}

function testDatabase(bool $withOutput): PDO
{
  $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $db->exec('CREATE TABLE ARTICLE (content_id TEXT PRIMARY KEY, title TEXT,
    transcript_vtt TEXT, summary TEXT, hashtags TEXT'
    . ($withOutput ? ', llm_summary TEXT, llm_tags TEXT, llm_hashtags TEXT' : '') . ')');
  $insert = $db->prepare('INSERT INTO ARTICLE (content_id, title, transcript_vtt, summary, hashtags)
    VALUES (?, ?, ?, ?, ?)');
  foreach (['a' => "WEBVTT\n\n00:00.000 --> 00:01.000\n地域活動の話です。\n",
    'b' => "WEBVTT\n\n00:00.000 --> 00:01.000\n別の記事です。\n", 'empty' => ' ', 'null' => null] as $id => $vtt) {
    $insert->execute([$id, '記事' . $id, $vtt, '配信元の概要', '#配信元タグ']);
  }
  return $db;
}

$temporary = sys_get_temp_dir() . '/wca-summary-' . bin2hex(random_bytes(8));
mkdir($temporary);
mkdir($temporary . '/nested');
try {
  $options = summarizeOptions(['summarize.php', '--content', 'a', '--nodb']);
  check($options === ['content' => 'a', 'nodb' => true, 'cooldown' => 20, 'nohashtag' => true, 'help' => false], 'space options');
  check(summarizeOptions(['x', '--nohashtag', '--content=a', '--nodb'])
    === ['content' => 'a', 'nodb' => true, 'cooldown' => 20, 'nohashtag' => true, 'help' => false], 'nohashtag combines with content and nodb');
  check(summarizeOptions(['x', '--content=a'])['content'] === 'a', 'equals option');
  check(summarizeOptions(['x', '--with-hashtags'])['nohashtag'] === false, 'candidate mode is opt-in');
  check(summarizeOptions(['x'])['nohashtag'] === true, 'no-candidate mode is default');
  check(summarizeOptions(['x'])['cooldown'] === 20, 'cooldown defaults to twenty seconds');
  check(summarizeOptions(['x', '--cooldown=0'])['cooldown'] === 0, 'zero cooldown disables waiting');
  check(summarizeOptions(['x', '--cooldown', '7'])['cooldown'] === 7, 'space cooldown syntax');
  foreach ([['--content'], ['--content='], ['--content', '--nodb'], ['--content=a', '--content=b'], ['--cooldown'], ['--cooldown='], ['--cooldown=-1'], ['--cooldown=1.5'], ['--cooldown=abc'], ['--cooldown=999999999999999999999999'], ['--unknown']] as $args) {
    rejects(fn() => summarizeOptions(array_merge(['x'], $args)), 'invalid CLI must fail');
  }
  check(summarizePath('relative/db.sqlite', 'test') === dirname(__DIR__) . DIRECTORY_SEPARATOR . 'relative/db.sqlite', 'relative path');
  check(!str_starts_with(summarizePath('~/Documents/test', 'test'), '~'), 'home path expansion');
  check(summarizePath('~Documents/test', 'test') === summarizePath('~/Documents/test', 'test'), 'legacy home syntax');
  rejects(fn() => summarizePath('', 'test'), 'empty path');

  file_put_contents($temporary . '/one.md', "---\ntitle: 地域活動\nhashtags: '地域, #山手'\n---\n説明\n### 見出し #anchor\n[link](https://example.test/#fragment)\n");
  file_put_contents($temporary . '/nested/two.MD', "\xEF\xBB\xBF---\r\ntitle: もう一つ\r\nhashtags: ['#地域', '技術']\r\n---\r\n別の説明\r\n");
  file_put_contents($temporary . '/plain.md', "# 見出し\n#本文タグ\n");
  $catalog = summarizeTagCatalog($temporary);
  check(count($catalog) === 3, 'recursive catalog deduplicates tags and ignores anchors');
  $byTag = array_column($catalog, null, 'hashtag');
  check(count($byTag['#地域']['pages']) === 2, 'duplicate tag retains page contexts');
  check(str_contains($byTag['#山手']['pages'][0]['description'], '説明'), 'catalog includes description');
  file_put_contents($temporary . '/invalid.md', "---\nhashtags: [broken\n---\n");
  rejects(fn() => summarizeTagCatalog($temporary), 'invalid YAML must fail');
  unlink($temporary . '/invalid.md');
  rejects(fn() => summarizeTagCatalog($temporary . '/missing'), 'missing tag directory must fail');

  $vtt = "\xEF\xBB\xBFWEBVTT\r\nKind: captions\r\n\r\nNOTE ignore\r\nignored\r\n\r\nSTYLE\r\n::cue { color: red }\r\n\r\nREGION\r\nid:region\r\n\r\ncue-id\r\n00:00:01.000 --> 00:00:02.000 align:start\r\n<v Speaker>地域 &amp; 技術</v>\r\n<b>活動</b>\r\n\r\n00:02.000 --> 00:03.000\r\n<00:02.100>こんにちは\r\n";
  check(summarizeTranscript($vtt) === "地域 & 技術 活動\nこんにちは", 'cue extraction strips timing, identifiers, metadata, markup');
  check(summarizeTranscript("WEBVTT\n\nNOTE empty\n") === '', 'empty cues');
  rejects(fn() => summarizeTranscript('not vtt'), 'invalid vtt');

  $valid = ['summary' => "一行目。\n二行目。\n三行目。\n四行目。\n五行目。", 'tags' => ['地域', '技術'], 'hashtags' => ['#地域']];
  $allowed = ['#地域', '#山手', '#技術', '#その他'];
  check(summarizeValidate(summarizeJson($valid), $allowed) === $valid, 'valid result');
  $wire = ['summary_lines' => explode("\n", $valid['summary']), 'tags' => $valid['tags'], 'hashtags' => $valid['hashtags']];
  check(summarizeValidate(summarizeJson($wire), $allowed) === $valid, 'wire lines converted to single string');
  foreach ([["one"], ['1', '2', '', '4', '5'], ['1', '2', "3\nextra", '4', '5'], ['1', '2', 3, '4', '5'], new stdClass()] as $lines) {
    rejects(fn() => summarizeValidate(summarizeJson(array_replace($wire, ['summary_lines' => $lines])), $allowed), 'invalid wire lines');
  }
  $none = $valid;
  $none['tags'] = [];
  $none['hashtags'] = [];
  check(summarizeValidate(summarizeJson($none), []) === $none, 'zero tags is valid');
  $maximum = $valid;
  $maximum['tags'] = array_map(fn($i) => 'タグ' . $i, range(1, 20));
  $maximum['hashtags'] = array_slice($allowed, 0, 3);
  check(summarizeValidate(summarizeJson($maximum), $allowed) === $maximum, 'maximum counts valid');
  $badResults = [
    ['summary' => ['一', '二', '三', '四', '五']],
    ['summary' => "一\n二\n三\n四"],
    ['summary' => "一\n二\n\n四\n五"],
    ['tags' => array_fill(0, 21, 'tag')],
    ['tags' => new stdClass()],
    ['tags' => [42]],
    ['tags' => ['']],
    ['hashtags' => $allowed],
    ['hashtags' => ['#未登録']],
    ['hashtags' => ['地域']],
  ];
  foreach ($badResults as $bad) {
    rejects(fn() => summarizeValidate(summarizeJson(array_replace($valid, $bad)), $allowed), 'bad output must fail');
  }
  rejects(fn() => summarizeValidate('not JSON', $allowed), 'invalid JSON');
  try {
    summarizeValidate("```json\n" . summarizeJson($valid) . "\n```", $allowed);
    throw new RuntimeException('code-fenced JSON must be rejected');
  } catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'LLM generated content is not valid JSON')
      && str_contains($error->getMessage(), 'Markdown code fence'), 'JSON error identifies stage and code fence');
  }
  rejects(fn() => summarizeValidate(summarizeJson($valid), []), 'empty catalog forbids generated tags');

  check(summarizeSchema([])['properties']['tags']['maxItems'] === 20, 'schema allows up to twenty analysis tags');
  $emptySchema = summarizeSchema([]);
  check($emptySchema['properties']['hashtags'] === ['type' => 'array', 'const' => []], 'empty catalog uses constant array without invalid zero-length grammar');
  $selectionSchema = summarizeSchema($allowed);
  check($selectionSchema['properties']['hashtags']['items']['enum'] === $allowed
    && $selectionSchema['properties']['hashtags']['maxItems'] === 3, 'normal selection schema retains candidates and limit');

  $payload = summarizePayload('', 'system', '{{ARTICLE_JSON}} / {{TAG_CATALOG_JSON}}', [], $catalog, '題名', '{{TAG_CATALOG_JSON}}');
  check(!array_key_exists('model', $payload), 'unspecified model omitted');
  check(str_contains($payload['messages'][1]['content'], '"transcript":"{{TAG_CATALOG_JSON}}"'), 'template substitutions are not recursive');
  $payload = summarizePayload(' local-model ', 'system', 'user', [], [], 'title', 'text');
  check($payload['model'] === 'local-model', 'specified model forwarded');
  $withoutCandidates = summarizePayload('', 'system', summarizeRead(__DIR__ . '/../prompt/summarize-user.txt'), [], [], '題名', '本文');
  check(str_contains($withoutCandidates['messages'][1]['content'], "[]"), 'empty candidate catalog sent');
  check(!str_contains(summarizeJson($withoutCandidates), '#地域')
    && !str_contains(summarizeJson($withoutCandidates), '別の説明'), 'candidate hashtags and page context absent');
  $noSelection = $valid;
  $noSelection['hashtags'] = [];
  check(summarizeValidate(summarizeJson($noSelection), []) === $noSelection, 'no candidates still permits content analysis tags');

  $db = testDatabase(true);
  $before = $db->query('SELECT * FROM ARTICLE ORDER BY content_id')->fetchAll(PDO::FETCH_ASSOC);
  $calls = [];
  $generate = static function (array $row) use (&$calls, $valid): string {
    $calls[] = $row['content_id'];
    return summarizeJson($valid);
  };
  $singleWaits = [];
  check(summarizeArticles($db, $options, $allowed, $generate, static function (int $seconds) use (&$singleWaits): void { $singleWaits[] = $seconds; }) === 0, 'nodb succeeds');
  check($singleWaits === [], 'single article has no cooldown');
  check($calls === ['a'], 'content filters exactly one article');
  check($db->query('SELECT * FROM ARTICLE ORDER BY content_id')->fetchAll(PDO::FETCH_ASSOC) === $before, 'nodb leaves data unchanged');

  $withoutOutput = testDatabase(false);
  $withoutOutput->exec('PRAGMA query_only = ON');
  check(summarizeArticles($withoutOutput, $options, $allowed, $generate) === 0, 'nodb works with read-only DB and missing output columns');
  rejects(fn() => summarizeCheckSchema($withoutOutput, false), 'write requires schema');
  $write = ['content' => 'a', 'nodb' => false];
  check(summarizeArticles($db, $write, $allowed, $generate) === 0, 'write succeeds');
  $saved = $db->query("SELECT * FROM ARTICLE WHERE content_id = 'a'")->fetch(PDO::FETCH_ASSOC);
  check($saved['llm_summary'] === $valid['summary'], 'five-line string stored');
  check(json_decode($saved['llm_tags'], true) === $valid['tags'], 'tags stored as JSON');
  check(json_decode($saved['llm_hashtags'], true) === $valid['hashtags'], 'hashtags stored as JSON');
  check($saved['summary'] === '配信元の概要' && $saved['hashtags'] === '#配信元タグ', 'source metadata preserved');
  check($db->query("SELECT llm_summary FROM ARTICLE WHERE content_id = 'b'")->fetchColumn() === null, 'other article unchanged');
  $calls = [];
  check(summarizeArticles($db, $write, $allowed, $generate) === 0, 'completed article succeeds without regeneration');
  check($calls === [], 'completed article does not call generator');
  check($db->query("SELECT * FROM ARTICLE WHERE content_id = 'a'")->fetch(PDO::FETCH_ASSOC) === $saved, 'skip preserves saved fields');
  check(summarizeArticles($db, $options, $allowed, $generate) === 0, 'nodb previews completed article');
  check($calls === ['a'], 'nodb still calls generator for completed article');
  $db->exec("UPDATE ARTICLE SET llm_tags = NULL WHERE content_id = 'a'");
  $saved = $db->query("SELECT * FROM ARTICLE WHERE content_id = 'a'")->fetch(PDO::FETCH_ASSOC);
  check(summarizeArticles($db, $write, $allowed, fn() => '{}') === 1, 'bad generation fails');
  check($db->query("SELECT * FROM ARTICLE WHERE content_id = 'a'")->fetch(PDO::FETCH_ASSOC) === $saved, 'failure preserves previous result');
  check(summarizeArticles($db, $write, [], fn() => summarizeJson($none)) === 0, 'save empty selection');
  check($db->query("SELECT llm_hashtags FROM ARTICLE WHERE content_id = 'a'")->fetchColumn() === '[]', 'no-match stored as empty array');

  $calls = [];
  $skipWaits = [];
  check(summarizeArticles($db, ['content' => null, 'nodb' => false], $allowed, $generate,
    static function (int $seconds) use (&$skipWaits): void { $skipWaits[] = $seconds; }) === 0, 'batch skips completed articles including empty JSON tags');
  check($calls === ['b'] && $skipWaits === [], 'only unfinished article is generated without waiting for skipped article');
  $db->exec("UPDATE ARTICLE SET llm_summary = ' ' WHERE content_id = 'a'");
  $db->exec("UPDATE ARTICLE SET llm_tags = '' WHERE content_id = 'b'");
  $calls = [];
  $events = [];
  check(summarizeArticles($db, ['content' => null, 'nodb' => false, 'cooldown' => 7], $allowed,
    static function (array $row) use (&$calls, &$events, $valid): string {
      $calls[] = $row['content_id'];
      $events[] = 'generate:' . $row['content_id'];
      if ($row['content_id'] === 'a') {
        throw new RuntimeException('simulated HTTP failure');
      }
      return summarizeJson($valid);
    }, static function (int $seconds) use (&$events): void { $events[] = 'wait:' . $seconds; }) === 1, 'batch reports partial failure');
  check($calls === ['a', 'b'], 'batch continues and skips empty transcripts');
  check($events === ['generate:a', 'wait:7', 'generate:b'], 'cooldown runs once between attempts, even after failure');
  $zeroWaits = [];
  check(summarizeArticles($db, ['content' => null, 'nodb' => true, 'cooldown' => 0], $allowed,
    fn() => summarizeJson($valid), static function (int $seconds) use (&$zeroWaits): void { $zeroWaits[] = $seconds; }) === 0, 'zero cooldown processes batch');
  check($zeroWaits === [], 'zero cooldown never waits');
  foreach (['missing', 'empty', 'null'] as $id) {
    rejects(fn() => summarizeArticles($db, ['content' => $id, 'nodb' => true], $allowed, $generate), 'missing/no transcript errors');
  }
  $previous = $db->query("SELECT llm_summary FROM ARTICLE WHERE content_id = 'a'")->fetchColumn();
  check(summarizeArticles($db, $write, $allowed, static function () use ($db, $valid): string {
    $db->exec("UPDATE ARTICLE SET title = 'changed during generation' WHERE content_id = 'a'");
    return summarizeJson($valid);
  }) === 1, 'concurrent article change detected');
  check($db->query("SELECT llm_summary FROM ARTICLE WHERE content_id = 'a'")->fetchColumn() === $previous, 'stale summary not saved');

  fwrite(STDOUT, "Passed {$checks} checks.\n");
} finally {
  foreach (['one.md', 'nested/two.MD', 'plain.md', 'invalid.md'] as $file) {
    if (is_file($temporary . '/' . $file)) {
      unlink($temporary . '/' . $file);
    }
  }
  rmdir($temporary . '/nested');
  rmdir($temporary);
}
