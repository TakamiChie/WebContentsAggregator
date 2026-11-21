# 文字起こし・LLM概要のNotion同期

`upload_notion.php` は、SQLiteの `ARTICLE` から次の2番組の記事を選び、Notionの「ちえラジChat」ページ配下にある「音声配信原稿」データソースへ同期します。

- `takamichie`（ちえラジChat）
- `takamichie_gameonly`（高見知英のゲームの話しかしないポッドキャスト）

既存ページは `URL` プロパティで照合します。本文の見出し `文字起こし` から次のレベル1見出しまでにあるブロックをすべて削除し、SQLiteのWebVTTから抽出した文字起こしで置き換えます。旧見出し `見出し` は初回同期時に `文字起こし` へ改名します。どちらの見出しもないページでは、ページ末尾に `文字起こし` 見出しを作成して本文を追加します。

LLMの生成結果は本文ではなく、次のプロパティへ保存します。

- `LLM概要`: `ARTICLE.llm_summary`
- `LLMタグ`: JSON配列の `ARTICLE.llm_tags` をカンマ区切りのテキストへ変換
- `コンテンツID`: `ARTICLE.content_id`
- `自動同期日時`: 同期実行日時

URLが未登録の場合は、`名前`、`URL`、`日付` を設定して新しいページを作成します。ゲーム番組の記事には既存の `ゲームの話しかしないポッドキャスト` タグも設定します。ちえラジChatの記事は曜日や内容によって既存タグが変わるため、自動作成時にタグを推測しません。

## 設定

Notionでインテグレーションを作成し、「音声配信原稿」データベースへ接続してください。必要な権限は、読み取り、コンテンツ挿入、コンテンツ更新です。トークンは `settings.json` に保存せず、`TOKEN_ENV` で指定した環境変数へ設定します。

```json
{
  "NOTION_UPLOAD": {
    "DATA_SOURCE_ID": "9f2e6df6-a37c-44c9-a195-d3ab9c955d97",
    "TOKEN_ENV": "NOTION_TOKEN",
    "API_VERSION": "2026-03-11",
    "LOOKBACK_DAYS": 30,
    "TIMEOUT": 60
  }
}
```

通常実行では直近30日から、未同期または内容が変わった記事だけを同期します。同期済みハッシュとNotionページIDはSQLite内に自動作成する `NOTION_SYNC` テーブルへ保存します。

```powershell
$env:NOTION_TOKEN = 'secret_...'
php upload_notion.php
```

## 1記事を指定した動作確認

`--content` は期間制限を無視し、指定した `content_id` の1件だけを処理します。まず `--dry-run` で選択結果を確認できます。

```powershell
php upload_notion.php --content=c0554bcd-9395-4efe-bb4e-da8e635d05eb --dry-run
php upload_notion.php --content=c0554bcd-9395-4efe-bb4e-da8e635d05eb --force
```

過去分をすべて同期する場合は `--all`、同期ハッシュが同じ記事も再送する場合は `--force` を使います。

```powershell
php upload_notion.php --all
php upload_notion.php --content=<content_id> --force
```

## Notionデータベースのプロパティ

「音声配信原稿」には次のプロパティが必要です。2026年11月22日の確認時点では、すべて作成済みです。

- `名前`（タイトル）
- `日付`（日付）
- `URL`（URL）
- `タグ`（マルチセレクト）
- `コンテンツID`（テキスト）
- `自動同期日時`（日付）
- `LLM概要`（テキスト）
- `LLMタグ`（テキスト）

