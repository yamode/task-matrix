<?php
// ============================================================
// task-cal/api.php  — LINE WORKS カレンダー同期プロキシ
// デプロイ先: /home/yamado/yamado.co.jp/task-cal/api.php
//   → https://yamado.co.jp/task-cal/api.php
// ============================================================

// CORS（GitHub Pages からのリクエストを許可）
header('Access-Control-Allow-Origin: https://yamode.github.io');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── 認証情報 ──────────────────────────────────────────────────
$client_id        = 'Q4cFtfNZkbVhJ6xXbk8N';
$client_secret    = 'U8kSBJpEi1';
$service_account  = 'iw1bi.serviceaccount@yamado';
$private_key_path = '/home/yamado/yamado.co.jp/private_20260320211118.key';

// イベントを登録する専用カレンダー名
define('TASK_CAL_NAME',    'タスクカレンダー');
define('MONTHLY_CAL_NAME', '月次タスク');

// ── JWT 生成 ──────────────────────────────────────────────────
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$now     = time();
$header  = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
$payload = base64url_encode(json_encode([
    'iss' => $client_id,
    'sub' => $service_account,
    'iat' => $now,
    'exp' => $now + 3600,
]));
$signing_input = $header . '.' . $payload;
$private_key   = file_get_contents($private_key_path);

if (!$private_key) {
    echo json_encode(['ok' => false, 'error' => 'private_key_not_found']);
    exit;
}

openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
$jwt = $signing_input . '.' . base64url_encode($signature);

// ── アクセストークン取得（calendar スコープ）────────────────
$ch = curl_init('https://auth.worksmobile.com/oauth2/v2.0/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'     => $jwt,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'scope'         => 'calendar',
    ]),
]);
$token_res  = json_decode(curl_exec($ch), true);
$token_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (empty($token_res['access_token'])) {
    echo json_encode(['ok' => false, 'error' => 'token_failed', 'http' => $token_code, 'detail' => $token_res]);
    exit;
}
$access_token = $token_res['access_token'];

// ── タスクカレンダーID取得（なければ作成）────────────────────
// 同一リクエスト内でユーザーごとにキャッシュ
$cal_id_cache = [];

// カレンダー名をキャッシュキーに含める
function getOrCreateCalendar(string $cal_name, string $lw_user_id, string $access_token): ?string {
    global $cal_id_cache;
    $cache_key = $cal_name . ':' . $lw_user_id;
    if (isset($cal_id_cache[$cache_key])) return $cal_id_cache[$cache_key];

    // カレンダー一覧を取得
    $ch = curl_init("https://www.worksapis.com/v1.0/users/{$lw_user_id}/calendar-personals");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$access_token}"]]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    foreach (($res['calendarPersonals'] ?? []) as $cal) {
        if (($cal['calendarName'] ?? '') === $cal_name) {
            $cal_id_cache[$cache_key] = $cal['calendarId'];
            return $cal['calendarId'];
        }
    }

    // なければ作成
    $ch = curl_init("https://www.worksapis.com/v1.0/calendars");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$access_token}", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'calendarName' => $cal_name,
            'members'      => [['id' => $lw_user_id, 'type' => 'USER', 'role' => 'CALENDAR_EVENT_READ_WRITE']],
            'isPublic'     => false,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $res2 = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $cal_id = $res2['calendarId'] ?? null;
    if ($cal_id) $cal_id_cache[$cache_key] = $cal_id;
    return $cal_id;
}

function getTaskCalendarId(string $lw_user_id, string $access_token): ?string {
    return getOrCreateCalendar(TASK_CAL_NAME, $lw_user_id, $access_token);
}


// ── リクエスト解析 ────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$events = $body['events'] ?? [];

$results = [];

// ── デバッグ：カレンダー一覧・タスクカレンダー取得/作成テスト ─
if ($action === 'debug_calendar') {
    $lw_user_id = $body['lw_user_id'] ?? '';
    if (!$lw_user_id) { echo json_encode(['ok' => false, 'error' => 'lw_user_id required']); exit; }

    // 1. カレンダー一覧取得
    $ch = curl_init("https://www.worksapis.com/v1.0/users/{$lw_user_id}/calendar-personals");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$access_token}"]]);
    $list_raw  = curl_exec($ch);
    $list_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $list_json = json_decode($list_raw, true);

    // 2. タスクカレンダーID取得（なければ作成）
    $cal_id = getTaskCalendarId($lw_user_id, $access_token);

    echo json_encode([
        'ok'          => true,
        'list_code'   => $list_code,
        'calendars'   => $list_json['calendarPersonals'] ?? [],
        'task_cal_id' => $cal_id,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── 基本カレンダーのイベントリスト取得（summary一致を削除） ──
if ($action === 'find_and_delete_by_summary') {
    $lw_user_id = $body['lw_user_id']  ?? '';
    $summary    = $body['summary']     ?? '';
    $from       = $body['from']        ?? '2026-01-01';
    $until      = $body['until']       ?? '2027-12-31';
    if (!$lw_user_id || !$summary) { echo json_encode(['ok' => false, 'error' => 'lw_user_id/summary required']); exit; }

    // + だけ %2B に変換（コロン等はそのまま）
    $from_enc  = str_replace('+', '%2B', $from  . 'T00:00:00+09:00');
    $until_enc = str_replace('+', '%2B', $until . 'T23:59:59+09:00');
    $url = "https://www.worksapis.com/v1.0/users/{$lw_user_id}/calendar/events?fromDateTime={$from_enc}&untilDateTime={$until_enc}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$access_token}"]]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data   = json_decode($raw, true);
    $found  = [];
    $deleted = [];
    foreach (($data['events'] ?? []) as $ev_wrap) {
        $ev_id  = $ev_wrap['eventComponents'][0]['eventId'] ?? null;
        $ev_sum = $ev_wrap['eventComponents'][0]['summary'] ?? '';
        if ($ev_id && $ev_sum === $summary) {
            $found[] = $ev_id;
            $del_ch = curl_init("https://www.worksapis.com/v1.0/users/{$lw_user_id}/calendar/events/{$ev_id}");
            curl_setopt_array($del_ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => ["Authorization: Bearer {$access_token}"]]);
            curl_exec($del_ch); $del_code = curl_getinfo($del_ch, CURLINFO_HTTP_CODE); curl_close($del_ch);
            $deleted[] = ['event_id' => $ev_id, 'status' => $del_code];
        }
    }
    echo json_encode(['ok' => true, 'list_code' => $code, 'found' => count($found), 'deleted' => $deleted], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── タスクカレンダーID取得（なければ作成）────────────────────
if ($action === 'ensure_calendar') {
    $lw_user_id = $body['lw_user_id'] ?? '';
    if (!$lw_user_id) { echo json_encode(['ok' => false, 'error' => 'lw_user_id required']); exit; }
    $cal_id = getTaskCalendarId($lw_user_id, $access_token);
    echo json_encode(['ok' => true, 'results' => [['cal_id' => $cal_id]]]);
    exit;
}

// ── イベント作成 ──────────────────────────────────────────────
if ($action === 'create') {
    foreach ($events as $ev) {
        $lw_user_id = $ev['lw_user_id'] ?? '';
        $summary    = $ev['summary']    ?? 'タスク';
        $date       = $ev['date']       ?? '';   // YYYY-MM-DD

        if (!$lw_user_id || !$date) {
            $results[] = array_merge($ev, ['event_id' => null, 'status' => 400]);
            continue;
        }

        // end.date は終了日の翌日（iCalendar 仕様: 終日イベントの end は排他的）
        $end_date = date('Y-m-d', strtotime($date . ' +1 day'));

        $event_body = json_encode([
            'eventComponents' => [[
                'summary'      => $summary,
                'start'        => ['date' => $date],
                'end'          => ['date' => $end_date],
                'transparency' => 'OPAQUE',
                'visibility'   => 'PUBLIC',
            ]],
            'sendNotification' => false,
        ], JSON_UNESCAPED_UNICODE);

        // カレンダーに登録（cal_idが渡されていればそれを使用、calendar_nameがあればその名前で取得、なければタスクカレンダー）
        $cal_id      = $ev['cal_id']       ?? null;
        $cal_name_ev = $ev['calendar_name'] ?? TASK_CAL_NAME;
        if (!$cal_id) $cal_id = getOrCreateCalendar($cal_name_ev, $lw_user_id, $access_token);
        $url = $cal_id
            ? "https://www.worksapis.com/v1.0/users/{$lw_user_id}/calendars/{$cal_id}/events"
            : "https://www.worksapis.com/v1.0/users/{$lw_user_id}/calendar/events"; // フォールバック

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$access_token}",
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $event_body,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res_json = json_decode($res, true);
        $event_id = $res_json['eventComponents'][0]['eventId']
                 ?? $res_json['eventId']
                 ?? null;

        // _ で始まるフィールド（メタデータ）をそのまま返す
        $meta = [];
        foreach ($ev as $k => $v) {
            if (isset($k[0]) && $k[0] === '_') $meta[$k] = $v;
        }

        $results[] = array_merge($meta, [
            'lw_user_id' => $lw_user_id,
            'event_id'   => $event_id,
            'cal_id'     => $cal_id,
            'status'     => $code,
        ]);
    }

// ── イベント削除 ──────────────────────────────────────────────
} elseif ($action === 'delete') {
    foreach ($events as $ev) {
        $lw_user_id = $ev['lw_user_id'] ?? '';
        $event_id   = $ev['event_id']   ?? '';

        if (!$lw_user_id || !$event_id) {
            $results[] = ['lw_user_id' => $lw_user_id, 'event_id' => $event_id, 'status' => 400];
            continue;
        }

        // カレンダーIDが渡された場合はそのカレンダー、なければ汎用エンドポイント
        $cal_id = $ev['cal_id'] ?? null;
        $url = $cal_id
            ? "https://www.worksapis.com/v1.0/users/{$lw_user_id}/calendars/{$cal_id}/events/{$event_id}"
            : "https://www.worksapis.com/v1.0/users/{$lw_user_id}/calendar/events/{$event_id}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$access_token}",
            ],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $results[] = ['lw_user_id' => $lw_user_id, 'event_id' => $event_id, 'status' => $code];
    }

// ── Bot メッセージ送信 ─────────────────────────────────────────
} elseif ($action === 'send_message') {
    $bot_id     = $body['bot_id']     ?? '6811651';
    $lw_user_id = $body['lw_user_id'] ?? '';
    $content    = $body['content']    ?? '';

    if (!$lw_user_id || !$content) {
        echo json_encode(['ok' => false, 'error' => 'missing params']); exit;
    }

    // Bot API 用に bot スコープでトークンを取得
    $ch_bot = curl_init('https://auth.worksmobile.com/oauth2/v2.0/token');
    curl_setopt_array($ch_bot, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'     => $jwt,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'scope'         => 'bot',
        ]),
    ]);
    $bot_token_res = json_decode(curl_exec($ch_bot), true);
    curl_close($ch_bot);

    if (empty($bot_token_res['access_token'])) {
        echo json_encode(['ok' => false, 'error' => 'bot_token_failed', 'detail' => $bot_token_res]); exit;
    }
    $bot_token = $bot_token_res['access_token'];

    // 1:1 メッセージ送信
    $msg_url = "https://www.worksapis.com/v1.0/bots/{$bot_id}/users/{$lw_user_id}/messages";
    $ch_msg = curl_init($msg_url);
    curl_setopt_array($ch_msg, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$bot_token}",
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'content' => ['type' => 'text', 'text' => $content],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $msg_res  = curl_exec($ch_msg);
    $msg_code = curl_getinfo($ch_msg, CURLINFO_HTTP_CODE);
    curl_close($ch_msg);

    echo json_encode(['ok' => $msg_code < 300, 'status' => $msg_code, 'result' => json_decode($msg_res, true)]);
    exit;

} else {
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
    exit;
}

echo json_encode(['ok' => true, 'results' => $results]);
