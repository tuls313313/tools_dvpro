<?php
require_once dirname(__DIR__) . '/env.php';
/**
 * helpers.php
 * Các hàm trợ giúp cho converter
 */

/**
 * Tạo response JSON chuẩn
 */
function jsonResponse($status, $message = '', $data = [])
{
    return json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Validate JSON format
 */
function isValidJSON($string)
{
    json_decode($string);
    return (json_last_error() === JSON_ERROR_NONE);
}

/**
 * Parse JWT token
 */
function parseJWTToken($token)
{
    try {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode($parts[1]), true);
        return $payload;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Kiểm tra token expiry
 */
function checkTokenExpiry($token)
{
    $payload = parseJWTToken($token);
    if (!$payload || !isset($payload['exp'])) {
        return false;
    }

    return time() < $payload['exp'];
}

/**
 * Format file size
 */
function formatFileSize($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Get IP address
 */
function getClientIP()
{
    if (function_exists('dvproClientIp')) {
        return dvproClientIp();
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

/**
 * Rate limiter
 */
class RateLimiter
{
    private $max_requests = 100;
    private $time_window = 3600; // 1 hour

    public function __construct($max_requests = 100, $time_window = 3600)
    {
        $this->max_requests = $max_requests;
        $this->time_window = $time_window;
    }

    /**
     * Check rate limit
     */
    public function check($identifier)
    {
        return dvproRateLimit('convert:legacy:' . (string)$identifier, $this->max_requests, $this->time_window);
    }

    /**
     * Get remaining requests
     */
    public function getRemaining($identifier)
    {
        try {
            $bucketKey = hash('sha256', 'convert:legacy:' . (string)$identifier);
            $st = dvproDatabase()->prepare(
                'SELECT window_start, request_count FROM app_rate_limits WHERE bucket_key = ? LIMIT 1'
            );
            $st->execute([$bucketKey]);
            $data = $st->fetch(PDO::FETCH_ASSOC);
            if (!$data || time() - (int)$data['window_start'] >= $this->time_window) {
                return $this->max_requests;
            }
            return max(0, $this->max_requests - (int)$data['request_count']);
        } catch (Throwable $e) {
            return dvproEnvBool('DB_RATE_LIMIT_FAIL_OPEN', false) ? $this->max_requests : 0;
        }
    }
}

/**
 * Logger utility
 */
class Logger
{
    public function __construct($log_dir = null) {}

    /**
     * Log message
     */
    public function log($level, $message, $data = [])
    {
        dvproLog('convert', (string)$level, (string)$message, is_array($data) ? $data : []);
    }

    /**
     * Log info
     */
    public function info($message, $data = [])
    {
        $this->log('info', $message, $data);
    }

    /**
     * Log error
     */
    public function error($message, $data = [])
    {
        $this->log('error', $message, $data);
    }

    /**
     * Log warning
     */
    public function warning($message, $data = [])
    {
        $this->log('warning', $message, $data);
    }

    /**
     * Get logs
     */
    public function getLogs($days = 1)
    {
        $days = max(1, (int)$days);
        $st = dvproDatabase()->prepare(
            'SELECT created_at, level, message, context_json, ip FROM app_logs
             WHERE channel = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)
             ORDER BY id DESC'
        );
        $st->execute(['convert']);
        return array_map(static function (array $row): string {
            return sprintf(
                '[%s] %s | %s | Data: %s | IP: %s',
                $row['created_at'],
                strtoupper((string)$row['level']),
                $row['message'],
                $row['context_json'] ?: '{}',
                $row['ip'] ?: 'unknown'
            );
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }
}

?>
