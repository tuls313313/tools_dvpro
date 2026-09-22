<?php
require_once __DIR__ . '/env.php';

dvproSendCspHeader();
date_default_timezone_set((string) dvproEnv('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$isLocal = dvproIsLocalHost();
$siteUrl = dvproAppUrl();
$brandUrl = dvproBrandUrl();
$assetUrl = $siteUrl;
if (!$isLocal && function_exists('dvproCurrentHost') && function_exists('dvproAllowedHosts')) {
    $requestHost = dvproCurrentHost();
    if (in_array($requestHost, dvproAllowedHosts(), true)) {
        $assetUrl = 'https://' . $requestHost . '/';
    }
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = preg_replace('#/+#', '/', $requestPath);
$requestPath = preg_replace('#^/(?:tools?\.dvpro\.vn)(?=/|$)#', '', $requestPath) ?: '/';
$requestPath = in_array($requestPath, ['/tools.dvpro.vn', '/tools.dvpro.vn'], true) ? '/' : $requestPath;

$defaultImage = 'https://dvpro.vn/uploads/24-04-2026/f431d4c6-d5b7-4951-8632-79e71ee86a1a.png';
$seoTitle = trim($title ?? (dvproEnv('APP_NAME', 'DVPro Tools') . ' - Công cụ miễn phí cho mạng xã hội'));
    $seoDescription = trim($description ?? 'DVPro Tools cung cấp công cụ miễn phí: quản lý cron, lấy mã 2FA, kiểm tra UID Facebook, bot tự động và tiện ích hỗ trợ dịch vụ số.');
    $seoKeywords = trim($keywords ?? 'DVPro Tools, công cụ miễn phí, cron online, 2FA, check UID Facebook, bot tự động, tools mạng xã hội');
$seoImage = $image ?? $defaultImage;
$canonicalUrl = $canonical ?? rtrim($siteUrl, '/') . ($requestPath === '/' ? '/' : $requestPath);
$currentPage = basename($requestPath) ?: 'index.php';
$manifestUrl = $siteUrl . 'site.webmanifest';
$layoutVersion = (int)(@filemtime(__FILE__) ?: 1);
$assetVersion = static function (string $path) use ($layoutVersion): int {
    $assetVersion = (int)(@filemtime(__DIR__ . '/' . ltrim($path, '/')) ?: 1);
    return max($layoutVersion, $assetVersion);
};
$faviconIco = $assetUrl . 'favicon.ico?v=' . $assetVersion('favicon.ico');
$faviconPng = $assetUrl . 'favicon.png?v=' . $assetVersion('favicon.png');
$cssApp = $assetUrl . 'assets/css/app.css?v=' . $assetVersion('assets/css/app.css');
$cssFa = $assetUrl . 'assets/css/fa.min.css?v=' . $assetVersion('assets/css/fa.min.css');
$jsApp = $assetUrl . 'assets/js/app.js?v=' . $assetVersion('assets/js/app.js');
$logoImage = $logoImage ?? ($assetUrl . 'favicon.ico?v=' . $assetVersion('favicon.ico'));
$cspNonce = dvproCspNonce();
$nonceAttr = dvproNonceAttr();
$isHome = ($currentPage === 'index.php' || $currentPage === '' || $requestPath === '/');
$isTools = ($currentPage === 'tools.php');
$isBots = ($currentPage === 'bots.php');
$isDonate = ($currentPage === 'donate.php');
$structuredData = $structuredData ?? [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => dvproEnv('APP_NAME', 'DVPro Tools'),
    'url' => rtrim(dvproEnv('APP_URL', 'https://tools.dvpro.vn'), '/') . '/',
    'description' => $seoDescription,
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'DVPro.vn',
        'url' => rtrim(dvproEnv('APP_BRAND_URL', 'https://dvpro.vn'), '/') . '/',
        'logo' => [
            '@type' => 'ImageObject',
            'url' => $defaultImage,
        ],
    ],
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => rtrim(dvproEnv('APP_URL', 'https://tools.dvpro.vn'), '/') . '/tools.php?q={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
];
?><!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
<meta name="description" content="<?=e($seoDescription)?>">
<meta name="keywords" content="<?=e($seoKeywords)?>">
<meta name="robots" content="index,follow">
<meta name="author" content="DVPro.vn">
<meta name="application-name" content="DVPro Tools">
<meta name="apple-mobile-web-app-title" content="DVPro Tools">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="format-detection" content="telephone=no">
<meta name="theme-color" content="#0b1220">
<link rel="canonical" href="<?=e($canonicalUrl)?>">
<link rel="manifest" href="<?=e($manifestUrl)?>">
<link rel="icon" href="<?=e($faviconIco)?>" sizes="any">
<link rel="icon" href="<?=e($faviconPng)?>" type="image/png" sizes="512x512">
<link rel="shortcut icon" href="<?=e($faviconIco)?>">
<link rel="apple-touch-icon" href="<?=e($faviconPng)?>">
<meta property="og:locale" content="vi_VN">
<meta property="og:site_name" content="DVPro Tools">
<meta property="og:image" content="<?=e($seoImage)?>">
<meta property="og:image:secure_url" content="<?=e($seoImage)?>">
<meta property="og:image:alt" content="<?=e($seoTitle)?>">
<meta property="og:title" content="<?=e($seoTitle)?>">
<meta property="og:description" content="<?=e($seoDescription)?>">
<meta property="og:url" content="<?=e($canonicalUrl)?>">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?=e($seoTitle)?>">
<meta name="twitter:description" content="<?=e($seoDescription)?>">
<meta name="twitter:image" content="<?=e($seoImage)?>">
<meta name="twitter:image:alt" content="<?=e($seoTitle)?>">
<style <?= $nonceAttr ?>>
html,body{margin:0;padding:0;min-height:100%;background:#070b14;color:#f8fafc}
body{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.55}
a{color:#38bdf8;text-decoration:none}
button,input,textarea,select{font:inherit}
img{max-width:100%;height:auto}
</style>
<link rel="preload" href="<?=e($cssApp)?>" as="style">
<link id="dvpro-core-style" rel="stylesheet" href="<?=e($cssApp)?>">
<link id="dvpro-icon-style" rel="stylesheet" href="<?=e($cssFa)?>" media="print" data-fa-defer="1">
<script <?= $nonceAttr ?>>
(function () {
  var retried = {};
  function retryStyle(id, path, version) {
    if (retried[id]) return;
    retried[id] = true;
    var link = document.getElementById(id);
    if (!link) return;
    var url = new URL(path, window.location.origin);
    url.searchParams.set('v', version);
    url.searchParams.set('retry', Date.now().toString());
    link.href = url.toString();
  }

  var core = document.getElementById('dvpro-core-style');
  var icons = document.getElementById('dvpro-icon-style');
  if (core) core.addEventListener('error', function () {
    retryStyle('dvpro-core-style', '/assets/css/app.css', <?=json_encode((string)$assetVersion('assets/css/app.css'))?>);
  });
  if (icons) icons.addEventListener('error', function () {
    retryStyle('dvpro-icon-style', '/assets/css/fa.min.css', <?=json_encode((string)$assetVersion('assets/css/fa.min.css'))?>);
  });

  window.addEventListener('load', function () {
    var primary = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();
    if (!primary) {
      retryStyle('dvpro-core-style', '/assets/css/app.css', <?=json_encode((string)$assetVersion('assets/css/app.css'))?>);
    }
  });
})();
</script>
<title><?=e($seoTitle)?></title>
<script <?= $nonceAttr ?> type="application/ld+json"><?=json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?></script>

</head>
<body class="dark site-shell">
<nav class="nav-shell" aria-label="Main">
  <div class="nav-inner">
    <a href="<?=$siteUrl?>" class="brand">
      <img src="<?=e($logoImage)?>" alt="DVPro" width="36" height="36" decoding="async" fetchpriority="high">
      <span class="brand-copy">
        <strong>DVPro Tools</strong>
        <span>Miễn phí • Nhanh • Dễ dùng</span>
      </span>
    </a>

    <div class="nav-links">
      <a href="<?=$siteUrl?>" class="nav-link <?=$isHome ? 'active' : ''?>"><i class="fas fa-house" aria-hidden="true"></i> Trang chủ</a>
      <a href="<?=$siteUrl?>tools.php" class="nav-link <?=$isTools ? 'active' : ''?>"><i class="fas fa-toolbox" aria-hidden="true"></i> Tools</a>
      <a href="<?=$siteUrl?>bots.php" class="nav-link <?=$isBots ? 'active' : ''?>"><i class="fas fa-robot" aria-hidden="true"></i> Bot</a>
      <a href="<?=$siteUrl?>donate.php" class="nav-link <?=$isDonate ? 'active' : ''?>"><i class="fas fa-heart" aria-hidden="true"></i> Donate</a>
    </div>

    <a href="<?=$siteUrl?>tools.php" class="nav-cta"><i class="fas fa-bolt" aria-hidden="true"></i> Dùng ngay</a>
    <button id="hamburger" class="menu-btn" type="button" aria-label="Mở menu" aria-expanded="false" aria-controls="mobile-menu">
      <i class="fas fa-bars" aria-hidden="true"></i>
    </button>
  </div>

  <div id="mobile-menu" class="mobile-panel" hidden>
    <a href="<?=$siteUrl?>" class="<?=$isHome ? 'active' : ''?>">
      <span><i class="fas fa-house" aria-hidden="true"></i> Trang chủ</span>
      <i class="fas fa-chevron-right" aria-hidden="true"></i>
    </a>
    <a href="<?=$siteUrl?>tools.php" class="<?=$isTools ? 'active' : ''?>">
      <span><i class="fas fa-toolbox" aria-hidden="true"></i> Tools</span>
      <span class="badge">Free</span>
    </a>
    <a href="<?=$siteUrl?>bots.php" class="<?=$isBots ? 'active' : ''?>">
      <span><i class="fas fa-robot" aria-hidden="true"></i> Bot</span>
      <i class="fas fa-chevron-right" aria-hidden="true"></i>
    </a>
    <a href="<?=$siteUrl?>donate.php" class="<?=$isDonate ? 'active' : ''?>">
      <span><i class="fas fa-heart" aria-hidden="true"></i> Donate</span>
      <i class="fas fa-chevron-right" aria-hidden="true"></i>
    </a>
    <a href="<?=$brandUrl?>" target="_blank" rel="noopener">
      <span><i class="fas fa-globe" aria-hidden="true"></i> DVPro.vn</span>
      <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i>
    </a>
  </div>
</nav>

<main class="site-main"><?=$content ?? ''?></main>

<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-brand">
      <img src="<?=e($logoImage)?>" alt="DVPro" width="32" height="32" loading="lazy" decoding="async">
      <div>
        <h3>DVPro Tools</h3>
        <p>Bộ công cụ và bot miễn phí hỗ trợ dịch vụ số, kiểm tra tài khoản, xử lý dữ liệu, 2FA, cron job và bot tự động.</p>
      </div>
    </div>
    <div class="footer-links">
      <a href="<?=$brandUrl?>">DVPro.vn</a>
      <a href="<?=$siteUrl?>tools.php">Tools</a>
      <a href="<?=$siteUrl?>bots.php">Bot</a>
      <a href="<?=$siteUrl?>donate.php">Donate</a>
      <a href="<?=$siteUrl?>cron/">Cron</a>
    </div>
    <div class="footer-copy">
      <span>© <?=date('Y')?> DVPro.vn — All rights reserved.</span>
      <span>Production ready • tools.dvpro.vn</span>
    </div>
  </div>
</footer>

<script <?= $nonceAttr ?> src="https://chathub.dvpro.vn/livechat-embed.js" data-channel="54sGvk9FaOcDOcCSoRkT9ROV" defer></script>
<script <?= $nonceAttr ?> src="<?=e($jsApp)?>" defer></script>
</body>
</html>


