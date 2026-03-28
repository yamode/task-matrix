-- ============================================================
-- タスク管理マトリクス — Supabase スキーマ
-- Supabase SQL Editor に貼り付けて実行してください
-- ============================================================

-- ── ユーザー ──────────────────────────────────────────────
-- Phase 1: Supabase Auth 連携（email + auth_id 追加）
-- auth_id = auth.users.id（Supabase Auth の UUID）
create table public.users (
  id             uuid primary key default gen_random_uuid(),
  name           text not null unique,
  display_name   text,                    -- LWニックネーム or 任意の表示名（WOFFログイン時に自動更新）
  email          text unique,             -- Supabase Authログイン用メールアドレス
  auth_id        uuid unique,
  lw_user_id     text unique,             -- LINE WORKS内部UUID（Bot API用）
  lw_account_id  text unique,             -- LINE WORKSアカウントID（App Link emailList用）例: xxx@yamado
  created_at     timestamptz default now()
);

-- 既存テーブルにカラムを追加する場合（初回セットアップ済みの環境用）
-- alter table public.users add column if not exists display_name text;
-- alter table public.users add column if not exists lw_user_id text unique;
-- alter table public.users add column if not exists lw_account_id text unique;
-- ↓ yamado環境向け既存ユーザーへのデータ移行（email から .co.jp を除去）
-- update public.users set lw_account_id = replace(email, '.co.jp', '') where email like '%@yamado.co.jp' and lw_account_id is null;

-- ── 1-shot タスク ──────────────────────────────────────────
create table public.tasks (
  id                bigserial primary key,
  user_id           uuid not null references public.users(id) on delete cascade,
  quadrant          text not null check (quadrant in ('tray','q1','q2','q3','q4')),
  text              text not null,
  done              boolean not null default false,
  assigner_id       uuid references public.users(id),
  deadline          date,
  approval_status   text check (approval_status in ('pending','accepted','disputed','done_pending','confirmed')),
  dispute_reason    text,
  subtasks          jsonb not null default '[]',
  memo              text not null default '',
  url               text not null default '',
  created_at        timestamptz default now(),
  updated_at        timestamptz default now()
);
create index tasks_user_idx     on public.tasks(user_id);
create index tasks_assigner_idx on public.tasks(assigner_id);

-- ── 1-shot タスクへのファイル添付 ─────────────────────────
create table public.task_files (
  id          bigserial primary key,
  task_id     bigint not null references public.tasks(id) on delete cascade,
  user_id     uuid   not null references public.users(id) on delete cascade,
  file_path   text not null,
  file_name   text not null,
  file_type   text,
  file_size   bigint,
  created_at  timestamptz default now()
);

-- ── 定型タスクマスタ ────────────────────────────────────────
create table public.recurring_tasks (
  id         bigserial primary key,
  user_id    uuid not null references public.users(id) on delete cascade,
  type       text not null check (type in ('monthly','annual')),
  name       text not null,
  day        int  not null check (day between 1 and 31),
  month      int           check (month between 1 and 12),
  memo       text not null default '',
  url        text not null default '',
  subtasks   jsonb not null default '[]',
  created_at timestamptz default now()
);
create index recurring_tasks_user_idx on public.recurring_tasks(user_id);

-- ── 定型タスクインスタンス ─────────────────────────────────
create table public.recurring_instances (
  id                    bigserial primary key,
  master_id             bigint not null references public.recurring_tasks(id) on delete cascade,
  year                  int not null,
  month                 int not null check (month between 1 and 12),
  done                  boolean not null default false,
  subtask_done          jsonb not null default '{}',
  instance_memo         text,
  lw_calendar_event_id  text,
  created_at            timestamptz default now(),
  unique(master_id, year, month)
);

-- ── 定型タスク共有 ─────────────────────────────────────────
create table public.recurring_task_shares (
  id                  bigserial primary key,
  master_id           bigint not null references public.recurring_tasks(id) on delete cascade,
  shared_with_user_id uuid   not null references public.users(id) on delete cascade,
  unique(master_id, shared_with_user_id)
);

-- ── 定型タスクインスタンスへのファイル添付 ────────────────
create table public.recurring_instance_files (
  id          bigserial primary key,
  instance_id bigint not null references public.recurring_instances(id) on delete cascade,
  user_id     uuid   not null references public.users(id) on delete cascade,
  file_path   text not null,
  file_name   text not null,
  file_type   text,
  file_size   bigint,
  created_at  timestamptz default now()
);

-- ============================================================
-- RLS・権限設定（Phase 1）
-- ============================================================

-- 全テーブルのRLSを有効化
alter table public.users                   enable row level security;
alter table public.tasks                   enable row level security;
alter table public.task_files              enable row level security;
alter table public.recurring_tasks         enable row level security;
alter table public.recurring_instances     enable row level security;
alter table public.recurring_task_shares   enable row level security;
alter table public.recurring_instance_files enable row level security;

-- GRANT（anon・authenticated ロールに全テーブルのアクセス権を付与）
grant usage on schema public to anon, authenticated;
grant all on all tables    in schema public to anon, authenticated;
grant all on all sequences in schema public to anon, authenticated;

-- RLSポリシー（全テーブル・全ロールに allow_all）
-- Phase 2 で auth.uid() ベースのポリシーに強化予定
do $$
declare t text;
begin
  for t in select tablename from pg_tables where schemaname = 'public'
  loop
    execute format('drop policy if exists "allow_all" on public.%I', t);
    execute format('
      create policy "allow_all" on public.%I
        for all to anon, authenticated
        using (true) with check (true)', t);
  end loop;
end $$;

-- RLSヘルパー関数（auth.uid() → public.users.id を返す）
create or replace function get_my_user_id()
returns uuid language sql security definer stable as $$
  select id from public.users where auth_id = auth.uid()
$$;
