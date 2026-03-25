# タスク管理アプリ 引き継ぎメモ

> **最終更新**: 2026-03-25（Q1-Q4 表示改善・カレンダー重複登録バグ修正・レポーティング・本番同期済み）
> **次の作業担当への指示**: このファイルを読んでから、**必ず下記「作業開始前の手順」を実行してから** `dev/index.html` を読むこと。

---

## 開発版・製品版の運用ルール

| ファイル | URL | 用途 |
|---|---|---|
| `dev/index.html` | `https://yamode.github.io/task-matrix/dev/` | **開発中はこちらのみ編集** |
| `index.html` | `https://yamode.github.io/task-matrix/` | リリース時に dev をコピーして反映 |

- WOFF（LINE WORKS）は現在 dev 版に向けている（test bot のため開発者のみ閲覧可）
- リリース時： `cp dev/index.html index.html` → commit → push → WOFF URL を製品版に戻す

---

## プロジェクト概要

**単一HTMLファイルのタスク管理Webアプリ（Supabase + GitHub Pages）**

- URL: https://yamode.github.io/task-matrix/
- ソース: `D:\山人 Dropbox\00_YAMADO ALL\55_Claude\task\index.html`（Macは `/Users/hikaru/山人 Dropbox/...`）
- GitHubリポジトリ: https://github.com/yamode/task-matrix（branch: main, file: index.html）
- Supabase Project: https://ynzpjdarpfaurzomrddu.supabase.co

---

## Supabase 認証情報

```
URL: https://ynzpjdarpfaurzomrddu.supabase.co
KEY: sb_publishable_iumeCKdl6tr3AU3DL0roWA_B_WiCOwn
```

（新形式のpublishable key。RLSはすべてのテーブルで `allow_all` ポリシーを設定済み）

---

## 現在のDBスキーマ（Supabase上に実在）

```sql
-- ユーザー（Phase 1+2: Supabase Auth + LINE WORKS WOFF 連携済み）
users: id uuid PK, name text UNIQUE, email text UNIQUE, auth_id uuid UNIQUE,
       lw_user_id text UNIQUE,
       display_name text,                   -- LW WOFFから取得した表示名（例: 佐々木 耀）
       created_at

-- 1-shotタスク
tasks: id bigserial PK,
       user_id uuid FK → users,
       quadrant text CHECK (tray/q1/q2/q3/q4),
       text text,
       done bool DEFAULT false,
       assigner_id uuid FK → users,          -- 配布した人（NULLなら自分のタスク）
       deadline date,
       approval_status text                  -- pending/accepted/disputed/done_pending/confirmed
         CHECK (pending/accepted/disputed/done_pending/confirmed),
       dispute_reason text,
       subtasks jsonb DEFAULT '[]',          -- [{id, text, done, order}]
       memo text DEFAULT '',
       url text DEFAULT '',
       lw_calendar_event_ids jsonb DEFAULT '{}', -- {lw_user_id: event_id}（期限設定時に同期）
       is_private boolean DEFAULT false,     -- true=レポートで内容非公開（件数はカウント）
       created_at, updated_at

-- 定型タスクマスタ
recurring_tasks: id bigserial PK,
       user_id uuid FK → users,
       type text CHECK (monthly/annual),
       name text,
       day int CHECK (1-31),
       month int CHECK (1-12),              -- annualのみ
       memo text DEFAULT '',
       url text DEFAULT '',
       subtasks jsonb DEFAULT '[]',         -- [{id, text, order}]（完了はインスタンス側）
       created_at

-- 定型タスクインスタンス（year/month単位で自動生成）
recurring_instances: id bigserial PK,
       master_id bigint FK → recurring_tasks,
       year int, month int,
       done bool DEFAULT false,
       subtask_done jsonb DEFAULT '{}',     -- {subtask_id: true}
       instance_memo text,
       lw_calendar_event_ids jsonb DEFAULT '{}', -- {lw_user_id: event_id, ...}（共有分含む）
       created_at
       UNIQUE(master_id, year, month)

-- 定型タスク共有
recurring_task_shares: id bigserial PK,
       master_id bigint FK → recurring_tasks,
       shared_with_user_id uuid FK → users,
       UNIQUE(master_id, shared_with_user_id)

-- 定型タスクインスタンスへのファイル添付
recurring_instance_files: id bigserial PK,
       instance_id bigint FK → recurring_instances,
       user_id uuid FK → users,
       file_path, file_name, file_type, file_size, created_at

-- 1-shotタスクへのファイル添付
task_files: id bigserial PK,
       task_id bigint FK → tasks,
       user_id uuid FK → users,
       file_path, file_name, file_type, file_size, created_at

-- Storageバケット: 'task-attachments'（private、anon allow_all）
```

---

## 実装済み機能

### ログイン
- メール+パスワード（Supabase Auth）。セッション永続化あり（リロード後もログイン維持）。
- LINE WORKSアプリ内ではWOFF自動ログイン（ログアウトボタン非表示）。

### タスクトレー + 4象限
- tray/q1/q2/q3/q4 の5エリア。パネル右上に「Q1〜Q4」番号を表示（凡例として）
- SortableJS によるドラッグ&ドロップ（タッチ対応）
- モバイル: 1カラム表示

### タスク詳細シート（ボトムシート）
- タスク行をタップで開く
- 編集可能: タスク名・象限（Q1〜Q4表記）・期限・サブタスク・メモ・URL・ファイル添付
- 「🔒 非公開にする」チェック: レポートで内容を隠す（件数はカウント）
- 期限設定時に自動でLINE WORKSカレンダーへ同期（専用カレンダー「タスクカレンダー」に登録）
- 完了トグル・削除ボタン

### タスク配布（アサイン）ワークフロー
- タスク詳細シートから相手の名前を入力して「配布する」
- 配布後: タスクが相手の `user_id` に移り `approval_status='pending'`
- **受信側**: 「受信タスク」セクション（トレー上部）に表示 → 「承認してトレーへ」または「不服申請」
- 承認: `approval_status='accepted'`, `quadrant='tray'` → 相手のトレーに入る
- 不服: `approval_status='disputed'`, `dispute_reason=理由`
- 作業完了: `done=true` → `approval_status='done_pending'`
- 配布者が「確認済みにする」: `approval_status='confirmed'`
- キャンセル: タスクが配布者のトレーに戻る

### 配布したタスク（セクション）
- 4象限の下に表示
- ステータスバッジ: 承認待ち/対応中/不服申請中/完了確認待ち/確認済み
- アクション: 確認済みにする / 取消

### 定型タスク（月次・年次）
- カレンダーナビ（◀▶で月移動）
- インスタンス自動生成（現在月〜12ヶ月先）
- **新規インスタンス生成時のみ** LWカレンダーへ自動同期（共有メンバー含む）
  - ⚠️ 既存インスタンスの再同期は廃止（クリーンアップ後の重複登録防止のため）
- インスタンス詳細シート: サブタスク・メモ・URL・ファイル添付

### マスタ管理（分離オーバーレイ）
- 「⚙️ マスタ管理」ボタンで全画面オーバーレイ
- タブ: マスタ一覧 / 共有設定
- 共有: 相手の名前で追加、カレンダーが共有される

### レポーティング（📊 レポートボタン）
- 右上「📊 レポート」ボタンで全画面オーバーレイ表示
- アクティブユーザー（タスク1件以上）のみ表示
- 列: メンバー / トレー / Q1 / Q2 / Q3 / Q4 / 完了 / 合計
- 行クリックでタスク詳細を展開
- 非公開タスク: 件数はカウント・内容は「🔒 非公開」と表示（本人は見える）

---

## 作業開始前の手順（必須）

**編集対象は `dev/index.html`。完成したら `cp dev/index.html index.html` して両方コミット・プッシュ。**

```bash
# Mac のリポジトリパス
cd "/Users/hikaru/山人 Dropbox/00_YAMADO ALL/55_Claude/task"

# .git 内がDropbox Smart Syncでオンライン専用になっている場合は先にダウンロード
for f in "/Users/hikaru/山人 Dropbox/00_YAMADO ALL/55_Claude/task/.git/"*; do
  [ -f "$f" ] && open -g "$f"
done
sleep 5

# リモートの最新を取得（作業開始前に必ず実行）
git pull origin main
```

## GitHubへのプッシュ手順（作業後）

```bash
cd "/Users/hikaru/山人 Dropbox/00_YAMADO ALL/55_Claude/task"
# 本番反映する場合
cp dev/index.html index.html
git add dev/index.html index.html
git commit -m "コミットメッセージ"
git push origin main
```

---

## ロードマップ

### ✅ Phase 2（完了・動作確認済み）— LINE WORKS WOFF自動ログイン

- WOFF SDK（v3.6）組み込み済み　※SDKパス: `static.worksmobile.net/static/wm/woff/edge/3.6/sdk.js`
- WOFF ID: `2sGuLQU8T2BvJXN88QeCIg`（※末尾は大文字I、小文字lではない）
- LINE WORKSアプリ内からタップ → ログイン画面なしに自動認証（2026-03-25 動作確認）
- `users.lw_user_id` にLINE WORKS UUID（`profile.userId`）を登録することで紐づけ
  - ⚠️ WOFFの`profile.userId`はUUID形式（例: `8ed17a2a-8453-42df-...`）でログインIDではない
  - 全スタッフ（45名）のUUIDをLINE WORKS Users APIで取得・一括登録済み
- テストBot（ID: 6811651）の固定メニューに「📋 タスク管理」登録済み
- ブラウザアクセス時はメール+パスワードにフォールバック
- no-cacheメタタグ追加済み（LINE WORKS WebViewのキャッシュ対策）

**LINE WORKS Developer Console設定：**
- アプリ名: タスクマトリクス（Client ID: IJaFwFL_nzmI5Thmne4d）
- WOFFアプリ: タスクマトリクス（ID: 2sGuLQU8T2BvJXN88QeCIg）
- Endpoint URL: `https://yamode.github.io/task-matrix/`
- WOFF URL: `https://woff.worksmobile.com/woff/2sGuLQU8T2BvJXN88QeCIg`
- 固定メニュー登録スクリプト: `55_Claude/woff-approval/`の認証情報を流用

---

### ✅ Phase 1（完了）— ID/パスワード認証

- Supabase Auth（メール+パスワード）導入済み
- 新規登録フォーム・パスワードリセット（メール送信）実装済み
- `users` テーブルに `email`, `auth_id` カラム追加済み
- RLS有効・全テーブルに `allow_all` ポリシー（`TO anon, authenticated`）設定済み
- `get_my_user_id()` ヘルパー関数作成済み
- セッション管理は Supabase Auth（JWT）に移行済み

**Supabase設定済み事項：**
- Authentication → Sign In / Providers → Email：Confirm email = OFF
- Authentication → URL Configuration：Site URL = `https://yamode.github.io/task-matrix/`
- RLS：全テーブル有効、`allow_all (TO anon, authenticated)` ポリシー
- GRANT：`anon`, `authenticated` ロールに全テーブルのアクセス権付与済み

---

### ✅ Phase 0（完了）— コア機能
- タスクトレー＋4象限・D&D
- タスク詳細シート（サブタスク・メモ・URL・ファイル添付）
- タスク配布ワークフロー（承認/不服/完了確認）
- 定型タスク（月次・年次）＋インスタンス管理
- マスタ管理・共有設定
- iPhone UX改善（ズーム防止・スワイプで閉じる）

---


### ✅ Phase 3（完了）— LINE WORKS 深化連携

- [x] カレンダー同期（定型タスク生成時にLWカレンダーへイベント登録）
  - `recurring_instances.lw_calendar_event_ids` / `tasks.lw_calendar_event_ids` カラム使用
  - 通常タスクの期限設定時もカレンダー同期済み（`saveTaskDeadline()`）
  - PHPプロキシ: `https://yamado.co.jp/task-cal/api.php`
  - 専用カレンダー「タスクカレンダー」に登録（`getTaskCalendarId()`で初回自動作成）
  - ⚠️ **重複登録バグ修正済み（2026-03-25）**: `ensureInstances()` の既存インスタンス自動再同期を廃止。新規生成時のみ同期するように変更。
- [x] display_name: WOFFログイン時にLINE WORKSのdisplayNameを自動取得・DB保存。配布先インクリメンタルサーチも対応
- [x] WOFFログイン時はログアウトボタン非表示
- [x] タスク追加スキル: `~/.claude/commands/add-task.md`。Supabase REST API経由でトレーにタスクを追加
- [x] セッション永続化（PCブラウザ）: リロード後もログイン状態を維持
- [x] レポーティング: 全員のタスク件数をメンバー一覧で表示。行クリックで明細展開。非公開タスクは件数のみカウント・明細は「🔒 非公開」表示
- [ ] Bot通知（配布タスクの承認/不服/完了確認時）← 未着手

**前提**: Phase 2 完了後に着手

---

## カレンダー一括削除スクリプト（緊急時用）

重複登録が発生した場合は `task-cal-cleanup.php` を使用して一括削除する。

```
場所: /Users/hikaru/山人 Dropbox/00_YAMADO ALL/55_Claude/task/task-cal-cleanup.php
デプロイ先: https://yamado.co.jp/task-cal/cleanup.php
（使用後は必ず 404 スタブに上書きすること）
```

**デプロイ・実行・無害化手順：**
```bash
# デプロイ
curl --ftp-ssl -u "yamado:yamado132586" \
  -T "/Users/hikaru/山人 Dropbox/00_YAMADO ALL/55_Claude/task/task-cal-cleanup.php" \
  "ftp://sv14189.xserver.jp/yamado.co.jp/public_html/task-cal/cleanup.php"

# ブラウザで https://yamado.co.jp/task-cal/cleanup.php を開いて実行

# 無害化（実行後すぐに）
echo '<?php http_response_code(404); exit;' > /tmp/stub.php
curl --ftp-ssl -u "yamado:yamado132586" \
  -T "/tmp/stub.php" \
  "ftp://sv14189.xserver.jp/yamado.co.jp/public_html/task-cal/cleanup.php"
```

**スクリプトの動作：**
- Service Account: `9rjbn.serviceaccount@yamado`（タスクマトリクスアプリ配下）
- 対象ユーザー: `hikaru.s@yamado`
- 対象カレンダー: `c_500280752_1d5e0c71-e7f0-4bd3-a06e-e0556fc611ac`
- `recurring_tasks` テーブルのタスク名と一致するイベントのみ削除
- 削除後に `recurring_instances.lw_calendar_event_ids` を空にリセット
- 秘密鍵: `/home/yamado/yamado.co.jp/private_20260325130643.key`（Xserver）

---

## テストチェックリスト

> リリース前・機能追加後に必ず流すこと。チェックは `- [ ]` のまま残す。

### 認証
- [ ] メール+パスワードで新規登録できる
- [ ] メール+パスワードでログインできる
- [ ] ログアウトできる
- [ ] パスワードリセットメールが届く
- [ ] LINE WORKSアプリ内から開くと自動ログインされる（実機確認）
- [ ] 未登録のLINE WORKSユーザーはエラーメッセージが表示される

### タスク操作
- [ ] タスクを追加できる（トレー・各象限）
- [ ] ドラッグ&ドロップで象限を移動できる（PC）
- [ ] ドラッグ&ドロップで象限を移動できる（iPhone実機）
- [ ] タスク行をタップして詳細シートが開く
- [ ] スワイプ下でシートが閉じる（iPhone実機）
- [ ] タスク名・期限・象限を編集して保存できる
- [ ] サブタスクの追加・完了・削除ができる
- [ ] メモ・URLが保存される
- [ ] ファイルを添付できる
- [ ] 完了トグルが機能する
- [ ] タスクを削除できる

### タスク配布ワークフロー
- [ ] 相手の名前でタスクを配布できる
- [ ] 受信側の「受信タスク」セクションに表示される
- [ ] 承認するとトレーに移動する
- [ ] 不服申請ができる
- [ ] 作業完了後に配布者が「確認済み」にできる
- [ ] 配布をキャンセルできる

### 定型タスク
- [ ] 月次タスクを作成できる
- [ ] 年次タスクを作成できる
- [ ] カレンダーナビで月を移動できる
- [ ] インスタンス詳細シートが開く
- [ ] インスタンスのサブタスク・メモが保存される
- [ ] 定型タスクを共有できる（相手のカレンダーに表示される）

### レポーティング
- [ ] 右上「📊 レポート」ボタンからオーバーレイが開く
- [ ] アクティブユーザー（タスク1件以上）のみ表示される
- [ ] トレー/Q1/Q2/Q3/Q4/完了/合計の件数が正しく表示される
- [ ] 行クリックで詳細（タスク一覧）が展開される
- [ ] 自分の非公開タスクは自分には内容が見える
- [ ] 他人の非公開タスクは「🔒 非公開」と表示される（件数には含まれる）
- [ ] タスク詳細シートの「🔒 非公開にする」チェックが保存される

---

## 引き継ぎ時のClaudeへの指示（コピペ用）

```
このフォルダの HANDOFF.md を読んで、タスク管理アプリの開発を引き継いでください。
HANDOFF.md にプロジェクト全体の構成・DBスキーマ・実装済み機能・
作業手順がまとまっています。作業開始前に必ず git pull を実行してから index.html を読むこと。
```
