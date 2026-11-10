<?php
require_once dirname(__DIR__) . '/env.php';
secureSessionStart();
$title = "Check Video TikTok • DVPro Tools";
$description = "Dán nhiều link TikTok để lấy thông tin video: author, mô tả, like, comment, share, play.";
$content = <<<'HTML'
<div class="page-wrap ttkv-page">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon pink"><i class="fas fa-video"></i></div>
      <div>
        <h1>Check Video TikTok</h1>
        <p>Dán nhiều link TikTok — mỗi link 1 dòng để lấy thông tin video nhanh.</p>
      </div>
    </div>
    <div class="tool-hero-actions">
      <span class="pill pill-pink">TikTok</span>
      <span class="pill pill-blue">Batch</span>
    </div>
  </div>

  <section class="tool-panel">
    <div class="tool-panel-head">
      <h2><i class="fas fa-link text-pink-300"></i> Danh sách link video</h2>
      <span class="pill pill-slate">Mỗi link 1 dòng</span>
    </div>
    <textarea aria-label="Danh sách link video TikTok" id="video_url" class="textarea pretty-scroll ttkv-input" rows="8" spellcheck="false" placeholder="https://www.tiktok.com/@user/video/..."></textarea>
    <div class="toolbar mt-4">
      <button id="startBtn" class="btn btn-pink" type="button"><i class="fas fa-play"></i> Kiểm tra</button>
      <button id="clearBtn" class="btn btn-secondary" type="button"><i class="fas fa-trash-alt"></i> Clear</button>
      <div class="spacer"></div>
      <div class="ttkv-progress">
        <div class="progress-shell"><div id="progressBar" class="progress-bar ttkv-progress-bar"></div></div>
      </div>
    </div>
    <div class="stats-row mt-5">
      <div class="stat-card live"><strong id="sumOk">0</strong><span>Success</span></div>
      <div class="stat-card die"><strong id="sumFail">0</strong><span>Failed</span></div>
      <div class="stat-card total"><strong id="sumTotal">0</strong><span>Total</span></div>
    </div>
  </section>

  <section class="tool-panel ttkv-result-panel">
    <div class="tool-panel-head">
      <h2><i class="fas fa-table text-blue-300"></i> Kết quả</h2>
      <div class="toolbar">
        <button id="copySelectedIds" class="btn btn-secondary" type="button" disabled><i class="fas fa-copy"></i> Copy ID đã chọn (<span id="selectedIdCount">0</span>)</button>
        <span id="copyIdStatus" role="status" aria-live="polite"></span>
      </div>
    </div>
    <p class="empty-table-note">Chưa có kết quả. Nhập link video rồi bấm Kiểm tra.</p>
    <div class="table-wrap ttkv-table-wrap" role="region" aria-label="Kết quả video TikTok" tabindex="0">
      <table class="ttkv-table">
        <thead>
          <tr>
            <th><input id="selectAllIds" type="checkbox" aria-label="Chọn tất cả ID" disabled></th>
            <th>Video</th>
            <th>ID</th>
            <th>Desc</th>
            <th>Like</th>
            <th>Comment</th>
            <th>Share</th>
            <th>Play</th>
            <th>Thao tác</th>
          </tr>
        </thead>
        <tbody id="resultTable"></tbody>
      </table>
    </div>
  </section>
</div>
<script __DVPRO_NONCE_ATTR__>
window.dvproCsrf = __DVPRO_CSRF_JSON__;

const startBtn = document.getElementById("startBtn")
const clearBtn = document.getElementById("clearBtn")
const ta = document.getElementById("video_url")

const progressBar = document.getElementById("progressBar")
const resultTable = document.getElementById("resultTable")
const selectAllIds = document.getElementById("selectAllIds")
const copySelectedIds = document.getElementById("copySelectedIds")
const selectedIdCount = document.getElementById("selectedIdCount")
const copyIdStatus = document.getElementById("copyIdStatus")

function updateIdSelection() {
    const boxes = [...resultTable.querySelectorAll('.ttkv-row-select:not(:disabled)')];
    const selected = boxes.filter(box => box.checked).length;
    selectAllIds.disabled = boxes.length === 0;
    selectAllIds.checked = boxes.length > 0 && selected === boxes.length;
    selectAllIds.indeterminate = selected > 0 && selected < boxes.length;
    copySelectedIds.disabled = selected === 0;
    selectedIdCount.textContent = String(selected);
    copyIdStatus.textContent = '';
}

selectAllIds.onchange = () => {
    resultTable.querySelectorAll('.ttkv-row-select:not(:disabled)').forEach(box => {
        box.checked = selectAllIds.checked;
    });
    updateIdSelection();
};

resultTable.addEventListener('change', event => {
    if (event.target.matches('.ttkv-row-select')) updateIdSelection();
});

copySelectedIds.onclick = async () => {
    const ids = [...resultTable.querySelectorAll('.ttkv-row-select:checked:not(:disabled)')]
        .map(box => box.value);
    if (ids.length === 0) return;
    try {
        await navigator.clipboard.writeText(ids.join('\n'));
        copyIdStatus.textContent = `Đã copy ${ids.length} ID`;
    } catch {
        copyIdStatus.textContent = 'Không copy được. Vui lòng cho phép truy cập clipboard rồi thử lại.';
    }
};

let success = 0
let fail = 0
let total = 0
let batchRunning = false
let rechecksRunning = 0

function updateCheckControls() {
    startBtn.disabled = batchRunning || rechecksRunning > 0;
    clearBtn.disabled = batchRunning || rechecksRunning > 0;
}

function updateStats(){

document.getElementById("sumOk").innerText = success
document.getElementById("sumFail").innerText = fail
document.getElementById("sumTotal").innerText = total

}


function newProxyBatch(){
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
}

async function checkLink(link, force = false, proxyBatch = newProxyBatch()){
    const fd = new FormData();
    fd.append("video_url", link);
    fd.append("force", force ? "1" : "0");
    fd.append("proxy_batch", proxyBatch);

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), __VIDEO_TT_REQUEST_TIMEOUT_MS__);
    try {
        const res = await fetch("./php/logic.php?action=check_tiktok", {
            method: "POST",
            headers: {"X-CSRF-Token": (window.dvproCsrf || "")},
            body: fd,
            signal: controller.signal
        });
        const body = await res.text();
        let result = null;
        for (const line of body.split(/\r?\n/)) {
            if (!line.trim()) continue;
            let data;
            try {
                data = JSON.parse(line);
            } catch {
                throw new Error(`Phản hồi máy chủ không hợp lệ (HTTP ${res.status})`);
            }
            if (data.type === "row") {
                result = data;
            } else if (data.error || data.message) {
                result = {status: 'ERROR', error: data.error || data.message};
            }
        }
        if (!result) {
            throw new Error(`Không nhận được kết quả kiểm tra (HTTP ${res.status})`);
        }
        return result;
    } catch (error) {
        if (error.name === 'AbortError') throw new Error('Hết thời gian chờ máy chủ. Vui lòng Recheck.');
        throw error;
    } finally {
        clearTimeout(timer);
    }
}

function renderResultRow(row, link, r, selected = false) {
    row.replaceChildren();
    row.dataset.status = r.status;
    const selectCell = document.createElement('td');
    selectCell.className = 'p-4 text-center';
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'ttkv-row-select';
    checkbox.value = String(r.item_id ?? '');
    checkbox.disabled = r.status !== 'SUCCESS' || !/^\d+$/.test(checkbox.value);
    checkbox.checked = !checkbox.disabled && selected;
    checkbox.setAttribute('aria-label', checkbox.disabled ? 'Dòng không có ID' : `Chọn ID ${checkbox.value}`);
    selectCell.appendChild(checkbox);
    row.appendChild(selectCell);
    const statusText = r.status === 'SUCCESS'
        ? 'SUCCESS'
        : `ERROR: ${r.error || 'Không nhận được thống kê TikTok'}`;
    const values = [link, r.item_id ?? '-', statusText, r.digg_count ?? '-',
        r.comment_count ?? '-', r.share_count ?? '-', r.play_count ?? '-'];
    values.forEach((value, column) => {
        const cell = document.createElement('td');
        cell.className = column === 0 ? 'p-4 text-pink-400 break-all' : 'p-4 text-center';
        if (column === 1) cell.classList.add('text-yellow-400');
        if (column === 2) cell.classList.add('ttkv-row-status', r.status === 'SUCCESS' ? 'text-green-400' : 'text-red-400');
        cell.textContent = String(value);
        row.appendChild(cell);
    });
    const actionCell = document.createElement('td');
    actionCell.className = 'p-4 text-center';
    const recheckBtn = document.createElement('button');
    recheckBtn.type = 'button';
    recheckBtn.className = 'btn btn-secondary ttkv-recheck';
    recheckBtn.textContent = 'Recheck';
    recheckBtn.setAttribute('aria-label', `Kiểm tra lại ${link}`);
    recheckBtn.onclick = () => recheckRow(row, link, recheckBtn);
    actionCell.appendChild(recheckBtn);
    row.appendChild(actionCell);
}

async function recheckRow(row, link, button) {
    if (button.disabled) return;
    button.disabled = true;
    button.textContent = 'Đang check...';
    row.querySelector('.ttkv-row-status').textContent = 'Đang kiểm tra lại...';
    rechecksRunning++;
    updateCheckControls();
    const wasSuccessful = row.dataset.status === 'SUCCESS';
    try {
        let r;
        try {
            r = await checkLink(link, true);
        } catch (e) {
            r = {status: 'ERROR', error: e.message || 'Lỗi mạng'};
        }
        if (wasSuccessful) success--; else fail--;
        if (r.status === 'SUCCESS') success++; else fail++;
        const selected = row.querySelector('.ttkv-row-select').checked;
        renderResultRow(row, link, r, selected);
        updateStats();
        updateIdSelection();
    } finally {
        rechecksRunning--;
        updateCheckControls();
    }
}

startBtn.onclick = async () => {
    if (startBtn.disabled) return;
    let raw = ta.value.trim();
    if (!raw) return alert('Nhập link');

    let links = raw.split('\n').map(x => x.trim()).filter(Boolean);
    total = links.length;
    success = 0;
    fail = 0;
    updateStats();
    resultTable.innerHTML = '';
    updateIdSelection();
    batchRunning = true;
    updateCheckControls();
    startBtn.innerText = 'Đang kiểm tra...';

    const concurrent = __VIDEO_TT_CONCURRENT__;
    const proxyBatch = newProxyBatch();
    let nextIndex = 0;
    let checked = 0;

    async function worker() {
        while (true) {
            const index = nextIndex++;
            if (index >= links.length) return;
            const link = links[index];
            let r;
            try {
                r = await checkLink(link, false, proxyBatch);
                if (r.status === 'SUCCESS') success++; else fail++;
            } catch (e) {
                r = { status: 'ERROR', error: e.message || 'Lỗi mạng' };
                fail++;
            }

            checked++;
            updateStats();
            progressBar.style.width = (checked / total * 100) + '%';
            const row = document.createElement('tr');
            row.className = 'border-b border-gray-800';
            renderResultRow(row, link, r, selectAllIds.checked);
            resultTable.appendChild(row);
            updateIdSelection();
        }
    }

    try {
        await Promise.all(Array.from({ length: Math.min(concurrent, links.length) }, () => worker()));
    } finally {
        batchRunning = false;
        updateCheckControls();
        startBtn.innerText = 'Kiểm tra';
    }
};

clearBtn.onclick = () => {

if(clearBtn.disabled) return

ta.value=""
resultTable.innerHTML=""
updateIdSelection()

progressBar.style.width="0%"

success=0
fail=0
total=0

updateStats()

}

</script>
HTML;
$content = str_replace(['__DVPRO_NONCE_ATTR__', '__DVPRO_CSRF_JSON__', '__VIDEO_TT_CONCURRENT__', '__VIDEO_TT_REQUEST_TIMEOUT_MS__'], [function_exists('dvproNonceAttr') ? dvproNonceAttr() : '', json_encode(function_exists('dvproCsrfToken') ? dvproCsrfToken() : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), max(1, min(10, (int)dvproEnv('VIDEO_TIKTOK_CONCURRENT', '5'))), (max(10, min(90, (int)dvproEnv('VIDEO_TIKTOK_PROXY_TOTAL_TIMEOUT', '45'))) + 10) * 1000], $content);
include '../layout.php';
