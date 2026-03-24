# タスク管理アプリ 引き継ぎメモ

> **最終更新**: 2026-03-24
> **次の作業担当への指示**: このファイルを読んでから、**必ず下記「作業開始前の手順」を実行してから** `index.html` を読むこと。

---

## プロジェクト概要

**単一HTMLファイルのタスク管理Webアプリ（Supabase + GitHub Pages）**

- URL: https://yamode.github.io/task-matrix/
- ソース: `D:\山人 Dropbox\00_YAMADO ALL\55_Claude\task\task-matrix-v2.html`
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
-- ユーザー（名前がユニークキー、同名=同一ユーザー）
users: id uuid PK, name text UNIQUE, created_at

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
       lw_calendar_event_id text,
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

## 実装済み機能（task-matrix-v2.html）

### ログイン
- 名前入力のみ（同名=同一アカウント）。UUIDをlocalStorageに保存して次回自動ログイン。

### タスクトレー + 4象限
- tray/q1/q2/q3/q4 の5エリア
- SortableJS によるドラッグ&ドロップ（タッチ対応）
- モバイル: 1カラム表示

### タスク詳細シート（ボトムシート）
- タスク行をタップで開く
- 編集可能: タスク名・象限・期限・サブタスク・メモ・URL・ファイル添付
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
- インスタンス詳細シート: サブタスク・メモ・URL・ファイル添付

### マスタ管理（分離オーバーレイ）
- 「⚙️ マスタ管理」ボタンで全画面オーバーレイ
- タブ: マスタ一覧 / 共有設定
- 共有: 相手の名前で追加、カレンダーが共有される

---

## 作業開始前の手順（必須）

**編集対象は `index.html`（GitHubに追跡されているファイル）。`task-matrix-v2.html` は古い作業コピーで、現在は参照しないこと。**

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
git add index.html
git commit -m "コミットメッセージ"
git push origin main
```

---

## 今後の優先実装候補（未着手）

1. **LINE WORKS OAuth認証**（Phase 2）
   - WOFFアプリとしてLINE WORKS内に組み込む
   - `users` テーブルに `lw_user_id` カラムを追加予定
   - 現在の名前ベースログインを置き換える

2. **LINE WORKSカレンダー同期**
   - 定型タスクのインスタンス生成時にLW Calendarにイベント登録
   - `recurring_instances.lw_calendar_event_id` カラムが既にある

3. **通知**
   - 配布タスクの承認/不服/完了確認時にLINE WORKSに通知（Bot）

---

## 引き継ぎ時のClaudeへの指示（コピペ用）

```
このフォルダの HANDOFF.md を読んで、タスク管理アプリの開発を引き継いでください。
HANDOFF.md にプロジェクト全体の構成・DBスキーマ・実装済み機能・
作業手順がまとまっています。作業開始前に必ず git pull を実行してから index.html を読むこと。
```
