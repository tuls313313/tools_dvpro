<?php
$title = 'Donate - Ủng hộ DVPro Tools';
$description = 'Ủng hộ DVPro Tools để duy trì server, cập nhật công cụ miễn phí và hỗ trợ cộng đồng dịch vụ số.';
$keywords = 'donate DVPro, ủng hộ DVPro Tools, chuyển khoản MB Bank';

$content = <<<'HTML'
<div class="page-wrap">
  <section class="hero mb-6">
    <div class="hero-kicker"><i class="fas fa-heart"></i> Duy trì công cụ miễn phí</div>
    <h1>Ủng hộ <span>DVPro Tools</span></h1>
    <p>Mọi đóng góp đều giúp duy trì server, cập nhật tool mới và giữ các tiện ích miễn phí cho cộng đồng.</p>
  </section>

  <section class="donate-grid mb-6">
    <div class="card u-p-5">
      <div class="flex items-center justify-between gap-3 mb-4">
        <h2 class="text-xl font-extrabold text-white m-0 flex items-center gap-2">
          <i class="fas fa-university text-blue-400"></i> Thông tin chuyển khoản
        </h2>
        <span class="pill pill-blue">MB Bank</span>
      </div>
      <div class="space-y-3">
        <div class="info-row"><span>Ngân hàng</span><strong>MB Bank</strong></div>
        <div class="info-row">
          <span>Số tài khoản</span>
          <div class="flex items-center gap-2">
            <strong id="account-number">0971810376</strong>
            <button type="button" class="copy-btn" data-action="copy-account" title="Sao chép STK"><i class="fas fa-copy"></i></button>
          </div>
        </div>
        <div class="info-row"><span>Chủ tài khoản</span><strong>Nguyễn Thành Tú</strong></div>
        <div class="info-row"><span>Nội dung gợi ý</span><strong>Donate DVPro</strong></div>
      </div>
      <div class="note"><i class="fas fa-triangle-exclamation"></i> <strong>Lưu ý:</strong> Vui lòng kiểm tra kỹ số tài khoản và tên chủ tài khoản trước khi chuyển.</div>
    </div>
    <div class="card qr-box">
      <h2 class="text-xl font-extrabold text-white m-0 mb-2 flex items-center gap-2"><i class="fas fa-qrcode text-green-400"></i> Quét QR để chuyển</h2>
      <p class="text-text-muted text-sm mb-4">Dùng app ngân hàng quét mã VietQR bên dưới.</p>
      <img src="https://api.vietqr.io/mb/0971810376/0/Donate%20Dvpro/vietqr_net_2.jpg?accountName=NGUYEN%20THANH%20TU" alt="QR Code MB Bank" width="280" height="280" loading="lazy" decoding="async">
    </div>
  </section>

  <section class="card contact-banner">
    <h3>Cảm ơn bạn đã đồng hành!</h3>
    <p>Nếu cần xác nhận giao dịch hoặc góp ý tool mới, hãy nhắn trực tiếp qua Zalo hoặc Telegram.</p>
    <div class="contact-actions">
      <a href="https://zalo.me/0971810376" target="_blank" rel="noopener" class="btn btn-green"><i class="fas fa-comment-dots"></i> Chat Zalo</a>
      <a href="https://t.me/ntt3132004" target="_blank" rel="noopener" class="btn btn-primary"><i class="fab fa-telegram-plane"></i> Telegram</a>
      <a href="tools.php" class="btn btn-secondary"><i class="fas fa-toolbox"></i> Về Tools</a>
    </div>
  </section>
</div>
HTML;

include __DIR__ . '/layout.php';
