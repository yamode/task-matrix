# TASKUL 開発ルール

## デプロイフロー（必須）

**編集ファイルは `task-matrix-v2.html`。コミット前に必ず `dev/index.html` にコピーすること。**

```bash
cp "task-matrix-v2.html" "dev/index.html"
git add task-matrix-v2.html dev/index.html
```

- `dev/index.html` → `https://yamode.github.io/taskul/dev/`（開発・確認用）
- `index.html` → `https://yamode.github.io/taskul/`（リリース時に dev をコピー）

コピーを忘れると GitHub Pages の dev 環境が古いバージョンのまま残る。

## 開発版・製品版の運用ルール

| ファイル | URL | 用途 |
|---|---|---|
| `dev/index.html` | `https://yamode.github.io/taskul/dev/` | **開発中はこちらで確認** |
| `index.html` | `https://yamode.github.io/taskul/` | リリース時に dev をコピーして反映 |

- WOFF（LINE WORKS）は製品版 URL に向けている
- リリース時： `cp dev/index.html index.html` → commit → push

## バージョン管理

- バージョン形式: `vYYYY-MM-DD-NN`（例: `v2026-03-28-12`）
- コミットのたびに末尾の連番を +1 する
- `task-matrix-v2.html` 末尾のフッター表記と `dev/index.html` の両方を更新する

```bash
grep "v2026-" dev/index.html  # コピー後にバージョンが一致しているか確認
```

## 作業開始前の手順（必須）

```bash
# Mac のリポジトリパス
cd "/Users/hikaru/山人 Dropbox/96_Claude/taskul"

# .git 内が Dropbox Smart Sync でオンライン専用になっている場合は先にダウンロード
for f in "/Users/hikaru/山人 Dropbox/96_Claude/taskul/.git/"*; do
  [ -f "$f" ] && open -g "$f"
done
sleep 5

# リモートの最新を取得（作業開始前に必ず実行）
git pull origin main
```

## GitHubへのプッシュ手順（作業後）

```bash
cd "/Users/hikaru/山人 Dropbox/96_Claude/taskul"
cp task-matrix-v2.html dev/index.html
# リリース時は dev/index.html → index.html もコピー
git add task-matrix-v2.html dev/index.html
git commit -m "コミットメッセージ

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
git push origin main
```

## ローカル開発サーバー

```bash
cd "/Users/hikaru/山人 Dropbox/96_Claude/taskul"
python3 -m http.server 8080
# http://localhost:8080/dev/ でアクセス
```

## Supabase 認証情報

```
URL: https://ynzpjdarpfaurzomrddu.supabase.co
KEY: sb_publishable_iumeCKdl6tr3AU3DL0roWA_B_WiCOwn
```

（新形式の publishable key。RLS はすべてのテーブルで `allow_all` ポリシーを設定済み）

## LINE WORKS 設定値

- アプリ名: TASKUL（Client ID: `IJaFwFL_nzmI5Thmne4d`）
- WOFFアプリ: TASKUL（ID: `2sGuLQU8T2BvJXN88QeCIg`）※末尾は大文字I、小文字lではない
- Endpoint URL: `https://yamode.github.io/taskul/`
- WOFF URL: `https://woff.worksmobile.com/woff/2sGuLQU8T2BvJXN88QeCIg`
- Bot ID: `6811651`（固定メニュー「📋 タスク管理」登録済み）

## デバッグ方針

- `const DEBUG = false;` がリリース版。開発時のみ `true` に変更する
- デバッグパネル（`#debug-panel`）の表示は設定画面の「🐛 開発者設定」でON/OFF可能

## /handoff 時の追加確認事項

- `dev/index.html` のバージョンが `task-matrix-v2.html` と一致しているか確認する
- 一致していない場合はコピーしてからコミットする
