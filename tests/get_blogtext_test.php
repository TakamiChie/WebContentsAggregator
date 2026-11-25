<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/get_blogtext.php';

function checkBlogText(bool $condition, string $message): void
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$directory = sys_get_temp_dir() . '/wca-blogtext-' . bin2hex(random_bytes(8));
mkdir($directory);
$database = $directory . '/articles.sqlite';
$settings = $directory . '/settings.json';
$atom = $directory . '/atom.xml';
$rss = $directory . '/rss.xml';

try {
  file_put_contents($atom, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <id>atom</id>
    <content type="html">&lt;h2&gt;見出し&lt;/h2&gt;&lt;p&gt;本文 &amp;amp; 続き&lt;br&gt;改行&lt;/p&gt;&lt;script&gt;不要&lt;/script&gt;</content>
  </entry>
  <entry>
    <link href="https://example.com/xhtml"/>
    <content type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml"><p>一段目</p><p>二段目</p></div></content>
  </entry>
  <entry><id>old</id><content type="html">&lt;p&gt;新しい本文&lt;/p&gt;</content></entry>
  <entry><id>wrong-source</id><content>更新対象外</content></entry>
  <entry><id>summary-only</id><summary>概要のみ</summary></entry>
  <entry><id>missing-article</id><content>未登録記事</content></entry>
</feed>
XML);
  file_put_contents($rss, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:body="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <item>
      <guid>rss</guid>
      <description>概要</description>
      <body:encoded><![CDATA[<p>RSS本文</p><ul><li>項目一</li><li>項目二</li></ul>]]></body:encoded>
    </item>
  </channel>
</rss>
XML);
  file_put_contents($settings, json_encode([
    'OUTPUT_PATH' => $database,
    'CONTENTS' => ['blogs' => [
      ['id' => 6, 'engine' => 'hatenablog', 'url' => $atom],
      ['id' => 7, 'engine' => 'hatenablog', 'url' => $rss],
      ['id' => 8, 'engine' => 'note', 'url' => $directory . '/absent.xml'],
      ['id' => 9, 'engine' => 'substack', 'url' => $directory . '/absent.xml'],
      ['id' => 10, 'url' => $directory . '/absent.xml'],
    ]],
  ], JSON_THROW_ON_ERROR));
  $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $db->exec("CREATE TABLE ARTICLE (content_id TEXT PRIMARY KEY, source_id INTEGER, fulltext TEXT);
    INSERT INTO ARTICLE VALUES ('atom', 6, NULL), ('https://example.com/xhtml', 6, ''),
      ('rss', 7, NULL), ('old', 6, '保存済み'), ('wrong-source', 8, NULL), ('summary-only', 6, NULL)");
  $args = ['get_blogtext.php', '--settings=' . $settings];
  checkBlogText(blogTextMain($args) === 0, 'Expected successful collection of both feeds');
  $rows = $db->query('SELECT content_id, fulltext FROM ARTICLE')->fetchAll(PDO::FETCH_KEY_PAIR);
  checkBlogText($rows === [
    'atom' => "見出し\n\n本文 & 続き\n改行",
    'https://example.com/xhtml' => "一段目\n\n二段目",
    'rss' => "RSS本文\n\n項目一\n\n項目二",
    'old' => '保存済み',
    'wrong-source' => null,
    'summary-only' => null,
  ], 'Expected only matching articles to receive the extracted text');
  checkBlogText(blogTextMain($args) === 0, 'Expected successful repeat run');
  checkBlogText($db->query('SELECT content_id, fulltext FROM ARTICLE')->fetchAll(PDO::FETCH_KEY_PAIR) === $rows,
    'Expected existing text to be preserved on repeat');
  fwrite(STDOUT, "get_blogtext_test: OK\n");
} finally {
  unset($db);
  foreach ([$database, $settings, $atom, $rss] as $file) {
    if (is_file($file)) {
      unlink($file);
    }
  }
  rmdir($directory);
}
