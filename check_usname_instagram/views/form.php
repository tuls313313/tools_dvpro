<?php
$title = "Check Live Instagram - DVPro.vn";
$description = "Công cụ kiểm tra username Instagram còn Live hay đã Die, hỗ trợ check danh sách nhanh.";
$content = <<<'HTML'
<div class="page-wrap ig-page">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon pink"><i class="fab fa-instagram"></i></div>
      <div>
        <h1>Check Live Instagram</h1>
        <p>Kiểm tra tài khoản Instagram còn <strong class="text-green-400">Live</strong> hay đã <strong class="text-red-400">Die</strong> bằng username.</p>
      </div>
    </div>
    <div class="tool-hero-actions">
      <span class="pill pill-pink">Instagram</span>
      <span class="pill pill-green">Batch</span>
    </div>
  </div>

  <section class="tool-panel">
    <div class="tool-panel-head">
      <h2><i class="fab fa-instagram text-pink-300"></i> Nhập danh sách username</h2>
      <span class="pill pill-slate">Mỗi username 1 dòng</span>
    </div>

    <textarea id="usernameInput" class="textarea pretty-scroll ig-input" rows="9" spellcheck="false" placeholder="abc123
xyz_ig
user_name..."></textarea>

    <div class="toolbar mt-4">
      <button id="startBtn" data-action="call" data-fn="startCheck" class="btn btn-pink" disabled type="button">
        <i class="fas fa-play"></i> <span>Bắt đầu Check</span>
      </button>
      <button id="clearBtn" data-action="call" data-fn="clearResults" class="btn btn-secondary" disabled type="button">
        <i class="fas fa-trash-alt"></i> Clear
      </button>
      <div class="spacer"></div>
      <div id="progressContainer" class="hidden ig-progress">
        <div class="progress-shell"><div id="progressBar" class="progress-bar ig-progress-bar" style="width:0%"></div></div>
      </div>
    </div>

    <div class="stats-row mt-5">
      <div class="stat-card live"><strong><span id="liveCount">0</span></strong><span>Live</span></div>
      <div class="stat-card die"><strong><span id="dieCount">0</span></strong><span>Die</span></div>
      <div class="stat-card total"><strong><span id="total">0</span></strong><span>Tổng</span></div>
    </div>
  </section>

  <div class="tool-grid-2 checker-results ig-results">
    <section class="tool-panel checker-result ig-result live">
      <div class="tool-panel-head">
        <h2 class="text-pink-300"><i class="fas fa-check-circle"></i> Live <span class="pill pill-pink">(<span id="liveCount2">0</span>)</span></h2>
        <button data-action="copy-text" data-target="liveBox" id="copyLiveBtn" type="button" class="btn btn-secondary btn-sm"><i class="fas fa-copy"></i> Copy</button>
      </div>
      <textarea id="liveBox" readonly class="textarea pretty-scroll checker-result-box ig-box live" rows="14" placeholder="Username LIVE sẽ hiện ở đây..."></textarea>
    </section>

    <section class="tool-panel checker-result ig-result die">
      <div class="tool-panel-head">
        <h2 class="text-red-300"><i class="fas fa-times-circle"></i> Die <span class="pill pill-red">(<span id="dieCount2">0</span>)</span></h2>
        <button data-action="copy-text" data-target="dieBox" id="copyDieBtn" type="button" class="btn btn-secondary btn-sm"><i class="fas fa-copy"></i> Copy</button>
      </div>
      <textarea id="dieBox" readonly class="textarea pretty-scroll checker-result-box ig-box die" rows="14" placeholder="Username DIE sẽ hiện ở đây..."></textarea>
    </section>
  </div>
  <div id="toast" class="tt-toast"></div>
</div>

<style>
    @keyframes slideup {
        from { transform: translateY(100px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    .animate-slideup { animation: slideup 0.4s ease-out; }
</style>
<script __DVPRO_NONCE_ATTR__>
window.dvproCsrf = __DVPRO_CSRF_JSON__;
    // === DOM Elements ===
    const els = {
        input: document.getElementById('usernameInput'),
        startBtn: document.getElementById('startBtn'),
        clearBtn: document.getElementById('clearBtn'),
        copyLiveBtn: document.getElementById('copyLiveBtn'),
        copyDieBtn: document.getElementById('copyDieBtn'),
        liveBox: document.getElementById('liveBox'),
        dieBox: document.getElementById('dieBox'),
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

    // === Toast Notification ===
    function showToast(message, type = 'success') {
        els.toast.innerHTML = `
            <div class="alert ${type === 'success' ? 'alert-success' : 'alert-error'} animate-slideup">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                <span>${message}</span>
            </div>`;
        setTimeout(() => els.toast.innerHTML = '', 3000);
    }

    // === Button state ===
    function updateButtonStates() {
        const hasInput = els.input.value.trim().length > 0;
        const hasLive = els.liveBox.value.trim().length > 0;
        const hasDie = els.dieBox.value.trim().length > 0;

        // Start button
        els.startBtn.disabled = !hasInput || isChecking;
        // Clear button
        els.clearBtn.disabled = !(hasInput || hasLive || hasDie) || isChecking;

        // Copy buttons
        els.copyLiveBtn.disabled = !hasLive;
        els.copyDieBtn.disabled = !hasDie;
    }

    // === Start Check ===
    async function startCheck() {
        const raw = els.input.value.trim();
        if (!raw) return;

        const usernames = raw.split('\n').map(u => u.trim()).filter(u => u);
        const unique = [...new Set(usernames)];
        if (unique.length === 0) return showToast('Kh\u00f4ng c\u00f3 username h\u1ee3p l\u1ec7!', 'error');

        // Reset
        els.total.textContent = unique.length;
        els.liveCount.textContent = els.liveCount2.textContent = 0;
        els.dieCount.textContent = els.dieCount2.textContent = 0;
        els.liveBox.value = els.dieBox.value = '';
        const concurrent = __IG_CONCURRENT__;
        let checked = 0;
        const queue = unique.slice();

        function updateProgress() {
            els.progressBar.style.width = (checked / unique.length * 100) + '%';
        }

        async function checkOne(user) {
            try {
                const res = await fetch('index.php?action=checkOne', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': (window.dvproCsrf || ''), 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: window.dvproCsrf || '', account: user })
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();

                if (data.status === 'live') {
                    els.liveBox.value += user + '\n';
                    els.liveCount.textContent = els.liveCount2.textContent = parseInt(els.liveCount.textContent, 10) + 1;
                } else {
                    els.dieBox.value += user + '\n';
                    els.dieCount.textContent = els.dieCount2.textContent = parseInt(els.dieCount.textContent, 10) + 1;
                }
            } catch (e) {
                els.dieBox.value += user + ' (lỗi)\n';
                els.dieCount.textContent = els.dieCount2.textContent = parseInt(els.dieCount.textContent, 10) + 1;
            } finally {
                checked++;
                updateProgress();
            }
        }

        async function worker() {
            while (queue.length) {
                await checkOne(queue.shift());
            }
        }

        await Promise.all(Array.from({ length: Math.min(concurrent, unique.length) }, worker));

        // Done
        isChecking = false;
        els.startBtn.innerHTML = '<i class="fas fa-play"></i> B\u1eaft \u0111\u1ea7u Check';
        updateButtonStates();
        setTimeout(() => els.progressContainer.classList.add('hidden'), 1500);
        showToast(`Ho\u00e0n th\u00e0nh! Live: ${els.liveCount.textContent} | Die: ${els.dieCount.textContent}`, 'success');
    }

    // === Clear ===
    function clearResults() {
        if (isChecking) return showToast('\u0110ang ki\u1ec3m tra, kh\u00f4ng th\u1ec3 x\u00f3a!', 'error');
        els.input.value = els.liveBox.value = els.dieBox.value = '';
        els.liveCount.textContent = els.liveCount2.textContent = 0;
        els.dieCount.textContent = els.dieCount2.textContent = 0;
        els.total.textContent = 0;
        els.progressBar.style.width = '0%';
        updateButtonStates();
        els.input.focus();
    }

    // === Copy ===
    function copyText(boxId) {
        const box = boxId === 'liveBox' ? els.liveBox : els.dieBox;
        const btn = boxId === 'liveBox' ? els.copyLiveBtn : els.copyDieBtn;

        if (!box.value.trim()) return;

        navigator.clipboard.writeText(box.value.trim()).then(() => {
            const oldHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i>\u0110\u00e3 copy!';
            btn.classList.remove('btn-secondary');
            btn.classList.add('btn-green');
            setTimeout(() => {
                btn.innerHTML = oldHTML;
                btn.classList.remove('btn-green');
                btn.classList.add('btn-secondary');
            }, 3000);
        });
    }

    // === Init ===
    function initInstagramChecker() {
        const yearEl = document.getElementById('year');
        if (yearEl) {
            yearEl.textContent = new Date().getFullYear();
        }

        if (!els.input || !els.startBtn) {
            console.error('Instagram checker form is missing required elements.');
            return;
        }

        els.input.addEventListener('input', updateButtonStates);
        els.input.addEventListener('paste', () => setTimeout(updateButtonStates, 50));
        updateButtonStates();
        els.input.focus();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInstagramChecker);
    } else {
        initInstagramChecker();
    }
</script>
HTML;
$content = str_replace(['__DVPRO_NONCE_ATTR__', '__DVPRO_CSRF_JSON__', '__IG_CONCURRENT__'], [function_exists('dvproNonceAttr') ? dvproNonceAttr() : '', json_encode(function_exists('dvproCsrfToken') ? dvproCsrfToken() : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), max(1, min(20, (int)dvproEnv('INSTAGRAM_CONCURRENT', '5')))], $content);
include dirname(__DIR__, 2) . '/layout.php';
