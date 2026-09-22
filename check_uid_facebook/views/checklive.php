<?php
$title = "Check Live UID Facebook - DVPro.vn";
$description = "Công cụ kiểm tra UID profile Facebook còn LIVE hay đã DIE, hỗ trợ check hàng loạt nhanh.";
$content = <<<'HTML'
<div class="page-wrap fbuid-page">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon blue"><i class="fab fa-facebook-f"></i></div>
      <div>
        <h1>Check Live UID Facebook</h1>
        <p>Kiểm tra UID profile Facebook còn <strong class="text-green-400">LIVE</strong> hay đã <strong class="text-red-400">DIE</strong>. Hỗ trợ check hàng loạt.</p>
      </div>
    </div>
    <div class="tool-hero-actions">
      <span class="pill pill-blue">Facebook</span>
      <span class="pill pill-green">Batch</span>
    </div>
  </div>

  <section class="tool-panel">
    <div class="tool-panel-head">
      <h2><i class="fas fa-list text-blue-300"></i> Nhập danh sách UID</h2>
      <span class="pill pill-slate">Mỗi UID 1 dòng</span>
    </div>

    <textarea id="input" class="textarea pretty-scroll fbuid-input" rows="9" spellcheck="false" placeholder="mỗi uid 1 dòng
61583769256099
61585956545220
61586188493685"></textarea>

    <div class="toolbar mt-4 fbuid-toolbar">
      <button data-action="call" data-fn="startCheck" id="startBtn" class="btn btn-primary" type="button">
        <i class="fas fa-play"></i> Bắt đầu Check
      </button>
      <button data-action="call" data-fn="clearAll" id="clearBtn" class="btn btn-secondary" type="button">
        <i class="fas fa-trash-alt"></i> Clear
      </button>
      <div class="spacer"></div>
      <div class="fbuid-progress">
        <div class="progress-shell"><div id="progressBar" class="progress-bar fbuid-progress-bar"></div></div>
        <div class="fbuid-progress-text">
          <span id="checkedCount">0</span>/<span id="totalCount">0</span>
        </div>
      </div>
    </div>

    <div class="stats-row mt-5">
      <div class="stat-card live"><strong id="liveCount">0</strong><span>Live</span></div>
      <div class="stat-card die"><strong id="dieCount">0</strong><span>Die</span></div>
      <div class="stat-card total"><strong id="totalCountBox">0</strong><span>Tổng</span></div>
    </div>
  </section>

  <div class="tool-grid-2 checker-results fbuid-results">
    <section class="tool-panel checker-result fbuid-result live">
      <div class="tool-panel-head">
        <h2 class="text-green-300"><i class="fas fa-check-circle"></i> Live <span class="pill pill-green">(<span id="liveCount2">0</span>)</span></h2>
        <button type="button" class="btn btn-secondary btn-sm" data-action="copy-text" data-target="liveBox"><i class="fa-regular fa-copy"></i> Copy</button>
      </div>
      <textarea id="liveBox" class="textarea pretty-scroll checker-result-box fbuid-box live" rows="14" readonly placeholder="UID LIVE sẽ hiện ở đây..."></textarea>
    </section>

    <section class="tool-panel checker-result fbuid-result die">
      <div class="tool-panel-head">
        <h2 class="text-red-300"><i class="fas fa-times-circle"></i> Die <span class="pill pill-red">(<span id="dieCount2">0</span>)</span></h2>
        <button type="button" class="btn btn-secondary btn-sm" data-action="copy-text" data-target="dieBox"><i class="fa-regular fa-copy"></i> Copy</button>
      </div>
      <textarea id="dieBox" class="textarea pretty-scroll checker-result-box fbuid-box die" rows="14" readonly placeholder="UID DIE sẽ hiện ở đây..."></textarea>
    </section>
  </div>
</div>

<script __DVPRO_NONCE_ATTR__>
let live = 0, die = 0, checked = 0, total = 0, checking = false;
const CONCURRENT = __FB_CONCURRENT__;
const BATCH = __FB_BATCH__;

function copyBox(id, btn) {
  const el = document.getElementById(id);
  if (!el) return;
  const text = el.value || '';
  navigator.clipboard.writeText(text).then(() => {
    if (!btn) return;
    const old = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Đã copy';
    btn.disabled = true;
    setTimeout(() => {
      btn.innerHTML = old;
      btn.disabled = false;
    }, 1100);
  }).catch(() => {
    el.focus();
    el.select();
    document.execCommand('copy');
  });
}

async function startCheck(){
  if (checking) return;
  let raw = document.getElementById("input").value.trim();
  if (!raw) return;

  let ids = [...new Set(raw.split("\n").map(x => x.trim()).filter(Boolean))];
  total = ids.length;
  checked = 0;
  live = 0;
  die = 0;
  checking = true;

  ["totalCount","totalCountBox"].forEach(id => document.getElementById(id).innerText = total);
  ["checkedCount","liveCount","dieCount","liveCount2","dieCount2"].forEach(id => document.getElementById(id).innerText = 0);
  document.getElementById("liveBox").value = "";
  document.getElementById("dieBox").value = "";
  document.getElementById("progressBar").style.width = "0%";
  document.getElementById("startBtn").innerHTML = "<i class='fas fa-spinner fa-spin'></i> Đang check...";
  document.getElementById("startBtn").disabled = true;
  document.getElementById("clearBtn").disabled = true;

  let queue = [...ids];

  function processResult(uid, status){
    checked++;
    document.getElementById("checkedCount").innerText = checked;
    document.getElementById("progressBar").style.width = Math.floor((checked / total) * 100) + "%";
    if (status === "LIVE") {
      live++;
      document.getElementById("liveCount").innerText = live;
      document.getElementById("liveCount2").innerText = live;
      document.getElementById("liveBox").value += uid + "\n";
    } else {
      die++;
      document.getElementById("dieCount").innerText = die;
      document.getElementById("dieCount2").innerText = die;
      document.getElementById("dieBox").value += uid + "\n";
    }
  }

  async function worker(){
    while (queue.length) {
      let batch = queue.splice(0, BATCH);
      try {
        let res = await fetch("index.php?action=check&id=" + batch.join(","));
        let data = await res.json();
        if (!Array.isArray(data)) data = [data];
        data.forEach(item => processResult(item.uid, item.status));
      } catch (e) {
        batch.forEach(id => processResult(id, "DIE"));
      }
    }
  }

  await Promise.all(Array.from({length: CONCURRENT}, () => worker()));

  checking = false;
  document.getElementById("startBtn").innerHTML = "<i class='fas fa-play'></i> Bắt đầu Check";
  document.getElementById("startBtn").disabled = false;
  document.getElementById("clearBtn").disabled = false;
}

function clearAll(){
  if (checking) return;
  document.getElementById("input").value = "";
  document.getElementById("liveBox").value = "";
  document.getElementById("dieBox").value = "";
  ["liveCount","dieCount","liveCount2","dieCount2","totalCount","totalCountBox","checkedCount"].forEach(id => document.getElementById(id).innerText = 0);
  document.getElementById("progressBar").style.width = "0%";
}
</script>
HTML;
$content = str_replace(
    ['__FB_CONCURRENT__', '__FB_BATCH__'],
    [
        max(1, min(50, (int)dvproEnv('FACEBOOK_CONCURRENT', '10'))),
        max(1, min(1000, (int)dvproEnv('FACEBOOK_MAX_BATCH', '100'))),
    ],
    $content
);
$content = str_replace('__DVPRO_NONCE_ATTR__', function_exists('dvproNonceAttr') ? dvproNonceAttr() : '', $content);
include dirname(__DIR__, 2) . '/layout.php';
