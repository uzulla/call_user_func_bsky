# Packagist to BlueSky

このツールは、Packagist.orgに投稿される新しいパッケージをBlueSkyのアカウントに自動投稿するためのPHPアプリケーションです。

## 機能

- Packagist.orgの新着パッケージRSSフィードを取得
- 新しいパッケージのみをBlueSkyに投稿
- 重複投稿を防止するための最新投稿チェック

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

`.env`ファイルを作成し、BlueSkyのアカウント情報を設定します：

```
BLUESKY_USERNAME=your-username.bsky.social
BLUESKY_PASSWORD=your-app-password
```

## 使用方法

```bash
php bin/console app:post-packages
```

## ライセンス

MIT
