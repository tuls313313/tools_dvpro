<?php
$title = 'DVPro Tools - Công cụ miễn phí cho mạng xã hội';
$description = 'DVPro Tools tổng hợp công cụ miễn phí: cron online, 2FA, kiểm tra UID Facebook, bot tự động và tiện ích hỗ trợ dịch vụ số.';
$keywords = 'DVPro Tools, công cụ miễn phí, cron online, 2FA, check UID Facebook, bot tự động';

$content = <<<'HTML'
<div class="page-wrap">
  <section class="hero mb-6 md:mb-8">
    <div class="hero-kicker">
      <i class="fas fa-sparkles"></i>
      Nền tảng tools miễn phí cho dịch vụ số
    </div>
    <h1>Làm việc nhanh hơn với <span>DVPro Tools</span></h1>
    <p>
      Tổng hợp công cụ kiểm tra tài khoản, xử lý dữ liệu, 2FA, cron job và bot tự động.
      Giao diện gọn, mượt trên điện thoại lẫn máy tính.
    </p>
    <div class="hero-actions">
      <a href="tools.php" class="btn btn-primary"><i class="fas fa-toolbox"></i> Khám phá Tools</a>
      <a href="bots.php" class="btn btn-secondary"><i class="fas fa-robot"></i> Xem Bot</a>
      <a href="donate.php" class="btn btn-green"><i class="fas fa-heart"></i> Ủng hộ</a>
    </div>
    <div class="stat-grid">
      <div class="stat-item"><strong>15+</strong><span>Công cụ sẵn dùng</span></div>
      <div class="stat-item"><strong>100%</strong><span>Miễn phí sử dụng</span></div>
      <div class="stat-item"><strong>24/7</strong><span>Truy cập online</span></div>
      <div class="stat-item"><strong>Mobile</strong><span>Tối ưu điện thoại</span></div>
    </div>
  </section>

  <section class="mb-8">
    <div class="section-head">
      <h2>Vì sao nên dùng?</h2>
      <p>Thiết kế lại để thao tác nhanh hơn, dễ tìm tool hơn và hiển thị đẹp trên mọi kích thước màn hình.</p>
    </div>
    <div class="feature-grid">
      <div class="card feature-item">
        <div class="fi"><i class="fas fa-bolt"></i></div>
        <h3>Nhanh & gọn</h3>
        <p>Vào tool là dùng ngay, không rối layout, phù hợp thao tác thường xuyên trên điện thoại.</p>
      </div>
      <div class="card feature-item">
        <div class="fi"><i class="fas fa-mobile-screen-button"></i></div>
        <h3>Responsive thật</h3>
        <p>Menu, card, form và khoảng cách được tinh chỉnh riêng cho mobile, tablet và desktop.</p>
      </div>
      <div class="card feature-item">
        <div class="fi"><i class="fas fa-layer-group"></i></div>
        <h3>Đủ tiện ích</h3>
        <p>Từ check UID, 2FA, mail OTP, cắt data, convert đến cron job và bot tự động.</p>
      </div>
    </div>
  </section>

  <section class="mb-8">
    <div class="section-head flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h2>Tools nổi bật</h2>
        <p>Một số công cụ được dùng nhiều nhất. Xem đầy đủ tại trang Tools.</p>
      </div>
      <a href="tools.php" class="btn btn-secondary"><i class="fas fa-arrow-right"></i> Tất cả tools</a>
    </div>
    <div class="grid-tools">
      <a href="check_uid_facebook/index.php" class="tool-card">
        <div class="icon icon-grad-fb"><i class="fab fa-facebook-f"></i></div>
        <h3>Check UID Facebook</h3>
        <p>Tra cứu UID nhanh từ link hoặc username Facebook.</p>
        <div class="meta"><span class="pill pill-blue">Social</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="2fa/index.php" class="tool-card">
        <div class="icon icon-grad-2fa"><i class="fas fa-shield-halved"></i></div>
        <h3>TOTP / 2FA</h3>
        <p>Tạo mã OTP 2FA tương thích Google Authenticator.</p>
        <div class="meta"><span class="pill pill-blue">Security</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="cron/" class="tool-card">
        <div class="icon icon-grad-cron"><i class="fas fa-clock"></i></div>
        <h3>Cron Job</h3>
        <p>Lên lịch gọi URL tự động và theo dõi lịch sử chạy.</p>
        <div class="meta"><span class="pill pill-green">Automation</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
<a href="mail/index.php" class="tool-card">
        <div class="icon icon-grad-mail"><i class="fas fa-envelope"></i></div>
        <h3>Check Mail / OTP</h3>
        <p>Đọc mail và lấy mã OTP nhanh cho nhiều loại hộp thư.</p>
        <div class="meta"><span class="pill pill-pink">Mail</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="cut_data/index.php" class="tool-card">
        <div class="icon icon-grad-cut"><i class="fas fa-scissors"></i></div>
        <h3>Cắt dữ liệu</h3>
        <p>Cắt, lọc và tách dữ liệu theo cột hoặc ký tự phân cách.</p>
        <div class="meta"><span class="pill pill-blue">Data</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="bots.php" class="tool-card">
        <div class="icon icon-grad-ttv"><i class="fas fa-robot"></i></div>
        <h3>Bot tự động</h3>
        <p>Bot hỗ trợ theo dõi kênh và nhận thông báo realtime.</p>
        <div class="meta"><span class="pill pill-green">Bot</span><span>Xem bot <i class="fas fa-arrow-right"></i></span></div>
      </a>
    </div>
  </section>

  <section class="card contact-banner">
    <h3>Cần tool mới hoặc hỗ trợ nhanh?</h3>
    <p>Nhắn trực tiếp để góp ý tính năng, báo lỗi hoặc yêu cầu tool cho quy trình làm việc của bạn.</p>
    <div class="contact-actions">
      <a href="https://zalo.me/0971810376" target="_blank" rel="noopener" class="btn btn-green"><i class="fas fa-comment-dots"></i> Chat Zalo</a>
      <a href="https://t.me/ntt3132004" target="_blank" rel="noopener" class="btn btn-primary"><i class="fab fa-telegram-plane"></i> Telegram</a>
      <a href="tools.php" class="btn btn-secondary"><i class="fas fa-toolbox"></i> Vào Tools</a>
    </div>
  </section></div>
HTML;

include __DIR__ . '/layout.php';
