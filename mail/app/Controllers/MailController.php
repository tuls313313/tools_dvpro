<?php
require_once dirname(__DIR__, 3) . '/env.php';
require_once __DIR__ . '/../Models/MailModel.php';

class MailController
{
    private function extractEmailOnly(string $account): string
    {
        $account = trim($account);
        if ($account === '') {
            return '';
        }
        $email = trim(explode('|', $account, 2)[0] ?? '');
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    public function index(): void
    {
        if (!function_exists('h')) {
            function h($str)
            {
                return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
            }
        }

        secureSessionStart();
        $csrfToken = dvproCsrfToken();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_single'])) {
            dvproRequireCsrf();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $this->ajaxSingle();
            return;
        }

        $title = "Đọc hòm thư - DVPro.vn";

        ob_start();
        ?>

<style>
.mail-page{max-width:74rem;margin:0 auto;padding:1.25rem 1rem 2rem;color:#fff}
.mail-hero{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1rem}
.mail-hero-main{display:flex;align-items:flex-start;gap:.9rem}
.mail-hero-icon{width:3.1rem;height:3.1rem;border-radius:1.05rem;display:grid;place-items:center;background:linear-gradient(135deg,#f43f5e,#e11d48);color:#fff;font-size:1.15rem;box-shadow:0 12px 28px rgba(225,29,72,.25)}
.mail-hero h1{margin:0;font-size:clamp(1.4rem,2.5vw,1.9rem);font-weight:900;color:#fff}
.mail-hero p{margin:.35rem 0 0;color:#94a3b8;font-size:.92rem;max-width:40rem}
.mail-pills{display:flex;flex-wrap:wrap;gap:.5rem}
.mail-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .7rem;border-radius:999px;font-size:.75rem;font-weight:700;border:1px solid transparent}
.mail-pill.blue{background:rgba(59,130,246,.12);color:#93c5fd;border-color:rgba(59,130,246,.22)}
.mail-pill.green{background:rgba(34,197,94,.12);color:#86efac;border-color:rgba(34,197,94,.22)}
.mail-panel{background:linear-gradient(180deg,rgba(16,24,39,.96),rgba(12,18,32,.94));border:1px solid rgba(148,163,184,.14);border-radius:1.35rem;box-shadow:0 22px 60px rgba(2,8,23,.45);padding:1.15rem;margin-bottom:1rem}
.mail-form-row{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;margin-bottom:.9rem}
.mail-label{font-size:.92rem;font-weight:800;color:#e2e8f0}
.mail-check{width:1.35rem;height:1.35rem;border-radius:.45rem;display:grid;place-items:center;background:rgba(34,197,94,.15);color:#4ade80;font-size:.75rem;font-weight:900;border:1px solid rgba(34,197,94,.25)}
.mail-btn{border:0;border-radius:.9rem;padding:.72rem 1rem;color:#fff;font-size:.88rem;font-weight:800;cursor:pointer;transition:.15s ease;display:inline-flex;align-items:center;gap:.45rem}
.mail-btn:hover{transform:translateY(-1px);opacity:.95}
.mail-btn:disabled{opacity:.6;cursor:not-allowed;transform:none}
.mail-btn-blue{background:linear-gradient(135deg,#0ea5e9,#2563eb);box-shadow:0 10px 24px rgba(37,99,235,.25)}
.mail-btn-green{background:linear-gradient(135deg,#22c55e,#16a34a);box-shadow:0 10px 24px rgba(22,163,74,.25)}
.mail-textarea{width:100%;min-height:230px;resize:vertical;border:1px solid rgba(148,163,184,.16);border-radius:1rem;background:rgba(2,8,23,.42);color:#fff;padding:1rem;outline:none;font-size:.9rem;line-height:1.55;font-weight:500;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
.mail-textarea:focus{border-color:rgba(59,130,246,.55);box-shadow:0 0 0 3px rgba(59,130,246,.18)}
.mail-action{margin-top:.9rem;display:flex;justify-content:flex-end}
.mail-error{margin-top:.9rem;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;padding:.85rem 1rem;border-radius:1rem;font-size:.9rem;font-weight:700}
.mail-loading{display:none;margin-top:1rem;background:rgba(15,23,42,.55);border:1px solid rgba(148,163,184,.14);padding:1rem;border-radius:1rem;font-weight:700;color:#cbd5e1}
.mail-progress{margin-top:.45rem;font-size:.86rem;color:#93c5fd}
.mail-table-wrap{margin-top:1rem;width:100%;overflow-x:auto;border-radius:1.15rem;border:1px solid rgba(148,163,184,.14)}
.mail-table{width:100%;min-width:1100px;border-collapse:separate;border-spacing:0;table-layout:fixed}
.mail-table thead th{background:rgba(30,41,59,.9);color:#e2e8f0;padding:.9rem .8rem;font-size:.8rem;font-weight:800;text-align:left;white-space:nowrap;border-bottom:1px solid rgba(148,163,184,.14)}
.mail-table td{background:rgba(2,8,23,.22);border-top:1px solid rgba(148,163,184,.08);padding:.9rem .8rem;font-size:.82rem;line-height:1.45;font-weight:600;vertical-align:middle;word-break:break-word;overflow-wrap:anywhere;color:#e2e8f0}
.mail-table th:nth-child(1),.mail-table td:nth-child(1){width:22%}
.mail-table th:nth-child(2),.mail-table td:nth-child(2){width:55px;text-align:center}
.mail-table th:nth-child(3),.mail-table td:nth-child(3){width:18%}
.mail-table th:nth-child(4),.mail-table td:nth-child(4){width:120px}
.mail-table th:nth-child(6),.mail-table td:nth-child(6){width:95px;text-align:center}
.mail-table th:nth-child(7),.mail-table td:nth-child(7){width:95px;text-align:center}
.mail-hidden-row{display:none}
.mail-hidden-row.show{display:table-row}
.mail-from-name{font-size:.82rem;font-weight:800;color:#fff}
.mail-from-address{display:block;margin-top:3px;font-size:.75rem;color:#94a3b8}
.mail-time{font-size:.8rem;color:#94a3b8}
.mail-content{max-height:78px;overflow:hidden;color:#cbd5e1}
.mail-detail-btn{display:inline-block;border:none;background:none;color:#34d399;cursor:pointer;font-weight:800;font-size:.75rem;padding:0;margin-left:3px}
.mail-detail-btn:hover{text-decoration:underline}
.mail-code{color:#34d399;font-size:.95rem;font-weight:900;cursor:pointer;white-space:nowrap}
.mail-address{color:#fff;cursor:pointer;font-weight:800}
.mail-address:hover{color:#38bdf8;text-decoration:underline}
.mail-type{display:block;margin-top:4px;color:#34d399;font-size:.72rem;font-weight:800}
.mail-actions{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:nowrap}
.mail-icon-btn{width:32px;height:32px;border:none;border-radius:.75rem;color:#fff;font-size:13px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;line-height:1}
.mail-icon-detail{background:#7c3aed}
.mail-icon-more{background:#0284c7}
.mail-icon-refresh{background:#16a34a}
.mail-icon-btn:hover{opacity:.88;transform:translateY(-1px)}
.mail-status-row td{background:rgba(15,23,42,.55);color:#dbeafe}
#mailToast{position:fixed;right:20px;bottom:20px;background:#16a34a;color:#fff;padding:11px 15px;border-radius:10px;font-weight:800;font-size:13px;display:none;z-index:9999999;box-shadow:0 12px 30px rgba(0,0,0,.35)}
#mailModal{position:fixed;inset:0;background:rgba(2,8,23,.78);backdrop-filter:blur(8px);z-index:999999;display:none;align-items:center;justify-content:center;padding:18px}
#mailModal.show{display:flex}
.mail-modal-box{width:min(920px,96vw);height:min(860px,92vh);background:linear-gradient(180deg,rgba(16,24,39,.98),rgba(10,16,28,.96));border:1px solid rgba(148,163,184,.16);border-radius:1.35rem;padding:50px 16px 16px;position:relative;display:flex;flex-direction:column;box-shadow:0 25px 80px rgba(0,0,0,.5)}
.mail-modal-title{position:absolute;top:14px;left:18px;font-weight:900;color:#fff}
.mail-close-btn{position:absolute;top:10px;right:12px;width:36px;height:36px;border:0;border-radius:.8rem;background:rgba(148,163,184,.12);color:#e2e8f0;font-size:22px;cursor:pointer}
.mail-frame-wrap{flex:1;border-radius:1rem;overflow:hidden;border:1px solid rgba(148,163,184,.14);background:#0b1220}
#mailFrame{width:100%;height:100%;border:0;background:#fff}
@media (max-width:700px){
  .mail-action{justify-content:stretch}
  .mail-action .mail-btn{width:100%;justify-content:center}
}
</style>
<style>
.pretty-scroll::-webkit-scrollbar{height:10px;width:10px}
.pretty-scroll::-webkit-scrollbar-thumb{background:rgba(100,116,139,.35);border-radius:999px}
.pretty-scroll::-webkit-scrollbar-thumb:hover{background:rgba(100,116,139,.55)}
.pretty-scroll::-webkit-scrollbar-track{background:rgba(148,163,184,.15);border-radius:999px}
</style>
<div class="mail-page">
  <div class="mail-hero">
    <div class="mail-hero-main">
      <div class="mail-hero-icon"><i class="fas fa-envelope"></i></div>
      <div>
        <h1>Đọc hộp thư / OTP</h1>
        <p>Nhập email hoặc chuỗi account để đọc mail, lấy mã OTP và xem nội dung nhanh.</p>
      </div>
    </div>
    <div class="mail-pills">
      <span class="mail-pill blue"><i class="fas fa-bolt"></i> Graph API</span>
      <span class="mail-pill green"><i class="fas fa-shield-halved"></i> OAuth2</span>
    </div>
  </div>

  <div class="mail-panel">
    <form id="mailForm">
      <div class="mail-form-row">
        <span class="mail-label">Nhập Email / Account</span>
        <span class="mail-check">✓</span>
        <button type="button" class="mail-btn mail-btn-blue">Graph API</button>
        <span class="mail-check">✓</span>
        <button type="button" class="mail-btn mail-btn-blue">OAuth2</button>
      </div>

      <textarea
        class="mail-textarea"
        name="accounts"
        id="accounts"
        placeholder="email@example.com&#10;email|refresh_token|client_id&#10;email|password|refresh_token|client_id"
      ></textarea>

      <div class="mail-action">
        <button type="submit" id="readBtn" class="mail-btn mail-btn-green"><i class="fas fa-inbox"></i> Đọc hộm thư</button>
      </div>

      <div id="mailError" class="mail-error" style="display:none"></div>

      <div id="mailLoading" class="mail-loading">
        Đang đọc hộm thư, tối đa 5 luồng cùng lúc...
        <div id="mailProgress" class="mail-progress"></div>
      </div>
    </form>
  </div>

  <div id="mailResult"></div>
</div>

<div id="mailToast"></div>

<div id="mailModal">
    <div class="mail-modal-box">
        <button type="button" class="mail-close-btn" data-action="close-mail-detail">×</button>
        <div class="mail-modal-title">Chi tiết Email</div>

        <div class="mail-frame-wrap">
           <iframe id="mailFrame" sandbox=""></iframe>
        </div>
    </div>
</div>

<script <?= function_exists('dvproNonceAttr') ? dvproNonceAttr() : '' ?>>
const mailCsrf = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';
window.mailCsrf = mailCsrf;
const mailForm = document.getElementById('mailForm');
const readBtn = document.getElementById('readBtn');
const mailError = document.getElementById('mailError');
const mailLoading = document.getElementById('mailLoading');
const mailProgress = document.getElementById('mailProgress');
const mailResult = document.getElementById('mailResult');

let running = 0;
let completed = 0;
let total = 0;
let queue = [];
const mailAccountMap = {};
const maxThreads = <?= max(1, min(50, (int)dvproEnv('MAIL_MAX_THREADS', '5'))) ?>;

mailForm.addEventListener('submit', function(e){
    e.preventDefault();

    mailError.style.display = 'none';
    mailError.innerHTML = '';
    mailResult.innerHTML = '';

    const rawAccounts = document.getElementById('accounts').value.trim();

    if(!rawAccounts){
        mailError.innerHTML = 'Vui lòng nhập tài khoản';
        mailError.style.display = 'block';
        return;
    }

    const accounts = rawAccounts
        .split(/\r?\n/)
        .map(item => item.trim())
        .filter(item => item.length > 0);

    if(accounts.length === 0){
        mailError.innerHTML = 'Vui lòng nhập tài khoản';
        mailError.style.display = 'block';
        return;
    }

    running = 0;
    completed = 0;
    total = accounts.length;

    queue = accounts.map((account, index) => ({
        account: account,
        index: index + 1
    }));

    Object.keys(mailAccountMap).forEach(key => delete mailAccountMap[key]);

    accounts.forEach((account, index) => {
        mailAccountMap[index + 1] = account;
    });

    mailResult.innerHTML = `
        <div class="mail-table-wrap">
            <table class="mail-table">
                <thead>
                    <tr>
                        <th>Mail</th>
                        <th>STT</th>
                        <th>From</th>
                        <th>Time</th>
                        <th>Content</th>
                        <th>Code</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody id="mailTableBody"></tbody>
            </table>
        </div>
    `;

    const tbody = document.getElementById('mailTableBody');

    accounts.forEach((account, index) => {
        tbody.insertAdjacentHTML('beforeend', `
            <tr id="acc_result_${index + 1}" class="mail-status-row" data-account-index="${index + 1}">
                <td><span class="mail-address" data-copy="${escapeHtml(getAccountEmail(account))}">${escapeHtml(getAccountEmail(account))}</span></td>
                <td>${index + 1}</td>
                <td></td>
                <td></td>
                <td>Đang chờ...</td>
                <td></td>
                <td></td>
            </tr>
        `);
    });

    readBtn.disabled = true;
    readBtn.innerHTML = 'Đang đọc...';
    mailLoading.style.display = 'block';

    updateProgress();
    runQueue();
});

function runQueue(){
    while(running < maxThreads && queue.length > 0){
        const job = queue.shift();
        readOneAccount(job);
    }

    if(completed >= total){
        readBtn.disabled = false;
        readBtn.innerHTML = 'Đọc hòm thư';
        mailLoading.style.display = 'none';
    }
}

async function readOneAccount(job){
    running++;
    updateProgress();

    const holder = document.getElementById('acc_result_' + job.index);
    if(holder){
        holder.innerHTML = `
            <td><span class="mail-address" data-copy="${escapeHtml(getAccountEmail(job.account))}">${escapeHtml(getAccountEmail(job.account))}</span></td>
            <td>${job.index}</td>
            <td></td>
            <td></td>
            <td>Đang đọc mail...</td>
            <td></td>
            <td></td>
        `;
    }

    try{
        const formData = new FormData();
        formData.append('ajax_single', '1');
        formData.append('csrf_token', mailCsrf || window.mailCsrf || '');
        formData.append('account', job.account);
        formData.append('account_index', job.index);
        formData.append('stt_start', job.index);

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const data = await response.json();

        const currentHolder = document.getElementById('acc_result_' + job.index);

        if(data.status){
            if(currentHolder){
                currentHolder.outerHTML = data.html || '';
            }
        }else{
            if(currentHolder){
                currentHolder.outerHTML = `
                    <tr id="acc_result_${job.index}" data-account-index="${job.index}">
                        <td><span class="mail-address" data-copy="${escapeHtml(getAccountEmail(job.account))}">${escapeHtml(getAccountEmail(job.account))}</span></td>
                        <td>${job.index}</td>
                        <td colspan="5">${escapeHtml(data.message || 'Có lỗi xảy ra')}</td>
                    </tr>
                `;
            }
        }

    }catch(error){
        const currentHolder = document.getElementById('acc_result_' + job.index);

        if(currentHolder){
            currentHolder.outerHTML = `
                <tr id="acc_result_${job.index}" data-account-index="${job.index}">
                    <td><span class="mail-address" data-copy="${escapeHtml(getAccountEmail(job.account))}">${escapeHtml(getAccountEmail(job.account))}</span></td>
                    <td>${job.index}</td>
                    <td colspan="5">Lỗi AJAX: ${escapeHtml(error.message)}</td>
                </tr>
            `;
        }
    }finally{
        running--;
        completed++;
        updateProgress();
        runQueue();
    }
}

function updateProgress(){
    mailProgress.innerHTML = `Đã xong ${completed}/${total} - Đang chạy ${running}/${maxThreads}`;
}

function renderMailLoadingRow(account, index, text){
    return `
        <tr id="acc_result_${index}" class="mail-status-row" data-account-index="${index}">
            <td><span class="mail-address" data-copy="${escapeHtml(getAccountEmail(account))}">${escapeHtml(getAccountEmail(account))}</span></td>
            <td>${index}</td>
            <td></td>
            <td></td>
            <td>${escapeHtml(text || 'Dang doc mail...')}</td>
            <td></td>
            <td>
                <div class="mail-actions">
                    <button type="button" class="mail-icon-btn mail-icon-refresh" title="Doc lai mail nay" data-action="reread-mail" data-index="${index}" disabled>
                        <i class="fa-solid fa-rotate-right"></i>
                    </button>
                </div>
            </td>
        </tr>
    `;
}

function replaceMailAccountRows(index, html){
    const rows = Array.from(document.querySelectorAll('[data-account-index="' + index + '"]'));

    if(rows.length === 0){
        const tbody = document.getElementById('mailTableBody');
        if(tbody){
            tbody.insertAdjacentHTML('beforeend', html);
        }
        return;
    }

    rows[0].outerHTML = html;
    rows.slice(1).forEach(row => row.remove());
}

async function rereadMailAccount(index){
    const account = mailAccountMap[index];

    if(!account){
        showMailToast('Khong tim thay mail de doc lai');
        return;
    }

    replaceMailAccountRows(index, renderMailLoadingRow(account, index, 'Dang doc lai mail nay...'));

    try{
        const formData = new FormData();
        formData.append('ajax_single', '1');
        formData.append('csrf_token', mailCsrf || window.mailCsrf || '');
        formData.append('account', account);
        formData.append('account_index', index);
        formData.append('stt_start', index);

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const data = await response.json();

        if(data.status){
            replaceMailAccountRows(index, data.html || '');
            showMailToast('Da doc lai: ' + getAccountEmail(account));
            return;
        }

        replaceMailAccountRows(index, `
            <tr id="acc_result_${index}" data-account-index="${index}">
                <td><span class="mail-address" data-copy="${escapeHtml(getAccountEmail(account))}">${escapeHtml(getAccountEmail(account))}</span></td>
                <td>${index}</td>
                <td colspan="5">${escapeHtml(data.message || 'Co loi xay ra')}</td>
            </tr>
        `);
    }catch(error){
        replaceMailAccountRows(index, `
            <tr id="acc_result_${index}" data-account-index="${index}">
                <td><span class="mail-address" data-copy="${escapeHtml(getAccountEmail(account))}">${escapeHtml(getAccountEmail(account))}</span></td>
                <td>${index}</td>
                <td colspan="5">Loi AJAX: ${escapeHtml(error.message)}</td>
            </tr>
        `);
    }
}

function getAccountEmail(account){
    return String(account || '').split('|')[0] || account;
}

function escapeHtml(text){
    return String(text || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function showMailToast(text){
    const toast = document.getElementById('mailToast');
    toast.innerHTML = text;
    toast.style.display = 'block';

    setTimeout(() => {
        toast.style.display = 'none';
    }, 1600);
}

function copyMailCode(code){
    if(!code){
        showMailToast('Không có code để copy');
        return;
    }

    navigator.clipboard.writeText(code).then(() => {
        showMailToast('Đã copy code: ' + code);
    }).catch(() => {
        const input = document.createElement('input');
        input.value = code;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
        showMailToast('Đã copy code: ' + code);
    });
}

function copyMailAddress(email){
    if(!email){
        showMailToast('Khong co mail de copy');
        return;
    }

    navigator.clipboard.writeText(email).then(() => {
        showMailToast('Da copy mail: ' + email);
    }).catch(() => {
        const input = document.createElement('input');
        input.value = email;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
        showMailToast('Da copy mail: ' + email);
    });
}

function toggleMailGroup(groupId, btn){
    const rows = document.querySelectorAll('.mail-hidden-row[data-group="' + groupId + '"]');
    let isShow = false;

    rows.forEach(row => {
        if(row.classList.contains('show')){
            isShow = true;
        }
    });

    rows.forEach(row => {
        if(isShow){
            row.classList.remove('show');
        }else{
            row.classList.add('show');
        }
    });

    if(btn){
        btn.innerHTML = isShow ? '⬇' : '⬆';
    }
}

function decodeMailUtf8(base64){
    try{
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);

        for(let i = 0; i < binary.length; i++){
            bytes[i] = binary.charCodeAt(i);
        }

        return new TextDecoder('utf-8').decode(bytes);
    }catch(e){
        return '';
    }
}

function sanitizeMailBody(html){
    const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');

    doc.querySelectorAll('script, iframe, object, embed, form, input, button, meta, link').forEach(el => el.remove());

    doc.querySelectorAll('*').forEach(el => {
        [...el.attributes].forEach(attr => {
            const name = attr.name.toLowerCase();
            const value = String(attr.value || '');

            if(name.startsWith('on')){
                el.removeAttribute(attr.name);
            }

            if(['href', 'src', 'xlink:href'].includes(name) && /^\s*javascript:/i.test(value)){
                el.removeAttribute(attr.name);
            }
        });
    });

    return doc.body.innerHTML;
}

function openMailDetail(button){
    const body = decodeMailUtf8(button.dataset.body || '');

    const html = `
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
html,body{
    margin:0;
    padding:10px;
    background:#fff !important;
    overflow-x:hidden;
    color:#111;
    font-family:Arial,Helvetica,sans-serif;
    font-size:14px;
    line-height:1.5;
}
img{
    max-width:100% !important;
    height:auto !important;
}
table{
    max-width:100% !important;
}
*{
    box-sizing:border-box;
    word-break:break-word;
    overflow-wrap:anywhere;
}
</style>
</head>
<body>${sanitizeMailBody(body)}</body>
</html>
`;

    document.getElementById('mailFrame').srcdoc = html;
    document.body.classList.add('mail-modal-open');
    document.getElementById('mailModal').classList.add('show');
}

function closeMailDetail(){
    document.body.classList.remove('mail-modal-open');
    document.getElementById('mailModal').classList.remove('show');
    document.getElementById('mailFrame').srcdoc = '';
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        closeMailDetail();
    }
});

document.getElementById('mailModal').addEventListener('click', function(e){
    if(e.target.id === 'mailModal'){
        closeMailDetail();
    }
});
</script>

<?php
        $content = ob_get_clean();

        include "../layout.php";
    }

    private function ajaxSingle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        dvproNoStoreHeaders();
        if (!dvproRateLimit('mail_ajax:' . dvproClientIp(), 30, 60)) {
            http_response_code(429);
            echo json_encode(['status' => false, 'message' => 'Too many requests'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $account = trim($_POST['account'] ?? '');
        $accountIndex = (int)($_POST['account_index'] ?? 1);
        $sttStart = (int)($_POST['stt_start'] ?? $accountIndex);

        if ($account === '') {
            echo json_encode([
                'status' => false,
                'message' => 'Tài khoản trống'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $model = new MailModel();
        $emailOnly = $this->extractEmailOnly($account);
        $box = $model->sanitizePublicMailResult(
            $model->readMail($account, 'all', 10),
            $emailOnly
        );

        ob_start();
        $this->renderAccountRows($box, $accountIndex, $sttStart);
        $html = ob_get_clean();

        echo json_encode([
            'status' => true,
            'html' => $html,
            'email' => $box['email'] ?? $emailOnly,
            'mailbox_mode' => $box['mailbox_mode'] ?? null,
            'mailbox_type' => $box['mailbox_type'] ?? null,
            'mailbox_type_label' => $box['mailbox_type_label'] ?? null,
            'expires_at' => $box['expires_at'] ?? null,
            'remaining_seconds' => $box['remaining_seconds'] ?? null,
            // Sanitized metadata only (no password/token/client_id).
            'account' => $box['account'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function mailboxTypeLabel(array $box): string
    {
        $label = trim((string)($box['mailbox_type_label'] ?? ''));

        if ($label !== '') {
            return $label;
        }

        $mode = strtolower(trim((string)($box['mailbox_mode'] ?? '')));

        if ($mode === 'permanent') {
            return 'Email dài hạn';
        }

        if ($mode !== '') {
            return 'Email ngắn hạn';
        }

        if (($box['expires_at'] ?? null) === null && ($box['remaining_seconds'] ?? null) === null) {
            return 'Email dài hạn';
        }

        return 'Email ngắn hạn';
    }

    private function shouldShowMailboxType(string $email): bool
    {
        $email = strtolower(trim($email));

        return str_ends_with($email, '@edu.evofu.com');
    }

    private function cleanPreview(string $subject, string $body): string
    {
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = strip_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('/\x{00A0}+/u', ' ', $body);
        $body = preg_replace('/&nbsp;+/i', ' ', $body);
        $body = preg_replace('/\s+/', ' ', $body);
        $body = trim($body);

        $subject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $subject = preg_replace('/\s+/', ' ', $subject);
        $subject = trim($subject);

        return trim($subject . ' - ' . $body);
    }

    private function formatDate(string $date): string
    {
        if (!$date) {
            return '';
        }

        try {
            $time = strtotime($date);

            if ($time) {
                return date('H:i - d/m/Y', $time);
            }
        } catch (Throwable $e) {
        }

        return $date;
    }

    private function renderAccountRows(array $box, int $accountIndex, int $sttStart): void
    {
        $email = $box['email'] ?? '';
        $messages = $box['messages'] ?? [];
        $groupId = 'mail_group_' . $accountIndex . '_' . uniqid();
        $mailboxTypeLabel = $this->mailboxTypeLabel($box);
        $showMailboxType = $this->shouldShowMailboxType((string)$email);

        if (empty($messages)):
            ?>
            <tr id="acc_result_<?= h($accountIndex) ?>" data-account-index="<?= h($accountIndex) ?>">
                <td>
                    <span class="mail-address" data-copy="<?= h($email) ?>">
                        <?= h($email) ?>
                    </span>
                    <?php if ($showMailboxType): ?>
                        <span class="mail-type"><?= h($mailboxTypeLabel) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $accountIndex ?></td>
                <td colspan="4"><?= h($box['error'] ?? 'Không có mail') ?></td>
                <td>
                    <div class="mail-actions">
                        <button
                            type="button"
                            class="mail-icon-btn mail-icon-refresh"
                            title="Doc lai mail nay"
                            data-action="reread-mail" data-index="<?= h($accountIndex) ?>"
                        >
                            <i class="fa-solid fa-rotate-right"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php
            return;
        endif;

        foreach ($messages as $msgIndex => $msg):

            $sender = $msg['from'][0] ?? [];

            $fromName = $sender['name'] ?? '';
            $fromAddress = $sender['address'] ?? '';

            $subject = $msg['subject'] ?? '';
            $body = $msg['message'] ?? '';
            $date = $msg['date'] ?? '';
            $code = $msg['code'] ?? '';

            $contentPreview = $this->cleanPreview($subject, $body);
            $shortContent = mb_substr($contentPreview, 0, 150);

            $isFirst = $msgIndex === 0;
            $rowClass = $isFirst ? '' : 'mail-hidden-row';
            ?>
            <tr
                <?php if ($isFirst): ?>id="acc_result_<?= h($accountIndex) ?>"<?php endif; ?>
                class="<?= $rowClass ?>"
                data-account-index="<?= h($accountIndex) ?>"
                <?php if (!$isFirst): ?>
                    data-group="<?= h($groupId) ?>"
                <?php endif; ?>
            >
                <td>
                    <span class="mail-address" data-copy="<?= h($email) ?>">
                        <?= h($email) ?>
                    </span>
                    <?php if ($showMailboxType): ?>
                        <span class="mail-type"><?= h($mailboxTypeLabel) ?></span>
                    <?php endif; ?>
                </td>

                <td><?= $isFirst ? h($accountIndex) : '' ?></td>

                <td>
                    <div class="mail-from-name"><?= h($fromName ?: $fromAddress) ?></div>
                    <span class="mail-from-address"><?= h($fromAddress) ?></span>
                </td>

                <td>
                    <div class="mail-time"><?= h($this->formatDate($date)) ?></div>
                </td>

                <td>
                    <div class="mail-content">
                        <?= h($shortContent) ?>

                        <?php if (mb_strlen($contentPreview) > 150): ?>
                            ...
                        <?php endif; ?>

                        <button
                            type="button"
                            class="mail-detail-btn"
                            data-body="<?= h(base64_encode($body)) ?>"
                            data-action="open-mail-detail"
                        >
                            Chi tiết &gt;&gt;
                        </button>
                    </div>
                </td>

                <td>
                    <?php if (!empty($code)): ?>
                        <span class="mail-code" data-copy="<?= h($code) ?>">
                            <?= h($code) ?>
                        </span>
                    <?php endif; ?>
                </td>

                <td>
                    <div class="mail-actions">
                        <button
                            type="button"
                            class="mail-icon-btn mail-icon-refresh"
                            title="Doc lai mail nay"
                            data-action="reread-mail" data-index="<?= h($accountIndex) ?>"
                        >
                            <i class="fa-solid fa-rotate-right"></i>
                        </button>

                        <button
                            type="button"
                            class="mail-icon-btn mail-icon-detail"
                            title="Xem chi tiết"
                            data-body="<?= h(base64_encode($body)) ?>"
                            data-action="open-mail-detail"
                        >
                            👁
                        </button>

                        <?php if ($isFirst && count($messages) > 1): ?>
                            <button
                                type="button"
                                class="mail-icon-btn mail-icon-more"
                                title="Xem thêm / Thu gọn"
                                data-action="toggle-mail-group" data-group="<?= h($groupId) ?>"
                            >
                                ⬇
                            </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php
        endforeach;
    }
}
