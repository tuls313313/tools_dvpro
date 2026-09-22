<?php
$title = 'Danh sách Bot - DVPro Tools';
$description = 'Tổng hợp bot miễn phí của DVPro: bot YouTube Telegram, bot hỗ trợ dịch vụ số và các bot sắp ra mắt.';
$keywords = 'DVPro bot, bot youtube, bot telegram, bot tự động';

$content = <<<'HTML'
<div class="page-wrap">
  <section class="hero mb-6">
    <div class="hero-kicker"><i class="fas fa-robot"></i> Bot tự động miễn phí</div>
    <h1>Bộ sưu tập <span>Bot</span> DVPro</h1>
    <p>Các bot hỗ trợ theo dõi, thông báo và tự động hóa công việc hằng ngày. Giao diện rõ ràng, mở bot chỉ với một chạm.</p>
  </section>

  <section class="grid-tools">
    <a href="bot_ytb/index.php" class="tool-card">
      <div class="icon icon-grad-yt"><i class="fab fa-youtube"></i></div>
      <h3>Bot YouTube</h3>
      <p>Theo dõi kênh YouTube tự động và nhận thông báo video mới ngay trên Telegram.</p>
      <div class="meta"><span class="pill pill-green"><i class="fas fa-circle text-[8px]"></i> Đang hoạt động</span><span>Chi tiết <i class="fas fa-arrow-right"></i></span></div>
    </a>
    <div class="tool-card" class="is-disabled-card">
      <div class="icon icon-grad-plus"><i class="fas fa-plus"></i></div>
      <h3>Bot sắp ra mắt</h3>
      <p>Các bot mới cho mạng xã hội sẽ được cập nhật tại đây.</p>
      <div class="meta"><span class="pill pill-blue">Coming soon</span><a href="https://t.me/ntt3132004" target="_blank" rel="noopener">Góp ý</a></div>
    </div>
  </section>

  <section class="card contact-banner mt-6">
    <h3>Muốn bot riêng cho quy trình của bạn?</h3>
    <p>Gửi ý tưởng bot qua Zalo hoặc Telegram để được tư vấn và cập nhật vào thư viện.</p>
    <div class="contact-actions">
      <a href="https://zalo.me/0971810376" target="_blank" rel="noopener" class="btn btn-green"><i class="fas fa-comment-dots"></i> Chat Zalo</a>
      <a href="https://t.me/ntt3132004" target="_blank" rel="noopener" class="btn btn-primary"><i class="fab fa-telegram-plane"></i> Telegram</a>
      <a href="tools.php" class="btn btn-secondary"><i class="fas fa-toolbox"></i> Về Tools</a>
    </div>
  </section>
</div>
HTML;

include __DIR__ . '/layout.php';
