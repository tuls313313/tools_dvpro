<?php
// /tools/2fa/index.php
require_once __DIR__ . '/../env.php';
secureSessionStart();
$title = "TOTP / 2FA Generator • DVPro Tools";

/**
 * ====== TOTP CORE (PHP thuần) ======
 */
function base32_decode_strict(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    // bỏ khoảng trắng, dấu '=' và ký tự lạ
    $b32 = preg_replace('/[^A-Z2-7]/', '', $b32);

    if ($b32 === '') return '';

    $bits = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $ch = $b32[$i];
        $pos = strpos($alphabet, $ch);
        if ($pos === false) throw new InvalidArgumentException("Invalid Base32 char: {$ch}");
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $binary = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $binary .= chr(bindec(substr($bits, $i, 8)));
    }
    return $binary;
}

function totp(string $secretBase32, ?int $time = null, int $digits = 6, int $period = 30, string $algo = 'sha1'): string {
    $time = $time ?? time();
    $counter = intdiv($time, $period);

    $key = base32_decode_strict($secretBase32);
    if ($key === '') throw new InvalidArgumentException("Secret rỗng/không hợp lệ.");

    // Counter 8 bytes big-endian
    $binCounter = pack('N*', 0) . pack('N*', $counter);

    $hash = hash_hmac($algo, $binCounter, $key, true);

    $offset = ord(substr($hash, -1)) & 0x0F;
    $part = substr($hash, $offset, 4);
    $value = unpack('N', $part)[1] & 0x7FFFFFFF;

    $mod = 10 ** $digits;
    return str_pad((string)($value % $mod), $digits, '0', STR_PAD_LEFT);
}

function totp_remaining_seconds(?int $time = null, int $period = 30): int {
    $time = $time ?? time();
    return $period - ($time % $period);
}

/**
 * ====== AJAX ENDPOINT (JSON) ======
 * POST: secret, digits(6/8), period
 * return: { ok: true, otp, remain, server_time }
 */
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    dvproNoStoreHeaders();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!dvproRateLimit('2fa:' . dvproClientIp(), (int)dvproEnv('TWOFA_RATE_LIMIT', '60'), (int)dvproEnv('TWOFA_RATE_WINDOW', '60'))) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    dvproRequireCsrf();

    try {
        $secret = trim($_POST['secret'] ?? '');
        $digits = (int)($_POST['digits'] ?? 6);
        $digits = in_array($digits, [6, 8], true) ? $digits : 6;

        $period = (int)($_POST['period'] ?? 30);
        $period = max(10, $period);

        if (strlen($secret) > 256) {
            throw new InvalidArgumentException('Secret qua dai.');
        }
        if ($secret === '') {
            throw new InvalidArgumentException("Bạn chưa nhập secret.");
        }

        $now = time();
        $otp = totp($secret, $now, $digits, $period, 'sha1');
        $remain = totp_remaining_seconds($now, $period);

        echo json_encode([
            'ok' => true,
            'otp' => $otp,
            'remain' => $remain,
            'server_time' => $now,
            'digits' => $digits,
            'period' => $period,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * ====== PAGE UI ======
 */
$content = <<<'HTML'
<div class="page-wrap">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon pink"><i class="fas fa-shield-halved"></i></div>
      <div>
        <h1>TOTP / 2FA Generator</h1>
        <p>Nhập <strong>secret Base32</strong> để lấy OTP hiện tại. AJAX - không reload.</p>
      </div>
    </div>
    <div class="tool-hero-actions"><span class="pill pill-pink">Security</span></div>
  </div>

  <section class="tool-panel">
    <form id="otpForm" class="space-y-5" autocomplete="off">
      <div class="field">
        <label>Secret (Base32)</label>
        <input id="secret" name="secret" class="input" placeholder="VD: JBSWY3DPEHPK3PXP" />
        <div class="hint">Secret thường gồm A-Z và 2-7 (có thể có dau =, hệ thống tự bỏ).</div>
      </div>

      <div class="tool-grid-2">
        <div class="field">
          <label>Digits</label>
          <select id="digits" name="digits" class="input">
            <option value="6" selected>6</option>
            <option value="8">8</option>
          </select>
        </div>
        <div class="field">
          <label>Period (seconds)</label>
          <input id="period" type="number" min="10" name="period" value="30" class="input" />
        </div>
      </div>

      <div class="toolbar">
        <button id="btnGen" type="submit" class="btn btn-pink btn-lg"><i class="fas fa-key"></i> Lấy OTP</button>
        <button id="btnCopy" type="button" class="btn btn-secondary" disabled><i class="fas fa-copy"></i> Copy OTP</button>
        <button id="btnAuto" type="button" class="btn btn-secondary"><i class="fas fa-rotate"></i> Auto: OFF</button>
      </div>

      <div id="alert" class="alert alert-error hidden">
        <div class="font-semibold">Lỗi</div>
        <div id="alertMsg" class="text-sm mt-1"></div>
      </div>
    </form>

    <div id="result" class="hidden mt-5 card-soft p-5">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <div class="text-xs text-gray-400">OTP hiện tại</div>
          <div id="otpValue" class="mt-1 text-4xl tracking-widest font-extrabold text-white">--</div>
          <div class="mt-2 text-sm text-gray-400">
            Còn lại: <span id="remainSec" class="text-pink-300 font-semibold">0</span>s trước khi đổi mã
          </div>
          <div class="mt-2 text-xs text-gray-500">
            Server time: <span id="serverTime">-</span>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span id="toast" class="hidden px-4 py-2 rounded-xl border border-green-500/30 bg-green-500/10 text-green-300 text-sm"></span>
        </div>
      </div>
    </div>
  </section>
</div>

<script __DVPRO_NONCE_ATTR__>
(() => {
  const form = document.getElementById('otpForm');
  const secretEl = document.getElementById('secret');
  const digitsEl = document.getElementById('digits');
  const periodEl = document.getElementById('period');

  const btnGen  = document.getElementById('btnGen');
  const btnCopy = document.getElementById('btnCopy');
  const btnAuto = document.getElementById('btnAuto');

  const alertBox = document.getElementById('alert');
  const alertMsg = document.getElementById('alertMsg');

  const resultBox = document.getElementById('result');
  const otpValue  = document.getElementById('otpValue');
  const remainSec = document.getElementById('remainSec');
  const serverTime = document.getElementById('serverTime');

  const toast = document.getElementById('toast');

  let lastOtp = '';
  let autoOn = false;
  let tickTimer = null;      // countdown timer
  let autoTimer = null;      // auto refresh timer

  function showError(msg) {
    alertMsg.textContent = msg;
    alertBox.classList.remove('hidden');
  }
  function clearError() {
    alertBox.classList.add('hidden');
    alertMsg.textContent = '';
  }
  function showToast(msg, ok = true) {
    toast.classList.remove('hidden');
    toast.classList.toggle('border-green-500/30', ok);
    toast.classList.toggle('bg-green-500/10', ok);
    toast.classList.toggle('text-green-300', ok);

    toast.classList.toggle('border-red-500/30', !ok);
    toast.classList.toggle('bg-red-500/10', !ok);
    toast.classList.toggle('text-red-300', !ok);

    toast.textContent = msg;
    setTimeout(() => toast.classList.add('hidden'), 1800);
  }

  function setLoading(on) {
    btnGen.disabled = on;
    btnGen.textContent = on ? 'Đang lấy OTP...' : 'Lấy OTP';
  }

  function stopTimers() {
    if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
    if (autoTimer) { clearTimeout(autoTimer); autoTimer = null; }
  }

  function startCountdown(sec) {
    stopTimers();
    let s = Math.max(0, parseInt(sec, 10) || 0);
    remainSec.textContent = String(s);

    tickTimer = setInterval(() => {
      s = Math.max(0, s - 1);
      remainSec.textContent = String(s);

      // auto bật: đến 0 thì tự gọi lại
      if (autoOn && s <= 0) {
        clearInterval(tickTimer);
        tickTimer = null;
        // gọi lại sau 200ms cho chắc (tránh lệch tick)
        autoTimer = setTimeout(() => fetchOtp(true), 200);
      }
    }, 1000);
  }

  const twoFaCsrf = __DVPRO_CSRF_JSON__;
async function fetchOtp(isAuto = false) {
    clearError();
    const secret = (secretEl.value || '').trim();
    const digits = digitsEl.value;
    const period = periodEl.value;

    if (!secret) {
      showError('Bạn chưa nhập secret.');
      return;
    }

    setLoading(!isAuto);
    try {
      const fd = new FormData();
  fd.append('csrf_token', twoFaCsrf || '');
  fd.append('secret', secret);
      fd.append('digits', digits);
      fd.append('period', period);

      const res = await fetch('?ajax=1', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        throw new Error(data?.error || ('HTTP ' + res.status));
      }

      lastOtp = data.otp || '';
      otpValue.textContent = lastOtp || '——';
      serverTime.textContent = data.server_time ? String(data.server_time) : '—';

      resultBox.classList.remove('hidden');
      btnCopy.disabled = !lastOtp;

      startCountdown(data.remain ?? 0);

      if (!isAuto) showToast('Xong!', true);

    } catch (e) {
      showError(e?.message || 'Có lỗi xảy ra.');
      if (!isAuto) showToast('Lỗi!', false);
    } finally {
      setLoading(false);
    }
  }

  btnCopy.addEventListener('click', async () => {
    if (!lastOtp) return;
    try {
      await navigator.clipboard.writeText(lastOtp);
      showToast('Đã copy OTP!', true);
    } catch (e) {
      showToast('Copy thất bại (trình duyệt chặn).', false);
    }
  });

  btnAuto.addEventListener('click', () => {
    autoOn = !autoOn;
    btnAuto.textContent = autoOn ? 'Auto: ON' : 'Auto: OFF';
    btnAuto.classList.toggle('ring-2', autoOn);
    btnAuto.classList.toggle('ring-pink-500/30', autoOn);

    // bật auto mà chưa có otp thì gọi luôn
    if (autoOn && !lastOtp) fetchOtp(false);
    // tắt auto thì chỉ tắt autoTimer, countdown vẫn chạy bình thường
    if (!autoOn && autoTimer) { clearTimeout(autoTimer); autoTimer = null; }
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    fetchOtp(false);
  });
})();
</script>
HTML;

$content = str_replace(['__DVPRO_NONCE_ATTR__', '__DVPRO_CSRF_JSON__'], [function_exists('dvproNonceAttr') ? dvproNonceAttr() : '', json_encode(dvproCsrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $content);
include '../layout.php';
