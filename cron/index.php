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
         LEFT JOIN cron_logs l ON l.id=(
           SELECT MAX(cl.id)
           FROM cron_logs cl
           WHERE cl.task_id=t.id
         )
         WHERE t.user_id=?
         ORDER BY t.id DESC"
    );
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cronFetchTaskStatus(PDO $db, int $uid): array
{
    $st = $db->prepare(
        "SELECT t.id,t.is_active,t.interval_seconds,
                l.status AS last_status,l.started_at AS last_started_at,l.finished_at AS last_finished_at
         FROM cron_tasks t
         LEFT JOIN cron_logs l ON l.id=(
           SELECT MAX(cl.id)
           FROM cron_logs cl
           WHERE cl.task_id=t.id
         )
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

function cronBulkTasks(PDO $db, int $uid, array $ids, string $operation, ?int $interval = null): array
{
    if (!in_array($operation, ['start', 'pause', 'delete', 'interval'], true)
        || !$ids || count($ids) > 500) {
        throw new InvalidArgumentException('Chọn từ 1 đến 500 task và thao tác hợp lệ.');
    }
    foreach ($ids as $id) {
        if ((!is_int($id) && !is_string($id))
            || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('Danh sách task không hợp lệ.');
        }
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($operation === 'interval' && ($interval === null || $interval < 3 || $interval > 86400)) {
        throw new InvalidArgumentException('Chu kỳ phải từ 3 đến 86400 giây.');
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$uid], $ids);
    $db->beginTransaction();
    try {
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $st = $db->prepare("SELECT id,is_active FROM cron_tasks WHERE user_id=? AND id IN ($marks)" . $lock);
        $st->execute($params);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($tasks) !== count($ids)) {
            throw new InvalidArgumentException('Có task đã bị xóa hoặc không thuộc tài khoản của bạn. Hãy tải lại danh sách.');
        }
        $launch = [];
        if ($operation === 'delete') {
            $db->prepare("DELETE FROM cron_logs WHERE task_id IN (SELECT id FROM cron_tasks WHERE user_id=? AND id IN ($marks))")->execute($params);
            $db->prepare("DELETE FROM cron_tasks WHERE user_id=? AND id IN ($marks)")->execute($params);
        } elseif ($operation === 'interval') {
            $db->prepare("UPDATE cron_tasks SET interval_seconds=? WHERE user_id=? AND id IN ($marks)")->execute(array_merge([$interval], $params));
        } else {
            $active = $operation === 'start' ? 1 : 0;
            $db->prepare("UPDATE cron_tasks SET is_active=? WHERE user_id=? AND id IN ($marks)")->execute(array_merge([$active], $params));
            foreach ($tasks as $task) {
                if ($active && empty($task['is_active'])) $launch[] = (int)$task['id'];
            }
        }
        $db->commit();
        return ['count' => count($ids), 'launch' => $launch];
    } catch (Throwable $ex) {
        if ($db->inTransaction()) $db->rollBack();
        throw $ex;
    }
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
        $st = $db->prepare("SELECT id,code,code_hash,name,twofa_secret,twofa_enabled FROM cron_users WHERE code=? LIMIT 1");
        $st->execute([$code]);
        $candidate = $st->fetch(PDO::FETCH_ASSOC);

        if ($candidate && password_verify($code, (string)($candidate['code_hash'] ?? ''))) {
            $u = $candidate;
        } elseif (!$candidate) {
            // Keep compatibility with legacy accounts whose login code is not the username.
            $users = $db->query("SELECT id,code,code_hash,name,twofa_secret,twofa_enabled FROM cron_users")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as $legacyCandidate) {
                $hash = (string)($legacyCandidate['code_hash'] ?? '');
                if ($hash !== '' && password_verify($code, $hash)) {
                    $u = $legacyCandidate;
                    break;
                }
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
        <label for="login-twofa">Mã 2FA</label>
        <input id="login-twofa" type="text" name="twofa_code" required inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="000000" class="input" style="text-align:center;letter-spacing:.4em;font-weight:800;font-size:1.15rem" autocomplete="one-time-code">
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

    <form id="loginForm" method="POST" class="space-y-4" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
      <div class="field">
        <label for="login-username">Username</label>
        <input id="login-username" type="text" name="login_code" required placeholder="Nhập username..." class="input" autocomplete="off" autocapitalize="none" spellcheck="false">
      </div>
      <button type="submit" class="btn btn-primary btn-block">
        <i class="fas fa-right-to-bracket"></i> Đăng nhập
      </button>
    </form>

<script <?= function_exists('dvproNonceAttr') ? dvproNonceAttr() : '' ?>>
document.addEventListener('DOMContentLoaded', function () {
  const loginForm = document.getElementById('loginForm');
  const registerPanel = document.getElementById('registerPanel');
  const accountHelp = document.getElementById('accountHelp');
  const showRegisterBtn = document.getElementById('showRegisterBtn');
  const showLoginBtn = document.getElementById('showLoginBtn');
  if (!loginForm || !registerPanel || !accountHelp) return;

  showRegisterBtn?.addEventListener('click', function () {
    loginForm.classList.add('hidden');
    accountHelp.classList.add('hidden');
    registerPanel.classList.remove('hidden');
    document.querySelector('input[name="username"]')?.focus();
  });

  showLoginBtn?.addEventListener('click', function () {
    registerPanel.classList.add('hidden');
    loginForm.classList.remove('hidden');
    accountHelp.classList.remove('hidden');
    document.querySelector('input[name="login_code"]')?.focus();
  });
});
</script>

    <div id="registerPanel" class="<?= !empty($_SESSION['cron_register_error']) ? '' : 'hidden' ?>">
      <?php if (!empty($_SESSION['cron_register_error'])): ?>
        <div class="alert alert-error mb-4"><?= e($_SESSION['cron_register_error']) ?></div>
        <?php unset($_SESSION['cron_register_error']); ?>
      <?php endif; ?>

      <form method="POST" class="space-y-4" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
        <input type="hidden" name="action" value="register_username">
        <div class="field">
          <label for="register-username">Tạo tài khoản bằng username</label>
          <input id="register-username" type="text" name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_.-]{3,32}" placeholder="Ví dụ: user123" class="input" autocomplete="off" autocapitalize="none" spellcheck="false">
        </div>
        <button type="submit" class="btn btn-secondary btn-block">
          <i class="fas fa-user-plus"></i> Tạo tài khoản
        </button>
      </form>

      <button type="button" id="showLoginBtn" class="btn btn-ghost btn-block mt-3">Đã có tài khoản? Đăng nhập</button>
    </div>

    <div id="accountHelp" class="card-soft p-4 mt-5">
      <div class="flex items-start gap-3">
        <div class="tool-icon blue" style="width:2.5rem;height:2.5rem;font-size:1rem"><i class="fas fa-circle-info"></i></div>
        <div>
          <p class="text-sm font-semibold text-white m-0">Chưa có tài khoản?</p>
          <p class="text-xs text-text-muted mt-1 mb-0">Tài khoản mới chỉ cần username, không cần mật khẩu.</p>
        </div>
      </div>
<button type="button" id="showRegisterBtn" class="btn btn-ghost btn-block mt-3">Tạo tài khoản mới</button>
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
        $mutatingActions = ['bulk', 'add', 'delete', 'toggle', 'update', 'discard_2fa', 'setup_2fa', 'confirm_2fa', 'disable_2fa'];
        if (in_array($action, $mutatingActions, true)) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                exit('Method not allowed');
            }
            cronRequireCsrf();
        }

        if ($action === 'bulk') {
            try {
                $ids = $_POST['ids'] ?? [];
                $operation = $_POST['bulk_action'] ?? '';
                if (!is_array($ids) || !is_string($operation)) {
                    throw new InvalidArgumentException('Yêu cầu không hợp lệ.');
                }
                $interval = filter_var($_POST['interval'] ?? null, FILTER_VALIDATE_INT);
                $result = cronBulkTasks($db, $uid, $ids, $operation, $interval === false ? null : $interval);
                $labels = ['start' => 'Đã bật', 'pause' => 'Đã dừng', 'delete' => 'Đã xóa', 'interval' => 'Đã đổi chu kỳ cho'];
                $_SESSION['cron_flash_success'] = $labels[$operation] . ' ' . $result['count'] . ' task.';
                $failed = 0;
                foreach ($result['launch'] as $taskId) {
                    if (!cronLaunchTaskProcess($taskId, true)) $failed++;
                }
                if ($failed) {
                    $_SESSION['cron_flash_error'] = $failed . ' task đã bật lịch nhưng chưa khởi chạy ngay được. Hãy kiểm tra lịch sử chạy.';
                }
            } catch (InvalidArgumentException $ex) {
                $_SESSION['cron_flash_error'] = $ex->getMessage();
            } catch (Throwable $ex) {
                error_log('Cron bulk action failed: ' . $ex->getMessage());
                $_SESSION['cron_flash_error'] = 'Không hoàn tất được thao tác. Vui lòng kiểm tra lại danh sách trước khi thử lại.';
            }
            header('Location: ?');
            exit;
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

            $st = $db->prepare("SELECT l.id,l.status,l.http_code,l.response_body,l.error_msg,l.duration_ms,l.started_at,l.finished_at FROM cron_logs l INNER JOIN cron_tasks t ON t.id=l.task_id WHERE l.task_id=? AND t.user_id=? ORDER BY l.id DESC LIMIT 20");

            $st->execute([$tid, $uid]);

            header('Content-Type: application/json');

            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));

            exit;

        }

        if ($action === 'task_status') {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $statusTasks = cronFetchTaskStatus($db, $uid);
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
  <?php foreach (['error', 'success'] as $flashType):
      $flash = $_SESSION['cron_flash_' . $flashType] ?? '';
      unset($_SESSION['cron_flash_' . $flashType]);
      if ($flash !== ''): ?>
    <div class="alert alert-<?= $flashType ?>" role="status"><?= e($flash) ?></div>
  <?php endif; endforeach; ?>
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
        <form method="POST" data-cron-confirm="Tắt xác thực 2FA cho tài khoản này?">
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

  <section class="tool-panel cron-list-panel">
    <div class="tool-panel-head cron-list-head">
      <h2><i class="fas fa-list text-cyan-300"></i> Danh sách task</h2>
      <div class="cron-list-head-actions">
        <span class="pill pill-slate" id="cronVisibleCount"><?= (int)$taskCnt ?> task</span>
        <button type="button" class="btn btn-primary btn-sm" id="cronAddTask"><i class="fas fa-plus"></i> Thêm task</button>
      </div>
    </div>

    <?php if (empty($tasks)): ?>
      <div class="cron-empty">
        <i class="fas fa-inbox"></i>
        <div>Chưa có task nào. Bấm “Thêm task” để tạo task đầu tiên.</div>
      </div>
    <?php else: ?>
      <div class="cron-list-tools">
        <div class="cron-filters">
          <input class="input" type="search" id="cronSearch" placeholder="Tìm tên, URL, ID..." aria-label="Tìm task theo tên, URL hoặc ID">
          <select class="input" id="cronStatusFilter" aria-label="Lọc trạng thái task">
            <option value="all">Tất cả trạng thái</option>
            <option value="1">Đang bật</option>
            <option value="0">Tạm dừng</option>
          </select>
        </div>
        <form method="POST" id="cronBulkForm" class="cron-bulk-form" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
          <input type="hidden" name="action" value="bulk">
          <div class="cron-selection">
            <strong id="cronSelectedCount" aria-live="polite">Đã chọn 0</strong>
            <button type="button" class="btn btn-ghost btn-sm" id="cronClearSelection" disabled>Bỏ chọn</button>
          </div>
          <div class="cron-bulk-actions">
            <button class="btn btn-green btn-sm" name="bulk_action" value="start" disabled><i class="fas fa-play"></i> Chạy</button>
            <button class="btn btn-secondary btn-sm" name="bulk_action" value="pause" disabled><i class="fas fa-pause"></i> Dừng</button>
            <button class="btn btn-danger btn-sm" name="bulk_action" value="delete" disabled><i class="fas fa-trash"></i> Xóa</button>
            <button type="button" class="btn btn-secondary btn-sm" id="cronCopyUrls" disabled><i class="fas fa-copy"></i> Copy URL</button>
            <div class="cron-bulk-interval">
              <input class="input" type="number" name="interval" id="cronBulkInterval" min="3" max="86400" placeholder="Giây" aria-label="Chu kỳ mới cho task đã chọn">
              <button class="btn btn-secondary btn-sm" name="bulk_action" value="interval" disabled>Đổi chu kỳ</button>
            </div>
          </div>
        </form>
        <p class="cron-bulk-note" id="cronBulkNotice" role="status">Chọn tối đa 500 task. Dừng sẽ ngừng các lượt tiếp theo; lượt đang chạy có thể vẫn hoàn tất.</p>
      </div>
      <div class="table-wrap cron-table-wrap" role="region" aria-label="Danh sách task Cron" tabindex="0">
        <table class="cron-table">
          <thead>
            <tr>
              <th class="cron-select-cell"><input type="checkbox" id="cronSelectAll" class="cron-checkbox" aria-label="Chọn tất cả task đang hiển thị"></th>
              <th>Task</th>
              <th>URL</th>
              <th>Chu kỳ</th>
              <th>Lần tới</th>
              <th>Trạng thái</th>
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
            <tr data-cron-row="<?= (int)$t['id'] ?>" data-active="<?= (int)!empty($t['is_active']) ?>" data-interval="<?= (int)$t['interval_seconds'] ?>">
              <td class="cron-select-cell"><input type="checkbox" class="cron-checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" form="cronBulkForm" aria-label="Chọn task #<?= (int)$t['id'] ?>: <?= e($t['name']) ?>"></td>
              <td>
                <div class="cron-task-name" title="<?= e($t['name']) ?>"><?= e($t['name']) ?></div>
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
                  <span class="cron-badge on" data-task-active="<?= (int)$t['id'] ?>"><i class="fas fa-circle"></i> Đang bật</span>
                <?php else: ?>
                  <span class="cron-badge off" data-task-active="<?= (int)$t['id'] ?>"><i class="fas fa-pause"></i> Tạm dừng</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="cron-actions">
                  <button type="button" class="cron-act" title="Xem log" aria-label="Xem lịch sử chạy" data-action="view-logs" data-id="<?= (int)$t['id'] ?>"><i class="fas fa-eye"></i></button>
                  <a class="cron-act" title="Sửa" aria-label="Sửa task" data-cron-edit href="?action=edit&id=<?= (int)$t['id'] ?>"><i class="fas fa-pen"></i></a>
                  <form method="POST" data-cron-confirm="toggle">
                    <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="cron-act" title="<?= !empty($t['is_active']) ? 'Dừng task' : 'Chạy task' ?>" aria-label="<?= !empty($t['is_active']) ? 'Dừng task' : 'Chạy task' ?>"><i class="fas fa-power-off"></i></button>
                  </form>
                  <form method="POST" data-cron-confirm="Xóa task này và toàn bộ lịch sử chạy? Không thể hoàn tác." data-cron-danger>
                    <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="cron-act danger" title="Xóa" aria-label="Xóa task"><i class="fas fa-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="cron-empty" id="cronNoMatches" hidden>Không tìm thấy task phù hợp.</div>
    <?php endif; ?>
  </section>
</div>

<dialog id="cronTaskDialog" class="cron-dialog cron-dialog-form" aria-labelledby="cronTaskTitle">
  <div class="cron-modal-card">
    <div class="cron-modal-head">
      <h2 id="cronTaskTitle">Thêm task mới</h2>
      <button type="button" class="btn btn-ghost btn-sm" data-cron-close aria-label="Đóng">&times;</button>
    </div>
    <form method="POST" id="cronTaskForm">
      <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="id" value="">
      <div class="cron-modal-body cron-task-fields">
        <div class="field">
          <label for="cron-task-name">Tên task</label>
          <input class="input" type="text" id="cron-task-name" name="name" required maxlength="120" placeholder="VD: check-status" autofocus>
        </div>
        <div class="field">
          <label for="cron-task-url">URL cần gọi</label>
          <input class="input" type="url" id="cron-task-url" name="url" required placeholder="https://example.com/job">
        </div>
        <div class="field">
          <label for="cron-task-interval">Chu kỳ (giây)</label>
          <input class="input" type="number" id="cron-task-interval" name="interval" required min="3" max="86400" value="60" aria-describedby="cronIntervalHint">
          <span class="hint" id="cronIntervalHint">Từ 3 đến 86400 giây.</span>
        </div>
      </div>
      <div class="cron-modal-foot">
        <button type="button" class="btn btn-secondary btn-sm" data-cron-close>Hủy</button>
        <button type="submit" class="btn btn-primary btn-sm" id="cronTaskSave"><i class="fas fa-plus"></i> Thêm task</button>
      </div>
    </form>
  </div>
</dialog>

<dialog id="cronConfirmDialog" class="cron-dialog cron-dialog-confirm" aria-labelledby="cronConfirmTitle" aria-describedby="cronConfirmMessage">
  <div class="cron-modal-card">
    <div class="cron-modal-head">
      <h2 id="cronConfirmTitle">Xác nhận thao tác</h2>
      <button type="button" class="btn btn-ghost btn-sm" data-cron-close aria-label="Đóng">&times;</button>
    </div>
    <div class="cron-modal-body"><p id="cronConfirmMessage"></p></div>
    <div class="cron-modal-foot">
      <button type="button" class="btn btn-secondary btn-sm" data-cron-close autofocus>Hủy</button>
      <button type="button" class="btn btn-primary btn-sm" id="cronConfirmAccept">Xác nhận</button>
    </div>
  </div>
</dialog>

  <?php if (!empty($cuser['twofa_secret']) && empty($cuser['twofa_enabled'])): ?>
  <dialog id="cronTwofaDialog" class="cron-dialog cron-dialog-form" aria-labelledby="cronTwofaTitle" data-auto-open>
    <div class="cron-modal-card">
    <div class="cron-modal-head">
      <h2 id="cronTwofaTitle"><i class="fas fa-shield-halved text-green-300"></i> Xác thực 2FA</h2>
      <button class="btn btn-ghost btn-sm" type="button" data-cron-close aria-label="Đóng">&times;</button>
    </div>
    <div class="cron-modal-body">
    <p class="text-text-muted text-sm">Nhập secret vào Google Authenticator rồi điền mã xác thực.</p>
    <div class="cron-2fa-box">
      <div class="note"><i class="fas fa-shield-halved"></i> Nhập secret bên dưới vào Google Authenticator (không dùng QR bên thứ 3 để tránh lộ secret).</div>
      <div class="flex-1">
        <div class="field mb-3">
          <label>Secret</label>
          <div class="toolbar">
            <code class="input cron-secret"><?= e($cuser['twofa_secret']) ?></code>
            <button type="button" class="btn btn-secondary btn-sm" data-action="copy-secret" aria-label="Sao chép secret" data-secret="<?= e($cuser['twofa_secret']) ?>"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div id="twofaMsg" class="hidden mb-3"></div>
        <form id="twofaForm" method="POST" class="toolbar" style="align-items:flex-end">
          <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
          <input type="hidden" name="action" value="confirm_2fa">
          <div class="field" style="flex:1">
            <label for="cron-confirm-code">Mã 2FA</label>
            <input class="input" id="cron-confirm-code" type="text" name="code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" style="text-align:center;letter-spacing:.35em;font-weight:800">
          </div>
          <button class="btn btn-green" type="submit"><i class="fas fa-check"></i> Xác nhận</button>
        </form>
      </div>
    </div>
    </div>
    <div class="cron-modal-foot">
      <form method="POST" data-cron-confirm="Hủy thiết lập 2FA đang chờ?">
        <input type="hidden" name="csrf_token" value="<?= e(cronCsrfToken()) ?>">
        <input type="hidden" name="action" value="discard_2fa">
        <button class="btn btn-secondary btn-sm" type="submit">Hủy thiết lập</button>
      </form>
      <button type="button" class="btn btn-secondary btn-sm" data-cron-close>Đóng</button>
    </div>
    </div>
  </dialog>
  <?php endif; ?>

<dialog id="logModal" class="cron-dialog" aria-labelledby="cronLogTitle">
  <div class="cron-modal-card">
    <div class="cron-modal-head">
      <h2 id="cronLogTitle"><i class="fas fa-history text-primary"></i> Lịch sử chạy</h2>
      <button type="button" class="btn btn-ghost btn-sm" data-action-hide-logs="1" aria-label="Đóng">&times;</button>
    </div>
    <div id="logBody" class="cron-modal-body"></div>
    <div class="cron-modal-foot">
      <button type="button" class="btn btn-secondary btn-sm" data-action-hide-logs="1">Đóng</button>
    </div>
  </div>
</dialog>

<script <?= function_exists('dvproNonceAttr') ? dvproNonceAttr() : '' ?>>
window.cronCsrf = <?= json_encode(cronCsrfToken(), JSON_UNESCAPED_UNICODE) ?>;

var cronTaskDialog = document.getElementById('cronTaskDialog');
var cronTaskForm = document.getElementById('cronTaskForm');
var cronConfirmDialog = document.getElementById('cronConfirmDialog');
var cronPendingConfirm = null;
function cronOpenTask(task) {
  cronTaskForm.reset();
  cronTaskForm.elements.action.value = task ? 'update' : 'add';
  cronTaskForm.elements.id.value = task ? task.id : '';
  cronTaskForm.elements.name.value = task ? task.name : '';
  cronTaskForm.elements.url.value = task ? task.url : '';
  cronTaskForm.elements.interval.value = task ? task.interval : 60;
  document.getElementById('cronTaskTitle').textContent = task ? 'Sửa task #' + task.id : 'Thêm task mới';
  document.getElementById('cronTaskSave').innerHTML = task ? '<i class="fas fa-save"></i> Lưu thay đổi' : '<i class="fas fa-plus"></i> Thêm task';
  cronTaskDialog.showModal();
}
function cronAsk(message, callback, danger) {
  document.getElementById('cronConfirmMessage').textContent = message;
  document.getElementById('cronConfirmAccept').className = 'btn btn-sm ' + (danger ? 'btn-danger' : 'btn-primary');
  cronPendingConfirm = callback;
  cronConfirmDialog.showModal();
}
function cronSubmitConfirmed(form, submitter) {
  // Native submit omits the clicked button; retain its operation explicitly.
  if (submitter && submitter.name) {
    var operation = document.createElement('input');
    operation.type = 'hidden';
    operation.name = submitter.name;
    operation.value = submitter.value;
    form.appendChild(operation);
  }
  HTMLFormElement.prototype.submit.call(form);
}
document.getElementById('cronAddTask').addEventListener('click', function() { cronOpenTask(null); });
document.querySelectorAll('[data-cron-edit]').forEach(function(link) {
  link.addEventListener('click', function(event) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    var row = link.closest('[data-cron-row]');
    cronOpenTask({id: row.dataset.cronRow, name: row.querySelector('.cron-task-name').title, url: row.querySelector('.cron-url').title, interval: row.dataset.interval});
  });
});
document.querySelectorAll('.cron-dialog').forEach(function(dialog) {
  dialog.addEventListener('click', function(event) {
    if (event.target.closest('[data-cron-close]')) dialog.close();
    if (event.target === dialog) {
      var rect = dialog.getBoundingClientRect();
      if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    }
  });
});
cronConfirmDialog.addEventListener('close', function() { cronPendingConfirm = null; });
document.getElementById('cronConfirmAccept').addEventListener('click', function() {
  var callback = cronPendingConfirm;
  cronPendingConfirm = null;
  cronConfirmDialog.close();
  if (callback) callback();
});
document.querySelectorAll('form[data-cron-confirm]').forEach(function(form) {
  form.addEventListener('submit', function(event) {
    event.preventDefault();
    var message = form.dataset.cronConfirm;
    if (message === 'toggle') {
      var row = form.closest('[data-cron-row]');
      message = (row.dataset.active === '1' ? 'Dừng' : 'Chạy') + ' task “' + row.querySelector('.cron-task-name').title + '”?';
    }
    cronAsk(message, function() { cronSubmitConfirmed(form, event.submitter); }, form.hasAttribute('data-cron-danger'));
  });
});
document.querySelectorAll('[data-auto-open]').forEach(function(dialog) { dialog.showModal(); });
<?php if (isset($editTask)): ?>
cronOpenTask(<?= json_encode(['id' => (int)$editTask['id'], 'name' => $editTask['name'], 'url' => $editTask['url_decrypted'], 'interval' => (int)$editTask['interval_seconds']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);
<?php endif; ?>

function hideLogs() {
  var modal = document.getElementById('logModal');
  if (modal) modal.close();
}

function viewLogs(taskId) {
  var modal = document.getElementById('logModal');
  var body = document.getElementById('logBody');
  if (!modal || !body) return;
  body.innerHTML = '<div class="cron-empty"><i class="fas fa-spinner fa-spin"></i><div>Đang tải lịch sử...</div></div>';
  if (!modal.open) modal.showModal();

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
        var queued = item.status === 'queued';
        var queuedExisting = item.status === 'queued_existing';
        var running = item.status === 'running';
        var cls = ok ? 'is-ok' : (queued || queuedExisting || running ? 'is-run' : 'is-bad');
        var label = ok ? 'Thành công' : (queuedExisting ? 'Đang chờ (đã có job)' : (queued ? 'Đã xếp hàng' : (running ? 'Đang chạy' : 'Thất bại')));
        var icon = ok ? 'fa-check-circle' : (queuedExisting ? 'fa-clock' : (queued ? 'fa-clock' : (running ? 'fa-spinner' : 'fa-times-circle')));
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
    cronAsk('Đăng xuất khỏi tài khoản Cron?', function() {
    fetch('?', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams({
        action: 'logout',
        csrf_token: window.cronCsrf
      }).toString()
    }).then(function() { location.reload(); });
    }, false);
  });
}

var cronBulkForm = document.getElementById('cronBulkForm');
var cronRows = Array.from(document.querySelectorAll('[data-cron-row]'));
function cronSelectedRows() {
  return cronRows.filter(function(row) { return !row.hidden && row.querySelector('[name="ids[]"]').checked; });
}
function updateCronSelection() {
  if (!cronBulkForm) return;
  var visible = cronRows.filter(function(row) { return !row.hidden; });
  var selected = cronSelectedRows();
  var all = document.getElementById('cronSelectAll');
  all.checked = visible.length > 0 && selected.length === visible.length;
  all.indeterminate = selected.length > 0 && selected.length < visible.length;
  all.disabled = !visible.length;
  document.getElementById('cronSelectedCount').textContent = 'Đã chọn ' + selected.length;
  document.getElementById('cronClearSelection').disabled = !selected.length;
  document.getElementById('cronCopyUrls').disabled = !selected.length;
  cronRows.forEach(function(row) {
    row.classList.toggle('is-selected', row.querySelector('[name="ids[]"]').checked);
  });
  cronBulkForm.querySelectorAll('[name="bulk_action"]').forEach(function(button) {
    var state = button.value === 'start' ? '0' : (button.value === 'pause' ? '1' : null);
    button.disabled = !selected.length || selected.length > 500 || (state !== null && !selected.some(function(row) { return row.dataset.active === state; }));
  });
}
function filterCronRows() {
  if (!cronBulkForm) return;
  var query = document.getElementById('cronSearch').value.trim().toLocaleLowerCase('vi');
  var state = document.getElementById('cronStatusFilter').value;
  cronRows.forEach(function(row) {
    var text = row.querySelector('.cron-task-name').textContent + ' ' + row.querySelector('.cron-url').title + ' #' + row.dataset.cronRow;
    row.hidden = !((state === 'all' || row.dataset.active === state) && text.toLocaleLowerCase('vi').includes(query));
    if (row.hidden) row.querySelector('[name="ids[]"]').checked = false;
  });
  var count = cronRows.filter(function(row) { return !row.hidden; }).length;
  document.getElementById('cronVisibleCount').textContent = count + '/' + cronRows.length + ' task';
  document.getElementById('cronNoMatches').hidden = count > 0;
  updateCronSelection();
}
if (cronBulkForm) {
  document.getElementById('cronSearch').addEventListener('input', filterCronRows);
  document.getElementById('cronStatusFilter').addEventListener('change', filterCronRows);
  document.getElementById('cronSelectAll').addEventListener('change', function(event) {
    cronRows.forEach(function(row) { if (!row.hidden) row.querySelector('[name="ids[]"]').checked = event.target.checked; });
    updateCronSelection();
  });
  document.getElementById('cronClearSelection').addEventListener('click', function() {
    cronRows.forEach(function(row) { row.querySelector('[name="ids[]"]').checked = false; });
    updateCronSelection();
  });
  cronRows.forEach(function(row) { row.querySelector('[name="ids[]"]').addEventListener('change', updateCronSelection); });
  document.getElementById('cronCopyUrls').addEventListener('click', function() {
    var urls = cronSelectedRows().map(function(row) { return row.querySelector('.cron-url').title; }).join('\n');
    var notice = document.getElementById('cronBulkNotice');
    if (!navigator.clipboard) {
      notice.textContent = 'Trình duyệt chưa cho phép sao chép. Hãy mở trang bằng HTTPS.';
      return;
    }
    navigator.clipboard.writeText(urls).then(function() {
      notice.textContent = 'Đã copy ' + cronSelectedRows().length + ' URL.';
    }).catch(function() { notice.textContent = 'Không copy được. Hãy cấp quyền clipboard cho trang.'; });
  });
  cronBulkForm.addEventListener('submit', function(event) {
    var selected = cronSelectedRows();
    var operation = event.submitter ? event.submitter.value : '';
    document.getElementById('cronBulkInterval').required = false;
    if (!selected.length || selected.length > 500 || !operation) {
      event.preventDefault();
      document.getElementById('cronBulkNotice').textContent = 'Chọn từ 1 đến 500 task rồi chọn thao tác.';
      return;
    }
    if (operation === 'interval') {
      var input = document.getElementById('cronBulkInterval');
      input.required = true;
      if (!input.reportValidity()) { event.preventDefault(); return; }
    }
    event.preventDefault();
    var messages = {
      start: 'Chạy ' + selected.length + ' task đã chọn?',
      pause: 'Dừng ' + selected.length + ' task đã chọn? Các lượt đang chạy có thể vẫn hoàn tất.',
      delete: 'Xóa ' + selected.length + ' task đã chọn và toàn bộ lịch sử của chúng? Không thể hoàn tác.',
      interval: 'Đổi chu kỳ của ' + selected.length + ' task đã chọn thành ' + document.getElementById('cronBulkInterval').value + ' giây?'
    };
    cronAsk(messages[operation], function() {
      cronBulkForm.setAttribute('aria-busy', 'true');
      cronSubmitConfirmed(cronBulkForm, event.submitter);
    }, operation === 'delete');
  });
  document.getElementById('cronBulkInterval').addEventListener('input', function() { this.required = false; });
  updateCronSelection();
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
        var row = document.querySelector('[data-cron-row="' + item.id + '"]');
        if (row) {
          row.dataset.active = item.active ? '1' : '0';
          var toggle = row.querySelector('form input[value="toggle"]').form.querySelector('button');
          toggle.title = item.active ? 'Dừng task' : 'Chạy task';
          toggle.setAttribute('aria-label', toggle.title);
        }
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
            ? '<i class="fas fa-circle"></i> Đang bật'
            : '<i class="fas fa-pause"></i> Tạm dừng';
        }
      });

      if (!cronConfirmDialog.open) filterCronRows();
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
