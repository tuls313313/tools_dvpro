<?php
$title = 'Danh sách Tools - DVPro Tools';
$description = 'Danh sách công cụ miễn phí của DVPro: check UID Facebook, Instagram, TikTok, 2FA, mail OTP, cắt data, convert, cron job và nhiều tiện ích khác.';
$keywords = 'DVPro Tools, danh sách tools, check UID Facebook, 2FA, mail OTP, cron job, convert data';

$content = <<<'HTML'
<div class="page-wrap">
  <section class="hero mb-6">
    <div class="hero-kicker"><i class="fas fa-toolbox"></i> Thư viện công cụ miễn phí</div>
    <h1>Tất cả <span>Tools</span> của DVPro</h1>
    <p>Tìm nhanh công cụ bạn cần. Giao diện card rõ ràng, dễ bấm trên điện thoại và nhìn thoáng trên PC.</p>
    <div class="mt-5">
      <label class="search-box" for="tool-search">
        <i class="fas fa-magnifying-glass"></i>
        <input id="tool-search" type="search" placeholder="Tìm tool: facebook, 2fa, mail, cron, tiktok..." autocomplete="off">
      </label>
    </div>
  </section>

  <section>
    <div class="section-head flex items-center justify-between gap-3">
      <div>
        <h2>Danh mục công cụ</h2>
        <p id="tool-count">Đang hiển thị tất cả tools</p>
      </div>
    </div>

    <div id="tools-grid" class="grid-tools">
      <a href="check_uid_facebook/index.php" class="tool-card" data-keywords="check uid facebook fb uid social">
        <div class="icon icon-grad-fb"><i class="fab fa-facebook-f"></i></div>
        <h3>Check UID Facebook</h3>
        <p>Kiểm tra và lấy UID Facebook từ link hoặc username.</p>
        <div class="meta"><span class="pill pill-blue">Facebook</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="check_usname_instagram/index.php" class="tool-card" data-keywords="instagram username ig check">
        <div class="icon icon-grad-ig"><i class="fab fa-instagram"></i></div>
        <h3>Check Username Instagram</h3>
        <p>Kiểm tra username Instagram, hỗ trợ xử lý danh sách nhanh.</p>
        <div class="meta"><span class="pill pill-pink">Instagram</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="check_usname_tiktok/index.php" class="tool-card" data-keywords="tiktok username check">
        <div class="icon icon-grad-tt"><i class="fab fa-tiktok"></i></div>
        <h3>Check Username TikTok</h3>
        <p>Kiểm tra username TikTok và trạng thái tài khoản.</p>
        <div class="meta"><span class="pill pill-slate">TikTok</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="check_video_tiktok/index.php" class="tool-card" data-keywords="tiktok video check link">
        <div class="icon icon-grad-ttv"><i class="fas fa-video"></i></div>
        <h3>Check Video TikTok</h3>
        <p>Phân tích và kiểm tra thông tin video TikTok từ link.</p>
        <div class="meta"><span class="pill pill-green">TikTok</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="check_threads/index.php" class="tool-card" data-keywords="threads meta username check">
        <div class="icon icon-grad-th"><i class="fas fa-at"></i></div>
        <h3>Check Threads</h3>
        <p>Kiểm tra tài khoản Threads và thông tin liên quan.</p>
        <div class="meta"><span class="pill pill-slate">Threads</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="2fa/index.php" class="tool-card" data-keywords="2fa totp authenticator otp security">
        <div class="icon icon-grad-2fa"><i class="fas fa-shield-halved"></i></div>
        <h3>TOTP / 2FA</h3>
        <p>Tạo mã OTP 2FA tương thích Google Authenticator.</p>
        <div class="meta"><span class="pill pill-blue">Security</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="mail/index.php" class="tool-card" data-keywords="mail otp hotmail outlook email">
        <div class="icon icon-grad-mail"><i class="fas fa-envelope"></i></div>
        <h3>Check Mail / OTP</h3>
        <p>Đọc mail và lấy mã OTP nhanh cho nhiều loại hộp thư.</p>
        <div class="meta"><span class="pill pill-pink">Mail</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
<a href="convert/index.php" class="tool-card" data-keywords="convert chatgpt session json">
        <div class="icon icon-grad-convert"><i class="fas fa-right-left"></i></div>
        <h3>Convert Session JSON</h3>
        <p>Chuyển đổi session JSON ChatGPT sang các định dạng khác.</p>
        <div class="meta"><span class="pill pill-blue">Convert</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="link/index.php" class="tool-card" data-keywords="link url short check">
        <div class="icon icon-grad-link"><i class="fas fa-link"></i></div>
        <h3>Công cụ Link</h3>
        <p>Xử lý link, rút gọn, kiểm tra và phân tích URL.</p>
        <div class="meta"><span class="pill pill-blue">Link</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="cut_data/index.php" class="tool-card" data-keywords="cut data tach cot loc du lieu">
        <div class="icon icon-grad-cut"><i class="fas fa-scissors"></i></div>
        <h3>Cắt dữ liệu</h3>
        <p>Cắt, lọc và tách dữ liệu theo cột hoặc ký tự phân cách.</p>
        <div class="meta"><span class="pill pill-blue">Data</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
      <a href="cron/" class="tool-card" data-keywords="cron job schedule url auto">
        <div class="icon icon-grad-cron"><i class="fas fa-clock"></i></div>
        <h3>Cron Job</h3>
        <p>Quản lý cron tự động gọi URL, theo dõi lịch sử chạy.</p>
        <div class="meta"><span class="pill pill-green">Automation</span><span>Mở tool <i class="fas fa-arrow-right"></i></span></div>
      </a>
    </div>

    <div id="tools-empty" class="card contact-banner mt-4 u-hidden">
      <h3>Không tìm thấy tool phù hợp</h3>
      <p>Thử từ khóa khác như <strong>facebook</strong>, <strong>2fa</strong>, <strong>mail</strong> hoặc <strong>cron</strong>.</p>
    </div>
  </section>
</div>
HTML;

include __DIR__ . '/layout.php';
