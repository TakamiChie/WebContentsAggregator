# データベースのリモートアップロード

`upload_database.php` は `OUTPUT_PATH` の SQLite データベースから整合性のあるスナップショットを作成し、ワークフローの最後にリモートへアップロードします。HTTPS（PUT）、SFTP、SCP、FTP、FTPS など、実行環境の PHP/cURL が対応する URL を利用できます。

`settings.json` にアップロード先を設定してください。パスワードは JSON に直接保存せず、環境変数名を指定します。

```json
{
  "OUTPUT_PATH": "~/Documents/onpu-tamago.net.2026/user/data/mediadata.sqlite3",
  "DATABASE_UPLOAD": {
    "URL": "sftp://example.com/var/www/data/mediadata.sqlite3",
    "USERNAME": "deploy",
    "PASSWORD_ENV": "WEBCONTENTS_UPLOAD_PASSWORD",
    "PRIVATE_KEY": "",
    "SSH_PUBLIC_KEY": "",
    "TIMEOUT": 300
  }
}
```

SSH 鍵を使う場合は、パスワード設定の代わりに次の項目を指定できます。`~` で始まるパスも利用できます。

```json
{
  "DATABASE_UPLOAD": {
    "URL": "sftp://example.com/var/www/data/mediadata.sqlite3",
    "USERNAME": "deploy",
    "PASSWORD_ENV": "",
    "PRIVATE_KEY": "~/.ssh/id_ed25519",
    "SSH_PUBLIC_KEY": "~/.ssh/id_ed25519.pub",
    "TIMEOUT": 300
  }
}
```

単独で確認する場合は次を実行します。

```sh
php upload_database.php
```

送信エラー時は終了コード 1 になり、Dagu のワークフローも失敗として記録されます。
