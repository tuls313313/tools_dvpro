<?php
require_once dirname(__DIR__, 3) . '/env.php';


class MailModel
{
    private string $apiUrl;
    private string $exchangeCodeApiUrl;
    private string $gluluLookupApiUrl;
    private string $gluluDomain;
    private string $gluluMailDomain;

    private int $timeout;
    private int $maxTop;
    private int $maxAccountLength = 2500;

    public function __construct()
    {
        $this->apiUrl = (string)dvproEnv('MAIL_API_URL', '');
        $this->exchangeCodeApiUrl = (string)dvproEnv('MAIL_EXCHANGE_CODE_API_URL', '');
        $this->gluluLookupApiUrl = (string)dvproEnv('MAIL_GLULU_LOOKUP_API_URL', '');
        $this->gluluDomain = (string)dvproEnv('MAIL_GLULU_DOMAIN', '');
        $this->gluluMailDomain = (string)dvproEnv('MAIL_GLULU_EMAIL_DOMAIN', '');
        $this->timeout = max(1, (int)dvproEnv('MAIL_TIMEOUT', '30'));
        $this->maxTop = max(1, (int)dvproEnv('MAIL_MAX_TOP', '10'));
    }

    public function readMail(string $account, string $listMail = 'all', int $top = 10): array
    {
        $accountTrim = trim($account);

        // Email-only input is allowed for temporary/exchange mailboxes only.
        // Never auto-load password/refresh_token from mail_accounts.txt on public web.
        if (filter_var($accountTrim, FILTER_VALIDATE_EMAIL)) {
            $allowStoredLookup = function_exists('dvproEnvBool')
                ? dvproEnvBool('MAIL_ALLOW_STORED_LOOKUP', false)
                : false;

            if ($allowStoredLookup) {
                $storedAccount = $this->findStoredAccountByEmail($accountTrim);
                if ($storedAccount !== null) {
                    return $this->readMail($storedAccount, $listMail, $top);
                }
            }

            if ($this->isGluluEmail($accountTrim)) {
                return [
                    'email' => $accountTrim,
                    'status' => false,
                    'error' => 'Can nhap day du email|password (khong tu dong lay tu mail_accounts.txt)',
                    'messages' => []
                ];
            }

            return $this->readExchangeCode($accountTrim);
        }

        $parsed = $this->parseAccount($account);

        if (!$parsed['status']) {
            return [
                'email' => $parsed['email'] ?? '',
                'status' => false,
                'error' => $parsed['error'],
                'messages' => []
            ];
        }

        $data = $parsed['data'];

        if ($this->isGluluEmail($data['email'])) {
            return $this->readGluluMail($data['email'], $data['password'], $top);
        }

        $allowedListMail = ['all', 'inbox'];
        if (!in_array($listMail, $allowedListMail, true)) {
            $listMail = 'all';
        }

        $top = max(1, min((int)$top, $this->maxTop));

        $payload = [
            'email'         => $data['email'],
            'password'      => $data['password'],
            'refresh_token' => $data['refresh_token'],
            'client_id'     => $data['client_id'],
            'account'       => implode('|', [
                $data['email'],
                $data['password'],
                $data['refresh_token'],
                $data['client_id']
            ]),
            'list_mail'     => $listMail,
            'top'           => $top,
        ];

        $ch = curl_init($this->apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: DVPro-Mail-Reader/1.0'
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $caFile = $this->availableCaFile();

        if ($caFile !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlError) {
            return [
                'email' => $data['email'],
                'status' => false,
                'error' => 'Không kết nối được API',
                'messages' => []
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'email' => $data['email'],
                'status' => false,
                'error' => 'API lỗi HTTP ' . $httpCode,
                'messages' => []
            ];
        }

        $json = json_decode((string)$response, true);

        if (!is_array($json)) {
            return [
                'email' => $data['email'],
                'status' => false,
                'error' => 'API trả về không phải JSON',
                'messages' => []
            ];
        }

        $json['email'] = $json['email'] ?? $data['email'];
        $json['messages'] = is_array($json['messages'] ?? null) ? $json['messages'] : [];

        return $json;
    }

    private function readGluluMail(string $email, string $password, int $top = 10): array
    {
        $payload = [
            'email' => $email,
            'password' => $password,
            'limit' => 25,
            'domain' => $this->gluluDomain,
        ];

        $ch = curl_init($this->gluluLookupApiUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: DVPro-Glulu-Mail/1.0'
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $caFile = $this->availableCaFile();

        if ($caFile !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlError) {
            return [
                'email' => $email,
                'status' => false,
                'error' => 'Khong ket noi duoc API Glulu',
                'messages' => []
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'email' => $email,
                'status' => false,
                'error' => 'API Glulu loi HTTP ' . $httpCode,
                'messages' => []
            ];
        }

        $json = json_decode((string)$response, true);

        if (!is_array($json)) {
            return [
                'email' => $email,
                'status' => false,
                'error' => 'API Glulu tra ve khong phai JSON',
                'messages' => []
            ];
        }

        if (($json['success'] ?? false) === false) {
            return [
                'email' => $json['email_address'] ?? $email,
                'status' => false,
                'error' => $json['message'] ?? 'API Glulu tra ve loi',
                'messages' => []
            ];
        }

        $messages = [];

        foreach (($json['emails'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $messages[] = [
                'from' => [
                    [
                        'name' => (string)($item['from_name'] ?? ''),
                        'address' => (string)($item['from_email'] ?? $item['from_addr'] ?? ''),
                    ]
                ],
                'subject' => (string)($item['subject'] ?? ''),
                'message' => (string)($item['body'] ?? $item['preview'] ?? ''),
                'date' => (string)($item['date'] ?? $item['received_at'] ?? ''),
                'code' => (string)($item['code'] ?? ''),
                'links' => $item['links'] ?? [],
                'raw' => $item,
            ];
        }

        $account = is_array($json['account'] ?? null) ? $json['account'] : [];
        $mailboxMode = (string)($account['mailbox_mode'] ?? '');
        $expiresAt = $account['expires_at'] ?? null;
        $remainingSeconds = $account['remaining_seconds'] ?? null;
        $mailboxType = $this->mailboxTypeFromGlulu($mailboxMode, $expiresAt, $remainingSeconds);

        return [
            'email' => $json['email_address'] ?? $email,
            'status' => true,
            'messages' => $messages,
            'mailbox_mode' => $mailboxMode,
            'mailbox_type' => $mailboxType['type'],
            'mailbox_type_label' => $mailboxType['label'],
            'expires_at' => $expiresAt,
            'remaining_seconds' => $remainingSeconds,
            'account' => $account,
            'raw' => $json,
        ];
    }

    private function mailboxTypeFromGlulu(string $mailboxMode, mixed $expiresAt, mixed $remainingSeconds): array
    {
        $mode = strtolower(trim($mailboxMode));

        if ($mode === 'permanent') {
            return [
                'type' => 'permanent',
                'label' => 'Email dài hạn',
            ];
        }

        if ($mode !== '') {
            return [
                'type' => 'temporary',
                'label' => 'Email ngắn hạn',
            ];
        }

        if ($expiresAt === null && $remainingSeconds === null) {
            return [
                'type' => 'permanent',
                'label' => 'Email dài hạn',
            ];
        }

        return [
            'type' => 'temporary',
            'label' => 'Email ngắn hạn',
        ];
    }

    private function readExchangeCode(string $email): array
    {
        $url = $this->exchangeCodeApiUrl . '?email=' . urlencode($email);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: DVPro-Exchange-Code/1.0'
            ],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($curlError) {
            return [
                'email' => $email,
                'status' => false,
                'error' => 'Không kết nối được API Exchange',
                'messages' => []
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'email' => $email,
                'status' => false,
                'error' => 'API Exchange lỗi HTTP ' . $httpCode,
                'messages' => []
            ];
        }

        $json = json_decode((string)$response, true);

        if (!is_array($json)) {
            return [
                'email' => $email,
                'status' => false,
                'error' => 'API Exchange trả về không phải JSON',
                'messages' => []
            ];
        }

        if (($json['success'] ?? false) === false) {
            $message = $json['message'] ?? 'Có lỗi xảy ra';

            if ($message === '账号不存在') {
                $message = 'Tài khoản không tồn tại';
            }

            return [
                'email' => $email,
                'status' => false,
                'error' => $message,
                'messages' => []
            ];
        }

        $code = trim((string)($json['data']['emailCode'] ?? ''));
        $time = (string)($json['data']['emailCodeTime'] ?? '');

        if ($code === '') {
            return [
                'email' => $email,
                'status' => false,
                'error' => 'Không có mã xác minh',
                'messages' => []
            ];
        }

        return [
            'email' => $email,
            'status' => true,
            'messages' => [
                [
                    'from' => [
                        [
                            'name' => 'Code gpt',
                            'address' => 'tools.dvpro.vn'
                        ]
                    ],
                    'subject' => 'Mã xác minh',
                    'message' => 'Mã xác minh: ' . $code,
                    'date' => $time,
                    'code' => $code
                ]
            ]
        ];
    }

    private function parseAccount(string $account): array
    {
        $account = trim($account);

        if ($account === '') {
            return [
                'status' => false,
                'email' => '',
                'error' => 'Tài khoản trống'
            ];
        }

        if (strlen($account) > $this->maxAccountLength) {
            return [
                'status' => false,
                'email' => '',
                'error' => 'Dòng tài khoản quá dài'
            ];
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $account)) {
            return [
                'status' => false,
                'email' => '',
                'error' => 'Dòng tài khoản chứa ký tự không hợp lệ'
            ];
        }

        if (preg_match('/https?:\/\//i', $account)) {
            return [
                'status' => false,
                'email' => '',
                'error' => 'Không được nhập URL trong tài khoản'
            ];
        }

        $parts = array_map('trim', explode('|', $account));

        if (count($parts) === 2 && $this->isGluluEmail($parts[0] ?? '')) {
            [$email, $password] = $parts;
            $refreshToken = '';
            $clientId = '';
        } elseif (count($parts) === 3) {
            [$email, $refreshToken, $clientId] = $parts;
            $password = '';
        } elseif (count($parts) === 4) {
            [$email, $password, $refreshToken, $clientId] = $parts;
        } else {
            return [
                'status' => false,
                'email' => $parts[0] ?? '',
                'error' => 'Sai định dạng. Hỗ trợ email|refresh_token|client_id hoặc email|password|refresh_token|client_id'
            ];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'status' => false,
                'email' => $email,
                'error' => 'Email không hợp lệ'
            ];
        }

        if ($this->isGluluEmail($email)) {
            if ($password === '' || strlen($password) > 500) {
                return [
                    'status' => false,
                    'email' => $email,
                    'error' => 'Password khong hop le'
                ];
            }

            return [
                'status' => true,
                'data' => [
                    'email' => $email,
                    'password' => $password,
                    'refresh_token' => '',
                    'client_id' => '',
                ]
            ];
        }

        if (!$this->safeToken($refreshToken, 20, 3000)) {
            return [
                'status' => false,
                'email' => $email,
                'error' => 'Refresh token không hợp lệ'
            ];
        }

        if (!$this->safeToken($clientId, 5, 300)) {
            return [
                'status' => false,
                'email' => $email,
                'error' => 'Client ID không hợp lệ'
            ];
        }

        if ($password !== '' && strlen($password) > 500) {
            return [
                'status' => false,
                'email' => $email,
                'error' => 'Password quá dài'
            ];
        }

        return [
            'status' => true,
            'data' => [
                'email' => $email,
                'password' => $password,
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
            ]
        ];
    }


    public function sanitizePublicMailResult(array $result, ?string $fallbackEmail = null): array
    {
        $email = trim((string)($result['email'] ?? ''));
        if ($email === '' && $fallbackEmail) {
            $email = trim((string)$fallbackEmail);
        }
        if ($email !== '' && str_contains($email, '|')) {
            $email = trim(explode('|', $email, 2)[0]);
        }

        $messages = [];
        foreach (($result['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $messages[] = $this->sanitizePublicMessage($message);
        }

        $safeAccount = null;
        if (isset($result['account']) && is_array($result['account'])) {
            $safeAccount = $this->sanitizePublicAccountMeta($result['account']);
            if ($safeAccount === []) {
                $safeAccount = null;
            }
        }

        return [
            'email' => $email,
            'status' => (bool)($result['status'] ?? !empty($messages)),
            'messages' => $messages,
            'error' => array_key_exists('error', $result)
                ? ($result['error'] !== null ? (string)$result['error'] : null)
                : null,
            'mailbox_mode' => $result['mailbox_mode'] ?? null,
            'mailbox_type' => $result['mailbox_type'] ?? null,
            'mailbox_type_label' => $result['mailbox_type_label'] ?? null,
            'expires_at' => $result['expires_at'] ?? null,
            'remaining_seconds' => $result['remaining_seconds'] ?? null,
            // Public metadata only. Never password/token/client_id/raw credentials.
            'account' => $safeAccount,
        ];
    }

    private function sanitizePublicAccountMeta(array $account): array
    {
        $safe = [];
        foreach ($account as $key => $value) {
            $k = strtolower((string)$key);
            if (
                str_contains($k, 'password') ||
                str_contains($k, 'passwd') ||
                str_contains($k, 'secret') ||
                str_contains($k, 'token') ||
                str_contains($k, 'client_id') ||
                str_contains($k, 'clientid') ||
                str_contains($k, 'authorization') ||
                str_contains($k, 'cookie') ||
                $k === 'pass' ||
                $k === 'pwd'
            ) {
                continue;
            }

            if (is_array($value)) {
                $nested = $this->sanitizePublicAccountMeta($value);
                if ($nested !== []) {
                    $safe[$key] = $nested;
                }
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function sanitizePublicMessage(array $message): array
    {
        $from = [];
        foreach (($message['from'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $from[] = [
                'name' => (string)($item['name'] ?? ''),
                'address' => (string)($item['address'] ?? $item['email'] ?? ''),
            ];
        }

        $links = [];
        if (isset($message['links']) && is_array($message['links'])) {
            foreach ($message['links'] as $link) {
                if (is_string($link) && $link !== '') {
                    $links[] = $link;
                }
            }
        }

        return [
            'from' => $from,
            'subject' => (string)($message['subject'] ?? ''),
            'message' => (string)($message['message'] ?? $message['body'] ?? $message['bodyPreview'] ?? ''),
            'date' => (string)($message['date'] ?? $message['receivedDateTime'] ?? ''),
            'code' => (string)($message['code'] ?? ''),
            'links' => $links,
        ];
    }

    private function findStoredAccountByEmail(string $email): ?string
    {
        $st = dvproDatabase()->prepare('SELECT account_line FROM mail_accounts WHERE email = ? AND is_active = 1 LIMIT 1');
        $st->execute([strtolower(trim($email))]);
        $account = $st->fetchColumn();
        return is_string($account) && trim($account) !== '' ? trim($account) : null;
    }

    private function safeToken(string $value, int $min, int $max): bool
    {
        $value = trim($value);
        $len = strlen($value);

        if ($len < $min || $len > $max) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }

        return true;
    }

    private function isGluluEmail(string $email): bool
    {
        return (bool)preg_match('/@' . preg_quote($this->gluluMailDomain, '/') . '$/i', trim($email));
    }

    private function availableCaFile(): ?string
    {
        $paths = array_filter([
            (string)dvproEnv('CURL_CA_BUNDLE', ''),
        ]);

        foreach ($paths as $path) {
            if (!$this->isAllowedPath($path)) {
                continue;
            }

            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function isAllowedPath(string $path): bool
    {
        $path = trim($path);

        if ($path === '') {
            return false;
        }

        $openBaseDir = trim((string)ini_get('open_basedir'));

        if ($openBaseDir === '') {
            return true;
        }

        $target = str_replace('\\', '/', $path);
        $targetLower = strtolower($target);

        foreach (explode(PATH_SEPARATOR, $openBaseDir) as $baseDir) {
            $baseDir = trim($baseDir);

            if ($baseDir === '') {
                continue;
            }

            $base = str_replace('\\', '/', $baseDir);
            $base = rtrim($base, '/');

            if ($base === '') {
                continue;
            }

            $baseLower = strtolower($base);

            if ($targetLower === $baseLower || str_starts_with($targetLower, $baseLower . '/')) {
                return true;
            }
        }

        return false;
    }
}
