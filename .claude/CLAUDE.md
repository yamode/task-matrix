# TASKUL 開発ルール

## デプロイフロー（必須）

**編集ファイルは `task-matrix-v2.html`。コミット前に必ず `dev/index.html` にコピーすること。**

```bash
cp "task-matrix-v2.html" "dev/index.html"
git add task-matrix-v2.html dev/index.html
```

- `dev/index.html` → `https://yamode.github.io/task-matrix/dev/`（開発・確認用）
- `index.html` → `https://yamode.github.io/task-matrix/`（リリース時に dev をコピー）

コピーを忘れると GitHub Pages の dev 環境が古いバージョンのまま残る。

## バージョン管理

- バージョン形式: `vYYYY-MM-DD-NN`（例: `v2026-03-28-12`）
- コミットのたびに末尾の連番を +1 する
- `task-matrix-v2.html` 末尾のフッター表記と `dev/index.html` の両方を更新する

```bash
grep "v2026-" dev/index.html  # コピー後にバージョンが一致しているか確認
```

## デバッグ方針

- `const DEBUG = true;` のままが開発版。リリース時のみ `false` に変更する
- デバッグパネル（`#debug-panel`）の表示は設定画面の「🐛 開発者設定」でON/OFF可能

## /handoff 時の追加確認事項

- `dev/index.html` のバージョンが `task-matrix-v2.html` と一致しているか確認する
- 一致していない場合はコピーしてからコミットする
