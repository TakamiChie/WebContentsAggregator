<?php

declare(strict_types=1);
require_once __DIR__ . '/../classify_topics.php';
require_once __DIR__ . '/../vendor/autoload.php';

$checks = 0;
function check(bool $value, string $message): void {
  global $checks;
  if (!$value) { throw new RuntimeException($message); }
  $checks++;
}
function rejects(callable $action, string $message): void {
  try { $action(); } catch (Throwable) { check(true, $message); return; }
  throw new RuntimeException($message);
}
function database(): PDO {
  $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $db->exec("CREATE TABLE SOURCE(id INTEGER PRIMARY KEY,source_type TEXT);
    INSERT INTO SOURCE VALUES(1,'podcast'),(2,'blog');
    CREATE TABLE ARTICLE(content_id TEXT PRIMARY KEY,source_id INTEGER,llm_tags TEXT,llm_hashtags TEXT,
      llm_summary TEXT,transcript_vtt TEXT);
    INSERT INTO ARTICLE VALUES('a',1,'[\"山手縁乃庭\",\"ゲーム\",\"ゲーム\"]','[\"#旧分類\"]','山手縁乃庭では子どもたちとゲームを遊び、自由な遊びを支える運営の工夫について詳しく振り返っています。','not even valid VTT');
    INSERT INTO ARTICLE VALUES('b',1,'[\"他の団体\",\"ゲーム\"]',NULL,'ゲームの遊び方と攻略の工夫について詳しく紹介し、一緒に遊ぶ人たちとの交流の楽しさを語っています。','山手縁乃庭');
    INSERT INTO ARTICLE VALUES('c',1,NULL,'[\"#保持\"]',NULL,NULL);
    INSERT INTO ARTICLE VALUES('d',1,'[]','[\"#旧分類\"]',NULL,NULL);
    INSERT INTO ARTICLE VALUES('blog',2,'[\"山手縁乃庭\"]',NULL,'山手縁乃庭では子どもの遊びを支える活動を行い、運営の工夫や見守りの大切さを紹介しています。',NULL);");
  return $db;
}
function rules(): array {
  return [['hashtag' => '#山手縁乃庭','rules' => [['any' => ['山手縁乃庭','縁乃庭'], 'all' => ['山手','子どもの居場所'], 'none' => ['別会場']]]]];
}
function row(PDO $db, string $id): array {
  $read = $db->prepare('SELECT * FROM ARTICLE WHERE content_id=?'); $read->execute([$id]); return $read->fetch(PDO::FETCH_ASSOC);
}
$options = classifyOptions(['x']);
check(classifyOptions(['x','--analyze','--top=50'])['top'] === 50, 'analysis options');
foreach ([['--limit=-1'],['--top=0'],['--content'],['--cooldown=2'],['--unknown']] as $args) {
  rejects(fn() => classifyOptions(array_merge(['x'],$args)), 'bad or obsolete CLI rejected');
}
check(classifyTags(null) === null && classifyTags(' ') === null, 'missing tags');
check(classifyTags('[]') === [], 'generated empty tags');
check(count(classifyTags('["#SBC","ＳＢＣ","sbc"]')) === 1, 'normalized document deduplication');
check(array_keys(classifyTags('地域，教育、#山手縁乃庭')) === ['地域','山手縁乃庭','教育'], 'plain tag formats');
rejects(fn() => classifyTags('[broken'), 'broken JSON rejected');
rejects(fn() => classifyTags('{"tag":"地域"}'), 'object rejected');
rejects(fn() => classifyTags('[42]'), 'non-string rejected');
$catalog = rules();
$substantive = '山手縁乃庭と縁乃庭では、山手の子どもの居場所として日々遊びを支え、運営の工夫を振り返りました。';
check(classifyArticle(classifyTags('["縁乃庭"]'),$catalog,$substantive)['hashtags'] === ['#山手縁乃庭'], 'alias exact match with substantive summary');
check(classifyArticle(classifyTags('["山手縁乃庭の思い出"]'),$catalog)['hashtags'] === [], 'partial match does not assert mention');
check(classifyArticle(classifyTags('["山手","子どもの居場所"]'),$catalog,$substantive)['hashtags'] === ['#山手縁乃庭'], 'all-tags group');
check(classifyArticle(classifyTags('["山手"]'),$catalog)['hashtags'] === [], 'partial all-tags group does not match');
check(classifyArticle(classifyTags('["縁乃庭","別会場"]'),$catalog)['hashtags'] === [], 'exclude overrides matching group');
check(classifyArticle(classifyTags('["山手縁乃庭"]'),$catalog,'山手縁乃庭は夏休み期間中です。インタビュースキルや質問の工夫について詳しく話しています。')['hashtags'] === [], 'holiday-only mention excluded');
check(classifyArticle(classifyTags('["山手縁乃庭"]'),$catalog,'山手縁乃庭は休業中です。')['decisions']['#山手縁乃庭']['summary_relevance'][0]['reason'] === 'administrative_only', 'administrative reason recorded');
check(classifyArticle(classifyTags('["山手縁乃庭"]'),$catalog,'来週の山手縁乃庭の開催日と日程が案内され、木曜日に行く予定です。')['hashtags'] === [], 'long schedule-only summary excluded');
check(classifyArticle(classifyTags('["山手縁乃庭"]'),$catalog,'山手縁乃庭は今週休業中であることを紹介し、次の開催日と来週の日程を詳しく案内しています。')['hashtags'] === [], 'introducing a schedule is still administrative');
$passingMention = '山手縁乃庭の活動について簡単に紹介しています。' . str_repeat('インタビューの質問や編集について詳しく振り返り、聞き方の課題を考えています。',10);
check(classifyArticle(classifyTags('["山手縁乃庭"]'),$catalog,$passingMention)['hashtags'] === [], 'low proportion mention excluded');
check(classifyArticle(classifyTags('["山手縁乃庭"]'),$catalog,'山手縁乃庭の活動を紹介。')['hashtags'] === [], 'very short mention excluded');
check(classifyArticle(classifyTags('["山手縁乃庭"]'),$catalog,'')['hashtags'] === [], 'summary required to confirm depth');
check(classifyArticle(classifyTags('["山手縁乃庭"]'),$catalog,'山手縁乃庭の休業に至った運営上の課題と再開に向けた工夫を振り返り、地域の活動を継続する意義を考えています。')['hashtags'] === ['#山手縁乃庭'], 'substantive discussion of closure is retained');

$db = database();
$before = row($db,'a');
check(classifyRun($db,$options,$catalog) === 0, 'batch classification');
$after = row($db,'a');
check($after['llm_hashtags'] === '["#山手縁乃庭"]', 'tags saved');
check($after['llm_summary'] === $before['llm_summary'] && $after['transcript_vtt'] === $before['transcript_vtt']
  && $after['llm_tags'] === $before['llm_tags'], 'existing analysis and transcript preserved');
check(row($db,'b')['llm_hashtags'] === '[]', 'transcript is not used to add a candidate topic');
check(row($db,'c')['llm_hashtags'] === '["#保持"]', 'missing tags preserve hashtags');
check(row($db,'d')['llm_hashtags'] === '[]', 'generated empty tags clear stale hashtags');
check(row($db,'blog')['llm_hashtags'] === null, 'podcast-only scope');
$timestamp = $db->query("SELECT updated_at FROM TOPIC_CLASSIFICATION WHERE content_id='a'")->fetchColumn();
$db->exec("UPDATE TOPIC_CLASSIFICATION SET updated_at=123 WHERE content_id='a'");
check(classifyRun($db,$options,$catalog) === 0
  && (int)$db->query("SELECT updated_at FROM TOPIC_CLASSIFICATION WHERE content_id='a'")->fetchColumn() === 123, 'unchanged article skips save');
$db->exec("UPDATE ARTICLE SET llm_hashtags=NULL WHERE content_id='a'");
check(classifyRun($db,$options,$catalog) === 0 && row($db,'a')['llm_hashtags'] === '["#山手縁乃庭"]', 'cleared hashtags regenerated');
$positiveSummary = row($db,'a')['llm_summary'];
$db->exec("UPDATE ARTICLE SET llm_summary='山手縁乃庭は夏休み期間中です。' WHERE content_id='a'");
check(classifyRun($db,$options,$catalog) === 0 && row($db,'a')['llm_hashtags'] === '[]', 'summary change invalidates cache and removes incidental tag');
$update = $db->prepare("UPDATE ARTICLE SET llm_summary=? WHERE content_id='a'"); $update->execute([$positiveSummary]);
$db->exec("UPDATE ARTICLE SET llm_tags='[\"ゲーム\"]' WHERE content_id='a'");
check(classifyRun($db,$options,$catalog) === 0 && row($db,'a')['llm_hashtags'] === '[]', 'changed tags remove old association');
$newCatalog = [['hashtag'=>'#ゲーム','rules'=>[['any'=>['ゲーム'],'all'=>[],'none'=>[]]]]];
check(classifyRun($db,$options,$newCatalog) === 0 && row($db,'b')['llm_hashtags'] === '["#ゲーム"]', 'rule changes reclassify');
$db->exec("UPDATE ARTICLE SET llm_tags='[broken' WHERE content_id='a'");
check(classifyRun($db,$options,$newCatalog) === 1 && row($db,'a')['llm_hashtags'] === '["#ゲーム"]', 'invalid tags retain old hashtags');
check(classifyRun($db,array_replace($options,['all-types'=>true]),$catalog) === 1
  && row($db,'blog')['llm_hashtags'] === '["#山手縁乃庭"]', 'batch continues after error and all-types works');

$readDb = database();
$rows = $readDb->query("SELECT content_id,llm_tags,llm_summary FROM ARTICLE WHERE source_id=1 ORDER BY content_id")->fetchAll(PDO::FETCH_ASSOC);
$report = classifyDistribution($rows,$catalog,30);
check($report['articles'] === 4 && $report['generated_tag_articles'] === 3 && $report['missing_tag_articles'] === 1, 'distribution counts');
check($report['matched_articles'] === 1 && $report['unmatched_articles'] === 2, 'distribution topic coverage');
$frequencies = array_column($report['tag_frequencies'],null,'normalized');
check($frequencies['ゲーム']['documents'] === 2, 'duplicates count as one document');
check($report['frequency_histogram'] === [['documents_per_tag'=>1,'unique_tags'=>2],['documents_per_tag'=>2,'unique_tags'=>1]], 'full frequency histogram independent of top limit');
$cooccurring = array_column($report['topics'][0]['cooccurring_tags'],null,'normalized');
check($cooccurring['ゲーム']['cooccurring_documents'] === 1 && $cooccurring['ゲーム']['lift'] === 1.5, 'co-occurrence and lift');
check($cooccurring['山手縁乃庭']['lift'] === 3.0, 'topic-specific tag lift');
check(classifyArticle(classifyTags('["ゲーム"]'),$catalog)['hashtags'] === [], 'co-occurrence never creates inferred rules');
$readDb->exec('PRAGMA query_only=ON');
check(classifyRun($readDb,array_replace($options,['analyze'=>true]),$catalog) === 0, 'analysis read-only');
check(classifyRun($readDb,array_replace($options,['nodb'=>true,'limit'=>1]),$catalog) === 0, 'preview read-only');
check(row($readDb,'a')['llm_hashtags'] === '["#旧分類"]', 'read-only modes preserve DB');
check((int)$readDb->query("SELECT COUNT(*) FROM sqlite_master WHERE name='TOPIC_CLASSIFICATION'")->fetchColumn() === 0, 'read-only modes create no state');

$limitDb = database();
check(classifyRun($limitDb,array_replace($options,['limit'=>1]),$catalog) === 0
  && row($limitDb,'b')['llm_hashtags'] === null, 'limit respected');
check(classifyRun($limitDb,array_replace($options,['limit'=>1]),$catalog) === 0
  && row($limitDb,'b')['llm_hashtags'] === '[]', 'limit advances past cached article');
$four = array_map(fn($i)=>['hashtag'=>'#topic'.$i,'rules'=>[['any'=>['山手縁乃庭'],'all'=>[],'none'=>[]]]],range(1,4));
check(count(classifyArticle(classifyTags('["山手縁乃庭"]'),$four,$substantive)['hashtags']) === 4, 'no three-tag limit');
rejects(fn() => classifyRun(database(),$options,[]), 'empty catalog cannot clear tags');

$temp = sys_get_temp_dir().'/wca-tags-'.bin2hex(random_bytes(8));
mkdir($temp);
try {
  file_put_contents($temp.'/one.md',"---\ntitle: 活動\nhashtags: 山手縁乃庭\ntopic_classifier:\n  aliases: [縁乃庭]\n  tags_any: [フリースペース]\n  tags_all: [山手, 子ども]\n  tags_none: [別会場]\n---\n本文は使用しない\n");
  $loaded = classifyCatalog($temp);
  check($loaded[0]['rules'][0]['any'] === ['山手縁乃庭','縁乃庭','フリースペース'], 'explicit tags and aliases loaded');
  check($loaded[0]['rules'][0]['all'] === ['山手','子ども'], 'all group loaded');
  file_put_contents($temp.'/one.md',"---\nhashtags: 山手縁乃庭\n---\n");
  check(count(classifyCatalog($temp)) === 1, 'description not required');
  file_put_contents($temp.'/one.md',"---\nhashtags: 山手縁乃庭\ntopic_classifier:\n  tags_any: wrong\n---\n");
  rejects(fn()=>classifyCatalog($temp), 'bad tag rule rejected');
} finally { unlink($temp.'/one.md'); rmdir($temp); }
fwrite(STDOUT,"Passed {$checks} checks.\n");
