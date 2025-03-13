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

#### GitHub Actionsでの設定

GitHub Actionsのワークフローファイル（.github/workflows/your-workflow.yml）で、以下のように設定します：

```yaml
jobs:
  post-packages:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      # 他の必要なセットアップステップ...
      
      # GitHub Actions Variableから環境変数に設定
      - name: Set environment variables
        run: |
          echo "LAST_ITEM_PUBDATE=${{ vars.LAST_ITEM_PUBDATE || '1' }}" >> $GITHUB_ENV
      
      # アプリケーションの実行
      - name: Run application
        run: php bin/console app:post-packages
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          BLUESKY_USERNAME: ${{ secrets.BLUESKY_USERNAME }}
          BLUESKY_PASSWORD: ${{ secrets.BLUESKY_PASSWORD }}
```

この設定により：
1. GitHub Actions Variableの `LAST_ITEM_PUBDATE` を環境変数として設定します
2. 変数が存在しない場合は、デフォルト値として `1` を使用します
3. アプリケーションは環境変数から最後に処理したパッケージの公開日時を取得します
4. 処理後、アプリケーションはGitHub API経由でGitHub Actions Variableを更新します

## 使用方法

```bash
php bin/console app:post-packages
```

## ライセンス

MIT
