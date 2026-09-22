<?php
require_once dirname(__DIR__) . '/env.php';
secureSessionStart();

// ===== AJAX HANDLE =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json');
    dvproRequireCsrf();

    $service     = trim($_POST['service'] ?? '');
    $raw_links   = trim($_POST['link'] ?? '');
    $per_link    = (int)($_POST['per_link'] ?? 0);
    $total       = (int)($_POST['total'] ?? 0);
    $mode        = $_POST['mode'] ?? 'link';

    if (!$raw_links || $per_link <= 0 || $total <= 0) {
        echo json_encode([
            'status' => false,
            'message' => 'Thiếu dữ liệu hoặc sai định dạng'
        ]);
        exit;
    }

    $links = array_filter(array_map('trim', explode("\n", $raw_links)));
    $link_count = count($links);

    if ($link_count === 0) {
        echo json_encode([
            'status' => false,
            'message' => 'Không có link hợp lệ'
        ]);
        exit;
    }

    $per_total_each = ceil($total / $link_count);
    $lines = [];

    foreach ($links as $link) {
        $count = ceil($per_total_each / $per_link);
        for ($i = 0; $i < $count; $i++) {
            if ($mode === 'full') {
                $lines[] = $link . "|" . $per_link;
            } elseif ($mode === 'service') {
                if ($service) {
                    $lines[] = $service . "|" . $link . "|" . $per_link;
                } else {
                    $lines[] = $link . "|" . $per_link;
                }
            } else {
                $lines[] = $link;
            }
        }
    }

    echo json_encode([
        'status' => true,
        'result' => implode("\n", $lines),
        'total_lines' => count($lines),
        'total_links' => $link_count
    ]);
    exit;
}

$title = "Tool chia link - DVPro.vn";
$content = <<<'HTML'
<div class="page-wrap">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon cyan"><i class="fas fa-link"></i></div>
      <div>
        <h1>Tool chia link</h1>
        <p>Nhập nhiều link, chọn chế độ xuất và chia đều số lượng theo nhu cầu.</p>
      </div>
    </div>
    <div class="tool-hero-actions">
      <span class="pill pill-blue">Link tools</span>
    </div>
  </div>

  <section class="tool-panel">
    <form id="toolForm" class="space-y-5">
  __DVPRO_CSRF_FIELD__
      <div class="tool-grid-2">
        <div class="field">
          <label>Service (tuỳ chọn)</label>
          <input id="service" name="service" class="input" placeholder="VD: buff, view, like...">
        </div>
        <div class="field">
          <label>Chế độ xuất</label>
          <select id="mode" name="mode" class="input">
            <option value="link">Chỉ link</option>
            <option value="full">Link|số lượng</option>
            <option value="service">Service|Link|số lượng</option>
          </select>
        </div>
      </div>

      <div class="field">
        <label>Nhập nhiều link (mỗi dòng 1 link)</label>
        <textarea id="link" name="link" class="textarea" rows="8" placeholder="https://..."></textarea>
      </div>

      <div class="tool-grid-2">
        <div class="field">
          <label>Số lượng mỗi dòng</label>
          <input id="per_link" name="per_link" type="number" min="1" class="input" placeholder="VD: 100">
        </div>
        <div class="field">
          <label>Tổng số lượng</label>
          <input id="total" name="total" type="number" min="1" class="input" placeholder="VD: 1000">
        </div>
      </div>

      <div class="toolbar">
        <button type="submit" class="btn btn-primary"><i class="fas fa-wand-magic-sparkles"></i> Xử lý link</button>
        <button type="button" class="btn btn-secondary" data-action="reset-form" data-form="toolForm" data-box="resultBox"><i class="fas fa-broom"></i> Xoá</button>
      </div>
    </form>

    <div id="resultBox" class="mt-5"></div>
  </section>
</div>
<script __DVPRO_NONCE_ATTR__>
document.getElementById('toolForm').addEventListener('submit', async function(e){
  e.preventDefault();
  const box = document.getElementById('resultBox');
  box.innerHTML = '<div class="alert alert-info"><i class="fas fa-spinner fa-spin"></i> Đang xử lý...</div>';
  const form = new FormData(this);
  try {
    const res = await fetch('', { method:'POST', body: form });
    const data = await res.json();
    if(!data.status){
      box.innerHTML = `<div class="alert alert-error">${data.message || 'Có lỗi xảy ra'}</div>`;
      return;
    }
    box.innerHTML = `
      <div class="tool-panel" style="padding:1rem;margin-top:0">
        <div class="tool-panel-head">
          <h3><i class="fas fa-check-circle text-green-400"></i> Kết quả</h3>
          <div class="toolbar">
            <span class="pill pill-green">${data.total_lines} dòng</span>
            <span class="pill pill-blue">${data.total_links} link</span>
            <button type="button" class="btn btn-sm btn-secondary" data-action="copy-result" data-target="resultOut"><i class="fas fa-copy"></i> Copy</button>
          </div>
        </div>
        <textarea id="resultOut" class="result-box" rows="12">${data.result || ''}</textarea>
      </div>`;
  } catch (err) {
    box.innerHTML = '<div class="alert alert-error">Không kết nối được server</div>';
  }
});
</script>
HTML;
$content = str_replace(['__DVPRO_CSRF_FIELD__', '__DVPRO_NONCE_ATTR__'], [function_exists('dvproCsrfField') ? dvproCsrfField() : '', function_exists('dvproNonceAttr') ? dvproNonceAttr() : ''], $content);
include '../layout.php';
