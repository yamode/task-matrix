# タスク管理アプリ 引き継ぎメモ

> **最終更新**: 2026-03-28（添付ファイルバグ修正・共有/DLボタン・タスクメタアイコン・領域別ビュー・ログインロゴ・画像パス動的解決）
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
       lw_user_id text UNIQUE,              -- LINE WORKS内部UUID（Bot API・WOFF認証用）
       lw_account_id text UNIQUE,           -- LW App Link emailList用（例: xxx@yamado）※組織ごとに形式が異なるため別管理
       display_name text,                   -- LW WOFFから取得した表示名（例: 佐々木 耀）
       lw_task_calendar_id text,            -- LWタスクカレンダーID
       lw_enabled boolean DEFAULT true,     -- LW連携オン/オフ（2026-03-28 追加）
       org_id bigint FK → organizations,    -- 所属組織（2026-03-28 Phase A）
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
       area_id bigint FK → areas,            -- 領域タグ（2026-03-27）
       processed_at timestamptz,             -- 完了時刻（自動削除判定用）（2026-03-28 追加）
       completion_memo text DEFAULT '',      -- 受信者の完了報告メモ（2026-03-28 追加）
       assigner_archived_at timestamptz,     -- 配布者側のアーカイブ日時（2026-03-28 追加）
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
       file_path, file_name, file_type, file_size,
       is_completion boolean DEFAULT false,          -- true=完了報告時の添付（2026-03-28 追加）
       created_at

-- Storageバケット: 'task-attachments'（private、anon allow_all）

-- 組織管理（Phase A: 2026-03-28 DB追加のみ・UIは未実装）
organizations: id bigserial PK, name text, owner_id uuid FK → users, created_at
org_members:   id bigserial PK, org_id bigint FK → organizations, user_id uuid FK → users,
               role text DEFAULT 'member', UNIQUE(org_id, user_id)

-- 領域タグ（2026-03-27 追加）
areas: id bigserial PK, name text NOT NULL, color text DEFAULT '#667eea', created_at
```

✅ **マイグレーション全実行済み（2026-03-28）**:
- areas テーブル GRANT、tasks.processed_at、users.lw_enabled、organizations/org_members テーブル、users.org_id
- users.lw_account_id（lw_account_id text UNIQUE + 既存ユーザーへのデータ移行）
- tasks.completion_memo、task_files.is_completion、tasks.assigner_archived_at（2026-03-28 後半セッション）

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
- 受信者が完了報告: メモ＋ファイルを添付して送信 → `approval_status='done_pending'`
  - 受信者側: 「⏳ 確認待ち」バッジ表示・整理不可
  - 配布者側: 詳細シート上部に「📋 完了報告」ボックスで報告内容を確認可能
- 配布者が「確認済みにする」: `approval_status='confirmed'` → 配布タスク一覧で取り消し線表示
- 配布者が「差し戻す」: `approval_status='accepted'` に戻り・完了報告データをクリア・受信者に通知
- キャンセル: タスクが配布者のトレーに戻る
- アーカイブ分離: 配布者は `assigner_archived_at`、受信者は `archived_at` を独立管理

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


### 🔲 Phase 4 — 音声入力待機モード（厨房・ハンズフリー用途）

**背景**: 厨房スタッフがiPadを常時掲示し、画面タッチなしでタスク確認・登録できるようにする

#### Phase 4A（最小構成）
- [ ] 「待機モード開始」ボタン（1タップで起動）
- [ ] Web Speech API (SpeechRecognition) によるコマンド認識
  - 「タスクを読んで」→ SpeechSynthesis で読み上げ
  - 「〇〇をトレーに追加して」→ タスク登録
  - 「Q1を読んで」など象限指定読み上げ
- [ ] タイムアウト後の自動再起動（実質的に常時待機）
- [ ] 視覚フィードバック（パルスアニメーション付きマイクアイコン）

#### Phase 4B（快適性向上）
- [ ] ウェイクフレーズ検知（「タスク」と言うと反応）
- [ ] 厨房ビュー（大きなUI・高視認性レイアウト）

**技術メモ**:
- Web Speech API は HTTPS 必須（GitHub Pages = OK）・iOS Safari 動作確認済み
- 最初の1回だけタップが必要（iOS セキュリティ制限）、完全ハンズフリーは不可
- `continuous: true` + タイムアウト自動再起動で実質常時待機を実現

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
- [x] Bot通知（タスク配布時に配布先へ通知）（2026-03-28 実装・修正完了）
  - 完了報告送信時→配布者へ通知、差し戻し時→受信者へ通知（2026-03-28 実装済み）

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

## 改善リスト（積み残し）

> スクリーンショットのタスクトレーから抽出（2026-03-26時点）。優先度は未定。

### バグ・不具合
- [x] 領域を追加するとエラーが出て失敗する（2026-03-28 areas テーブルへの GRANT + エラーログ追加。Supabase側でSQL実行要）
- [x] 添付ファイルの確認ができない（登録したら消える？）→ 2026-03-28 パス安全化・contentType明示で修正
- [x] 添付ファイルを開くと右上の閉じるでアプリごと閉じてしまう → 2026-03-28 共有/DLボタン・「閉じる」テキストボタンに変更
- [x] マスタ管理: 追加したあとにリストが更新されない（2026-03-27 修正済み）
- [x] サブタスクを追加すると、あとから編集できない（2026-03-27 インライン編集実装済み）
- [x] スマホからだと、期限設定フィールドの背景がグレーになっている（2026-03-27 修正済み）
- [x] スマホからだと、期限設定フィールドが右側にはみ出している（2026-03-27 修正済み）
- [x] 配布タスクの配布者名が「？」になることがある（2026-03-27 キャッシュ修正済み）
- [x] PC版: タスク完了の丸枠ボタンのクリック判定が厳しい（2026-03-27 24pxに拡大済み）

### UX改善
- [ ] スマホ版ヘッダー左上の空白にロゴを表示（`img/logo_300.png`、`IMG_BASE` で動的パス解決済みなので流用可）
- [x] マスタ管理の「＋追加」ボタンをヘッダーから「すべて/月次/年次」フィルター行の右端に移動（2026-03-28）
- [x] レポート画面の明細一覧では「完了済み」タスクを非表示にする（2026-03-28）
- [x] 配布タスクのパネルは PC版では常に開いた状態にする（2026-03-28 CSS @media 1200px で常時展開）
- [x] 月次タスクのヘッダー（年月表示エリア）の border-top を他カラム上部と揃える。「定型タスク」を cal-nav 内に収め、マスタ管理ボタンは設定画面に移動（2026-03-28）
- [x] PCレイアウト: 各カラムの上端ラインがずれている → 「緊急」「計画」ラベルを削除し上端を揃えた（2026-03-28）
- [x] アプリタイトルをヘッダーバーに移動（2026-03-28 TASKUL をヘッダー左端に配置）
- [x] 定型タスクのサブタスクがあるものをタップしたら、サブタスク一覧にスクロール（2026-03-28）
- [x] レポート画面の明細一覧の見出しを Q1〜Q4 に統一（2026-03-28）
- [x] 定型タスクマスタ画面の「+追加」ボタンと「✖️」の間隔を広げた（2026-03-28）
- [x] 添付ファイルのプレビューがしたい（2026-03-27 画像モーダル・PDFインライン実装済み）
- [x] 不服で差し戻されたタスクは、タスク作成者のタスクトレーに返ってくるほうが自然（2026-03-27 実装済み）
- [x] レポートの右上ボタンはアイコンではなく「レポートを閉じる」テキストにする（2026-03-27 修正済み）
- [x] レポートでは完了したタスクは集計に含めない（2026-03-27 完了列削除済み）
- [x] タスクの削除はタスク作成者だけが実行可能にする（2026-03-27 実装済み）
- [x] マスタ管理: トップページの「+追加」は不要（2026-03-27 削除済み）
- [x] 「不服申し立て」→「差し戻し」に表記変更（2026-03-27 全箇所変更済み）
- [x] タスクの「×」ボタン横のアイコンは不要（2026-03-27 削除済み）
- [x] マスタ管理: 日付順に並べる（2026-03-27 月次→年次・日付順ソート実装済み）
- [x] マスタ管理: 月次と年次で表示切り替え（2026-03-27 フィルタボタン追加済み）
- [x] 「×」ボタン押下時に削除確認ダイアログを表示する（2026-03-27 実装済み）
- [x] 完了済みタスクの丸枠に「☑️」を表示する（2026-03-27 ✓チェックマーク実装済み）

### 新機能
- [x] アプリ名を「TASKUL」に変更（title・ログイン画面・ヘッダー。WOFF設定はLW Developer Consoleで別途変更要）（2026-03-28）
- [x] タスク入力欄をヘッダーエリア直下に集約（`#global-add-bar`）（2026-03-28）
  - 行き先（トレー/Q1〜Q4）と領域をその場で選択。各エリアの個別入力欄は撤去
  - デフォルト行き先: タスクトレー／領域: localStorage で前回値を引き継ぐ
- [x] 配布タスクに LW 1:1 トークルームへの遷移ボタンを追加（💬 LW）（2026-03-28）
- [x] 処理済みタスクを「processed_at + 一定期間後に自動削除」方式に変更（2026-03-28）
  - 設定画面（⚙️ 設定）で保持期間（日数）を変更可能。デフォルト30日
  - ⚠️ Supabase マイグレーション必要: `ALTER TABLE tasks ADD COLUMN IF NOT EXISTS processed_at timestamptz;`
- [x] 広告表示エリアを追加（ヘッダー直下プレースホルダー `#ad-banner`）（2026-03-28）
- [x] LWカレンダー同期先を専用の「タスクカレンダー」にする（なければ自動作成）※Supabase `users.lw_task_calendar_id` にカレンダーIDをDB永続化済み（要マイグレーション: `ALTER TABLE users ADD COLUMN IF NOT EXISTS lw_task_calendar_id TEXT;`）
- [x] 完了済みタスクをまとめて「処理済み」ボックスに移動するボタンを画面上部に追加（2026-03-27 トグルで処理済みボックス表示）
- [x] PC版3カラムレイアウト（左: タスクトレー＋受信タスク配布タスク／中: 4象限タスク＋処理済み／右: 定型タスク）（2026-03-27 1200px以上で有効）
- [x] タスクに「領域（タグ）」を設定できるようにする（2026-03-27 実装済み）
  - 任意のユーザーが「領域マスタ」（⚙ボタン）から追加・編集・削除可能
  - DBに `areas` テーブルと `tasks.area_id` カラムを追加済み
  - ⚠️ **Supabase SQLマイグレーション実行済み（2026-03-27）**:
    ```sql
    CREATE TABLE IF NOT EXISTS areas (id bigserial PRIMARY KEY, name text NOT NULL, color text DEFAULT '#667eea', created_at timestamptz DEFAULT now());
    ALTER TABLE tasks ADD COLUMN IF NOT EXISTS area_id bigint REFERENCES areas(id);
    ```
- [x] モバイル固定フッターナビ実装（2026-03-28 v04〜v12）
  - 2行4列グリッド: トレー/緊急重要/重要/月次(右端2行span) / 配布/緊急/後回し
  - ログイン画面では非表示、ログイン後のみ表示
  - PC（1200px以上）では非表示
  - iPhone ホームインジケーター対応: `viewport-fit=cover` + `padding-bottom:env(safe-area-inset-bottom,20px)`
- [x] 設定・アーカイブ画面の閉じるボタンを「×」→「閉じる」ボタンに変更（2026-03-28）
- [x] 設定画面に「🐛 開発者設定」セクション追加（DEBUG=true 時のみ表示）（2026-03-28）
  - デバッグパネルのON/OFF切り替えチェックボックス（localStorage で状態保持）
  - デバッグパネルはフッターナビの上に表示（z-index競合解消）
- [x] Bot通知テスト関数（`testBotNotification`）を削除（役目終了・デッドコード）（2026-03-28）
- [x] `dev/index.html` への自動コピーをデプロイフローに組み込み（コミットのたびに実施）（2026-03-28）
- [x] 新規ビュー「領域別タスク」: 領域ごとのタスクリストをカンバン形式で横並び表示（スマホは縦スクロール）→ 2026-03-28 レポート内タブとして実装
- [x] レポート画面に領域別ビューを追加: 個人ごとに各領域のタスク件数を把握できるビュー → 2026-03-28 [🏷 領域別]タブとして実装・スマホは sticky ジャンプタグ付き
- [ ] レポート: 人ごとにタスク分布を2次元グラフで可視化
- [x] 設定画面（`#settings-overlay`）を追加（⚙️ 設定ボタンから開く）（2026-03-28）
- [ ] タスクのエクスポートを「設定」画面の中から実行できるようにする
- [x] **LINE WORKS連携のオン/オフ切り替え**（設定画面・ユーザー単位）（2026-03-28）
  - `users.lw_enabled boolean DEFAULT true` カラム追加済み（Supabase マイグレーション要）
  - オフ時: Bot通知・1:1ボタンを抑止。設定画面チェックボックスでトグル
- [x] Bot通知（配布タスク承認/差し戻し/完了確認時）（2026-03-28）
  - `api.php` に `send_message` アクション追加。Xserverにデプロイ済み
  - ⚠️ LW Developer Console で Service Account に `bot` スコープの権限付与が必要
- [x] 組織管理機能 Phase A: DB のみ（`organizations` / `org_members` テーブル作成 + `users.org_id`）（2026-03-28）
  - Supabase マイグレーション実行済み
  - UI実装は別セッション（Phase B）
- [x] `users.lw_account_id` カラム追加（2026-03-28）
  - LW App Link の `emailList` 用。`lw_user_id`（Bot API UUID）・`email`（ログイン用）・`lw_account_id`（App Link用）を分離管理
  - 組織管理実装時にインポート機能で各ユーザーへ設定できる設計
  - 既存ユーザーへのデータ移行済み（`email` から `.co.jp` 除去で自動セット）
- [ ] **製品版公開前：リポジトリ・フォルダ名を TASKUL に統一**（アプリ名正式決定に伴う整理）
  - ローカルフォルダ: `96_Claude/task/` → `96_Claude/taskul/`
  - GitHubリポジトリ: `task-matrix` → `taskul`
  - メインHTMLファイル: `task-matrix-v2.html` → `index.html`（または `taskul.html`）
  - GitHub Pages URL 変更: `yamode.github.io/task-matrix/` → `yamode.github.io/taskul/`
  - ⚠️ WOFFアプリのURL設定を同時に変更すること（旧URLは即時404になる）
  - `.claude/CLAUDE.md`・`HANDOFF.md` 内の参照を一括置換
- [ ] 多言語対応
- [ ] Payment機能（製品配布用）
- [ ] 製品化ロードマップの策定
- [ ] **組織管理機能 Phase B（PC限定）**
  - [ ] LINE WORKSユーザー情報のCSVアップロード機能（`organizations`/`org_members`/`users` へ一括登録）
  - [ ] CSVテンプレートファイルのダウンロード機能（カラム定義付き）
  - [ ] 広告管理機能：広告枠ごとにカスタムHTMLを設定・挿入できる管理画面（`#ad-banner` 等の既存枠を対象）

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

### カレンダー同期（タスクカレンダー対応）
- [ ] 1-shotタスクに期限を設定すると「タスクカレンダー」にイベントが登録される（デフォルトカレンダーではない）
- [ ] 期限を変更すると古いイベントが削除され、新しいイベントが「タスクカレンダー」に作成される
- [ ] 期限をクリアするとカレンダーイベントが削除される
- [ ] タスクを削除するとカレンダーイベントが削除される
- [ ] タスクを配布すると旧イベントが削除され、配布先ユーザーのカレンダーに再登録される
- [ ] 定型タスクの新規インスタンス生成時に「タスクカレンダー」に登録される
- [ ] 定型タスクのマスタ削除時にカレンダーイベントが削除される
- [ ] 「タスクカレンダー」が存在しないユーザーは初回ログイン時に自動作成される

### レポーティング
- [ ] 右上「📊 レポート」ボタンからオーバーレイが開く
- [ ] アクティブユーザー（タスク1件以上）のみ表示される
- [ ] トレー/Q1/Q2/Q3/Q4/完了/合計の件数が正しく表示される
- [ ] 行クリックで詳細（タスク一覧）が展開される
- [ ] 自分の非公開タスクは自分には内容が見える
- [ ] 他人の非公開タスクは「🔒 非公開」と表示される（件数には含まれる）
- [ ] タスク詳細シートの「🔒 非公開にする」チェックが保存される

---

## 作業ログ

### 2026-03-27

**実施内容:**

バグ修正:
- Bug3: `saveMaster()` のリスト更新をオーバーレイ開閉チェックなしで常時実行するよう修正
- Bug4: タスク詳細シートのサブタスクにインライン編集を追加（クリック→input→Enter/blurで保存）
- Bug5/6: スマホの期限フィールド背景を白に・はみ出し防止CSS追加
- Bug7: `cacheUsersFromTasks()` の引数にmatrixタスク全件を追加し配布者名の「？」を修正
- Bug8: `.task-check` サイズを18px→24pxに拡大しクリック判定を改善

UX改善:
- UX2: `submitDispute()` でタスクを `user_id=assigner_id, assigner_id=null, quadrant='tray'` に更新し差し戻し時に配布者トレーへ自動返却
- UX3: レポートの閉じるボタンを「レポートを閉じる」テキストに変更
- UX4: レポートテーブルから「完了」列を削除し合計にも含めない
- UX5: `renderTaskItem()` と詳細シートで `assigner_id` がある場合は削除ボタンを非表示
- UX6/8: トップの定型タスク「＋追加」ボタンと📋アイコンを削除
- UX7: 「不服申し立て/申請中」を全て「差し戻し」に変更（STATUS_LABEL・モーダル・トースト）
- UX9: `renderMoMasterList()` で月次優先・日付昇順ソートを追加
- UX10: マスタ管理オーバーレイに「すべて/月次/年次」フィルタボタンを追加
- UX11: `deleteTask()` の先頭に `confirm()` を追加
- UX12: `task-check-inner` のCSSをflex化し `✓` テキストを表示するよう変更

新機能:
- UX1: `previewFile()` 関数追加。画像は`<img>`モーダル、PDFは`<iframe>`、それ以外は「開く」ダウンロードリンク
- 新機能2: `showDoneBox` フラグ・「📦 完了済みをまとめる」トグルボタン・処理済みボックスセクション追加
- 新機能3: `.three-col-layout` ラッパーdivを追加し、`@media (min-width:1200px)` でCSS Gridによる3カラム化
- 新機能4: `areas` テーブル・`tasks.area_id` カラム追加（Supabase SQLマイグレーション実行済み）。タスク詳細に領域セレクタ・領域管理モーダル・タスクアイテムに色付きバッジを追加

**コミット:** `b968a23` — feat: 改善リスト大量実装（バグ修正・UX改善・新機能）

**残作業:**
- 添付ファイルの確認ができない問題（登録したら消える？）→ 未調査
- 添付ファイルを開くと右上閉じるでアプリが閉じる → 未調査
- 領域別カンバンビュー → 未着手
- レポートの領域別ビュー → 未着手
- Bot通知（配布タスクの承認/不服/完了確認時）→ 未着手

---

### 2026-03-28

**実施内容:**

LW App Link 修正（複数セッション）:
- LWボタンを App Link（`line.worksmobile.com/message/send`）方式に修正
- メッセージ入力モーダル追加（タスク名・期限＋任意メッセージを組み合わせて送信）
- `emailList` パラメータの試行錯誤:
  - UUID → NG（「ゲスト参加者は利用できません」エラー）
  - `users.email`（フルメール）→ NG
  - `.co.jp` 除去した `xxx@yamado` 形式 → OK（動作確認済み）

設計改善（lw_account_id）:
- LW IDは組織ごとに形式が異なるため `users.lw_account_id` カラムを独立追加
- `lw_user_id`（Bot API UUID）・`lw_account_id`（App Link用）・`email`（ログイン用）を分離管理
- Supabase マイグレーション実行済み・既存ユーザーへのデータ移行済み
- 組織管理機能（Phase B）でインポート機能を実装予定

Bot通知バグ修正:
- キャッシュミス時に通知が届かない問題を修正（DB直接取得フォールバック追加）
- `assignTask` の配布通知に `await` 追加・詳細ログ出力

Supabaseマイグレーション全実行:
- 前セッションから積み残していたマイグレーション SQL を全て実行済み

**バージョン:** `v2026-03-28-04`

**コミット:**
- `82a18c4` fix: Bot通知がキャッシュミスのユーザーに届かない問題を修正
- `c554f3a` fix: タスク配布時に配布先ユーザーへのBot通知が抜けていた
- `cb5dbc1` fix: LWボタンをApp Link方式に修正・メッセージ入力モーダル追加
- `17914b2` fix: LWトークのemailListをxxx@yamado形式に修正・バージョン表記追加
- `2c45932` feat: lw_account_id カラム追加・App Link emailList を専用フィールドで管理

---

### 2026-03-28（続き：フッターナビ・UX修正セッション）

**実施内容:**

フッターナビ実装（v05〜v12）:
- 2行4列グリッドのモバイル固定フッターを実装（トレー/緊急重要/重要/月次(2行span) / 配布/緊急/後回し）
- ログイン画面では非表示・ログイン後のみ表示・PC（1200px以上）では非表示
- iPhone ホームインジケーター被り問題を段階的に修正:
  - `viewport-fit=cover` をmetaタグに追加（`env(safe-area-inset-bottom)` を有効化）
  - `padding-bottom: env(safe-area-inset-bottom, 20px)` でボタンをインジケーター上に押し上げ
  - フッター下の白い余白とボタン下段の仕切り線を整備

デバッグパネル改善:
- デバッグパネルをフッターナビの上に表示するよう `bottom` を調整（z-index競合解消）
- 設定画面に「🐛 開発者設定」セクション追加（`DEBUG=true` 時のみ表示）
- チェックボックスでデバッグパネルON/OFF切り替え・localStorage で状態保持

UX修正:
- マスタ管理の「＋追加」ボタンをヘッダーからフィルター行右端に移動
- Bot通知テスト関数（`testBotNotification`）を削除（デッドコード）

デプロイフロー修正:
- `dev/index.html` へのコピーを毎回実施するフローを確立（以前は忘れていた）

**バージョン:** `v2026-03-28-12`

**コミット:**
- `88748eb` feat: フッターナビ追加・設定UI改善・領域管理強化
- `e6cbf24` refactor: Bot通知テスト関数を削除・v06
- `5d9f492` deploy: dev/index.html を v06 に更新（デプロイ漏れ修正）
- `34d4abe` fix: フッターナビ下切れ修正・マスタ管理＋追加ボタン移動 v07
- `cdd73d6` fix: デバッグパネルをフッター上に移動・設定画面にON/OFF追加 v08
- `26d554d` fix: viewport-fit=cover 追加・iPhoneホームインジケーター被り解消 v09
- `c2831d1` fix: フッターナビ下線追加・safe-area対応を padding→bottom 方式に変更 v10
- `f920a30` fix: フッター下の隙間を白で塗りつぶし v11
- `48b6db6` fix: フッター下段ボタンに仕切り線を復活 v12

**残作業:**
- 添付ファイルの確認ができない問題 → 未調査
- 添付ファイルを開くと右上閉じるでアプリが閉じる → 未調査
- Bot通知（承認/差し戻し/完了確認時）→ ⚠️ LW Developer Console で bot スコープ権限付与が必要
- 領域別カンバンビュー → 未着手
- レポートの領域別ビュー → 未着手
- 組織管理 Phase B（UI実装）→ 未着手

---

### 2026-03-28（続き：完了報告機能・フィールド制御・UX改善セッション）

**実施内容:**

完了報告機能（新規）:
- 受信者がタスク詳細から「完了報告を送る」フォーム（メモ＋ファイル）で報告を送信
- `approval_status='done_pending'` に遷移・配布者へBot通知
- 配布者の詳細シート上部に「📋 完了報告」ボックスを表示（メモ・添付ファイル確認可）
- 配布者が「確認済みにする」→ `confirmed`、「差し戻す」→ `accepted` にリセット＋受信者通知
- 新DBカラム: `tasks.completion_memo`、`task_files.is_completion`、`tasks.assigner_archived_at`

役割別フィールド制御（openTaskDetail 全面書き直し）:
- 配布者: メモ・URL・期限・ファイルのみ編集可。タスク名/象限/領域/非公開はdisabled
- 受信者: サブタスク・ファイル・象限・領域・メモのみ編集可。タスク名/期限/URL/非公開はdisabled

配布タスクアーカイブ分離:
- `assigner_archived_at` で配布者と受信者のアーカイブを独立管理
- `done_pending` 状態のタスクは受信者側の「整理」対象から除外

UX改善:
- 月次タスクセクションに「未完了のみ」iOSトグルスイッチフィルターを追加
- ログイン画面「山人 業務タスク管理」→「powered by YAMADO」
- 配布済みタスクの `confirmed` 状態を取り消し線・薄表示・✓ プレフィックスで表示

開発環境:
- `.claude/launch.json` 追加（`python3 -m http.server 8080` ローカルサーバー設定）
- ローカル確認: `http://localhost:8080/task-matrix-v2.html`
- Supabase Redirect URLsに `http://localhost:8080` 追加推奨

**バージョン:** `v2026-03-28-16`

**コミット:**
- `f26ef77` feat: 完了報告機能・役割別フィールド制御・配布タスクアーカイブ分離 (v2026-03-28-14)
- `a6430c4` feat: 月次タスク「未完了のみ表示」フィルタートグル追加 (v2026-03-28-15)
- `9d2a94e` fix: ログイン画面テキスト変更・月次フィルターをトグルスイッチUIに変更 (v2026-03-28-16)
- `2f8f36b` chore: .claude/launch.json 追加（ローカル開発サーバー設定）

**残作業:**
- 完了報告機能の実機テスト（Bot通知が届くか確認）
- 添付ファイルの確認ができない問題 → 未調査
- 添付ファイルを開くと右上閉じるでアプリが閉じる → 未調査
- 領域別カンバンビュー → 未着手
- レポートの領域別ビュー → 未着手
- 組織管理 Phase B（UI実装）→ 未着手

---

### 2026-03-28（続き：添付ファイル修正・領域別ビュー・ロゴ実装セッション）

**実施内容:**

添付ファイルバグ修正:
- アップロードパスを `Date.now()_ランダム英数字+拡張子` に変更（日本語ファイル名対応）
- `contentType` を明示指定（非画像ファイルのアップロード失敗を解消）
- `target="_blank"` を廃止し、スマホは Web Share API / PC は force ダウンロードに変更
- プレビューモーダルの閉じるボタンを「✕」→「閉じる」テキストに変更（WOFF誤操作防止）
- 共有/DLボタンを白背景で視認性改善
- ファイルアップロード/削除後に `fileCountCache` を即時更新・`renderMatrix()` を呼ぶ

タスクメタアイコン:
- `renderTaskItem` にメタアイコン行を追加（💬メモ / 🔗URL / 🔒非公開 / 📎添付ファイル）
- `loadTasks` 時に `task_files` の件数を一括取得して `fileCountCache` に保持

バグ修正:
- `deleteTask`（一覧✕ボタン）に LW カレンダーイベント削除を追加（詳細シート側は実装済みだった）

領域別タスクビュー:
- レポートオーバーレイに `[📊 レポート] [🏷 領域別]` タブを追加
- カンバン列は PC 横スクロール / モバイル縦積み
- 完了済みタスク除外・「領域なし」列はタスクがある場合のみ表示
- タスクカードをタップ → 詳細シートが上層（z-index 1100）で開き、閉じると領域ビューに戻る
- モバイル: 領域名ごとのカラーアンカータグを sticky ヘッダー内に配置（スクロール追従）

ログインロゴ:
- `img/logo_300.png` をリポジトリに追加
- ログイン画面の ✅ アイコン + 「TASKUL」テキストをロゴ画像に置き換え
- `IMG_BASE` 定数でURLのパス階層を自動解決（`/task-matrix/` → `img/` / `/task-matrix/dev/` → `../img/`）
- `dev/img/` を廃止し `img/` を1箇所のみで管理

**バージョン:** `v2026-03-28-23`

**コミット:**
- `880ccab` fix: 添付ファイルのアップロード修正・共有/DLボタン追加・タスクメタアイコン表示
- `8280f10` fix: 削除時のLWカレンダー連動・共有ボタン視認性改善・メタアイコン即時反映
- `28d43cd` feat: 領域別タスクビュー追加
- `c067397` refactor: 領域別ビューをレポート内タブに統合・モバイルジャンプタグ追加
- `cf5a241` fix: 領域ジャンプタグをstickyヘッダー内に移動（スクロール追従）
- `078e26e` feat: ログイン画面のロゴをimg/logo_300.pngに変更・TASKULテキスト削除
- `2d5bd2d` chore: ロゴ画像 img/logo_300.png を追加
- `c72d1cd` refactor: 画像パスをIMG_BASEで動的解決、dev/img/を廃止

**残作業:**
- スマホ版ヘッダー左上の空白にロゴを表示
- 組織管理 Phase B（PC限定）: LWユーザーCSVアップロード・テンプレDL・広告管理
- レポートの領域別ビュー拡張（タスク件数グラフ等）
- タスクエクスポート（設定画面から）
- 製品版公開前: リポジトリ・フォルダ名を TASKUL に統一

---

## 引き継ぎ時のClaudeへの指示（コピペ用）

```
このフォルダの HANDOFF.md を読んで、タスク管理アプリの開発を引き継いでください。
HANDOFF.md にプロジェクト全体の構成・DBスキーマ・実装済み機能・
作業手順がまとまっています。作業開始前に必ず git pull を実行してから index.html を読むこと。
```
