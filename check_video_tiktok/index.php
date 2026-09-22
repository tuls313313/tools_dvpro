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
    <textarea id="video_url" class="textarea pretty-scroll ttkv-input" rows="8" spellcheck="false" placeholder="https://www.tiktok.com/@user/video/..."></textarea>
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
    </div>
    <div class="table-wrap ttkv-table-wrap">
      <table class="ttkv-table">
        <thead>
          <tr>
            <th>Video</th>
            <th>Author</th>
            <th>Desc</th>
            <th>Like</th>
            <th>Comment</th>
            <th>Share</th>
            <th>Play</th>
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

let success = 0
let fail = 0
let total = 0


function updateStats(){

document.getElementById("sumOk").innerText = success
document.getElementById("sumFail").innerText = fail
document.getElementById("sumTotal").innerText = total

}


async function checkLink(link){

const fd = new FormData()
fd.append("video_url", link)

const res = await fetch("./php/logic.php?action=check_tiktok",{
method:"POST", headers:{"X-CSRF-Token":(window.dvproCsrf||"")},
body:fd
})

const reader = res.body.getReader()
const decoder = new TextDecoder()

let result = null

while(true){

const {value,done} = await reader.read()
if(done) break

const text = decoder.decode(value)

const lines = text.split("\n")

for(const line of lines){

if(!line.trim()) continue

const data = JSON.parse(line)

if(data.type === "row"){
result = data
}

}

}

return result
}



startBtn.onclick = async () => {
    let raw = ta.value.trim();
    if (!raw) return alert('Nhập link');

    let links = raw.split('\n').map(x => x.trim()).filter(Boolean);
    total = links.length;
    success = 0;
    fail = 0;
    updateStats();
    resultTable.innerHTML = '';
    startBtn.disabled = true;
    clearBtn.disabled = true;
    startBtn.innerText = 'Đang kiểm tra...';

    const concurrent = __VIDEO_TT_CONCURRENT__;
    let nextIndex = 0;
    let checked = 0;

    async function worker() {
        while (true) {
            const index = nextIndex++;
            if (index >= links.length) return;
            const link = links[index];
            let r;
            try {
                r = await checkLink(link);
                if (r.status === 'SUCCESS') success++; else fail++;
            } catch (e) {
                r = { status: 'ERROR', error: 'Lỗi mạng' };
                fail++;
            }

            checked++;
            updateStats();
            progressBar.style.width = (checked / total * 100) + '%';
            resultTable.insertAdjacentHTML('beforeend', `
                <tr class="border-b border-gray-800">
                    <td class="p-4 text-pink-400 break-all">${link}</td>
                    <td class="p-4 text-center text-yellow-400">${r.item_id ?? '-'}</td>
                    <td class="p-4 text-center ${r.status === 'SUCCESS' ? 'text-green-400' : 'text-red-400'}">${r.status ?? 'ERROR'}</td>
                    <td class="p-4 text-center">${r.digg_count ?? '-'}</td>
                    <td class="p-4 text-center">${r.comment_count ?? '-'}</td>
                    <td class="p-4 text-center">${r.share_count ?? '-'}</td>
                    <td class="p-4 text-center">${r.play_count ?? '-'}</td>
                </tr>
            `);
        }
    }

    await Promise.all(Array.from({ length: Math.min(concurrent, links.length) }, worker));
    startBtn.disabled = false;
    clearBtn.disabled = false;
    startBtn.innerText = 'Kiểm tra';
};

clearBtn.onclick = () => {

if(clearBtn.disabled) return

ta.value=""
resultTable.innerHTML=""

progressBar.style.width="0%"

success=0
fail=0
total=0

updateStats()

}

</script>
HTML;
$content = str_replace(['__DVPRO_NONCE_ATTR__', '__DVPRO_CSRF_JSON__', '__VIDEO_TT_CONCURRENT__'], [function_exists('dvproNonceAttr') ? dvproNonceAttr() : '', json_encode(function_exists('dvproCsrfToken') ? dvproCsrfToken() : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), max(1, min(10, (int)dvproEnv('VIDEO_TIKTOK_CONCURRENT', '3')))], $content);
include '../layout.php';
