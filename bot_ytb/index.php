<?php
$title = "Bot YTB - DVPro.vn";
$content = <<<'HTML'
<div class="page-wrap">
  <div class="tool-hero">
    <div class="tool-hero-main">
      <div class="tool-icon red"><i class="fab fa-youtube"></i></div>
      <div>
        <h1>Bot YouTube <span>@bot_ytb_bot</span></h1>
        <p>Theo dõi kênh YouTube tự động và nhận thông báo video mới ngay trên Telegram.</p>
      </div>
    </div>
    <div class="tool-hero-actions">
      <span class="pill pill-green"><i class="fas fa-circle" style="font-size:8px"></i> Đang hoạt động</span>
      <a href="https://t.me/bot_ytb_bot" target="_blank" rel="noopener" class="btn btn-primary"><i class="fab fa-telegram-plane"></i> Mở bot</a>
    </div>
  </div>

  <div class="tool-grid-2">
    <section class="tool-panel">
      <div class="tool-panel-head">
        <h2><i class="fas fa-circle-info text-blue-300"></i> Giới thiệu</h2>
      </div>
      <p class="text-slate-300 mb-5">Bot giúp bạn theo dõi nhiều kênh YouTube cùng lúc và gửi thông báo video mới về Telegram theo thời gian thực.</p>
      <h3 class="text-white font-extrabold mb-3">Hướng dẫn nhanh</h3>
      <div class="command-list">
        <div class="command-item"><code>/add &lt;link_kênh&gt;</code><span>Thêm kênh mới để theo dõi</span></div>
        <div class="command-item"><code>/remove &lt;link_kênh&gt;</code><span>Xóa kênh đã theo dõi</span></div>
        <div class="command-item"><code>/list</code><span>Liệt kê tất cả kênh đang theo dõi</span></div>
      </div>
    </section>

    <section class="tool-panel" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center">
      <div class="tool-icon red mb-4" style="width:4rem;height:4rem;font-size:1.5rem"><i class="fab fa-telegram-plane"></i></div>
      <h2 class="text-white font-extrabold mb-2">Mở Bot trên Telegram</h2>
      <p class="text-slate-300 mb-5">Bấm để bắt đầu sử dụng ngay trên Telegram.</p>
      <a href="https://t.me/bot_ytb_bot" target="_blank" rel="noopener" class="btn btn-primary btn-lg mb-5"><i class="fab fa-telegram-plane"></i> Mở @bot_ytb_bot</a>
      <div class="toolbar">
        <input type="text" readonly value="@bot_ytb_bot" class="input" style="width:10rem;text-align:center">
        <button type="button" class="btn btn-amber" data-action="copy-value" data-value="@bot_ytb_bot"><i class="fas fa-copy"></i> Copy</button>
      </div>
    </section>
  </div>
</div>
HTML;
include '../layout.php';
