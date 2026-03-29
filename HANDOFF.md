# タスク管理アプリ 引き継ぎメモ

> **最終更新**: 2026-03-29（TEST BOT固定メニューをdev WOFF（TASKUL_DEV）に変更、dev/index.htmlのWOFF ID自動切り替え対応）
> **次の作業担当への指示**: このファイルを読んでから、**必ず下記「作業開始前の手順」を実行してから** `task-matrix-v2.html` を読むこと。

---

## プロジェクト概要

**単一HTMLファイルのタスク管理Webアプリ（Supabase + GitHub Pages）**

- URL: https://yamode.github.io/taskul/
- 編集ファイル: `task-matrix-v2.html`（コミット前に `dev/index.html` にコピー必須）
- GitHubリポジトリ: https://github.com/yamode/taskul（branch: main）
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
- **Phase C で追加予定**: `tenants`, `tenant_members`, `plans`, `plan_modules`, `modules`, `contracts`, `ad_campaigns`, `system_admins` + 既存テーブルに `tenant_id` 追加 + `users.org_id` 廃止（`org_members` に統一）+ `organizations.owner_id` 廃止（`tenant_members` に統一）（詳細はロードマップ Phase C 参照）
- **Phase D で追加予定**: `departments`, `department_members`, `daily_task_masters`, `daily_completions`（詳細はロードマップ Phase D 参照）
- **ERD**: `96_Claude/output/taskul-erd.html` に全体ER図あり

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

### 🔲 Phase C — テナント管理・モジュール構造・SaaS基盤（設計済み 2026-03-29）

#### C-1: 設計方針

**テナント = 課金・契約・データ隔離の単位。モジュール = 機能群の論理区分（DBスキーマ分けではない）。**

- ソロユーザーも1テナント（type: individual/corporate で区別）
- モジュールは `public` スキーマ内のテーブルプレフィックスで区別するだけ（`task_*`, `hr_*`, `acc_*`）
- データ隔離は `tenant_id` + RLS のみで完結。PostgreSQLスキーマ分けは使わない
- フロントエンドは将来 GitHub Pages 以外に移行可。Supabase のバックエンド設計は独立
- **ユーザーは複数組織・複数部署に所属可能**（すべて中間テーブルで多対多管理）

```
tenant（課金・契約・データ隔離の境界）
  ├── tenant_members（role: master/member）← 契約マスター
  ├── contract → plan → plan_modules（どのモジュールが使えるか）
  ├── ad_campaigns（広告表示ルール）
  └── organizations[]（店舗・支社・本社）
       ├── org_members（role: master/member）← 組織マスター
       └── departments[]（営業部・調理部・経理部）
            └── department_members（role: master/member）← 部署マスター

権限階層（上位は下位を包含）:
  契約マスター ⊃ 組織マスター ⊃ 部署マスター ⊃ メンバー

public スキーマ（すべてここ）
  ├── 共通:   tenants, tenant_members, plans, plan_modules, modules, contracts,
  │          users, organizations, org_members, departments, department_members ...
  ├── task_*: tasks, recurring_tasks, task_files, areas ...   ← タスク管理モジュール
  ├── hr_*:   hr_employees, hr_attendance, hr_shifts ...      ← 人事モジュール（将来）
  └── acc_*:  acc_journals, acc_invoices, acc_budgets ...     ← 経理モジュール（将来）
```

#### C-2: 新規テーブル（8テーブル）

**`tenants`**
```sql
create table public.tenants (
  id          bigserial primary key,
  name        text not null,
  slug        text unique,                      -- 将来のサブドメイン用
  type        text not null default 'individual' check (type in ('individual', 'corporate')),
  status      text not null default 'active' check (status in ('active', 'suspended', 'cancelled')),
  max_users   int not null default 5,
  created_at  timestamptz default now(),
  updated_at  timestamptz default now()
);
-- ※ owner_id は廃止 → tenant_members.role='master' で管理
```

**`tenant_members`**（テナント × ユーザー、契約マスター管理）
```sql
create table public.tenant_members (
  id          bigserial primary key,
  tenant_id   bigint not null references public.tenants(id) on delete cascade,
  user_id     uuid not null references public.users(id) on delete cascade,
  role        text not null default 'member' check (role in ('master', 'member')),
  unique(tenant_id, user_id)
);
```

**`modules`**（モジュール台帳 — システム管理者が管理）
```sql
create table public.modules (
  id          text primary key,                 -- 'task', 'hr', 'accounting'
  name        text not null,                    -- 'タスク管理'
  description text,
  icon        text,                             -- '📋'
  sort_order  int not null default 0,
  is_active   boolean not null default true,    -- false = 未リリース（アクセス不可）
  created_at  timestamptz default now()
);
-- 初期データ:
-- ('task',       'タスク管理', ..., 1, true)
-- ('hr',         '人事管理',   ..., 2, false)
-- ('accounting', '経理',       ..., 3, false)
```

**`plans`**（プランマスタ）
```sql
create table public.plans (
  id            bigserial primary key,
  name          text not null,                  -- 'free', 'starter', 'business', 'enterprise'
  display_name  text not null,
  max_users     int not null default 5,
  price_monthly int not null default 0,         -- 円
  price_annual  int not null default 0,
  plan_config   jsonb not null default '{}',    -- プランレベルの設定（branding, support等）
  is_active     boolean not null default true,
  sort_order    int not null default 0,
  created_at    timestamptz default now()
);
```

**`plan_modules`**（プラン × モジュールの紐付け）
```sql
create table public.plan_modules (
  id          bigserial primary key,
  plan_id     bigint not null references public.plans(id) on delete cascade,
  module_id   text not null references public.modules(id) on delete cascade,
  config      jsonb not null default '{}',      -- モジュール別機能フラグ
  unique(plan_id, module_id)
);
-- 例: free プラン × task モジュール
-- config: {"max_tasks": 100, "recurring_tasks": true, "lw_sync": false, "max_storage_mb": 500}
```

**`contracts`**（テナント × プランの紐付け）
```sql
create table public.contracts (
  id            bigserial primary key,
  tenant_id     bigint not null references public.tenants(id) on delete cascade,
  plan_id       bigint not null references public.plans(id),
  status        text not null default 'active' check (status in ('active', 'trial', 'expired', 'cancelled')),
  billing_cycle text not null default 'monthly' check (billing_cycle in ('monthly', 'annual', 'custom')),
  started_at    timestamptz not null default now(),
  expires_at    timestamptz,
  cancelled_at  timestamptz,
  notes         text,
  created_at    timestamptz default now(),
  updated_at    timestamptz default now()
);
```

**`ad_campaigns`**（広告設定）
```sql
create table public.ad_campaigns (
  id          bigserial primary key,
  name        text not null,
  html        text not null,                    -- #ad-banner に注入するHTML
  target_type text not null default 'global' check (target_type in ('global', 'plan', 'tenant')),
  target_id   bigint,                           -- plan_id or tenant_id（global は NULL）
  priority    int not null default 0,
  is_active   boolean not null default true,
  starts_at   timestamptz,
  ends_at     timestamptz,
  created_at  timestamptz default now()
);
```

**`system_admins`**（サービス運営者）
```sql
create table public.system_admins (
  id         bigserial primary key,
  user_id    uuid not null references public.users(id) on delete cascade unique,
  role       text not null default 'admin' check (role in ('admin', 'super_admin')),
  created_at timestamptz default now()
);
```

#### C-3: 既存テーブルの変更

**tenant_id 追加:**

| テーブル | 変更 | 備考 |
|---------|------|------|
| `users` | `+ tenant_id bigint FK→tenants` | 移行後 NOT NULL |
| `organizations` | `+ tenant_id bigint FK→tenants` | 1テナント内に複数 org 可 |
| `tasks` | `+ tenant_id bigint FK→tenants` + index | RLS隔離キー |
| `recurring_tasks` | `+ tenant_id bigint FK→tenants` + index | 同上 |
| `areas` | `+ tenant_id bigint FK→tenants` | テナント内共有 |

FK経由で隔離されるため追加不要: `task_files`, `recurring_instances`, `recurring_instance_files`, `recurring_task_shares`

**廃止・変更:**

| テーブル | 変更 | 理由 |
|---------|------|------|
| `users` | `org_id` カラム廃止 | `org_members` で多対多管理に統一（複数組織所属対応） |
| `organizations` | `owner_id` カラム廃止 | `tenant_members.role='master'` に統一 |
| `org_members` | `role` の値を `'owner'`→`'master'` に変更 | 権限階層の統一（master/member） |

#### C-4: SQL関数（モジュールアクセス制御 + 権限チェック）

```sql
-- system_admin 判定
create or replace function is_system_admin()
returns boolean language sql security definer stable as $$
  select exists(select 1 from public.system_admins where user_id = get_my_user_id())
$$;

-- 現在ユーザーの tenant_id 取得
create or replace function get_my_tenant_id()
returns bigint language sql security definer stable as $$
  select tenant_id from public.users where auth_id = auth.uid()
$$;

-- テナントが使えるモジュール一覧
create or replace function get_tenant_modules(p_tenant_id bigint)
returns setof text language sql security definer stable as $$
  select pm.module_id
  from contracts c
  join plan_modules pm on pm.plan_id = c.plan_id
  join modules m on m.id = pm.module_id
  where c.tenant_id = p_tenant_id
    and c.status in ('active', 'trial')
    and m.is_active = true
$$;

-- 契約マスター判定
create or replace function is_tenant_master(p_tenant_id bigint)
returns boolean language sql security definer stable as $$
  select exists(
    select 1 from public.tenant_members
    where tenant_id = p_tenant_id and user_id = get_my_user_id() and role = 'master'
  )
$$;

-- 組織マスター判定（契約マスターは自動的に組織マスターを兼ねる）
create or replace function is_org_master(p_org_id bigint)
returns boolean language sql security definer stable as $$
  select exists(
    select 1 from public.org_members
    where org_id = p_org_id and user_id = get_my_user_id() and role = 'master'
  ) or exists(
    select 1 from public.tenant_members tm
    join public.organizations o on o.tenant_id = tm.tenant_id
    where o.id = p_org_id and tm.user_id = get_my_user_id() and tm.role = 'master'
  )
$$;

-- 部署マスター判定（組織マスターは自動的に部署マスターを兼ねる）
create or replace function is_dept_master(p_dept_id bigint)
returns boolean language sql security definer stable as $$
  select exists(
    select 1 from public.department_members
    where department_id = p_dept_id and user_id = get_my_user_id() and role = 'master'
  ) or exists(
    select 1 from public.org_members om
    join public.departments d on d.org_id = om.org_id
    where d.id = p_dept_id and om.user_id = get_my_user_id() and om.role = 'master'
  ) or exists(
    select 1 from public.tenant_members tm
    join public.organizations o on o.tenant_id = tm.tenant_id
    join public.departments d on d.org_id = o.id
    where d.id = p_dept_id and tm.user_id = get_my_user_id() and tm.role = 'master'
  )
$$;
```

フロントエンドはログイン後に `get_tenant_modules()` を呼び、利用可能なモジュール一覧を取得してナビゲーションを構築する。権限チェック関数はRLS・アプリロジック両方から利用する。

#### C-5: プラン例

| プラン | 使えるモジュール | max_users | 月額 |
|--------|----------------|-----------|------|
| free | task | 3 | 0円 |
| starter | task | 10 | 980円 |
| business | task | 50 | 2,980円 |
| enterprise | task, hr, accounting | 無制限 | 要相談 |
| yamado_internal | task, hr, accounting | 無制限 | 0円（自社用） |

#### C-6: Admin Panel

- 同じ Supabase プロジェクト、`system_admins` テーブルで認証
- フロントエンドは別ファイル（現状は `admin/index.html`、将来は任意）

| 画面 | 内容 |
|------|------|
| ダッシュボード | テナント数・アクティブ契約数・総ユーザー数 |
| テナント管理 | 一覧・詳細編集・停止/再開 |
| モジュール管理 | モジュール台帳・is_active 切替 |
| プラン管理 | プラン一覧・編集・plan_modules の設定 |
| 契約管理 | テナント×プラン紐付け・ステータス変更 |
| 広告管理 | HTMLエディタ+プレビュー・配信対象・スケジュール |
| ユーザー検索 | 全テナント横断検索（読み取り中心） |

#### C-7: RLS戦略（2段階）

**Phase 1**（Admin Panel デプロイ時）: 管理テーブルのみ admin only RLS。既存テーブルは allow_all のまま。

**Phase 2**（SaaS公開前）: 全テーブルにテナント隔離を適用。
```sql
-- 例: tasks
create policy "tenant_isolation" on public.tasks
  for all to authenticated
  using (tenant_id = get_my_tenant_id())
  with check (tenant_id = get_my_tenant_id());

create policy "admin_bypass" on public.tasks
  for all to authenticated using (is_system_admin());
```
⚠️ Phase 2 は WOFF ログインフロー（anon アクセス）への影響があるため別途対策が必要。

#### C-8: マイグレーション手順

1. 新テーブル作成（追加のみ、リスクなし）
2. 既存テーブルに `tenant_id` 追加（nullable）
3. 初期データ投入（modules シード、yamado_internal プラン、山人テナント、契約、system_admin）
4. 既存データの `tenant_id` バックフィル
5. メインアプリの INSERT に `tenant_id` 追加、ログイン後に `get_tenant_modules()` 呼び出し追加
6. Admin Panel デプロイ + 管理テーブル RLS 有効化
7. `tenant_id` に NOT NULL 制約追加
8. 全テーブルのテナント RLS 有効化（⚠️ 要テスト）

### 🔲 Phase D — 日次タスク・部署管理（設計済み 2026-03-29）

部署（フロント・厨房・清掃等）ごとに毎日のルーティンタスクを管理する機能。完了は部署単位（誰か1人がやれば完了）。既存の定型タスク（月次/年次）とは独立したテーブル群。

#### D-1: 部署テーブル + 部署メンバー

```sql
create table public.departments (
  id          bigserial primary key,
  org_id      bigint not null references public.organizations(id) on delete cascade,
  name        text not null,
  sort_order  int not null default 0,
  created_at  timestamptz default now()
  -- Phase C: + tenant_id bigint FK→tenants
);
create unique index departments_org_name_idx on public.departments(org_id, name);

-- ユーザーの部署所属（多対多: 複数部署に所属可能）
create table public.department_members (
  id              bigserial primary key,
  department_id   bigint not null references public.departments(id) on delete cascade,
  user_id         uuid not null references public.users(id) on delete cascade,
  role            text not null default 'member' check (role in ('master', 'member')),
  unique(department_id, user_id)
);
```

#### D-2: 日次タスクマスタ

```sql
create table public.daily_task_masters (
  id              bigserial primary key,
  department_id   bigint not null references public.departments(id) on delete cascade,
  name            text not null,
  sort_order      int not null default 0,
  dow_pattern     smallint[] not null default '{0,1,2,3,4,5,6}',
  -- 0=日, 1=月, ..., 6=土（JS Date.getDay() と一致）
  -- 例: 平日のみ = '{1,2,3,4,5}'
  memo            text not null default '',
  is_active       boolean not null default true,
  created_at      timestamptz default now()
  -- Phase C: + tenant_id bigint FK→tenants
);
```

#### D-3: 完了トラッキング（完了ログ方式）

```sql
create table public.daily_completions (
  id              bigserial primary key,
  master_id       bigint not null references public.daily_task_masters(id) on delete cascade,
  completion_date date not null,
  completed_by    uuid not null references public.users(id),
  completed_at    timestamptz not null default now(),
  memo            text,
  unique(master_id, completion_date)  -- 1タスク1日1回のみ
);
```

| 状態 | DBの状態 |
|------|---------|
| 未完了 | `daily_completions` に行がない |
| 完了 | 行あり（誰が・いつ完了したか記録） |
| 取消 | 行を DELETE |

#### D-4: 既存定型タスクとの比較

| | 定型タスク（既存） | 日次タスク（新規） |
|---|---|---|
| 所有 | ユーザー（`user_id`） | 部署（`department_id`） |
| 頻度 | 月次 / 年次 | 毎日（曜日パターン対応） |
| インスタンス | 事前生成（`recurring_instances`） | 完了ログのみ（`daily_completions`） |
| 共有 | `recurring_task_shares` で個別共有 | 部署メンバー全員が自動的に閲覧 |

既存テーブルへの変更なし（`department_members` は新規テーブル）。

#### D-5: UI 概要

**メイン画面 — 日次タスクセクション**
```
📋 日次タスク     フロント (3/5完了)
2026-03-29 (土)              [◀ ▶]
─────────────────────────────
[x] 予約確認          田中 09:15
[x] チェックイン準備    鈴木 10:30
[ ] 売上日報
[x] 客室アサイン        田中 08:45
[ ] 夕食準備確認
```

**設定画面 — 部門管理（組織マスター以上）**
- 部門の追加・編集・削除
- 部門ごとの日次タスクマスタ管理（名前・曜日パターン・並び順）
- メンバーの部門割り当て（複数部署への所属可）
- 部署マスターの任命

**レポート — 日次タスクタブ**
- 全部門の当日完了状況を一覧（組織マスター以上向け）

#### D-6: 実装ステップ

1. DB: `departments` + `department_members` テーブル作成
2. 設定UI: 部門管理（CRUD + メンバー割当 + 部署マスター任命）
3. DB: `daily_task_masters` + `daily_completions` 作成
4. 設定UI: 日次タスクマスタ管理（部門ごと）
5. メイン画面: 日次タスクセクション + フッターナビ追加
6. レポート: 日次タスクタブ追加

Phase C（テナント管理）とは独立して実装可能。Phase C が先に入った場合は `tenant_id` を同時に追加。

#### D-7: 領域タグ × 部署連携（2026-03-29 追加メモ）

- 領域タグ（`areas` テーブル）は、将来 Phase D の「部署」機能と掛け合わせる
- **部署ごとに使用可能な領域タグを部署管理者が設定できる**仕組みにする
  - 例: フロント部署のメンバーは「フロント」「客室」タグのみ使用可、厨房部署は「厨房」「仕入」タグのみ、など
- **領域タグの設定（追加・編集・削除・部署への割り当て）は部署管理者以上の権限に限定**する
  - `department_members.role = 'master'` または組織オーナーのみ編集可能
- DB設計案: `department_areas` 中間テーブル（`department_id` × `area_id`）で多対多管理
- UIへの影響: タスク詳細シートの領域タグ選択肢を、ログインユーザーが所属する部署の許可タグに絞り込む

#### D-8: 部署共有ビュー — 日次タスクのサイネージ表示（2026-03-29 追加メモ）

- **ユースケース**: 部署のメンバー全員で1台のiPad・PCに画面を映し、日次タスクの進捗を共有しながら業務を進めたい
- **実現方式案**: 部署ごとに「閲覧専用の共有アカウント」的な仕組みを用意する
  - **採用: 案A — 共有トークンURL** — 部署マスターが設定画面から「共有リンクを発行」すると、ログイン不要で日次タスク画面だけ閲覧できるURLが生成される（`/shared/{token}` 形式）。完了チェックも可能にするかは要検討
- **表示内容**: 日次タスク一覧（D-5のUI）を全画面表示。完了状況はリアルタイム反映（Supabase Realtime）
- **セキュリティ考慮**: 共有画面には個人タスク（q1-q4）は表示しない。日次タスクのみに限定

---

## 改善リスト（積み残し）

### バグ・不具合
（現時点で把握している未解決バグなし）

### UX改善
- [x] スマホ版ヘッダー左上の空白にロゴを表示（`logo_250.png`・2026-03-29 完了）

### 新機能
- [ ] **AI日次コメント**（2026-03-29 追加メモ）
  - 1日の最初のログイン時、ユーザーのタスク処理状況（件数・象限バランス・期限超過など）を Claude API に渡し、気の利いたひとことコメントを生成して表示する
  - 表示場所: スマホ版の広告枠エリア（現在は空欄のバナー領域）
  - 「今日の最初のログイン」判定: `localStorage` に最終表示日を保存し、日付が変わっていたら呼び出す
  - 生成は非同期・軽量に（タスク一覧の読み込みをブロックしない）
  - コメント例: 「Q1が3件たまっています。まず一番重要なものから片付けましょう」「昨日より件数が減りましたね、いいペースです」など
  - APIキーの扱い: Supabase Edge Function 経由で Claude API を呼ぶ（フロントにキーを露出しない）
- [ ] レポート: 人ごとにタスク分布を2次元グラフで可視化
- [ ] タスクのエクスポート（設定画面から）
- [ ] **印刷機能**（2026-03-29 追加メモ）
  - 対象ページ: ユーザー別タスク一覧 / 月次タスク / 日次タスク / レポート
  - `@media print` CSS で印刷用レイアウトを定義（ヘッダー・フッターナビ・操作ボタン非表示）
  - 各ページに印刷ボタン（🖨）を配置 → `window.print()` 呼び出し
- [ ] 多言語対応
- [x] **製品版公開前: リポジトリ・フォルダ名を TASKUL に統一（2026-03-29 完了）**
  - `96_Claude/task/` → `96_Claude/taskul/`、GitHub repo `task-matrix` → `taskul`
  - GitHub Pages URL: `https://yamode.github.io/taskul/`（変更済み）
  - WOFF Endpoint URL 更新が必要（LINE WORKS Developer Console で手動設定）

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
- ~~LW同期ボタンの動作確認（PUT更新・フォールバック）~~　→ 完了（2026-03-29）

---

### 2026-03-29（夕方セッション）

**LWカレンダー同期バグ修正:**
- `syncTaskToLwCalendar` で未定義関数 `getOrCreateTaskCalendarId` を呼んでいた → `ensureCalendarId(lw_user_id, id)` に修正（これが「同期中...」で固まる根本原因）
- try-catch追加・`calPost`にレスポンスデバッグログ追加（CORSエラーを可視化）
- 期限変更時に旧LWイベントを `calDelete` していたが、設計意図通り `calUpdate` で日付更新する方式に修正（削除→再作成ではない）
- `saveDeadlineOnly` のカレンダー処理を修正: 既存イベントがあれば `calUpdate`、期限クリア時のみ `calDelete`

**バージョン:** `v2026-03-29-20`

**コミット:**
- `5edb19e` fix: メンバー編集でemailが空の場合にnullを送るよう修正
- `67f8777` feat: 月次タスク メンバー別ビューにスティッキージャンプタグ追加
- `afbda35` fix: LW同期のgetOrCreateTaskCalendarId未定義エラーを修正＋デバッグログ追加
- `af64e98` fix: 期限変更時にLWカレンダーの旧イベントを削除してから再同期（後に方針変更）
- `4f4c374` fix: 期限変更時はLWイベントをcalUpdateで日付更新（削除→再作成をやめる）

**残作業:**
- デバッグログの削除（リリース前に `DEBUG=false` + calPostのdbg行を整理）
- 月次タスクレポートの実機テスト

---

### 2026-03-29（夜セッション）

**レポートUI改善（スマホ対応）:**
- 「閉じる」ボタンがタブ折り返しで下に落ちていた問題を修正
- 1回目の修正: タブ＋閉じるを同行flexに（`flex-shrink:0`）→ ボタンテキストを「レポートを閉じる」に変更
- 2回目の修正: タブをフッターナビゲーションバーに移動（iOSアプリ風）
  - ヘッダー: タイトル（タブ切替でリアルタイム更新）＋「レポートを閉じる」
  - コンテンツ: `flex:1; overflow-y:auto` でスクロール
  - フッター: アイコン＋ラベルのタブバー（iPhone home bar 対応）

**バージョン:** `v2026-03-29-22`

**コミット:**
- `63fcfa5` fix: レポートヘッダーの「閉じる」ボタンを常に右端に固定・テキスト変更
- `b47c146` feat: レポートタブをフッターナビゲーションバーに変更

**残作業:**
- レポートフッタータブの実機確認（iPhone）
- デバッグログの削除（リリース前に `DEBUG=false` + calPostのdbg行を整理）

---

### 2026-03-29（深夜セッション: v1社内配布版リリース）

**v1社内配布版リリース準備 & 本番公開前作業:**

- `const DEBUG = false` に変更（リリース版）
- デバッグパネルに `display:none` 追加（設定からのON/OFFは引き続き動作）
- スマホ版ヘッダー左上にロゴ表示（`logo_250.png`・PC版は非表示のまま）
- `dev/index.html` と `index.html` を同期（製品版URL反映）

**リポジトリ・フォルダ名 TASKUL 統一:**
- GitHub repo: `yamode/task-matrix` → `yamode/taskul`（GitHub API で変更）
- 新 GitHub Pages URL: `https://yamode.github.io/taskul/`
- ローカルフォルダ: `96_Claude/task/` → `96_Claude/taskul/`
- `.claude/CLAUDE.md`・`HANDOFF.md` 内の全 URL・パス参照を更新

**Autumn Bot 固定メニューに TASKUL 追加:**
- `setup_persist_menu.php` に「✅ タスク管理（TASKUL）」ボタンを追加
- Xserver にアップロード → 実行（HTTP 201 成功）→ 削除
- `woff-approval/CLAUDE.md` の固定メニュー一覧を更新

**バージョン:** `v2026-03-29-23`

**コミット:**
- `1ff3bde` release: v1社内配布版リリース（DEBUG無効化・モバイルロゴ・製品版反映）
- `ced1175` chore: リポジトリ・フォルダ名を TASKUL に統一

**残作業:**
- ~~LINE WORKS Developer Console で WOFF Endpoint URL を手動変更~~ → 完了（2026-03-29）
- 社内45名への展開・周知
- 次フェーズ: Phase 4（音声入力）または Phase D（日次タスク）

---

### 2026-03-29（ロードマップ追記セッション）

**実施内容:**
- HANDOFF.md に新機能メモを4件追記（コード変更なし）
  - D-7: 領域タグ × 部署連携（部署管理者がタグの使用範囲を設定）
  - D-8: 部署共有ビュー（共有トークンURL方式を採用）
  - AI日次コメント（初回ログイン時に Claude API で状況コメント生成）
  - 印刷機能（ユーザー別・月次・日次・レポートの各ページ対応）

**コミット:** なし（HANDOFF.md の更新のみ、下記で一括コミット）

**残作業:**
- 次フェーズ: Phase 4（音声入力）または Phase D（日次タスク・部署管理）
- ~~LINE WORKS Developer Console で WOFF Endpoint URL を手動変更~~ → 完了（2026-03-29）

---

### 2026-03-29（TEST BOT dev WOFF 設定）

**実施内容:**
- LINE WORKS Developer Console で WOFF アプリ「TASKUL_DEV」を新規登録（ID: `bthr5fNolL7gx96noEJbbQ`、Endpoint URL: `https://yamode.github.io/taskul/dev/`）
- TEST BOT（Bot ID: `6811651`）の固定メニュー「✅ タスク」を dev WOFF URL に変更（`setup_persist_menu.php` 更新 → Xserver アップロード → 実行 → 削除）
- `dev/index.html` と `task-matrix-v2.html` で WOFF ID を URL パスで自動切り替えするよう修正
  - `/taskul/dev/` → `bthr5fNolL7gx96noEJbbQ`（TASKUL_DEV）
  - それ以外 → `2sGuLQU8T2BvJXN88QeCIg`（TASKUL 本番）

**コミット:**
- `757ac6f` fix: dev環境でdev WOFFのIDを使うよう自動切り替え

**残作業:**
- 次フェーズ: Phase 4（音声入力）または Phase D（日次タスク・部署管理）

---

## 引き継ぎ時のClaudeへの指示（コピペ用）

```
このフォルダの HANDOFF.md を読んで、タスク管理アプリ（TASKUL）の開発を引き継いでください。
作業開始前に必ず git pull を実行してから task-matrix-v2.html を読むこと。
```
