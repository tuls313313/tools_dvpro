<?php
$title = "Check Threads Username - DVPro.vn";
$description = "Công cụ kiểm tra username Threads còn LIVE hay DIE, hỗ trợ check danh sách nhanh.";
$content = <<<'HTML'
<div class="page-wrap th-page">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon slate"><i class="fas fa-at"></i></div>
      <div>
        <h1>Check Live Username Threads</h1>
        <p>Kiểm tra username Threads còn <strong class="text-green-400">LIVE</strong> hay <strong class="text-red-400">DIE</strong>.</p>
      </div>
    </div>
    <div class="tool-hero-actions">
      <span class="pill pill-slate">Threads</span>
      <span class="pill pill-green">Batch</span>
    </div>
  </div>

  <section class="tool-panel">
    <div class="tool-panel-head">
      <h2><i class="fas fa-list text-slate-300"></i> Nhập danh sách username</h2>
      <span class="pill pill-slate">Mỗi username 1 dòng</span>
    </div>

    <textarea id="input" class="textarea pretty-scroll th-input" rows="9" spellcheck="false" placeholder="abc123
xyz_ig
username"></textarea>

    <div class="toolbar mt-4">
      <button id="startBtn" data-action-start="1" class="btn btn-primary" type="button"><i class="fas fa-play"></i> Bắt đầu Check</button>
      <button id="clearBtn" data-action-clear="1" class="btn btn-secondary" type="button"><i class="fas fa-trash-alt"></i> Clear</button>
      <div class="spacer"></div>
      <div class="th-progress">
        <div class="progress-shell"><div id="progressBar" class="progress-bar th-progress-bar"></div></div>
        <div class="th-progress-text"><span id="progressText">0%</span></div>
      </div>
    </div>

    <div class="stats-row mt-5">
      <div class="stat-card live"><strong id="liveCount">0</strong><span>Live</span></div>
      <div class="stat-card die"><strong id="dieCount">0</strong><span>Die</span></div>
      <div class="stat-card total"><strong id="totalCount">0</strong><span>Tổng</span></div>
    </div>
  </section>

  <div class="tool-grid-2 checker-results th-results">
    <section class="tool-panel checker-result th-result live">
      <div class="tool-panel-head">
        <h2 class="text-green-300"><i class="fas fa-check-circle"></i> Live <span class="pill pill-green">(<span id="liveCount2">0</span>)</span></h2>
        <button type="button" class="btn btn-secondary btn-sm" data-action="copy-text" data-target="liveList"><i class="fa-regular fa-copy"></i> Copy</button>
      </div>
      <textarea id="liveList" class="textarea pretty-scroll checker-result-box th-box live" rows="14" readonly placeholder="Username LIVE sẽ hiện ở đây..."></textarea>
    </section>
    <section class="tool-panel checker-result th-result die">
      <div class="tool-panel-head">
        <h2 class="text-red-300"><i class="fas fa-times-circle"></i> Die <span class="pill pill-red">(<span id="dieCount2">0</span>)</span></h2>
        <button type="button" class="btn btn-secondary btn-sm" data-action="copy-text" data-target="dieList"><i class="fa-regular fa-copy"></i> Copy</button>
      </div>
      <textarea id="dieList" class="textarea pretty-scroll checker-result-box th-box die" rows="14" readonly placeholder="Username DIE sẽ hiện ở đây..."></textarea>
    </section>
  </div>
</div>
<script __DVPRO_NONCE_ATTR__>
window.dvproCsrf = __DVPRO_CSRF_JSON__;
let live = 0, die = 0, total = 0, checked = 0;
function update(){
  document.getElementById("liveCount").innerText = live;
  document.getElementById("liveCount2").innerText = live;
  document.getElementById("dieCount").innerText = die;
  document.getElementById("dieCount2").innerText = die;
  document.getElementById("totalCount").innerText = total;
  let pct = total ? Math.floor((checked/total)*100) : 0;
  document.getElementById("progressBar").style.width = pct + "%";
  document.getElementById("progressText").innerText = pct + "%";
}
async function startCheck(){
  let startBtn = document.getElementById("startBtn");
  let clearBtn = document.getElementById("clearBtn");
  live = 0; die = 0; checked = 0;
  document.getElementById("liveList").value = "";
  document.getElementById("dieList").value = "";
  update();
  startBtn.innerHTML = "<i class='fas fa-spinner fa-spin'></i> Đang chạy...";
  startBtn.disabled = true; clearBtn.disabled = true;
  let set = new Set();
  for (let u of document.getElementById("input").value.split("\n")) {
    u = u.trim(); if(u) set.add(u);
  }
  let list = Array.from(set); total = list.length; update();
  if(total === 0){
    startBtn.innerHTML = "<i class='fas fa-play'></i> Bắt đầu Check";
    startBtn.disabled = false; clearBtn.disabled = false; return;
  }
  try{
    let res = await fetch("index.php?action=check", {
      method:"POST",
      headers:{"Content-Type":"application/x-www-form-urlencoded","X-CSRF-Token":(window.dvproCsrf||"")},
      body:"csrf_token="+encodeURIComponent(window.dvproCsrf||"")+"&username="+encodeURIComponent(list.join("\n"))
    });
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";
    while(true){
      const {value, done} = await reader.read();
      if(done) break;
      buffer += decoder.decode(value,{stream:true});
      let lines = buffer.split("\n");
      buffer = lines.pop();
      for(let line of lines){
        line = line.trim(); if(!line) continue;
        try{
          let item = JSON.parse(line);
          checked++;
          if((item.status || "").toUpperCase() === "LIVE"){
            live++; document.getElementById("liveList").value += (item.username || item.user || "") + "\n";
          } else {
            die++; document.getElementById("dieList").value += (item.username || item.user || "") + "\n";
          }
          update();
        }catch(e){}
      }
    }
  }catch(e){}
  startBtn.innerHTML = "<i class='fas fa-play'></i> Bắt đầu Check";
  startBtn.disabled = false; clearBtn.disabled = false;
}
function clearAll(){
  document.getElementById("input").value = "";
  document.getElementById("liveList").value = "";
  document.getElementById("dieList").value = "";
  live = die = total = checked = 0; update();
}

function copyBox(id, btn){
  const el = document.getElementById(id);
  if(!el) return;
  navigator.clipboard.writeText(el.value || '').then(()=>{
    if(!btn) return;
    const old = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Đã copy';
    btn.disabled = true;
    setTimeout(()=>{ btn.innerHTML = old; btn.disabled = false; }, 1100);
  });
}

document.addEventListener('click', function(e){
  var s=e.target.closest('[data-action-start]'); if(s && typeof startCheck==='function'){ startCheck(); }
  var c=e.target.closest('[data-action-clear]'); if(c && typeof clearAll==='function'){ clearAll(); }
});
</script>
HTML;
$content = str_replace(['__DVPRO_NONCE_ATTR__', '__DVPRO_CSRF_JSON__'], [function_exists('dvproNonceAttr') ? dvproNonceAttr() : '', json_encode(function_exists('dvproCsrfToken') ? dvproCsrfToken() : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $content);
include dirname(__DIR__, 2) . '/layout.php';
