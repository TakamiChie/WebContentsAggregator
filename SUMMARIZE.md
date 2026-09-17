# 文字起こしの要約・タグ生成

`summarize.php` は `settings.json` の `OUTPUT_PATH` にある SQLite の `ARTICLE.transcript_vtt` を読み、LM Studio で次の3項目を生成します。

| 追加カラム | SQLite型 | 保存内容 |
| --- | --- | --- |
| `llm_summary` | `TEXT NULL DEFAULT NULL` | 改行で区切った5行の要約（単一の文字列） |
| `llm_tags` | `TEXT NULL DEFAULT NULL` | 内容分析タグ、最大20件のJSON文字列配列 |
| `llm_hashtags` | `TEXT NULL DEFAULT NULL` | 記事選定用ハッシュタグ、0～3件のJSON文字列配列（`#`付き） |

未生成は `NULL`、生成済みでタグがない場合は JSON の `[]` を保存します。配信元から取得する既存の `summary` / `hashtags` は変更しません。

## 必要なスキーマ（手動適用）

スクリプトはカラムの追加やテーブルの作成を行いません。通常実行前に、未追加のカラムについて以下のDDLを手動で適用してください。

```sql
ALTER TABLE ARTICLE ADD COLUMN llm_summary TEXT DEFAULT NULL;
ALTER TABLE ARTICLE ADD COLUMN llm_tags TEXT DEFAULT NULL;
ALTER TABLE ARTICLE ADD COLUMN llm_hashtags TEXT DEFAULT NULL;
```

入力には既存の `content_id`（主キー）、`title`、`transcript_vtt` が必要です。`--nodb` は生成結果用カラムの追加前でも実行できます。

## 設定・準備

PHPの `curl` / `pdo_sqlite` 拡張と、既存の Composer 依存（`composer install`、YAML読み取り用）が必要です。LM Studio のローカルサーバーを起動してください。

`settings.json` の設定例（既存の `CONTENTS` などと併用）:

```json
{
  "OUTPUT_PATH": "~/Documents/onpu-tamago.net.2026/user/data/mediadata.sqlite3",
  "TAG_COLLECTION_DIR": "~/Documents/onpu-tamago.net.2026/user/pages/02.works",
  "LLM_MODEL": "",
  "LLM_API_URL": "http://127.0.0.1:1234/v1/chat/completions"
}
```

- `LLM_MODEL`: 指定したモデルIDを送信します。省略・空文字列・`null` のときはリクエストの `model` を省略し、LM Studio の選択に任せます。
- `LLM_API_URL`: 任意。省略時は上記URLです。変更する場合はローカルLLMの Chat Completions エンドポイント全体を指定します。
- パスは `~` をホームディレクトリに展開します。既存設定と同様に `~Documents/...` も扱います。相対パスの基準はこのスクリプトのディレクトリです。

候補は `TAG_COLLECTION_DIR` 以下の `.md`（サブディレクトリを含む）のYAMLフロントマターの `hashtags` から収集します。文字列（カンマ・空白区切り）またはYAML配列に対応し、先頭の `#` は有無どちらでも構いません。重複を除き、`#`付きに統一します。本文の見出しやURLのアンカーは収集しません。

```yaml
---
title: 地域コミュニティ支援
hashtags: [地域コミュニティ支援, 山手縁乃庭]
---
地域の居場所づくりに関する活動の説明。
```

意味の近さを判断できるよう、候補ページのタイトルと本文もLLMへ渡します。候補が0件なら選定用ハッシュタグは `[]` になります。候補外タグ・件数超過・5行でない要約・壊れたJSONは保存せずエラーにします。意味的な関連性の判断自体はモデルに依存します。

プロンプトは `prompt/summarize-system.txt` / `prompt/summarize-user.txt`、出力形式は `prompt/summarize.schema.json` にあります。[LM Studio の構造化出力](https://lmstudio.ai/docs/developer/openai-compat/structured-output)を使用します。5行を安定させるため、LLMとの通信では5要素の `summary_lines` 配列を使い、検証後に改行で連結します。DB保存・標準出力の要約は単一の文字列です。

## 実行例

```shell
# 文字起こしがある全記事を生成・保存（記事選定用ハッシュタグなし）
php summarize.php

# content_idを指定した1記事を生成・保存
php summarize.php --content="対象のcontent_id"

# DBに書き込まず、1記事の生成結果を確認（--content ID の形式も可）
php summarize.php --content "対象のcontent_id" --nodb

# MDの候補を使用して記事選定用ハッシュタグも生成
php summarize.php --content "対象のcontent_id" --nodb --with-hashtags

# 全対象記事を生成し、標準出力にJSON Linesで出力
php summarize.php --nodb

# 記事間の待ち時間を5秒に変更（0で待機なし）
php summarize.php --cooldown=5

# 候補ハッシュタグ・ページ説明を渡さず、1記事の生成結果を比較検証
php summarize.php --content "対象のcontent_id" --nodb --nohashtag

# ローカルLLM不要の回帰テスト
php tests/summarize_test.php
```

`--nodb` ではDBを読み取り専用で開き、1記事につき1行のJSON（`content_id`, `summary`, `tags`, `hashtags`）を標準出力へ出します。要約中の改行はJSON内で `\n` となります。進捗・エラー・処理件数は標準エラーへ出します。

`--cooldown=N` または `--cooldown N` で記事間の待ち時間（整数秒）を指定できます。既定値は20秒、`0` で待機なしです。最初の記事の前と最後の記事の後は待ちません。記事の生成や保存に失敗した場合も、次の記事へ進む前に待ちます。単一記事の処理では待機しません。`--nodb` にも適用されます。

通常実行と `--nohashtag` は同じ動作です。候補ハッシュタグと候補ページのタイトル・本文をLLMに渡さず、記事データだけで要約と最大20件の内容分析タグ（`tags`）を生成します。候補リストは空配列となり、記事選定用の `hashtags` は `[]` に限定します。`TAG_COLLECTION_DIR` の参照・MDファイルの読み取りを省略するため、このモードでは同設定が未指定でも構いません。記事本文に元からあるハッシュタグは削除しません。`--nodb` を併用しない場合は生成結果をDBに保存し、既存の `llm_hashtags` も `[]` に更新します。候補を使う場合だけ `--with-hashtags` を指定してください。

候補が空の場合、出力スキーマには `const: []` を使います。一部のLM Studioランタイムでは `maxItems: 0` が不正な生成文法に変換され、構造化出力が効かなくなるためです。応答がJSONでなかった場合は、HTTP応答全体の解析失敗かLLM生成本文の解析失敗かをエラーメッセージで区別します。

全件処理は `transcript_vtt` がNULL・空文字列でない記事を対象とし、実行のたびに生成結果を更新します。WebVTTの時刻・キューID・注記・装飾タグを取り除き、発話本文を順序どおり送信します。入力は切り詰めません。長文でモデルのコンテキスト長を超える場合は、LM Studio側のコンテキスト長またはモデルを調整してください。出力の上限は2048トークンです。上限に達して途中で切れた応答は保存しません。HTTP接続は10秒、生成は600秒でタイムアウトします。

1記事の3項目をまとめて更新し、失敗した記事の既存結果は保持します。生成中に記事のタイトル・文字起こしが変わった場合も保存しません。記事単位の失敗後は残りを処理し、1件でも失敗すると終了コード1、全件成功なら0です。指定IDが存在しない・文字起こしがない場合も終了コード1です。
