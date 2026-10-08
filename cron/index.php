<?php

require_once __DIR__ . '/security.php';
cronSessionStart();

if (!function_exists('e')) {

    function e($v)

    {

        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    }

}

function base32Encode($d)

{

    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    $v = '';

    $b = 0;

    $n = 0;

    for ($i = 0, $l = strlen($d); $i < $l; $i++) {

        $b = ($b << 8) | ord($d[$i]);

        $n += 8;

        while ($n >= 5) {

            $v .= $a[($b >> ($n - 5)) & 31];

            $n -= 5;

        }

    }

    if ($n > 0) $v .= $a[($b << (5 - $n)) & 31];

    return $v;

}

function base32Decode($s)

{

    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    $s = preg_replace('/[^A-Z2-7]/', '', strtoupper((string)$s));

    $b = 0;

    $n = 0;

    $out = '';

    for ($i = 0, $l = strlen($s); $i < $l; $i++) {

        $p = strpos($a, $s[$i]);

        if ($p === false) continue;

        $b = ($b << 5) | $p;

        $n += 5;

        if ($n >= 8) {

            $out .= chr(($b >> ($n - 8)) & 255);

            $n -= 8;

        }

    }

    return $out;

}

function totpSecret()

{

    $s = random_bytes(20);

    return base32Encode($s);

}

function totpCode($s, $time = null)

{

    $key = base32Decode($s);

    if ($key === '') return '';

    $counter = intdiv((int)($time ?? time()), 30);

    $bin = pack('N*', 0) . pack('N*', $counter);

    $hash = hash_hmac('sha1', $bin, $key, true);

    $offset = ord(substr($hash, -1)) & 0xf;

    $part = substr($hash, $offset, 4);

    $value = unpack('N', $part)[1] & 0x7fffffff;

    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);

}

function totpNow($s)

{

    return totpCode($s, time());

}

function totpOffset($s, $off)

{

    return totpCode($s, time() + ($off * 30));

}

function totpVerify($s, $c)

{

    $code = preg_replace('/\D+/', '', trim((string)$c));

    if (strlen($code) !== 6) return false;

    for ($i = -1; $i <= 1; $i++) {

        if (hash_equals(totpOffset($s, $i), $code)) return true;

    }

    return false;

}

function cronFetchTasks(PDO $db, int $uid): array
{
    $st = $db->prepare(
        "SELECT t.*,l.status AS last_status,l.started_at AS last_started_at,l.finished_at AS last_finished_at
         FROM cron_tasks t
         LEFT JOIN (
           SELECT cl.*
           FROM cron_logs cl
           INNER JOIN (SELECT task_id, MAX(id) AS last_id FROM cron_logs GROUP BY task_id) latest
             ON latest.task_id=cl.task_id AND latest.last_id=cl.id
         ) l ON l.task_id=t.id
         WHERE t.user_id=?
         ORDER BY t.id DESC"
    );
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cronTaskTiming(array $task, ?int $now = null): array
{
    $now = $now ?? time();
    if (empty($task['is_active'])) {
        return ['next_seconds' => null, 'label' => 'Tạm dừng', 'running' => false];
    }

    $interval = cronNormalizeInterval((int)($task['interval_seconds'] ?? 60));
    $lastStarted = strtotime((string)($task['last_started_at'] ?? '')) ?: 0;
    if ($lastStarted <= 0) {
        return ['next_seconds' => 0, 'label' => 'Sắp chạy...', 'running' => false];
    }

    $running = ($task['last_status'] ?? '') === 'running'
        && empty($task['last_finished_at'])
        && $lastStarted + 30 > $now;
    if ($running) {
        return ['next_seconds' => 0, 'label' => 'Đang chạy...', 'running' => true];
    }

    $remaining = max(0, $lastStarted + $interval - $now);
    return [
        'next_seconds' => $remaining,
        'label' => $remaining > 0 ? ($remaining . 's') : 'Đang chờ...',
        'running' => false,
    ];
}

function cronFetchLogStats(PDO $db, int $uid): array
{
    $st = $db->prepare(
        "SELECT COUNT(l.id) AS total,
                COALESCE(SUM(CASE WHEN l.status='success' THEN 1 ELSE 0 END),0) AS success
         FROM cron_tasks t
         LEFT JOIN cron_logs l ON l.task_id=t.id
         WHERE t.user_id=?"
    );
    $st->execute([$uid]);
    $stats = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['total' => (int)($stats['total'] ?? 0), 'success' => (int)($stats['success'] ?? 0)];
}

try {

    $db = cronDatabase(true);

 } catch (Exception $ex) {
    die('DB Error');
}

cronMigrateTwofaSecrets($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_username') {
    cronRequireCsrf();

    $username = trim((string)($_POST['username'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
        $_SESSION['cron_register_error'] = 'Username phải dài 3-32 ký tự, chỉ gồm chữ cái, số, dấu chấm, gạch ngang hoặc gạch dưới.';
        header('Location: ?');
        exit;
    }

    try {
        $hash = password_hash($username, PASSWORD_DEFAULT);
        $st = $db->prepare('INSERT INTO cron_users (code, code_hash, name, twofa_enabled, created_at) VALUES (?, ?, ?, 0, ?)');
        $st->execute([$username, $hash, $username, date('Y-m-d H:i:s')]);
        $uid = (int)$db->lastInsertId();

        session_regenerate_id(true);
        $_SESSION['cron_uid'] = $uid;
        $_SESSION['cron_uname'] = $username;
        header('Location: ?');
        exit;
    } catch (PDOException $e) {
        $_SESSION['cron_register_error'] = 'Username đã tồn tại hoặc không thể tạo tài khoản.';
        header('Location: ?');
        exit;
    }
}

if (isset($_POST['login_code'])) {
    cronRequireCsrf();
    if (cronLoginBlocked()) {
        $_SESSION['cron_login_error'] = 'Đăng nhập bị tạm khóa. Vui lòng thử lại sau 15 phút.';
        header('Location: ?');
        exit;
    }

    $code = trim((string)$_POST['login_code']);
    $u = null;
    if ($code !== '' && strlen($code) <= 128) {
        $users = $db->query("SELECT id,code,code_hash,name,twofa_secret,twofa_enabled FROM cron_users")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $candidate) {
            $hash = (string)($candidate['code_hash'] ?? '');
            // Production: only hashed login codes are accepted.
            if ($hash !== '' && password_verify($code, $hash)) {
                $u = $candidate;
                break;
            }
        }
    }

    if (!$u) {
        cronRecordLoginFailure();
        $_SESSION['cron_login_error'] = 'Mã đăng nhập không hợp lệ.';
        header('Location: ?');
        exit;
    }

    cronClearLoginFailures();
    session_regenerate_id(true);
    $_SESSION['cron_login_id'] = (int)$u['id'];
    $_SESSION['cron_login_name'] = (string)$u['name'];
    if (!empty($u['twofa_enabled'])) {
        $_SESSION['cron_2fa_pending'] = true;
        unset($_SESSION['cron_uid'], $_SESSION['cron_uname']);
        header('Location: ?');
        exit;
    }

    $_SESSION['cron_uid'] = $_SESSION['cron_login_id'];
    $_SESSION['cron_uname'] = $_SESSION['cron_login_name'];
    header('Location: ?');
    exit;
}
if (isset($_POST['twofa_code']) && isset($_SESSION['cron_2fa_pending'])) {
    cronRequireCsrf();

    $uid = (int)($_SESSION['cron_login_id'] ?? 0);
    if ($uid <= 0 || cronTwofaBlocked($uid)) {
        $twofaError = '2FA bị tạm khóa. Vui lòng thử lại sau 15 phút.';
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => $twofaError]);
            exit;
        }
    } else {

    $st = $db->prepare("SELECT*FROM cron_users WHERE id=?");

    $st->execute([$uid]);

    $u = $st->fetch(PDO::FETCH_ASSOC);

    $code = preg_replace('/\D+/', '', trim((string)($_POST['twofa_code'] ?? '')));

    $ok = ($u && $u['twofa_enabled'] && totpVerify(cronDecryptSecret((string)$u['twofa_secret']), $code));

    if ($ok) {

        unset($_SESSION['cron_2fa_pending']);
        cronClearTwofaFailures($uid);

        $_SESSION['cron_uid'] = $uid;

        $_SESSION['cron_uname'] = $u['name'];

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

            header('Content-Type: application/json');

            echo json_encode(['ok' => true, 'message' => 'Xác thực 2FA thành công!']);

            exit;

        }

        header('Location: ?');

        exit;

    }

    cronRecordTwofaFailure($uid);
    $twofaError = 'Mã 2FA không đúng!';

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

        header('Content-Type: application/json');

        echo json_encode(['ok' => false, 'message' => $twofaError]);

        exit;

    }

    }

}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    cronRequireCsrf();
    session_destroy();
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Đã đăng xuất.']);
    exit;
}

$uid = (int)($_SESSION['cron_uid'] ?? 0);

$uname = $_SESSION['cron_uname'] ?? '';

$layoutCanonical = (isset($isLocal) && $isLocal) ? "http://localhost/tools.dvpro.vn/cron/" : "https://tools.dvpro.vn/cron/";

if (!$uid) {

    if (isset($_SESSION['cron_2fa_pending'])) {

        $title = "Cron 2FA";
$canonical = $layoutCanonical;
ob_start(); ?>
<div class="page-wrap" style="max-width:30rem">
  <section class="tool-panel" style="padding:1.45rem">
    <div class="tool-hero" style="margin-bottom:1.1rem">
      <div class="tool-hero-main">
        <div class="tool-icon cyan"><i class="fas fa-shield-halved"></i></div>
        <div>
          <h1>Xác thực 2FA</h1>
          <p>Nhập mã 6 số từ Google Authenticator để tiếp tục.</p>
        </div>
      </div>
    </div>

    <?php if (!empty($twofaError)): ?>
      <div class="alert alert-error mb-4"><?= e($twofaError) ?></div>
    <?php endif; ?>
    <div id="twofaPendingMsg" class="hidden mb-3"></div>

    <form id="twofaPendingForm" method="POST" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
      <div class="field">
        <label>Mã 2FA</label>
        <input type="text" name="twofa_code" required inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" class="input" style="text-align:center;letter-spacing:.4em;font-weight:800;font-size:1.15rem" autocomplete="one-time-code">
      </div>
      <button id="twofaPendingSubmit" type="submit" class="btn btn-primary btn-block">
        <i class="fas fa-shield-halved"></i> Xác thực
      </button>
    </form>

    <button id="twofaPendingBack" type="button" class="btn btn-ghost btn-block mt-3">
      Đăng nhập bằng mã khác
    </button>
  </section>
</div>
<?php
$content = ob_get_clean();
include dirname(__DIR__) . '/layout.php';
exit;
        exit;
    }

    $title = "Cron Login";
$canonical = $layoutCanonical;
ob_start(); ?>
<div class="page-wrap" style="max-width:32rem">
  <section class="tool-panel" style="padding:1.5rem">
    <div class="tool-hero" style="margin-bottom:1.2rem">
      <div class="tool-hero-main">
        <div class="tool-icon amber"><i class="fas fa-clock"></i></div>
        <div>
          <h1>Quản lý Cron</h1>
          <p>Đăng nhập bằng mã truy cập để quản lý lịch gọi URL tự động.</p>
        </div>
      </div>
      <div class="tool-hero-actions"><span class="pill pill-green">Free</span></div>
    </div>

    <?php if (!empty($_SESSION['cron_login_error'])): ?>
      <div class="alert alert-error mb-4"><?= e($_SESSION['cron_login_error']) ?></div>
      <?php unset($_SESSION['cron_login_error']); ?>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
      <div class="field">
        <label>Username</label>
        <input type="text" name="login_code" required placeholder="Nhập username..." class="input" autocomplete="username">
      </div>
      <button type="submit" class="btn btn-primary btn-block">
        <i class="fas fa-right-to-bracket"></i> Đăng nhập
      </button>
    </form>

    <?php if (!empty($_SESSION['cron_register_error'])): ?>
      <div class="alert alert-error mb-4"><?= e($_SESSION['cron_register_error']) ?></div>
      <?php unset($_SESSION['cron_register_error']); ?>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
      <input type="hidden" name="action" value="register_username">
      <div class="field">
        <label>Tạo tài khoản bằng username</label>
        <input type="text" name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_.-]{3,32}" placeholder="Ví dụ: user123" class="input" autocomplete="username">
      </div>
      <button type="submit" class="btn btn-secondary btn-block">
        <i class="fas fa-user-plus"></i> Tạo tài khoản
      </button>
    </form>

    <div class="card-soft p-4 mt-5">
      <div class="flex items-start gap-3">
        <div class="tool-icon blue" style="width:2.5rem;height:2.5rem;font-size:1rem"><i class="fas fa-circle-info"></i></div>
        <div>
          <p class="text-sm font-semibold text-white m-0">Đăng ký hoặc đăng nhập bằng username</p>
          <p class="text-xs text-text-muted mt-1 mb-0">Tài khoản mới chỉ cần username, không cần mật khẩu.</p>
        </div>
      </div>
      <div class="toolbar mt-4">
        <a href="https://zalo.me/0971810376" target="_blank" rel="noopener" class="btn btn-green btn-sm" style="flex:1"><i class="fas fa-comment-dots"></i> Zalo</a>
        <a href="https://t.me/ntt3132004" target="_blank" rel="noopener" class="btn btn-primary btn-sm" style="flex:1"><i class="fab fa-telegram-plane"></i> Telegram</a>
      </div>
    </div>
  </section>
</div>
<?php
$content = ob_get_clean();
include dirname(__DIR__) . '/layout.php';
exit;
    exit;
}

        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        $mutatingActions = ['add', 'delete', 'toggle', 'update', 'discard_2fa', 'setup_2fa', 'confirm_2fa', 'disable_2fa'];
        if (in_array($action, $mutatingActions, true)) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                exit('Method not allowed');
            }
            cronRequireCsrf();
        }

        if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {

            $n = cronSafeTaskName((string)($_POST['name'] ?? ''));

            $u = trim((string)($_POST['url'] ?? ''));

            $int = cronNormalizeInterval((int)($_POST['interval'] ?? 60));

            if ($n !== '' && $u !== '' && cronValidateUrl($u) !== false) {

                $db->prepare("INSERT INTO cron_tasks(user_id,name,url_encrypted,interval_seconds,created_at)VALUES(?,?,?,?,?)")->execute([$uid, $n, cronEncrypt($u), $int, date('Y-m-d H:i:s')]);

                cronLaunchTaskProcess((int)$db->lastInsertId(), true);

            } else {
                $_SESSION['cron_flash_error'] = 'URL không hợp lệ hoặc không được phép (chỉ http/https public).';
            }

            header('Location: ?');

            exit;

        }

        if ($action === 'delete' && isset($_POST['id'])) {

            $id = (int)$_POST['id'];

            $db->prepare("DELETE FROM cron_logs WHERE task_id IN(SELECT id FROM cron_tasks WHERE id=? AND user_id=?)")->execute([$id, $uid]);

            $db->prepare("DELETE FROM cron_tasks WHERE id=? AND user_id=?")->execute([$id, $uid]);

            header('Location: ?');

            exit;

        }

        if ($action === 'toggle' && isset($_POST['id'])) {

            $id = (int)$_POST['id'];
            $db->prepare("UPDATE cron_tasks SET is_active=CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=? AND user_id=?")->execute([$id, $uid]);

            $st = $db->prepare("SELECT is_active FROM cron_tasks WHERE id=? AND user_id=?");
            $st->execute([$id, $uid]);
            if ((int)$st->fetchColumn() === 1) {
                cronLaunchTaskProcess($id, true);
            }

            header('Location: ?');

            exit;

        }

        if ($action === 'edit' && isset($_GET['id'])) {

            $st = $db->prepare("SELECT*FROM cron_tasks WHERE id=? AND user_id=?");

            $st->execute([(int)$_GET['id'], $uid]);

            $editTask = $st->fetch(PDO::FETCH_ASSOC);

            if ($editTask) $editTask['url_decrypted'] = cronDecrypt($editTask['url_encrypted']);

            else {

                header('Location: ?');

                exit;

            }

        }

        if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {

            $id = (int)$_POST['id'];

            $n = cronSafeTaskName((string)($_POST['name'] ?? ''));

            $u = trim((string)($_POST['url'] ?? ''));

            $int = cronNormalizeInterval((int)($_POST['interval'] ?? 60));

            if ($n !== '' && $u !== '' && cronValidateUrl($u) !== false) {
                $st = $db->prepare("UPDATE cron_tasks SET name=?,url_encrypted=?,interval_seconds=? WHERE id=? AND user_id=?");
                $st->execute([$n, cronEncrypt($u), $int, $id, $uid]);
                if ($st->rowCount() > 0) {
                    cronLaunchTaskProcess($id, true);
                }
            } else {
                $_SESSION['cron_flash_error'] = 'URL không hợp lệ hoặc không được phép (chỉ http/https public).';
            }

            header('Location: ?');

            exit;

        }

        if ($action === 'discard_2fa') {

            $db->prepare("UPDATE cron_users SET twofa_secret='',twofa_enabled=0 WHERE id=?")->execute([$uid]);

            header('Location: ?');

            exit;

        }

        if ($action === 'setup_2fa') {

            $secret = totpSecret();

            $db->prepare("UPDATE cron_users SET twofa_secret=?,twofa_enabled=0 WHERE id=?")->execute([cronEncryptSecret($secret), $uid]);

            $cuser = $db->query("SELECT*FROM cron_users WHERE id=$uid")->fetch(PDO::FETCH_ASSOC);

        }

        if ($action === 'confirm_2fa' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {

            $st = $db->prepare("SELECT*FROM cron_users WHERE id=?");

            $st->execute([$uid]);

            $cu = $st->fetch(PDO::FETCH_ASSOC);

            $code = preg_replace('/\D+/', '', trim((string)($_POST['code'] ?? '')));

            $plainSecret = $cu ? cronDecryptSecret((string)$cu['twofa_secret']) : '';
            $ok = ($cu && $plainSecret !== '' && totpVerify($plainSecret, $code));

            if ($ok) {

                $db->prepare("UPDATE cron_users SET twofa_enabled=1 WHERE id=?")->execute([$uid]);

                $_SESSION['cron_2fa_msg'] = '2FA đã được bật!';

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

                    header('Content-Type: application/json');

                    echo json_encode(['ok' => true, 'message' => '2FA đã được bật!']);

                    exit;

                }

                header('Location: ?');

                exit;

            } else {

    $twofaError = 'Mã 2FA không đúng!';

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

                    header('Content-Type: application/json');

                    echo json_encode(['ok' => false, 'message' => $twofaError]);

                    exit;

                }

            }

        }

        if ($action === 'disable_2fa') {

            $db->prepare("UPDATE cron_users SET twofa_secret='',twofa_enabled=0 WHERE id=?")->execute([$uid]);

            $_SESSION['cron_2fa_msg'] = '2FA đã tắt.';

            header('Location: ?');

            exit;

        }

        if ($action === 'task_logs' && isset($_GET['task_id'])) {

            $tid = (int)$_GET['task_id'];

            $st = $db->prepare("SELECT id,status,http_code,response_body,error_msg,duration_ms,started_at,finished_at FROM cron_logs WHERE task_id=? AND task_id IN(SELECT id FROM cron_tasks WHERE id=? AND user_id=?) ORDER BY id DESC LIMIT 20");

            $st->execute([$tid, $tid, $uid]);

            header('Content-Type: application/json');

            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));

            exit;

        }

        if ($action === 'task_status') {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $statusTasks = cronFetchTasks($db, $uid);
            $items = [];
            $active = 0;
            $now = time();
            foreach ($statusTasks as $task) {
                if (!empty($task['is_active'])) {
                    $active++;
                }
                $timing = cronTaskTiming($task, $now);
                $items[] = [
                    'id' => (int)$task['id'],
                    'active' => !empty($task['is_active']),
                    'next_seconds' => $timing['next_seconds'],
                    'label' => $timing['label'],
                    'running' => $timing['running'],
                ];
            }
            $logStats = cronFetchLogStats($db, $uid);

            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo json_encode([
                'tasks' => $items,
                'stats' => [
                    'tasks' => count($statusTasks),
                    'active' => $active,
                    'runs' => $logStats['total'],
                    'success' => $logStats['success'],
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $tasks = cronFetchTasks($db, $uid);

        $taskCnt = count($tasks);

        $actCnt = 0;

        foreach ($tasks as $t) {

            if ($t['is_active']) $actCnt++;

        }

        $logStats = cronFetchLogStats($db, $uid);

        $logTotal = $logStats['total'];

        $logOk = $logStats['success'];

        $st = $db->prepare("SELECT*FROM cron_users WHERE id=?");

        $st->execute([$uid]);

        $cuser = $st->fetch(PDO::FETCH_ASSOC);

        if (!$cuser) $cuser = ['twofa_secret' => '', 'twofa_enabled' => 0];
        if (!empty($cuser['twofa_secret'])) {
            $cuser['twofa_secret'] = cronDecryptSecret((string)$cuser['twofa_secret']);
        }

        $twofaMsg = $_SESSION['cron_2fa_msg'] ?? null;

        unset($_SESSION['cron_2fa_msg']);

        $title = "Cron - $uname";
        $canonical = $layoutCanonical;
        ob_start();
        if (!empty($twofaMsg)) {
            echo '<div class="fixed top-4 right-4 z-50 alert alert-success shadow-xl">' . e((string)$twofaMsg) . '</div>';
        }
        ?>
<div class="page-wrap cron-dashboard">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon amber"><i class="fas fa-clock"></i></div>
      <div>
        <h1>Quản lý Cron</h1>
        <p>
          Tự động gọi URL theo giây •
          <strong class="text-white"><?= e($uname) ?></strong>
          <button type="button" id="cronLogout" class="cron-logout-btn">Thoát</button>
        </p>
      </div>
    </div>
    <div class="tool-hero-actions">
      <a href=".." class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Quay lại</a>
      <?php if (empty($cuser['twofa_enabled'])): ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
          <input type="hidden" name="action" value="setup_2fa">
          <button class="btn btn-green btn-sm" type="submit"><i class="fas fa-shield-halved"></i> Bật 2FA</button>
        </form>
      <?php else: ?>
        <form method="POST" onsubmit="return confirm('Tắt 2FA?')">
          <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
          <input type="hidden" name="action" value="disable_2fa">
          <button class="btn btn-danger btn-sm" type="submit"><i class="fas fa-shield-halved"></i> Tắt 2FA</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="cron-stats">
    <div class="cron-stat">
      <span>Tổng task</span>
      <strong id="cronStatTasks"><?= (int)$taskCnt ?></strong>
    </div>
    <div class="cron-stat is-live">
      <span>Đang chạy</span>
      <strong id="cronStatActive"><?= (int)$actCnt ?></strong>
    </div>
    <div class="cron-stat">
      <span>Lượt chạy</span>
      <strong id="cronStatRuns"><?= (int)$logTotal ?></strong>
    </div>
    <div class="cron-stat is-ok">
      <span>Thành công</span>
      <strong id="cronStatSuccess"><?= (int)$logOk ?></strong>
    </div>
  </div>

  <?php if (isset($editTask)): ?>
  <section class="tool-panel mb-4" style="border-color:rgba(245,158,11,.35)">
    <div class="tool-panel-head">
      <h2><i class="fas fa-pen text-yellow-300"></i> Sửa task</h2>
      <a href="?" class="btn btn-ghost btn-sm" aria-label="Đóng">&times;</a>
    </div>
    <form method="POST" class="cron-form-grid">
      <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int)$editTask['id'] ?>">
      <div class="field">
        <label>Tên task</label>
        <input class="input" type="text" name="name" required value="<?= e($editTask['name']) ?>">
      </div>
      <div class="field cron-span-2">
        <label>URL</label>
        <input class="input" type="url" name="url" required value="<?= e($editTask['url_decrypted']) ?>">
      </div>
      <div class="field">
        <label>Chu kỳ (giây)</label>
        <input class="input" type="number" name="interval" required min="3" value="<?= (int)$editTask['interval_seconds'] ?>">
      </div>
      <div class="cron-form-actions">
        <button class="btn btn-amber" type="submit"><i class="fas fa-save"></i> Lưu</button>
        <a class="btn btn-secondary" href="?">Hủy</a>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <?php if (!empty($cuser['twofa_secret']) && empty($cuser['twofa_enabled'])): ?>
  <section class="tool-panel mb-4" style="border-color:rgba(34,197,94,.3)">
    <div class="tool-panel-head">
      <h2><i class="fas fa-shield-halved text-green-300"></i> Xác thực 2FA</h2>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
        <input type="hidden" name="action" value="discard_2fa">
        <button class="btn btn-ghost btn-sm" type="submit" aria-label="Đóng">&times;</button>
      </form>
    </div>
    <p class="text-text-muted text-sm mb-4">Quét mã QR trong Google Authenticator hoặc nhập secret thủ công.</p>
    <div class="cron-2fa-box">
      <div class="note"><i class="fas fa-shield-halved"></i> Nhập secret bên dưới vào Google Authenticator (không dùng QR bên thứ 3 để tránh lộ secret).</div>
      >
      <div class="flex-1">
        <div class="field mb-3">
          <label>Secret</label>
          <div class="toolbar">
            <code class="input cron-secret"><?= e($cuser['twofa_secret']) ?></code>
            <button type="button" class="btn btn-secondary btn-sm" data-action="copy-secret" data-secret="<?= e($cuser['twofa_secret']) ?>"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div id="twofaMsg" class="hidden mb-3"></div>
        <form id="twofaForm" method="POST" class="toolbar" style="align-items:flex-end">
          <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
          <input type="hidden" name="action" value="confirm_2fa">
          <div class="field" style="flex:1">
            <label>Mã 2FA</label>
            <input class="input" type="text" name="code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" style="text-align:center;letter-spacing:.35em;font-weight:800">
          </div>
          <button class="btn btn-green" type="submit"><i class="fas fa-check"></i> Xác nhận</button>
        </form>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="tool-panel mb-4">
    <div class="tool-panel-head">
      <h2><i class="fas fa-plus text-blue-300"></i> Thêm task mới</h2>
      <span class="pill pill-blue">Create</span>
    </div>
    <form method="POST" class="cron-form-grid">
      <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
      <input type="hidden" name="action" value="add">
      <div class="field">
        <label>Tên task</label>
        <input class="input" type="text" name="name" required placeholder="VD: check-status">
      </div>
      <div class="field cron-span-2">
        <label>URL cần gọi</label>
        <input class="input" type="url" name="url" required placeholder="https://example.com/job">
      </div>
      <div class="field">
        <label>Chu kỳ (giây)</label>
        <input class="input" type="number" name="interval" required min="3" value="60">
      </div>
      <div class="cron-form-actions">
        <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i> Thêm task</button>
      </div>
    </form>
  </section>

  <section class="tool-panel cron-list-panel">
    <div class="tool-panel-head cron-list-head">
      <h2><i class="fas fa-list text-cyan-300"></i> Danh sách task</h2>
      <span class="pill pill-slate"><?= (int)$taskCnt ?> task</span>
    </div>

    <?php if (empty($tasks)): ?>
      <div class="cron-empty">
        <i class="fas fa-inbox"></i>
        <div>Chưa có task nào. Hãy thêm task đầu tiên ở form phía trên.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap cron-table-wrap">
        <table class="cron-table">
          <thead>
            <tr>
              <th>Task</th>
              <th>URL</th>
              <th>Chu kỳ</th>
              <th>Lần tới</th>
              <th>Status</th>
              <th class="text-right">Thao tác</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($tasks as $t):
              $du = cronDecrypt($t['url_encrypted']);
              if ($du === false) {
                  $du = '';
              }
              $timing = cronTaskTiming($t);
              $rm = $timing['next_seconds'];
              $nl = $timing['label'];
              $urlShort = mb_strlen((string)$du) > 58 ? (mb_substr((string)$du, 0, 58) . '...') : (string)$du;
          ?>
            <tr>
              <td>
                <div class="cron-task-name"><?= e($t['name']) ?></div>
                <div class="cron-task-id">#<?= (int)$t['id'] ?></div>
              </td>
              <td>
                <div class="cron-url" title="<?= e((string)$du) ?>"><?= e($urlShort) ?></div>
              </td>
              <td><span class="pill pill-slate"><?= (int)$t['interval_seconds'] ?>s</span></td>
              <td>
                <?php if (!empty($t['is_active']) && $rm !== null && $rm > 0): ?>
                  <span class="cron-next" data-task-next="<?= (int)$t['id'] ?>" data-next="<?= (int)$rm ?>"><?= e($nl) ?></span>
                <?php else: ?>
                  <span class="cron-next<?= empty($t['is_active']) ? ' is-muted' : '' ?>" data-task-next="<?= (int)$t['id'] ?>"><?= e($nl) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($t['is_active'])): ?>
                  <span class="cron-badge on" data-task-active="<?= (int)$t['id'] ?>"><i class="fas fa-circle"></i> Active</span>
                <?php else: ?>
                  <span class="cron-badge off" data-task-active="<?= (int)$t['id'] ?>"><i class="fas fa-pause"></i> Pause</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="cron-actions">
                  <button type="button" class="cron-act" title="Xem log" data-action="view-logs" data-id="<?= (int)$t['id'] ?>"><i class="fas fa-eye"></i></button>
                  <a class="cron-act" title="Sửa" href="?action=edit&id=<?= (int)$t['id'] ?>"><i class="fas fa-pen"></i></a>
                  <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="cron-act" title="Bật/Tắt"><i class="fas fa-power-off"></i></button>
                  </form>
                  <form method="POST" onsubmit="return confirm('Xóa task này?')">
                    <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="cron-act danger" title="Xóa"><i class="fas fa-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<div id="logModal" class="cron-modal hidden" data-modal-close="1">
  <div class="cron-modal-card">
    <div class="cron-modal-head">
      <h2><i class="fas fa-history text-primary"></i> Lịch sử chạy</h2>
      <button type="button" class="btn btn-ghost btn-sm" data-action-hide-logs="1" aria-label="Đóng">&times;</button>
    </div>
    <div id="logBody" class="cron-modal-body"></div>
    <div class="cron-modal-foot">
      <button type="button" class="btn btn-secondary btn-sm" data-action-hide-logs="1">Đóng</button>
    </div>
  </div>
</div>

<script <?= function_exists('dvproNonceAttr') ? dvproNonceAttr() : '' ?>>
window.cronCsrf = <?= json_encode(cronCsrfToken(), JSON_UNESCAPED_UNICODE) ?>;

function hideLogs() {
  var modal = document.getElementById('logModal');
  if (modal) modal.classList.add('hidden');
}

function viewLogs(taskId) {
  var modal = document.getElementById('logModal');
  var body = document.getElementById('logBody');
  if (!modal || !body) return;
  body.innerHTML = '<div class="cron-empty"><i class="fas fa-spinner fa-spin"></i><div>Đang tải lịch sử...</div></div>';
  modal.classList.remove('hidden');

  fetch('?action=task_logs&task_id=' + encodeURIComponent(taskId))
    .then(function(res) { return res.json(); })
    .then(function(rows) {
      if (!rows || !rows.length) {
        body.innerHTML = '<div class="cron-empty"><i class="far fa-clock"></i><div>Chưa có lịch sử chạy.</div></div>';
        return;
      }
      body.innerHTML = '';
      rows.forEach(function(item) {
        var ok = item.status === 'success';
        var running = item.status === 'running';
        var cls = ok ? 'is-ok' : (running ? 'is-run' : 'is-bad');
        var label = ok ? 'Thành công' : (running ? 'Đang chạy' : 'Thất bại');
        var icon = ok ? 'fa-check-circle' : (running ? 'fa-spinner' : 'fa-times-circle');
        var httpClass = (item.http_code >= 200 && item.http_code < 400) ? 'ok' : 'bad';
        var card = document.createElement('div');
        card.className = 'cron-log-item ' + cls;
        card.innerHTML =
          '<div class="cron-log-top">' +
            '<div class="cron-log-status"><i class="fas ' + icon + '"></i> ' + label + '</div>' +
            '<div class="cron-log-meta">' +
              '<span class="' + httpClass + '">HTTP ' + (item.http_code || 0) + '</span>' +
              '<span>' + (item.duration_ms || 0) + 'ms</span>' +
              '<span>' + esc(item.started_at || '') + '</span>' +
            '</div>' +
          '</div>' +
          (item.error_msg ? '<div class="cron-log-error">' + esc(item.error_msg) + '</div>' : '') +
          (item.response_body ? '<pre class="cron-log-body">' + esc(String(item.response_body).slice(0, 1200)) + '</pre>' : '');
        body.appendChild(card);
      });
    })
    .catch(function() {
      body.innerHTML = '<div class="cron-empty"><i class="fas fa-triangle-exclamation"></i><div>Không tải được lịch sử.</div></div>';
    });
}

function esc(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function showTwofaNotice(message, ok) {
  var box = document.getElementById('twofaMsg');
  if (!box) return;
  box.className = ok ? 'alert alert-success mb-3' : 'alert alert-error mb-3';
  box.textContent = message || '';
  box.classList.remove('hidden');
}

var twofaForm = document.getElementById('twofaForm');
if (twofaForm) {
  twofaForm.addEventListener('submit', function(event) {
    event.preventDefault();
    var btn = twofaForm.querySelector('button[type="submit"]');
    var codeInput = twofaForm.querySelector('input[name="code"]');
    var code = codeInput ? codeInput.value.trim() : '';
    if (!code) {
      showTwofaNotice('Vui lòng nhập mã 2FA', false);
      return;
    }
    if (btn) {
      btn.dataset.oldText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang xác nhận';
    }
    fetch('?', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: new URLSearchParams({
        action: 'confirm_2fa',
        code: code,
        csrf_token: window.cronCsrf
      }).toString()
    })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data && data.ok) {
          showTwofaNotice(data.message || '2FA đã được bật!', true);
          setTimeout(function() { window.location.href = '?'; }, 500);
        } else {
          showTwofaNotice((data && data.message) || 'Mã 2FA không đúng!', false);
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.oldText || 'Xác nhận';
          }
        }
      })
      .catch(function() {
        showTwofaNotice('Không gửi được yêu cầu.', false);
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = btn.dataset.oldText || 'Xác nhận';
        }
      });
  });
}

var cronLogout = document.getElementById('cronLogout');
if (cronLogout) {
  cronLogout.addEventListener('click', function() {
    fetch('?', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams({
        action: 'logout',
        csrf_token: window.cronCsrf
      }).toString()
    }).then(function() { location.reload(); });
  });
}

function tickCountdown() {
  var nodes = document.querySelectorAll('[data-next]');
  nodes.forEach(function(el) {
    var seconds = parseInt(el.getAttribute('data-next'), 10);
    if (!isFinite(seconds)) return;
    if (seconds > 1) {
      seconds -= 1;
      el.textContent = seconds + 's';
      el.setAttribute('data-next', String(seconds));
    } else {
      el.textContent = 'Đang chờ...';
      el.removeAttribute('data-next');
    }
  });
}
setInterval(tickCountdown, 1000);

var cronStatusBusy = false;
function pollTaskStatus() {
  if (cronStatusBusy || document.hidden) return;
  cronStatusBusy = true;
  fetch('?action=task_status', {
    cache: 'no-store',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      (data.tasks || []).forEach(function(item) {
        var next = document.querySelector('[data-task-next="' + item.id + '"]');
        if (next) {
          next.textContent = item.label || '';
          next.classList.toggle('is-muted', !item.active);
          if (item.active && item.next_seconds > 0) {
            next.setAttribute('data-next', String(item.next_seconds));
          } else {
            next.removeAttribute('data-next');
          }
        }

        var badge = document.querySelector('[data-task-active="' + item.id + '"]');
        if (badge) {
          badge.className = 'cron-badge ' + (item.active ? 'on' : 'off');
          badge.innerHTML = item.active
            ? '<i class="fas fa-circle"></i> Active'
            : '<i class="fas fa-pause"></i> Pause';
        }
      });

      var stats = data.stats || {};
      var statMap = {
        cronStatTasks: stats.tasks,
        cronStatActive: stats.active,
        cronStatRuns: stats.runs,
        cronStatSuccess: stats.success
      };
      Object.keys(statMap).forEach(function(id) {
        var el = document.getElementById(id);
        if (el && statMap[id] !== undefined) el.textContent = statMap[id];
      });
    })
    .catch(function() {})
    .finally(function() { cronStatusBusy = false; });
}
setInterval(pollTaskStatus, 2000);
setTimeout(pollTaskStatus, 500);
document.addEventListener('visibilitychange', function() {
  if (!document.hidden) pollTaskStatus();
});

// data-action-view-logs-binder
document.addEventListener('click', function(e){
  var v=e.target.closest('[data-action-view-logs]');
  if(v && typeof viewLogs==='function'){ viewLogs(parseInt(v.getAttribute('data-action-view-logs'),10)||0); }
  var h=e.target.closest('[data-action-hide-logs]');
  if(h && typeof hideLogs==='function'){ hideLogs(); }
  var m=e.target.closest('[data-modal-close]');
  if(m && e.target===m && typeof hideLogs==='function'){ hideLogs(); }
  var s=e.target.closest('[data-action="copy-secret"]');
  if(s){
    var val=s.getAttribute('data-secret')||'';
    if(window.dvproCopyValue){ window.dvproCopyValue(val,s); }
    else if(navigator.clipboard){ navigator.clipboard.writeText(val); }
  }
});
</script>
<?php
$content = ob_get_clean();
include dirname(__DIR__) . '/layout.php';
