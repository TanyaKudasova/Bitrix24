<?php
declare(strict_types=1);

/**
 * Дашборд воронки продаж CRM — обработчик приложения Битрикс24.
 *
 * Данные читаются REST API Битрикс24 напрямую, без платформы Вайбкод.
 * Классическое локальное приложение со своим обработчиком: этот файл —
 * и страница дашборда, и обработчик левого меню. Внутри портала CRM
 * читается токеном авторизованного пользователя. Показываются только
 * сделки, где этот пользователь ответственный.
 *
 * Файл: dash-001.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // в iframe портала ошибки не показываем, пишем в лог

/* =========================================================================
 * НАСТРОЙКИ
 * ========================================================================= */

// Точный адрес этого обработчика. Должен совпадать с адресом, указанным
// в настройках локального приложения и в привязке места LEFT_MENU.
const APP_URL = 'https://www.maxreform.ru/files/app/tk/dash-001.php';

// Воронка: 0 — основная. Идентификаторы стадий зависят от категории.
const CATEGORY_ID = 0;

// Сколько последних сделок показывать (в ТЗ 10–20).
const RECENT_LIMIT = 15;

// Потолок выборки для агрегата по стадиям.
const DEAL_CAP = 5000;

// Заголовок пункта в левом меню портала.
const LEFT_MENU_TITLE = 'Воронка продаж';

/* =========================================================================
 * ОШИБКИ И ВСПОМОГАТЕЛЬНОЕ
 * ========================================================================= */

final class AppError extends RuntimeException {}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($v): string
{
    return number_format((float)$v, 0, '.', ' ');
}

function pct($v): string
{
    return number_format((float)$v, 1, '.', ' ') . ' %';
}

function user_full_name(array $u): string
{
    $parts = array_filter([
        $u['LAST_NAME'] ?? $u['lastName'] ?? null,
        $u['NAME'] ?? $u['name'] ?? null,
        $u['SECOND_NAME'] ?? $u['secondName'] ?? null,
    ], static fn($p) => is_string($p) && trim($p) !== '');
    $name = trim(implode(' ', $parts));
    $id = $u['ID'] ?? $u['id'] ?? '?';
    return $name !== '' ? $name : ('#' . $id);
}

/* =========================================================================
 * REST БИТРИКС24
 * ========================================================================= */

/**
 * Токен приложения, если обработчик открыт из портала.
 * Других источников авторизации нет. Все запросы идут от лица сотрудника.
 */
function b24_app_token(): ?array
{
    if (!isset($GLOBALS['__b24'])) {
        return null;
    }
    $t = $GLOBALS['__b24'];
    return is_array($t) && !empty($t['domain']) && !empty($t['token']) ? $t : null;
}

/** HTTP-вызов метода REST Битрикс24. */
function b24_http(string $url, array $params): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new AppError('Сеть Битрикс24 недоступна: ' . $err);
    }
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        throw new AppError('Битрикс24 вернул некорректный ответ (HTTP ' . $status . ')');
    }
    return $j;
}

/**
 * Запрос к REST Битрикс24. Возвращает полный разобранный конверт.
 * Работает только токеном портала, других источников авторизации нет.
 */
function b24_call_raw(string $method, array $params = []): array
{
    $app = b24_app_token();
    if (!$app) {
        throw new AppError('Нет токена портала. Откройте приложение из Битрикс24.');
    }
    return b24_http('https://' . $app['domain'] . '/rest/' . $method . '.json', $params + ['auth' => $app['token']]);
}

/** Запрос к REST Битрикс24. Возвращает поле result, ошибка — исключение. */
function b24_call(string $method, array $params = []): array
{
    $j = b24_call_raw($method, $params);
    if (!empty($j['error'])) {
        $desc = isset($j['error_description']) ? (string)$j['error_description'] : '';
        throw new AppError('Битрикс24 ' . $j['error'] . ($desc !== '' ? ': ' . $desc : ''));
    }
    $r = $j['result'] ?? [];
    return is_array($r) ? $r : ['value' => $r];
}

/** Постраничный обход crm.deal.list. */
function b24_deals(array $params, int $cap): array
{
    $out = [];
    $start = 0;
    do {
        $p = $params;
        $p['start'] = $start;
        $env = b24_call_raw('crm.deal.list', $p);
        if (!empty($env['error'])) {
            $desc = isset($env['error_description']) ? (string)$env['error_description'] : '';
            throw new AppError('Битрикс24 ' . $env['error'] . ($desc !== '' ? ': ' . $desc : ''));
        }
        foreach ((array)($env['result'] ?? []) as $row) {
            $out[] = $row;
            if (count($out) >= $cap) {
                return $out;
            }
        }
        $next = $env['next'] ?? null;
        $start = ($next === null) ? null : (int)$next;
    } while ($start !== null && $start > 0);

    return $out;
}

/** Домен портала для ссылок на карточки. */
function b24_portal(): ?string
{
    $app = b24_app_token();
    return $app ? $app['domain'] : null;
}

/**
 * Параметры авторизации из запроса портала. Портал кладёт DOMAIN в query
 * string, а AUTH_ID в тело POST. Читаем оба места явно, плюс вложенный
 * auth и JSON, чтобы не зависеть от настройки request_order.
 */
function request_auth(): array
{
    $json = [];
    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $d = json_decode((string)$raw, true);
        if (is_array($d)) {
            $json = $d;
        }
    }

    $pick = static function (string $name) use ($json): ?string {
        foreach ([
            $_POST[$name] ?? null,
            $_GET[$name] ?? null,
            $_POST['auth'][$name] ?? null,
            $_GET['auth'][$name] ?? null,
            $json[$name] ?? null,
            $json['auth'][$name] ?? null,
        ] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return null;
    };

    $token = $pick('AUTH_ID') ?? $pick('access_token');
    $domain = $pick('DOMAIN') ?? $pick('domain');
    return [$token, $domain];
}

/**
 * Адрес обработчика с сохранением служебных параметров портала.
 * APP_SID нужен BX24.js, чтобы библиотека инициализировалась в iframe.
 * Токены и одноразовые коды в адрес не переносим.
 */
function app_self_url(): string
{
    $deny = ['AUTH_ID', 'auth', 'REFRESH_ID', 'code', 'state', 'tk'];
    $q = [];
    foreach ($_GET as $k => $v) {
        if (in_array((string)$k, $deny, true) || !is_scalar($v)) {
            continue;
        }
        $q[(string)$k] = (string)$v;
    }
    $qs = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    return APP_URL . ($qs !== '' ? '?' . $qs : '');
}


/* =========================================================================
 * ДАННЫЕ ДАШБОРДА
 * ========================================================================= */

/** Стадии воронки, по порядку отображения. */
function b24_statuses(): array
{
    $entity = CATEGORY_ID === 0 ? 'DEAL_STAGE' : 'DEAL_STAGE_' . CATEGORY_ID;
    $rows = b24_call('crm.status.list', [
        'filter' => ['ENTITY_ID' => $entity],
        'order' => ['SORT' => 'ASC'],
    ]);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'statusId' => (string)($r['STATUS_ID'] ?? ''),
            'name' => (string)($r['NAME'] ?? ''),
            'sort' => (int)($r['SORT'] ?? 0),
            'semantics' => $r['SEMANTICS'] ?? null,
        ];
    }
    return $out;
}

/**
 * Фильтр сделок Битрикс24: воронка, период и ответственный.
 * Ответственный — авторизованный пользователь, открывший дашборд.
 * Чужие сделки не запрашиваются вовсе, а не отфильтровываются на клиенте.
 */
function b24_deal_filter(array $period): array
{
    $userId = b24_current_user_id();
    if ($userId === null) {
        throw new AppError('Не удалось определить авторизованного пользователя. Откройте приложение из портала Битрикс24.');
    }
    $filter = [
        'CATEGORY_ID' => CATEGORY_ID,
        'ASSIGNED_BY_ID' => $userId,
    ];
    [$start, $end] = period_bounds($period);
    if ($start !== null) {
        $filter['>=DATE_CREATE'] = date('Y-m-d\TH:i:s', $start);
    }
    if ($end !== null) {
        $filter['<=DATE_CREATE'] = date('Y-m-d\TH:i:s', $end);
    }
    return $filter;
}

/** Сводка по стадиям: количество и сумма по каждой stageId. */
function b24_funnel(array $period): array
{
    $deals = b24_deals([
        'filter' => b24_deal_filter($period),
        'select' => ['ID', 'OPPORTUNITY', 'STAGE_ID', 'CURRENCY_ID'],
    ], DEAL_CAP);

    $acc = [];
    $total = 0;
    foreach ($deals as $d) {
        $sid = (string)($d['STAGE_ID'] ?? '');
        if (!isset($acc[$sid])) {
            $acc[$sid] = ['count' => 0, 'sum' => 0.0];
        }
        $acc[$sid]['count']++;
        $acc[$sid]['sum'] += (float)($d['OPPORTUNITY'] ?? 0);
        $total++;
    }

    $groups = [];
    foreach ($acc as $sid => $v) {
        $groups[] = [
            'stageId' => $sid,
            'count' => $v['count'],
            'aggregates' => ['amount' => ['sum' => $v['sum']]],
        ];
    }

    return [
        'count' => $total,
        'groups' => $groups,
        'meta' => ['truncated' => count($deals) >= DEAL_CAP],
    ];
}

/** Последние сделки за период. */
function b24_recent(array $period): array
{
    $rows = b24_deals([
        'filter' => b24_deal_filter($period),
        'select' => ['ID', 'TITLE', 'OPPORTUNITY', 'CURRENCY_ID', 'STAGE_ID', 'ASSIGNED_BY_ID', 'DATE_CREATE'],
        'order' => ['DATE_CREATE' => 'DESC'],
    ], RECENT_LIMIT);

    $out = [];
    foreach (array_slice($rows, 0, RECENT_LIMIT) as $d) {
        $out[] = [
            'id' => (int)($d['ID'] ?? 0),
            'title' => (string)($d['TITLE'] ?? ''),
            'amount' => (float)($d['OPPORTUNITY'] ?? 0),
            'currency' => (string)($d['CURRENCY_ID'] ?? ''),
            'stageId' => (string)($d['STAGE_ID'] ?? ''),
            'assignedById' => (int)($d['ASSIGNED_BY_ID'] ?? 0),
            'createdAt' => (string)($d['DATE_CREATE'] ?? ''),
        ];
    }
    return $out;
}

/** ФИО сотрудников по идентификаторам. */
function b24_users(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
    if (!$ids) {
        return [];
    }
    $rows = b24_call('user.get', ['filter' => ['ID' => $ids]]);
    $map = [];
    foreach ($rows as $u) {
        $map[(int)($u['ID'] ?? 0)] = user_full_name($u);
    }
    return $map;
}

/**
 * Профиль сотрудника, от чьего лица открыт дашборд.
 * Читается только токеном портала. Вебхук здесь не годится: user.current
 * по нему возвращает владельца вебхука, а не посетителя дашборда.
 */
function b24_current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $app = b24_app_token();
    if (!$app) {
        $user = null;
        return $user;
    }
    $j = b24_http('https://' . $app['domain'] . '/rest/user.current.json', ['auth' => $app['token']]);
    $u = $j['result'] ?? null;
    $user = (empty($j['error']) && is_array($u)) ? $u : null;
    return $user;
}

/** ID авторизованного пользователя. По нему ограничивается выборка сделок. */
function b24_current_user_id(): ?int
{
    $u = b24_current_user();
    $id = (int)($u['ID'] ?? 0);
    return $id > 0 ? $id : null;
}

/** Профиль сотрудника, от чьего лица читаются данные (для часового пояса). */
function b24_me(): ?array
{
    return b24_current_user();
}

/* =========================================================================
 * ФИЛЬТР ПЕРИОДА
 * ========================================================================= */

function read_period(): array
{
    $src = $_POST + $_GET;
    $preset = (string)($src['p'] ?? 'month');
    $from = (string)($src['from'] ?? '');
    $to = (string)($src['to'] ?? '');
    $allowed = ['today', 'week', 'month', 'quarter', 'year', 'all', 'custom'];
    if (!in_array($preset, $allowed, true)) {
        $preset = 'month';
    }
    return ['preset' => $preset, 'from' => $from, 'to' => $to];
}

function period_bounds(array $period): array
{
    $now = time();
    switch ($period['preset']) {
        case 'today':
            return [strtotime('today'), strtotime('tomorrow') - 1];
        case 'week':
            return [strtotime('monday this week 00:00'), $now];
        case 'month':
            return [strtotime('first day of this month 00:00'), $now];
        case 'quarter':
            return [strtotime('first day of ' . quarter_start_month() . ' 00:00'), $now];
        case 'year':
            return [strtotime('first day of january 00:00'), $now];
        case 'custom':
            $start = valid_date($period['from']) ? strtotime($period['from'] . ' 00:00:00') : null;
            $end = valid_date($period['to']) ? strtotime($period['to'] . ' 23:59:59') : null;
            if ($start !== null && $end !== null && $start > $end) {
                [$start, $end] = [$end, $start];
            }
            return [$start, $end];
        case 'all':
        default:
            return [null, null];
    }
}

function quarter_start_month(): string
{
    $m = (int)date('n');
    return [1 => 'january', 4 => 'april', 7 => 'july', 10 => 'october'][intdiv($m - 1, 3) * 3 + 1];
}

function valid_date(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

function period_label(array $period): string
{
    [$start, $end] = period_bounds($period);
    $names = [
        'today' => 'Сегодня', 'week' => 'Эта неделя', 'month' => 'Этот месяц',
        'quarter' => 'Этот квартал', 'year' => 'Этот год', 'all' => 'Всё время',
    ];
    $base = $names[$period['preset']] ?? 'Период';
    if ($start === null && $end === null) {
        return $base;
    }
    $fmt = 'd.m.Y';
    $a = $start !== null ? date($fmt, $start) : '…';
    $b = $end !== null ? date($fmt, $end) : '…';
    return $base . ' · ' . $a . ' — ' . $b;
}

/* =========================================================================
 * ПРИВЯЗКА ПУНКТА ЛЕВОГО МЕНЮ (REST Битрикс24, контекст приложения)
 * =========================================================================
 *
 * Классическое локальное приложение попадает в левое меню либо настройкой
 * в карточке приложения, либо методом placement.bind. Вебхук этот метод
 * вызвать не может (нужен контекст приложения, скоуп placement и права
 * администратора). Поэтому привязка выполняется только когда обработчик
 * открыт из портала и платформа передала токен приложения.
 */

/** Пункт LEFT_MENU уже привязан? */
function b24_has_left_menu(): bool
{
    if (!b24_app_token()) {
        return false;
    }
    try {
        foreach (b24_call('placement.get') as $b) {
            if (is_array($b) && ($b['placement'] ?? '') === 'LEFT_MENU') {
                return true;
            }
        }
    } catch (AppError $e) {
        error_log('[dash-001] placement.get: ' . $e->getMessage());
    }
    return false;
}

/** Привязать пункт LEFT_MENU к текущему обработчику. */
function b24_bind_left_menu(): array
{
    if (!b24_app_token()) {
        return ['ok' => false, 'error' => 'Нет токена приложения. Откройте обработчик из портала.'];
    }
    try {
        b24_call('placement.bind', [
            'PLACEMENT' => 'LEFT_MENU',
            'HANDLER' => APP_URL,
            'TITLE' => LEFT_MENU_TITLE,
        ]);
        return ['ok' => true];
    } catch (AppError $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* =========================================================================
 * СБОРКА ДАННЫХ
 * ========================================================================= */

function build_dashboard(array $period): array
{
    $stages = b24_statuses();
    $funnel = b24_funnel($period);
    $recent = b24_recent($period);

    $wonStageId = null;
    $openStages = [];
    foreach ($stages as $s) {
        $sem = strtoupper((string)($s['semantics'] ?? ''));
        if ($sem === 'S') {
            $wonStageId = (string)$s['statusId'];
        } elseif ($sem !== 'F') {
            $openStages[(string)$s['statusId']] = true;
        }
    }

    $groups = [];
    foreach ($funnel['groups'] as $g) {
        $groups[(string)$g['stageId']] = $g;
    }

    $totalCount = (int)$funnel['count'];
    $openSum = 0.0;
    $openCount = 0;
    foreach ($openStages as $sid => $_) {
        $openSum += (float)($groups[$sid]['aggregates']['amount']['sum'] ?? 0);
        $openCount += (int)($groups[$sid]['count'] ?? 0);
    }

    $wonCount = (int)($groups[$wonStageId]['count'] ?? 0);
    $wonSum = (float)($groups[$wonStageId]['aggregates']['amount']['sum'] ?? 0);
    $avgCheck = $wonCount > 0 ? $wonSum / $wonCount : 0.0;

    $stageRows = [];
    foreach ($stages as $s) {
        $sid = (string)$s['statusId'];
        $g = $groups[$sid] ?? null;
        $count = (int)($g['count'] ?? 0);
        $sum = (float)($g['aggregates']['amount']['sum'] ?? 0);
        $stageRows[] = [
            'id' => $sid,
            'name' => (string)($s['name'] ?? $sid),
            'semantics' => strtoupper((string)($s['semantics'] ?? '')),
            'count' => $count,
            'sum' => $sum,
            'share' => $totalCount > 0 ? $count / $totalCount * 100 : 0.0,
        ];
    }

    $users = b24_users(array_map(static fn($d) => (int)$d['assignedById'], $recent));

    $stageNames = [];
    foreach ($stageRows as $r) {
        $stageNames[$r['id']] = $r['name'];
    }

    $deals = [];
    foreach ($recent as $d) {
        $deals[] = [
            'id' => $d['id'],
            'title' => $d['title'],
            'amount' => $d['amount'],
            'currency' => $d['currency'],
            'stage' => $stageNames[$d['stageId']] ?? ($d['stageId'] !== '' ? $d['stageId'] : '—'),
            'assigned' => $users[$d['assignedById']] ?? '—',
            'createdAt' => $d['createdAt'],
        ];
    }

    return [
        'kpi' => [
            'open_sum' => $openSum,
            'open_count' => $openCount,
            'won_count' => $wonCount,
            'won_sum' => $wonSum,
            'avg_check' => $avgCheck,
            'total_count' => $totalCount,
        ],
        'stages' => $stageRows,
        'deals' => $deals,
        'truncated' => (bool)$funnel['meta']['truncated'],
    ];
}

/* =========================================================================
 * ТОЧКА ВХОДА
 * ========================================================================= */

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => $isHttps ? 'None' : 'Lax',
]);
session_start();

// Данные пользователя, если обработчик открыт из портала (классический placement).
// Токен не кэшируем в сессии, чтобы после смены пользователя не осталось чужого.
[$b24Token, $b24Domain] = request_auth();
if ($b24Token !== null && $b24Domain !== null) {
    $GLOBALS['__b24'] = ['domain' => $b24Domain, 'token' => $b24Token];
}

$period = read_period();

// Разовая привязка пункта левого меню. Выполняется только при открытии
// обработчика из портала. Явно — с ?tk=bind.
if (($_GET['tk'] ?? '') === 'bind') {
    $r = b24_bind_left_menu();
    render_message(
        $r['ok'] ? 'Пункт меню привязан' : 'Не удалось привязать пункт меню',
        $r['ok']
            ? ('Пункт «' . LEFT_MENU_TITLE . '» добавлен в левое меню портала. Обновите страницу портала.')
            : ($r['error'] . ' Проверьте скоуп placement у приложения и права администратора. Либо задайте обработчик левого меню в карточке приложения.')
    );
    exit;
}
if (b24_app_token() && empty($_SESSION['tk_bind_done']) && !b24_has_left_menu()) {
    $r = b24_bind_left_menu();
    $_SESSION['tk_bind_done'] = 1;
    $_SESSION['tk_bind_msg'] = $r['ok'] ? '' : ('Пункт левого меню не привязался автоматически. ' . $r['error']);
}

// Токена нет. Забираем его в браузере через BX24.getAuth и возвращаем POST-ом.
// Так приложение работает и без cookie сессии в стороннем iframe.
if (!b24_app_token()) {
    render_auth_bridge($period);
    exit;
}

try {
    $me = b24_me();
    if ($me && !empty($me['TIME_ZONE']) && is_string($me['TIME_ZONE'])) {
        @date_default_timezone_set($me['TIME_ZONE']);
    }
    $data = build_dashboard($period);
    render_page($data, $me, $period, b24_portal());
} catch (AppError $e) {
    http_response_code(502);
    render_message('Не удалось получить данные CRM', $e->getMessage());
}

/* =========================================================================
 * Отрисовка
 * ========================================================================= */

/**
 * Страница-мост. Сервер не получил токен портала, поэтому берём его
 * в браузере через BX24.getAuth и отправляем обратно POST-ом.
 */
function render_auth_bridge(array $period): void
{
    $action = json_encode(app_self_url(), JSON_UNESCAPED_SLASHES);
    $preset = json_encode($period['preset'], JSON_UNESCAPED_UNICODE);
    $from = json_encode($period['from'], JSON_UNESCAPED_UNICODE);
    $to = json_encode($period['to'], JSON_UNESCAPED_UNICODE);
    ?><!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Воронка продаж CRM</title>
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
body{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#f5f7fa;color:#1f2937}
.box{max-width:620px;padding:32px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{font-size:18px;margin:0 0 12px}
p{margin:0;color:#4b5563}
</style></head>
<body><div class="box"><h1>Воронка продаж CRM</h1><p id="msg">Получаем авторизацию портала…</p></div>
<script>
(function () {
  var finished = false;
  function fail() {
    if (finished) { return; }
    finished = true;
    document.getElementById('msg').textContent =
      'Не удалось получить авторизацию. Откройте приложение из левого меню Битрикс24.';
  }
  var timer = setTimeout(fail, 8000);
  if (!window.BX24 || typeof BX24.init !== 'function') { fail(); return; }
  BX24.init(function () {
    if (finished) { return; }
    var auth = (typeof BX24.getAuth === 'function') ? BX24.getAuth() : false;
    if (!auth || !auth.access_token) { fail(); return; }
    finished = true;
    clearTimeout(timer);
    var form = document.createElement('form');
    form.method = 'post';
    form.action = <?= $action ?>;
    function add(name, value) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }
    add('AUTH_ID', auth.access_token);
    add('DOMAIN', auth.domain || '');
    add('p', <?= $preset ?>);
    add('from', <?= $from ?>);
    add('to', <?= $to ?>);
    document.body.appendChild(form);
    form.submit();
  });
})();
</script></body></html><?php
}

function render_message(string $title, string $text): void
{
    ?><!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<style>
body{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#f5f7fa;color:#1f2937}
.box{max-width:620px;padding:32px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{font-size:18px;margin:0 0 12px}
p{margin:0;color:#4b5563}
</style></head>
<body><div class="box"><h1><?= e($title) ?></h1><p><?= e($text) ?></p></div></body></html><?php
}

function render_page(array $data, ?array $me, array $period, ?string $portal): void
{
    $kpi = $data['kpi'];
    $stages = $data['stages'];
    $deals = $data['deals'];
    $userName = $me ? user_full_name($me) : '';
    $notice = (string)($_SESSION['tk_bind_msg'] ?? '');
    unset($_SESSION['tk_bind_msg']);
    ?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Воронка продаж CRM</title>
<script src="//api.bitrix24.com/api/v1/"></script>
<style>
:root{--bg:#f4f6f9;--card:#fff;--line:#e5e9f0;--ink:#1f2937;--muted:#6b7280;--accent:#2f6fed;
--won:#1f9d55;--lose:#d64545;--open:#2f6fed}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
.wrap{padding:16px 18px 28px;max-width:1180px;margin:0 auto}
.head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}
.head h1{font-size:19px;margin:0}
.head .who{color:var(--muted);font-size:13px}
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:var(--card);
border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-bottom:16px}
.filters select,.filters input[type=date]{font:inherit;padding:6px 8px;border:1px solid var(--line);
border-radius:8px;background:#fff;color:var(--ink)}
.filters button{font:inherit;padding:6px 14px;border:0;border-radius:8px;background:var(--accent);
color:#fff;cursor:pointer}
.filters .label{color:var(--muted);font-size:13px}
.custom{display:none;gap:6px;align-items:center}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px}
.card .t{color:var(--muted);font-size:12.5px;text-transform:uppercase;letter-spacing:.03em}
.card .v{font-size:26px;font-weight:600;margin-top:6px}
.card .s{color:var(--muted);font-size:12.5px;margin-top:2px}
.grid{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
.panel{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px}
.panel h2{font-size:15px;margin:0 0 12px}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:7px 6px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;font-weight:600}
td.num,th.num{text-align:right;white-space:nowrap}
.bar{height:6px;border-radius:3px;background:#eef2f8;margin-top:5px;overflow:hidden}
.bar i{display:block;height:100%;background:var(--accent)}
.sem-S .bar i{background:var(--won)} .sem-F .bar i{background:var(--lose)}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background:var(--open)}
.sem-S .dot{background:var(--won)} .sem-F .dot{background:var(--lose)}
a.deal{color:var(--accent);text-decoration:none}
.empty{color:var(--muted);padding:14px 0}
.note{margin-top:14px;color:var(--muted);font-size:12.5px}
</style>
</head>
<body>
<div class="wrap">
  <?php if ($notice !== ''): ?>
  <div class="panel" style="border-color:#f0c36d;background:#fff8e6;margin-bottom:16px"><?= e($notice) ?></div>
  <?php endif; ?>
  <div class="head">
    <div>
      <h1>Воронка продаж CRM</h1>
      <div class="who"><?= $userName !== '' ? 'Мои сделки: ' . e($userName) : 'Аналитика по сделкам портала' ?></div>
    </div>
    <div class="who"><?= e(period_label($period)) ?></div>
  </div>

  <form class="filters" method="post" action="<?= e(app_self_url()) ?>">
    <span class="label">Период:</span>
    <select name="p" onchange="window.__tkToggle(this)">
      <?php
      $presets = [
          'today' => 'Сегодня', 'week' => 'Эта неделя', 'month' => 'Этот месяц',
          'quarter' => 'Этот квартал', 'year' => 'Этот год', 'all' => 'Всё время', 'custom' => 'Произвольный',
      ];
      foreach ($presets as $k => $v) {
          printf('<option value="%s"%s>%s</option>', e($k), $period['preset'] === $k ? ' selected' : '', e($v));
      }
      ?>
    </select>
    <span class="custom" id="custom-range" style="<?= $period['preset'] === 'custom' ? 'display:flex' : '' ?>">
      <input type="date" name="from" value="<?= e($period['from']) ?>">
      <span class="label">—</span>
      <input type="date" name="to" value="<?= e($period['to']) ?>">
    </span>
    <button type="submit">Показать</button>
  </form>

  <div class="cards">
    <div class="card">
      <div class="t">Сумма открытых сделок</div>
      <div class="v"><?= e(money($kpi['open_sum'])) ?> ₽</div>
      <div class="s"><?= (int)$kpi['open_count'] ?> сделок в работе</div>
    </div>
    <div class="card">
      <div class="t">Выигранных за период</div>
      <div class="v"><?= (int)$kpi['won_count'] ?></div>
      <div class="s">на <?= e(money($kpi['won_sum'])) ?> ₽</div>
    </div>
    <div class="card">
      <div class="t">Средний чек</div>
      <div class="v"><?= e(money($kpi['avg_check'])) ?> ₽</div>
      <div class="s">по выигранным сделкам</div>
    </div>
  </div>

  <div class="grid">
    <div class="panel">
      <h2>Сводка по стадиям воронки</h2>
      <?php if (!$stages): ?>
        <div class="empty">Стадии воронки не найдены.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Стадия</th><th class="num">Сделок</th><th class="num">Сумма, ₽</th><th class="num">Доля</th></tr></thead>
        <tbody>
        <?php foreach ($stages as $s): ?>
          <tr class="sem-<?= e($s['semantics'] !== '' ? $s['semantics'] : 'P') ?>">
            <td>
              <span class="dot"></span><?= e($s['name']) ?>
              <div class="bar"><i style="width:<?= e(number_format((float)$s['share'], 2, '.', '')) ?>%"></i></div>
            </td>
            <td class="num"><?= (int)$s['count'] ?></td>
            <td class="num"><?= e(money($s['sum'])) ?></td>
            <td class="num"><?= e(pct($s['share'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Последние сделки</h2>
      <?php if (!$deals): ?>
        <div class="empty">За выбранный период сделок нет.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Название</th><th class="num">Сумма</th><th>Стадия</th><th>Ответственный</th></tr></thead>
        <tbody>
        <?php foreach ($deals as $d): ?>
          <tr>
            <td>
              <a class="deal" href="#" data-deal="<?= (int)$d['id'] ?>"><?= e($d['title'] !== '' ? $d['title'] : ('Сделка #' . $d['id'])) ?></a>
              <div class="note" style="margin:2px 0 0"><?= e(substr((string)$d['createdAt'], 0, 10)) ?></div>
            </td>
            <td class="num"><?= e(money($d['amount'])) ?> <?= e($d['currency']) ?></td>
            <td><?= e($d['stage']) ?></td>
            <td><?= e($d['assigned']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="note">
    Показаны только сделки, где ответственный — текущий пользователь. Данные за выбранный период.
    <?= (int)$kpi['total_count'] ?> сделок под фильтр.
    <?php if ($data['truncated']): ?>
      <br>Выборка обрезана лимитом <?= (int)DEAL_CAP ?> сделок. Сузьте период.
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  // Домен портала для ссылок на карточки.
  var PORTAL = <?= json_encode($portal) ?>;

  var form = document.querySelector('form.filters');
  var sel = form.querySelector('select[name=p]');
  var custom = document.getElementById('custom-range');

  function addHidden(name, value) {
    var old = form.querySelector('input[type=hidden][name="' + name + '"]');
    if (old) { old.value = value; return; }
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }

  // Форма уходит POST-ом. Если cookie сессии в стороннем iframe недоступны,
  // токен возьмётся из SDK и придёт в теле запроса.
  form.addEventListener('submit', function () {
    if (!window.BX24 || typeof BX24.getAuth !== 'function') { return; }
    var auth = BX24.getAuth();
    if (!auth || !auth.access_token) { return; }
    addHidden('AUTH_ID', auth.access_token);
    addHidden('DOMAIN', auth.domain || PORTAL || '');
  });

  window.__tkToggle = function (s) {
    custom.style.display = s.value === 'custom' ? 'flex' : 'none';
  };
  if (sel.value === 'custom') { window.__tkToggle(sel); }

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('a.deal');
    if (!a) { return; }
    ev.preventDefault();
    var id = a.getAttribute('data-deal');
    if (PORTAL && id) {
      window.top.location.href = 'https://' + PORTAL + '/crm/deal/details/' + id + '/';
    }
  });

  function resize() {
    try {
      window.parent.postMessage({ type: 'vibe:resize', height: document.documentElement.scrollHeight }, '*');
    } catch (e) {}
  }
  window.addEventListener('load', resize);
  window.addEventListener('resize', resize);
  resize();

  // Внутри портала подстраиваем высоту через SDK Битрикс24.
  if (window.BX24 && typeof BX24.init === 'function') {
    BX24.init(function () {
      try {
        if (typeof BX24.fitWindow === 'function') { BX24.fitWindow(); }
      } catch (e) {}
    });
  }
})();
</script>
</body>
</html>
<?php
}
