<?php
require_once dirname(__DIR__) . '/env.php';
secureSessionStart();
// ===== BẬT / TẮT BẢO TRÌ =====
$maintenance = dvproEnvBool('TIKTOK_MAINTENANCE', false);

if ($maintenance) {
    http_response_code(503);
    header('Retry-After: 3600');

    $title = "Bảo trì hệ thống - DVPro.vn";
    $content = <<<'HTML'
<div class="page-wrap min-h-[70vh] flex items-center justify-center">
  <section class="tool-panel w-full max-w-2xl text-center" style="border-color:rgba(245,158,11,.35);padding:1.6rem">
    <div class="tool-icon amber mx-auto mb-4" style="width:3.5rem;height:3.5rem;font-size:1.3rem"><i class="fa-solid fa-screwdriver-wrench"></i></div>
    <h1 class="text-3xl font-extrabold text-white m-0">Hệ thống đang bảo trì</h1>
    <p class="text-text-muted mt-4 mb-0">Công cụ <strong class="text-white">Check Username TikTok</strong> đang được bảo trì tạm thời để nâng cấp và tối ưu hệ thống.</p>
    <p class="mt-4 text-sm font-semibold text-amber-100 mb-0">Vui lòng quay lại sau. Xin cảm ơn!</p>
    <div class="toolbar justify-center mt-5">
      <a href="../tools.php" class="btn btn-secondary"><i class="fas fa-toolbox"></i> Về Tools</a>
      <a href="../" class="btn btn-primary"><i class="fas fa-house"></i> Trang chủ</a>
    </div>
  </section>
</div>
HTML;
    include '../layout.php';
    exit;
}

require_once __DIR__ . '/models/TiktokModel.php';
$title = "Check Username TikTok - DVPro.vn";
$description = "Kiểm tra tài khoản TikTok Live/Die theo thời gian thực, hiển thị avatar, followers, likes, video và thông tin liên quan.";

$content = <<<'HTML'
<div class="page-wrap tt-page">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon slate"><i class="fab fa-tiktok"></i></div>
      <div>
        <h1>Check TikTok Users</h1>
        <p>Kiểm tra tài khoản TikTok <strong class="text-green-400">Live/Die</strong> theo thời gian thực • Hiển thị Avatar, Followers, Likes, Video.</p>
      </div>
    </div>
    <div class="tool-hero-actions">
      <span class="pill pill-slate">Realtime</span>
      <span class="pill pill-green">Free</span>
    </div>
  </div>

  <section class="tool-panel">
    <div class="tool-panel-head">
      <h2><i class="fab fa-tiktok text-cyan-300"></i> Nhập danh sách username hoặc link TikTok</h2>
      <span class="pill pill-blue">Batch check</span>
    </div>

    <textarea id="usnames" class="textarea pretty-scroll" rows="8" placeholder="@khaby.lame
@charlidamelio
https://www.tiktok.com/@mrbeast
@dvpro.vn"></textarea>

    <div class="toolbar mt-4">
      <button id="startBtn" class="btn btn-primary" type="button" disabled>
        <i class="fas fa-play"></i> <span>Check ngay</span>
      </button>
      <button id="clearBtn" class="btn btn-secondary" type="button" disabled>
        <i class="fas fa-trash-alt"></i> Clear All
      </button>
      <div class="spacer"></div>
      <div id="progressContainer" class="hidden tt-progress">
        <div class="progress-shell"><div id="progressBar" class="progress-bar tt-progress-bar"></div></div>
      </div>
    </div>

    <div class="stats-row mt-5">
      <div class="stat-card live"><strong id="liveCount">0</strong><span>Live</span></div>
      <div class="stat-card die"><strong id="dieCount">0</strong><span>Die</span></div>
      <div class="stat-card total"><strong id="total">0</strong><span>Tổng kiểm tra</span></div>
    </div>
  </section>

  <section class="tool-panel tt-result-panel live-panel">
    <div class="tool-panel-head">
      <h2>
        <i class="fas fa-check-circle text-green-400"></i>
        Tài khoản LIVE
        <span class="pill pill-green">(<span id="liveCount2">0</span>)</span>
      </h2>
      <div class="toolbar">
        <button id="toggleLive" class="btn btn-secondary btn-sm" type="button">Ẩn/Hiện</button>
        <button id="copyLiveUsernames" class="btn btn-primary btn-sm" type="button">Copy Username</button>
        <button id="exportLive" class="btn btn-green btn-sm" type="button">Xuất Excel</button>
      </div>
    </div>
    <div id="liveTableContainer" class="table-wrap tt-table-wrap">
      <table id="liveTable" class="tt-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Avatar</th>
            <th>Nickname</th>
            <th>Followers</th>
            <th>Following</th>
            <th>Videos</th>
            <th>Likes</th>
            <th>Private</th>
            <th>Time reg</th>
            <th>Time check</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <section class="tool-panel tt-result-panel die-panel">
    <div class="tool-panel-head">
      <h2>
        <i class="fas fa-times-circle text-red-400"></i>
        Tài khoản DIE
        <span class="pill pill-red">(<span id="dieCount2">0</span>)</span>
      </h2>
      <div class="toolbar">
        <button id="toggleDie" class="btn btn-secondary btn-sm" type="button">Ẩn/Hiện</button>
        <button id="copyDieUsernames" class="btn btn-primary btn-sm" type="button">Copy Username</button>
        <button id="exportDie" class="btn btn-green btn-sm" type="button">Xuất Excel</button>
      </div>
    </div>
    <div id="dieTableContainer" class="table-wrap tt-table-wrap">
      <table id="dieTable" class="tt-table die">
        <thead>
          <tr>
            <th>Username</th>
            <th>Lý do</th>
            <th>Time check</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <div id="toast" class="tt-toast"></div>
</div>
<script __DVPRO_NONCE_ATTR__ src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script __DVPRO_NONCE_ATTR__>
window.dvproCsrf = __DVPRO_CSRF_JSON__;

    const els = {
        input: document.getElementById('usnames'),
        startBtn: document.getElementById('startBtn'),
        clearBtn: document.getElementById('clearBtn'),
        liveTbody: document.querySelector('#liveTable tbody'),
        dieTbody: document.querySelector('#dieTable tbody'),
        liveCount: document.getElementById('liveCount'),
        liveCount2: document.getElementById('liveCount2'),
        dieCount: document.getElementById('dieCount'),
        dieCount2: document.getElementById('dieCount2'),
        total: document.getElementById('total'),
        progressContainer: document.getElementById('progressContainer'),
        progressBar: document.getElementById('progressBar'),
        toast: document.getElementById('toast')
    };

    let isChecking = false;

    // Toast đẹp
    function showToast(msg, type = 'success') {
        els.toast.innerHTML = `<div class="bg-${type==='success'?'emerald':'red'}-600 text-white px-6 py-4 rounded-xl shadow-2xl flex items-center gap-3 animate-slideup">
            <i class="fas fa-${type==='success'?'check-circle':'exclamation-triangle'} text-xl"></i>
            <span class="font-medium">${msg}</span>
        </div>`;
        setTimeout(() => els.toast.innerHTML = '', 3500);
    }
    function formatTime(ts) {
    const date = new Date(ts * 1000); // nhân 1000 vì Unix (giây)

    const pad = n => n.toString().padStart(2, '0');

    return `${pad(date.getDate())}/${pad(date.getMonth()+1)}/${date.getFullYear()} ` +
           `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

    function formatCheckedTime(value) {
        if (!value) return '-';
        const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}:\d{2})$/);
        if (match) return match[3] + '/' + match[2] + '/' + match[1] + ' ' + match[4];
        const date = value instanceof Date ? value : new Date(value);
        if (Number.isNaN(date.getTime())) return '-';
        const pad = n => String(n).padStart(2, '0');
        return pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + '/' + date.getFullYear() + ' ' +
            pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
    }

    function changeCount(type, delta) {
        const first = type === 'live' ? els.liveCount : els.dieCount;
        const second = type === 'live' ? els.liveCount2 : els.dieCount2;
        const next = Math.max(0, (parseInt(first.textContent, 10) || 0) + delta);
        first.textContent = second.textContent = next;
    }

    // Cập nhật trạng thái nút
    function updateButtons() {
        const hasInput = els.input.value.trim().length > 0;
        const hasLive = els.liveTbody.children.length > 0;
        const hasDie = els.dieTbody.children.length > 0;

        els.startBtn.disabled = !hasInput || isChecking;
        els.clearBtn.disabled = !(hasInput || hasLive || hasDie);

        if (hasInput && !isChecking) {
            els.startBtn.classList.add('bg-gradient-to-r', 'from-teal-600', 'to-cyan-600', 'hover:from-teal-700', 'hover:to-cyan-700', 'hover:scale-105');
        } else {
            els.startBtn.classList.remove('bg-gradient-to-r', 'from-teal-600', 'to-cyan-600', 'hover:from-teal-700', 'hover:to-cyan-700', 'hover:scale-105');
            els.startBtn.classList.add('bg-gray-600');
        }
    }

    // Toggle table
    document.getElementById('toggleLive').onclick = () => document.getElementById('liveTableContainer').classList.toggle('hidden');
    document.getElementById('toggleDie').onclick = () => document.getElementById('dieTableContainer').classList.toggle('hidden');

    // Start Check
    els.startBtn.onclick = async () => {
        let raw = els.input.value.trim();
        if (!raw) return showToast('Vui lòng nhập ít nhất 1 username!', 'error');

        const users = raw.split('\n')
            .map(u => u.trim())
            .filter(u => u)
            .map(u => {
                u = u.trim();
            
                // bỏ @ đầu
                u = u.replace(/^@+/, '');
            
                // nếu là dạng domain (có tiktok.com)
                if (u.includes('tiktok.com')) {
                    const match = u.match(/tiktok\.com\/@([^\/\?]+)/);
                    if (match) return match[1];
                }
            
                // nếu chỉ là username
                return u.split('?')[0];
            })
            .filter(u => u);
        const unique = [...new Set(users)];
        if (!unique.length) return showToast('Không tìm thấy username hợp lệ!', 'error');

        // Reset
        els.total.textContent = unique.length;
        els.liveCount.textContent = els.liveCount2.textContent = 0;
        els.dieCount.textContent = els.dieCount2.textContent = 0;
        els.liveTbody.innerHTML = '';
        els.dieTbody.innerHTML = '';
        isChecking = true;
        updateButtons();
        els.progressContainer.classList.remove('hidden');
        els.progressBar.style.width = '0%';
        els.startBtn.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg> Đang check...';

        const concurrent = __TT_CONCURRENT__;
        let checked = 0;
        let nextIndex = 0;

        function updateProgress() {
            els.progressBar.style.width = (checked / unique.length * 100) + '%';
        }

        async function checkUser(user) {
            const row = els.liveTbody.insertRow();
            row.innerHTML = `<td colspan="11" class="py-4 text-gray-400 italic">@${user} -> Đang kiểm tra...</td>`;

            try {
                const res = await fetch('ajax_check.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.dvproCsrf || '') },
                    body: new URLSearchParams({ csrf_token: window.dvproCsrf || '', username: user }),
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();

                row.remove();
                const time = formatCheckedTime(new Date());

                if (data && data.status === 'LIVE') {
                    const avatar = data.avatar ? data.avatar.replace(/u002F/g, '/').replace(/\\\//g, '/') : '';
                    const newRow = els.liveTbody.insertRow();
                    newRow.innerHTML = `
                        <td class="py-3"><a href="https://www.tiktok.com/@${data.username || user}" target="_blank" class="text-teal-400 hover:underline">${data.username || user}</a></td>
                        <td class="py-3">${avatar ? `<img src="${avatar}" class="w-10 h-10 rounded-full mx-auto object-cover shadow-lg" loading="lazy" alt="">` : 'No'}</td>
                        <td class="py-3">${data.nickname || '-'}</td>
                        <td class="py-3">${Number(data.followers || 0).toLocaleString('vi-VN')}</td>
                        <td class="py-3">${Number(data.following || 0).toLocaleString('vi-VN')}</td>
                        <td class="py-3">${Number(data.videos || 0).toLocaleString('vi-VN')}</td>
                        <td class="py-3">${Number(data.likes || 0).toLocaleString('vi-VN')}</td>
                        <td class="py-3"><span class="pill ${data.private ? 'pill-red' : 'pill-green'}">${data.private ? 'Yes' : 'No'}</span></td>
                        <td class="py-3 text-gray-400 text-xs">${formatTime(data.create_time)}</td>
                        <td class="py-3 text-gray-400 text-xs">${formatCheckedTime(data.checked_at)}</td>
                        <td class="py-3"><button type="button" data-action="recheck" data-user="${data.username || user}" class="btn btn-amber btn-sm recheck-btn">Recheck</button></td>
                    `;
                    changeCount('live', 1);
                } else {
                    const dieRow = els.dieTbody.insertRow();
                    dieRow.innerHTML = `
                        <td class="py-3"><a href="https://www.tiktok.com/@${user}" target="_blank" class="text-red-400 hover:underline">@${user}</a></td>
                        <td class="py-3 text-red-400 text-xs">${data?.message || 'Không tồn tại / Bị khóa'}</td>
                        <td class="py-3 text-gray-400 text-xs">${time}</td>
                        <td class="py-3"><button type="button" data-action="recheck" data-user="${user}" class="btn btn-amber btn-sm recheck-btn">Recheck</button></td>
                    `;
                    changeCount('die', 1);
                }
            } catch (e) {
                row.remove();
                const dieRow = els.dieTbody.insertRow();
                dieRow.innerHTML = `<td class="py-3">@${user}</td><td class="py-3 text-orange-400 text-xs">Lỗi mạng / Block</td><td class="py-3 text-gray-400 text-xs">${formatCheckedTime(new Date())}</td><td class="py-3"><button type="button" data-action="recheck" data-user="${user}" class="btn btn-amber btn-sm recheck-btn">Recheck</button></td>`;
                changeCount('die', 1);
            } finally {
                checked++;
                updateProgress();
            }
        }

        async function worker() {
            while (true) {
                const index = nextIndex++;
                if (index >= unique.length) return;
                await checkUser(unique[index]);
            }
        }

        await Promise.all(Array.from({ length: Math.min(concurrent, unique.length) }, worker));
    };

    // Recheck
window.recheck = async (user, btn) => {
    btn.disabled = true;
    btn.innerHTML = '<svg class="animate-spin h-5 w-5 mr-1" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg> Checking...';

    try {
        const res = await fetch('ajax_check.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.dvproCsrf || '') },
            body: new URLSearchParams({ csrf_token: window.dvproCsrf || '', username: user }),
        });
        const data = await res.json();
        const time = formatCheckedTime(new Date());

        // Xóa row hiện tại
        const row = btn.closest('tr');
        if (row && row.isConnected) {
            changeCount(row.parentElement === els.liveTbody ? 'live' : 'die', -1);
            row.remove();
        }

        if (data && data.status === 'LIVE') {
            const avatar = data.avatar ? data.avatar.replace(/u002F/g, '/').replace(/\\\//g, '/') : '';
            const newRow = els.liveTbody.insertRow();
            newRow.innerHTML = `
                <td class="py-3">
                    <a href="https://www.tiktok.com/@${data.username || user}" target="_blank" class="text-teal-400 hover:underline">
                        ${data.username || user}
                    </a>
                </td>
                <td class="py-3">${avatar ? `<img src="${avatar}" alt="" loading="lazy">` : 'No'}</td>
                <td class="py-3">${data.nickname || '-'}</td>
                <td class="py-3">${Number(data.followers || 0).toLocaleString('vi-VN')}</td>
                <td class="py-3">${Number(data.following || 0).toLocaleString('vi-VN')}</td>
                <td class="py-3">${Number(data.videos || 0).toLocaleString('vi-VN')}</td>
                <td class="py-3">${Number(data.likes || 0).toLocaleString('vi-VN')}</td>
                <td class="py-3"><span class="pill ${data.private ? 'pill-red' : 'pill-green'}">${data.private ? 'Yes' : 'No'}</span></td>
<td class="py-3 text-gray-400 text-xs">
    ${formatTime(data.create_time)}
</td>                            <td class="py-3 text-gray-400 text-xs">${formatCheckedTime(data.checked_at)}</td>

                <td class="py-3"><button type="button" data-action="recheck" data-user="${data.username || user}" class="btn btn-amber btn-sm recheck-btn">Recheck</button></td>
            `;
            changeCount('live', 1);
        } else {
            const dieRow = els.dieTbody.insertRow();
            dieRow.innerHTML = `
                <td class="py-3">
                    <a href="https://www.tiktok.com/@${user}" target="_blank" class="text-red-400 hover:underline">@${user}</a>
                </td>
                <td class="py-3 text-red-400 text-xs">
                    ${data.message || 'Không tồn tại / Bị khóa'}
                </td>
                <td class="py-3 text-gray-400 text-xs">${time}</td>
                <td class="py-3"><button type="button" data-action="recheck" data-user="${user}" class="btn btn-amber btn-sm recheck-btn">Recheck</button></td>
            `;
            changeCount('die', 1);
        }
    } catch (e) {
        const row = btn.closest('tr');
        if (row && row.isConnected) {
            changeCount(row.parentElement === els.liveTbody ? 'live' : 'die', -1);
            row.remove();
        }
        const dieRow = els.dieTbody.insertRow();
        dieRow.innerHTML = `<td class="py-3"><a href="https://www.tiktok.com/@${user}" target="_blank" class="text-red-400 hover:underline">@${user}</a></td>
            <td class="py-3 text-orange-400 text-xs">Lỗi mạng / Block</td>
            <td class="py-3 text-gray-400 text-xs">${formatCheckedTime(new Date())}</td>
            <td class="py-3"><button type="button" data-action="recheck" data-user="${user}" class="btn btn-amber btn-sm recheck-btn">Recheck</button></td>`;
        changeCount('die', 1);
    }
};

    // Copy Usernames
    document.getElementById('copyLiveUsernames').onclick = () => {
        const usernames = Array.from(els.liveTbody.querySelectorAll('tr td:first-child')).map(td => td.textContent.trim()).join('\n');
        if (!usernames) return showToast('Không có username Live để copy!', 'error');
        navigator.clipboard.writeText(usernames).then(() => showToast('Đã copy tất cả username Live!'));
    };
    document.getElementById('copyDieUsernames').onclick = () => {
        const usernames = Array.from(els.dieTbody.querySelectorAll('tr td:first-child')).map(td => td.textContent.trim()).join('\n');
        if (!usernames) return showToast('Không có username Die để copy!', 'error');
        navigator.clipboard.writeText(usernames).then(() => showToast('Đã copy tất cả username Die!'));
    };

    // Export Excel
    function exportExcel(tableId, filename) {
        const table = document.getElementById(tableId);
        const rows = Array.from(table.querySelectorAll('tr'));
        const data = rows.map(row => Array.from(row.cells).map(cell => cell.textContent.trim()));
        const ws = XLSX.utils.aoa_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Data");
        XLSX.writeFile(wb, filename);
    }
    document.getElementById('exportLive').onclick = () => exportExcel('liveTable', 'TikTok_Live_Accounts.xlsx');
    document.getElementById('exportDie').onclick = () => exportExcel('dieTable', 'TikTok_Die_Accounts.xlsx');

    // Clear
    els.clearBtn.onclick = () => {
        if (isChecking) return showToast('Đang kiểm tra, không thể xóa!', 'error');
        els.input.value = '';
        els.liveTbody.innerHTML = '';
        els.dieTbody.innerHTML = '';
        els.liveCount.textContent = els.liveCount2.textContent = els.dieCount.textContent = els.dieCount2.textContent = els.total.textContent = 0;
        updateButtons();
        els.input.focus();
    };

    // Init
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('year') && (document.getElementById('year').textContent = new Date().getFullYear());
        els.input.addEventListener('input', updateButtons);
        updateButtons();
        els.input.focus();
    });
</script>
HTML;

$content = str_replace(['__DVPRO_NONCE_ATTR__', '__DVPRO_CSRF_JSON__', '__TT_CONCURRENT__'], [function_exists('dvproNonceAttr') ? dvproNonceAttr() : '', json_encode(function_exists('dvproCsrfToken') ? dvproCsrfToken() : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), max(1, min(20, (int)dvproEnv('TIKTOK_CONCURRENT', '5')))], $content);

include '../layout.php';
