# Packagist to BlueSky

このツールは、Packagist.orgに投稿される新しいパッケージをBlueSkyのアカウントに自動投稿するためのPHPアプリケーションです。

## 機能

- Packagist.orgの新着パッケージRSSフィードを取得
- 新しいパッケージのみをBlueSkyに投稿
- GitHub Actions Variableを使用して最後に処理したパッケージの日時を保存
- 重複投稿を防止するためのフィルタリング

## 要件

- PHP 8.3以上
- Composer

## インストール

```bash
git clone [repository-url]
cd packagist-to-bluesky
composer install
```

## 設定

`.env`ファイルを作成し、必要な情報を設定します：

```
# BlueSky API credentials
BLUESKY_USERNAME=your-username.bsky.social
BLUESKY_PASSWORD=your-app-password

# GitHub API credentials and repository info
GITHUB_TOKEN=ghp_********************
GITHUB_REPOSITORY_OWNER=your-github-username
GITHUB_REPOSITORY_NAME=your-repository-name

# Packagist RSS feed URL
PACKAGIST_RSS_URL=https://packagist.org/feeds/releases.rss

# Log settings
LOG_LEVEL=info
```

### GitHub Actions Variableについて

このアプリケーションは、GitHub Actions Variableを使用して最後に処理したパッケージの公開日時を保存します。これにより、次回の実行時に既に処理済みのパッケージをスキップすることができます。

変数名は `LAST_ITEM_PUBDATE` で、UNIX秒形式の日時文字列が保存されます。

## 使用方法

```bash
php bin/console app:post-packages
```

## ライセンス

MIT
