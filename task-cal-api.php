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
define('TASK_CAL_NAME', 'タスクカレンダー');

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

function getTaskCalendarId(string $lw_user_id, string $access_token): ?string {
    global $cal_id_cache;
    if (isset($cal_id_cache[$lw_user_id])) return $cal_id_cache[$lw_user_id];

    // カレンダー一覧を取得（正しいエンドポイント: /calendar-personals）
    $ch = curl_init("https://www.worksapis.com/v1.0/users/{$lw_user_id}/calendar-personals");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$access_token}"],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    // 既存の「タスクカレンダー」を検索（フィールドは calendarName）
    foreach (($res['calendarPersonals'] ?? []) as $cal) {
        if (($cal['calendarName'] ?? '') === TASK_CAL_NAME) {
            $cal_id_cache[$lw_user_id] = $cal['calendarId'];
            return $cal['calendarId'];
        }
    }

    // なければ作成（正しいエンドポイント: POST /calendars）
    $ch = curl_init("https://www.worksapis.com/v1.0/calendars");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$access_token}",
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'calendarName' => TASK_CAL_NAME,
            'members'      => [['id' => $lw_user_id, 'type' => 'USER', 'role' => 'CALENDAR_EVENT_READ_WRITE']],
            'isPublic'     => false,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $res2 = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $cal_id = $res2['calendarId'] ?? null;
    if ($cal_id) $cal_id_cache[$lw_user_id] = $cal_id;
    return $cal_id;
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

        // 「タスクカレンダー」に登録（cal_idが渡されていればそれを使用、なければ自動取得）
        $cal_id = $ev['cal_id'] ?? null;
        if (!$cal_id) $cal_id = getTaskCalendarId($lw_user_id, $access_token);
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

} else {
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
    exit;
}

echo json_encode(['ok' => true, 'results' => $results]);
