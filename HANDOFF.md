# タスク管理アプリ 引き継ぎメモ

> **最終更新**: 2026-03-29（組織管理PhaseB完全実装・LWカレンダー同期リデザイン・月次タスクレポート追加）
> **次の作業担当への指示**: このファイルを読んでから、**必ず下記「作業開始前の手順」を実行してから** `task-matrix-v2.html` を読むこと。

---

## プロジェクト概要

**単一HTMLファイルのタスク管理Webアプリ（Supabase + GitHub Pages）**

- URL: https://yamode.github.io/task-matrix/
- 編集ファイル: `task-matrix-v2.html`（コミット前に `dev/index.html` にコピー必須）
- GitHubリポジトリ: https://github.com/yamode/task-matrix（branch: main）
- Supabase Project: https://ynzpjdarpfaurzomrddu.supabase.co

> 開発フロー・プッシュ手順・Supabase認証情報・LINE WORKS設定値は `.claude/CLAUDE.md` 参照

---

## DBスキーマ（Supabase上に実在）

```sql
users: id uuid PK, name, email, auth_id uuid, lw_user_id, lw_account_id,
       display_name, lw_task_calendar_id, lw_enabled boolean,
       org_id bigint FK→organizations, created_at

tasks: id bigserial PK, user_id, quadrant(tray/q1/q2/q3/q4), text, done,
       assigner_id, deadline date, approval_status(pending/accepted/disputed/done_pending/confirmed),
       dispute_reason, subtasks jsonb, memo, url,
       lw_calendar_event_ids jsonb,  -- {lw_user_id: {event_id, cal_id}}
       is_private boolean, area_id FK→areas,
       processed_at timestamptz, completion_memo, assigner_archived_at, created_at, updated_at

recurring_tasks: id bigserial PK, user_id, type(monthly/annual), name, day, month,
                 memo, url, subtasks jsonb, created_at

recurring_instances: id bigserial PK, master_id FK→recurring_tasks, year, month,
                     done boolean, subtask_done jsonb, instance_memo,
                     lw_calendar_event_ids jsonb,
                     UNIQUE(master_id, year, month)

recurring_task_shares: id bigserial PK, master_id, shared_with_user_id, UNIQUE(master_id, shared_with_user_id)
recurring_instance_files: id bigserial PK, instance_id, user_id, file_path, file_name, file_type, file_size
task_files: id bigserial PK, task_id, user_id, file_path, file_name, file_type, file_size, is_completion boolean

organizations: id bigserial PK, name text, owner_id uuid FK→users, created_at
org_members:   id bigserial PK, org_id FK→organizations, user_id FK→users,
               role text DEFAULT 'member', UNIQUE(org_id, user_id)

areas: id bigserial PK, name text, color text DEFAULT '#667eea', created_at
```

- **Storage**: バケット `task-attachments`（private、anon allow_all）
- **RLS**: 全テーブル `allow_all (TO anon, authenticated)` 設定済み
- **YAMADO組織**: 全45ユーザーを org_members に登録済み（2026-03-29）

---

## 実装済み機能

### 認証
- メール+パスワード（Supabase Auth）、セッション永続化あり
- LINE WORKSアプリ内: WOFF自動ログイン（ログアウトボタン非表示）

### タスク管理（コア）
- tray/q1/q2/q3/q4 の5エリア、SortableJSによるD&D（タッチ対応）
- PC版3カラムレイアウト（1200px以上）
- タスク詳細シート: タスク名・象限・期限・サブタスク・メモ・URL・ファイル添付・領域タグ
- 非公開フラグ（レポートで内容隠蔽・件数はカウント）
- 完了済みタスク: processed_at + 設定可能な保持期間（デフォルト30日）で自動削除

### タスク配布ワークフロー
- 配布→受信（pending）→承認（accepted）/差し戻し（disputed）→完了報告（done_pending）→確認済み（confirmed）
- 役割別フィールド制御（配布者/受信者で編集可能項目が異なる）
- Bot通知（配布・承認・差し戻し・完了報告・確認済み）
- LW 1:1トークルームへの遷移ボタン（`lw_account_id` 使用）

### 定型タスク（月次・年次）
- カレンダーナビ、インスタンス自動生成（現在月〜12ヶ月先）
- **LW同期: 手動ボタン方式**（タスク詳細「🔄 LW同期」ボタン押下時のみ）
  - 既存イベントは PUT 更新、LW側削除済みの場合は create フォールバック
  - `lw_calendar_event_ids` に `{lw_user_id: {event_id, cal_id}}` を保存
- インスタンス詳細: サブタスク・メモ・ファイル添付
- マスタ共有（相手のカレンダーにも同期）

### 組織管理（Phase B 完了: 2026-03-29）
- 設定画面「🏢 組織管理」セクション
  - オーナー: メンバー管理オーバーレイ（検索追加・インライン編集・削除）
  - オーナー: CSVテンプレートDL・CSVアップロード一括インポート
  - メンバー: 組織名閲覧のみ（編集不可）
- ヘッダーに組織名表示（PC: 名前の左、モバイル: ヘッダー左上）
- `org_members.role`: owner / member

### レポーティング
- `📊 レポート` タブ: 全メンバーのタスク件数一覧（トレー/Q1-Q4/合計）、行クリックで明細展開
- `🏷 領域別` タブ: 領域ごとのカンバン（PC横スクロール・モバイル縦積み）
- `📅 月次タスク` タブ: 組織全員の月次定型タスク状況（◀▶月ナビ）
  - **メンバー別ビュー**: 進捗バー付きカードグリッド
  - **日にち別ビュー**: 日付ごとに全員のタスクを担当者バッジ付きで一覧

---

## ロードマップ

### ✅ 完了フェーズ（Phase 0〜3, Phase A+B）
- Phase 0: タスクコア機能（D&D・詳細シート・配布ワークフロー・定型タスク）
- Phase 1: Supabase Auth認証（メール+パスワード）
- Phase 2: LINE WORKS WOFF自動ログイン（全45名UUID登録済み）
- Phase 3: LW深化連携（カレンダー同期・Bot通知・レポーティング・領域別ビュー）
- Phase A: 組織管理DB（organizations/org_members テーブル）
- Phase B: 組織管理UI（メンバー管理・CSV・ヘッダー表示）

### 🔲 Phase 4 — 音声入力待機モード（厨房・ハンズフリー）

- [ ] 「待機モード開始」ボタン、Web Speech API によるコマンド認識
- [ ] 「タスクを読んで」→ SpeechSynthesis 読み上げ、「〇〇をトレーに追加して」→ 登録
- [ ] タイムアウト後の自動再起動（実質常時待機）
- [ ] ウェイクフレーズ検知・厨房ビュー（大きなUI）

**技術メモ**: Web Speech API は HTTPS 必須。iOS は最初の1回タップが必要（完全ハンズフリー不可）

---

## 改善リスト（積み残し）

### バグ・不具合
（現時点で把握している未解決バグなし）

### UX改善
- [ ] スマホ版ヘッダー左上の空白にロゴを表示（`img/logo_300.png`、`IMG_BASE` で流用可）

### 新機能
- [ ] レポート: 人ごとにタスク分布を2次元グラフで可視化
- [ ] タスクのエクスポート（設定画面から）
- [ ] 組織管理: 広告管理機能（`#ad-banner` にカスタムHTMLを設定する管理画面）
- [ ] 多言語対応
- [ ] Payment機能（製品配布用）
- [ ] **製品版公開前: リポジトリ・フォルダ名を TASKUL に統一**
  - `96_Claude/task/` → `96_Claude/taskul/`、`task-matrix` → `taskul`
  - GitHub Pages URL 変更 → WOFFアプリURLも同時変更すること

---

## テストチェックリスト

> リリース前・機能追加後に必ず流すこと。チェックは `- [ ]` のまま残す。

### 認証
- [ ] メール+パスワードでログイン・ログアウトできる
- [ ] LINE WORKSアプリ内から開くと自動ログインされる（実機確認）

### タスク操作
- [ ] タスクを追加できる（トレー・各象限）
- [ ] D&Dで象限を移動できる（PC・iPhone実機）
- [ ] タスク詳細シートの編集・保存ができる（名前・期限・サブタスク・メモ・URL・ファイル）
- [ ] 完了トグル・削除が機能する

### タスク配布ワークフロー
- [ ] 配布→受信→承認→完了報告→確認済み の一連フローが動く
- [ ] 各ステップでBot通知が届く（LINE WORKSアプリで確認）

### 定型タスク
- [ ] 月次・年次タスク作成、カレンダーナビ、インスタンス詳細シートが動く
- [ ] 「🔄 LW同期」ボタンでLWカレンダーにイベントが登録される
- [ ] 日付変更後に再度同期すると既存イベントが更新される（重複しない）
- [ ] LW側でイベント削除後に同期するとcreateに切り替わる

### 組織管理
- [ ] オーナーはメンバー追加・編集・削除できる
- [ ] CSVアップロードで一括インポートできる
- [ ] メンバー（非オーナー）は閲覧のみで編集不可
- [ ] ヘッダーに組織名が表示される（PC・モバイル）

### 月次タスクレポート
- [ ] 「📅 月次タスク」タブを開くと組織全員のタスクが表示される
- [ ] メンバー別・日にち別ビューを切り替えられる
- [ ] ◀▶で月を移動できる

### レポーティング
- [ ] 📊 レポート / 🏷 領域別 タブが動く
- [ ] 非公開タスクは他人には「🔒 非公開」表示

---

## カレンダー一括削除スクリプト（緊急時用）

```
場所: task-cal-cleanup.php（リポジトリ内）
デプロイ先: https://yamado.co.jp/task-cal/cleanup.php
（使用後は必ず 404 スタブに上書きすること）
```

FTPデプロイ: `curl --ftp-ssl -u "yamado:yamado132586" -T task-cal-cleanup.php "ftp://sv14189.xserver.jp/yamado.co.jp/public_html/task-cal/cleanup.php"`

---

## 作業ログ

### 2026-03-27（バグ修正・UX改善・新機能）

サブタスクインライン編集、スマホ期限フィールド修正、配布者名キャッシュ修正、差し戻し表記統一、マスタ管理ソート・フィルタ、添付ファイルプレビュー（画像モーダル・PDF）、PC版3カラムレイアウト、領域（タグ）機能追加。

**バージョン:** `v2026-03-27-xx` / **コミット:** `b968a23`

---

### 2026-03-28（LW App Link・Bot通知・フッターナビ・完了報告・領域別ビュー）

- LWボタンをApp Link方式に修正（`lw_account_id` フィールド追加・既存ユーザーへ移行済み）
- Bot通知バグ修正（キャッシュミス時フォールバック追加）
- フッターナビ実装（2行4列グリッド・iPhone safe-area対応）
- デバッグパネル設定画面からON/OFF可能に
- 完了報告機能追加（done_pending フロー・役割別フィールド制御・アーカイブ分離）
- 添付ファイルバグ修正（パス安全化・contentType明示・共有/DLボタン追加）
- タスクメタアイコン（💬🔗🔒📎）
- 領域別タスクビュー（レポート内「🏷 領域別」タブ・カンバン形式）
- ログイン画面ロゴ（`img/logo_300.png`・`IMG_BASE`で動的パス解決）
- 組織管理 Phase A: DB作成のみ（organizations/org_members テーブル・users.org_id）

**バージョン:** `v2026-03-28-23`

---

### 2026-03-29（組織管理PhaseB・LWカレンダー刷新・月次タスクレポート）

**組織管理 Phase B 完全実装:**
- 設定画面に「🏢 組織管理」セクション追加（CSVテンプレートDL・CSVアップロード一括インポート）
- メンバー管理専用オーバーレイ（inline editing・検索追加・削除）
- アクセス制御: オーナーのみ管理可・一般メンバーは閲覧のみ
- ヘッダーに組織名表示（PC・モバイル）
- 全45ユーザーをYAMADO組織に一括割当
- バグ修正: PostgRESTのJOIN構文が不安定→2クエリ+JSマージに変更

**LWカレンダー同期リデザイン:**
- 自動同期廃止→手動「🔄 LW同期」ボタン方式に変更（重複登録問題の根本解決）
- PUT更新API対応（`task-cal-api.php` に update アクション追加・Xserverへデプロイ）
- LW側でイベント削除済みの場合はcreateにフォールバック
- タスク詳細からタグ管理ボタン削除（設定画面のみに統一）

**月次タスクレポート新機能:**
- レポートオーバーレイに「📅 月次タスク」タブ追加
- メンバー別ビュー: 進捗バー付きカードグリッド
- 日にち別ビュー: 日付ごとに全員のタスクを担当者バッジ付きで一覧
- ◀▶ 月ナビ付き

**バージョン:** `v2026-03-29-15`

**コミット:**
- `b0cdf74` feat: 設定画面に組織管理セクション追加・CSVテンプレートDL
- `30ea843` feat: 組織管理CSVアップロード・一括インポート
- `75af568` feat: 組織管理メンバー管理機能
- `1445146` feat: メンバー管理専用オーバーレイ
- `9b6f1c1` fix: メンバーを管理ボタン反応しない問題修正
- `243059f` fix: メンバー管理オーバーレイに誰も表示されない問題修正
- `c0ab86c` fix: 組織管理をオーナー限定に修正
- `36735a9` feat: ヘッダーに組織名表示
- `dac9a80` feat: LWカレンダー同期を手動ボタン方式に変更
- `1f117a3` feat: LWカレンダー同期をupdate API対応に変更
- `6da22da` fix: LW側イベント削除済みの場合にcreateへフォールバック
- `4c91609` fix: LW同期ボタンをさりげないデザインに変更
- `a8809ed` fix: タスク詳細のタグ管理ボタンを削除
- `2fcacf4` feat: 月次タスクレポート追加（メンバー別・日にち別ビュー）

**残作業:**
- 月次タスクレポートの動作確認（実機テスト）
- LW同期ボタンの動作確認（PUT更新・フォールバック）

---

## 引き継ぎ時のClaudeへの指示（コピペ用）

```
このフォルダの HANDOFF.md を読んで、タスク管理アプリの開発を引き継いでください。
作業開始前に必ず git pull を実行してから task-matrix-v2.html を読むこと。
```
