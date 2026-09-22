<?php
require_once dirname(__DIR__) . '/env.php';
secureSessionStart();
/**
 * Tool: Cắt dữ liệu theo delimiter
 * - Range: lấy cột từ x → y
 * - List: lấy theo danh sách vị trí (vd: 1,3,5)
 * - Dedupe: loại dòng trùng
 * - UI: Tailwind đẹp + tabs + thống kê + copy + download
 * - Icon: Font Awesome (icon fonts)
 */

$result = '';
$error  = '';

if (isset($_POST['trigger']) || isset($_POST['download'])) {
    dvproRequireCsrf();
}

if (isset($_POST['trigger'])) {
    $raw = (string)($_POST['data'] ?? '');
    $raw = str_replace("\r\n", "\n", $raw);

    $lines = array_filter(array_map('trim', explode("\n", trim($raw))), fn($v) => $v !== '');

    // delimiter: cho phép "\t" hoặc "TAB"
    $delimiterInput = (string)($_POST['delimiter'] ?? '|');
    $delimiterInput = $delimiterInput === '' ? '|' : $delimiterInput;

    $delimiter = $delimiterInput;
    if (strtolower($delimiterInput) === 'tab' || $delimiterInput === '\t') {
        $delimiter = "\t";
    }

    $cutType = (($_POST['cut_type'] ?? 'range') === 'list') ? 'list' : 'range';

    if (!empty($_POST['dedupe'])) {
        $lines = array_values(array_unique($lines));
    }

    $output = [];

    foreach ($lines as $line) {
        $parts = explode($delimiter, $line);

        if ($cutType === 'range') {
            $start = max(1, (int)($_POST['start'] ?? 1)) - 1;
            $end   = max($start, (int)($_POST['end'] ?? 1)) - 1;

            $slice = array_slice($parts, $start, $end - $start + 1);
            $output[] = implode($delimiter, $slice);
        } else {
            $listRaw = (string)($_POST['list'] ?? '');
            preg_match_all('/\d+/', $listRaw, $m);
            $indexes = array_map('intval', $m[0] ?? []);

            $picked = [];
            foreach ($indexes as $i) {
                $idx = $i - 1;
                if ($idx >= 0 && array_key_exists($idx, $parts)) {
                    $picked[] = $parts[$idx];
                }
            }
            $output[] = implode($delimiter, $picked);
        }
    }

    $result = implode("\n", $output);

    // download
    if (isset($_POST['download']) && $result !== '') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="cut-result.txt"');
        echo $result;
        exit;
    }
}

$title = "Công cụ cắt dữ liệu";
$description = "Cắt dữ liệu theo delimiter: lấy cột từ x → y hoặc theo danh sách cột, loại dòng trùng, copy và download kết quả.";

$dataValue   = (string)($_POST['data'] ?? '');
$resultValue = (string)($result ?? '');

$normInput   = trim(str_replace("\r\n", "\n", $dataValue));
$inputLines  = $normInput !== '' ? count(array_filter(explode("\n", $normInput), fn($v) => trim($v) !== '')) : 0;

$normResult  = trim(str_replace("\r\n", "\n", $resultValue));
$resultLines = $normResult !== '' ? count(array_filter(explode("\n", $normResult), fn($v) => trim($v) !== '')) : 0;

$delimiterUi = (string)($_POST['delimiter'] ?? '|');
$cutTypeUi   = (($_POST['cut_type'] ?? 'range') === 'list') ? 'list' : 'range';
$startUi     = (int)($_POST['start'] ?? 1);
$endUi       = (int)($_POST['end'] ?? 1);
$listUi      = (string)($_POST['list'] ?? '');
$dedupeUi    = !empty($_POST['dedupe']);

ob_start();
?>
<div class="page-wrap cut-page">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon indigo"><i class="fa-solid fa-scissors"></i></div>
      <div>
        <h1>Công cụ cắt dữ liệu</h1>
        <p>Cắt theo <strong>từ x → y</strong> hoặc theo <strong>danh sách cột</strong>. Hỗ trợ copy & download.</p>
      </div>
    </div>
    <div class="tool-hero-actions">
      <span class="pill pill-slate"><i class="fa-regular fa-file-lines"></i> Input: <b><?= (int)$inputLines ?></b> dòng</span>
      <span class="pill pill-blue"><i class="fa-regular fa-circle-check"></i> Output: <b><?= (int)$resultLines ?></b> dòng</span>
    </div>
  </div>

  <form method="post" class="tool-panel cut-panel">
    <?= dvproCsrfField() ?>
    <div class="tool-panel-head">
      <h2><i class="fa-solid fa-sliders text-indigo-300"></i> Thiết lập</h2>
      <label class="cut-check">
        <input type="checkbox" name="dedupe" value="1" <?= $dedupeUi ? 'checked' : '' ?>>
        <span><i class="fa-solid fa-filter"></i> Loại bỏ dòng trùng</span>
      </label>
    </div>

    <div class="cut-grid">
      <section class="cut-box">
        <div class="cut-box-head">
          <div class="cut-box-title"><i class="fa-solid fa-clipboard-list text-emerald-300"></i> Nội dung</div>
          <div class="toolbar">
            <button type="button" class="btn btn-secondary btn-sm" data-action="copy-text" data-target="inputData"><i class="fa-regular fa-copy"></i> Sao chép</button>
            <button type="button" class="btn btn-secondary btn-sm" data-action="clear-input" data-target="inputData"><i class="fa-solid fa-broom"></i> Xoá</button>
          </div>
        </div>
        <textarea id="inputData" name="data" class="textarea pretty-scroll cut-area" rows="14" spellcheck="false" placeholder="DAAAAE|user1|pass1"><?= htmlspecialchars($dataValue, ENT_QUOTES, 'UTF-8') ?></textarea>
      </section>

      <section class="cut-box">
        <div class="cut-box-head">
          <div class="cut-box-title"><i class="fa-solid fa-square-check text-indigo-300"></i> Kết quả</div>
          <div class="toolbar">
            <button type="button" class="btn btn-secondary btn-sm" data-action="copy-text" data-target="resultData"><i class="fa-regular fa-copy"></i> Sao chép</button>
            <button type="submit" name="download" value="1" class="btn btn-primary btn-sm" <?= $resultValue === '' ? 'disabled' : '' ?>><i class="fa-solid fa-download"></i> Download</button>
          </div>
        </div>
        <textarea id="resultData" class="textarea pretty-scroll cut-area" rows="14" readonly spellcheck="false" placeholder="Kết quả sẽ hiện ở đây..."><?= htmlspecialchars($resultValue, ENT_QUOTES, 'UTF-8') ?></textarea>
      </section>
    </div>

    <div class="cut-settings">
      <div class="field">
        <label for="delimiter">Ký tự phân tách</label>
        <input id="delimiter" class="input font-mono" type="text" name="delimiter" value="<?= htmlspecialchars($delimiterUi, ENT_QUOTES, 'UTF-8') ?>" placeholder="|">
        <div class="hint">Ví dụ: <code>|</code>, <code>,</code>, <code>:</code>, <code>TAB</code></div>
      </div>

      <div class="field">
        <label>Chế độ cắt</label>
        <div class="cut-modes">
          <label class="cut-mode <?= $cutTypeUi === 'range' ? 'active' : '' ?>">
            <input type="radio" name="cut_type" value="range" <?= $cutTypeUi === 'range' ? 'checked' : '' ?> onchange="toggleCutType()">
            <span>
              <strong><i class="fa-solid fa-arrows-left-right"></i> Từ x → y</strong>
              <small>Lấy liên tiếp các cột từ vị trí x đến y.</small>
            </span>
          </label>
          <label class="cut-mode <?= $cutTypeUi === 'list' ? 'active' : '' ?>">
            <input type="radio" name="cut_type" value="list" <?= $cutTypeUi === 'list' ? 'checked' : '' ?> onchange="toggleCutType()">
            <span>
              <strong><i class="fa-solid fa-list-ol"></i> Theo danh sách</strong>
              <small>Chọn cột theo danh sách: 1,3,5...</small>
            </span>
          </label>
        </div>
      </div>

      <div class="cut-range" id="rangeFields" style="<?= $cutTypeUi === 'list' ? 'display:none' : '' ?>">
        <div class="field">
          <label for="start">Bắt đầu (x)</label>
          <input id="start" class="input" type="number" min="1" name="start" value="<?= (int)$startUi ?>">
        </div>
        <div class="field">
          <label for="end">Kết thúc (y)</label>
          <input id="end" class="input" type="number" min="1" name="end" value="<?= (int)$endUi ?>">
        </div>
      </div>

      <div class="field" id="listField" style="<?= $cutTypeUi === 'list' ? '' : 'display:none' ?>">
        <label for="list">Danh sách cột</label>
        <input id="list" class="input font-mono" type="text" name="list" value="<?= htmlspecialchars($listUi, ENT_QUOTES, 'UTF-8') ?>" placeholder="1,3,5">
        <div class="hint">Nhập vị trí cột, cách nhau bằng dấu phẩy.</div>
      </div>

      <div class="cut-actions">
        <button type="submit" name="trigger" value="1" class="btn btn-primary btn-lg">
          <i class="fa-solid fa-bolt"></i> Bắt đầu
        </button>
      </div>
    </div>
  </form>
</div>

<script <?= function_exists('dvproNonceAttr') ? dvproNonceAttr() : '' ?>>
function copyText(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  var text = el.value || '';
  navigator.clipboard.writeText(text).then(function () {
    if (!btn) return;
    var old = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Đã copy';
    btn.disabled = true;
    setTimeout(function () {
      btn.innerHTML = old;
      btn.disabled = false;
    }, 1200);
  }).catch(function () {
    el.focus();
    el.select();
    document.execCommand('copy');
  });
}

function clearInput() {
  var el = document.getElementById('inputData');
  if (el) {
    el.value = '';
    el.focus();
  }
}

function toggleCutType() {
  var type = (document.querySelector('input[name="cut_type"]:checked') || {}).value || 'range';
  var rangeFields = document.getElementById('rangeFields');
  var listField = document.getElementById('listField');
  if (rangeFields) rangeFields.style.display = type === 'range' ? '' : 'none';
  if (listField) listField.style.display = type === 'list' ? '' : 'none';
  document.querySelectorAll('.cut-mode').forEach(function (el) {
    var input = el.querySelector('input[type="radio"]');
    el.classList.toggle('active', !!(input && input.checked));
  });
}

document.querySelectorAll('input[name="cut_type"]').forEach(function (el) {
  el.addEventListener('change', toggleCutType);
});
toggleCutType();
</script>
<?php
$content = ob_get_clean();
include dirname(__DIR__) . '/layout.php';
