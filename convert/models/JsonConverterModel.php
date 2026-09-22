<?php
/**
 * Logic chuyển đổi JSON ChatGPT session.
 */

class JsonConverterModel
{
    public function __construct()
    {
        require_once dirname(__DIR__, 2) . '/env.php';
    }

    private function parseJWT(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            $payload = strtr($parts[1], '-_', '+/');
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
            $decoded = base64_decode($payload, true);

            if ($decoded === false) {
                return null;
            }

            $data = json_decode($decoded, true);
            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function createSyntheticIdToken(string $email, string $accountId, string $planType, string $userId, int $expiresAt): string
    {
        $header = [
            'alg' => 'none',
            'typ' => 'JWT',
            'cpa_synthetic' => true
        ];

        $payload = [
            'iat' => time(),
            'exp' => $expiresAt,
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => $accountId,
                'chatgpt_plan_type' => $planType,
                'chatgpt_user_id' => $userId,
                'user_id' => $userId
            ],
            'email' => $email
        ];

        return $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES))
            . '.synthetic';
    }

    private function formatUtcIso(?string $dateValue, ?int $timestamp = null): string
    {
        if (is_string($dateValue) && trim($dateValue) !== '') {
            try {
                return (new DateTimeImmutable($dateValue))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.v\Z');
            } catch (Throwable $e) {
                // Fall through to timestamp/default current time.
            }
        }

        $time = $timestamp ?? time();
        return (new DateTimeImmutable('@' . $time))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    public function checkTokenExpiry(string $token): array
    {
        $payload = $this->parseJWT($token);
        if (!$payload || !isset($payload['exp'])) {
            return [
                'status' => 'error',
                'message' => 'Token không hợp lệ.'
            ];
        }

        $expiryTime = (int) $payload['exp'];
        $currentTime = time();
        $isExpired = $currentTime > $expiryTime;

        return [
            'status' => 'success',
            'is_expired' => $isExpired,
            'expiry_date' => date('Y-m-d H:i', $expiryTime),
            'time_remaining' => max(0, $expiryTime - $currentTime),
            'message' => $isExpired ? 'Token đã hết hạn.' : 'Token còn hiệu lực.'
        ];
    }

    public function checkTokenUsage(string $token): array
    {
        if (trim($token) === '') {
            return [
                'status' => 'error',
                'message' => 'Token chưa được cung cấp.'
            ];
        }

        $response = $this->requestWhamUsage($token);
        if ($response['error'] !== null) {
            return [
                'status' => 'error',
                'message' => $response['error']
            ];
        }

        $statusCode = $response['status_code'];
        $body = json_decode($response['body'], true);
        if (!is_array($body)) {
            return [
                'status' => 'error',
                'active' => false,
                'status_code' => $statusCode,
                'message' => 'Không đọc được phản hồi kiểm tra token.'
            ];
        }

        if ($statusCode !== 200) {
            return [
                'status' => 'error',
                'active' => false,
                'status_code' => $statusCode,
                'message' => 'Token không hoạt động hoặc không có quyền truy cập. HTTP ' . $statusCode,
                'raw' => $body
            ];
        }

        $rateLimit = isset($body['rate_limit']) && is_array($body['rate_limit']) ? $body['rate_limit'] : [];
        $primaryWindow = isset($rateLimit['primary_window']) && is_array($rateLimit['primary_window']) ? $rateLimit['primary_window'] : [];
        $credits = isset($body['credits']) && is_array($body['credits']) ? $body['credits'] : [];
        $spendControl = isset($body['spend_control']) && is_array($body['spend_control']) ? $body['spend_control'] : [];
        $allowed = (bool) ($rateLimit['allowed'] ?? false);
        $limitReached = (bool) ($rateLimit['limit_reached'] ?? false);

        return [
            'status' => 'success',
            'active' => true,
            'status_code' => $statusCode,
            'message' => 'Token đang hoạt động tốt.',
            'user_id' => $body['user_id'] ?? null,
            'account_id' => $body['account_id'] ?? null,
            'email' => $body['email'] ?? null,
            'plan_type' => $body['plan_type'] ?? null,
            'rate_limit' => [
                'allowed' => $allowed,
                'limit_reached' => $limitReached,
                'used_percent' => $primaryWindow['used_percent'] ?? null,
                'reset_after_seconds' => $primaryWindow['reset_after_seconds'] ?? null,
                'reset_at' => isset($primaryWindow['reset_at']) ? date('Y-m-d H:i', (int) $primaryWindow['reset_at']) : null
            ],
            'credits' => [
                'has_credits' => $credits['has_credits'] ?? null,
                'unlimited' => $credits['unlimited'] ?? null,
                'overage_limit_reached' => $credits['overage_limit_reached'] ?? null,
                'balance' => $credits['balance'] ?? null
            ],
            'spend_control_reached' => $spendControl['reached'] ?? null,
            'rate_limit_reached_type' => $body['rate_limit_reached_type'] ?? null
        ];
    }

    private function requestWhamUsage(string $token): array
    {
        $url = (string)dvproEnv('CONVERT_WHAM_USAGE_URL', 'https://chatgpt.com/backend-api/wham/usage');
        $caFile = $this->findCaCertFile();
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'User-Agent: Mozilla/5.0'
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => (int)dvproEnv('CONVERT_HTTP_TIMEOUT', '10'),
                CURLOPT_CONNECTTIMEOUT => (int)dvproEnv('CONVERT_CONNECT_TIMEOUT', '8'),
                CURLOPT_FOLLOWLOCATION => true
            ];

            if ($caFile !== null) {
                $options[CURLOPT_CAINFO] = $caFile;
            }

            curl_setopt_array($ch, $options);

            $body = curl_exec($ch);
            $error = curl_error($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return [
                'status_code' => $statusCode,
                'body' => is_string($body) ? $body : '',
                'error' => $body === false ? ($error ?: 'Không thể kết nối tới ChatGPT.') : null
            ];
        }

        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true
        ];

        if ($caFile !== null) {
            $sslOptions['cafile'] = $caFile;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => (int)dvproEnv('CONVERT_HTTP_TIMEOUT', '10'),
                'ignore_errors' => true
            ],
            'ssl' => $sslOptions
        ]);
        $body = @file_get_contents($url, false, $context);
        $statusCode = 0;

        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $statusCode = (int) $matches[1];
        }

        return [
            'status_code' => $statusCode,
            'body' => is_string($body) ? $body : '',
            'error' => $body === false ? 'Không thể kết nối tới ChatGPT.' : null
        ];
    }

    private function findCaCertFile(): ?string
    {
        $candidates = [
            (string)dvproEnv('CURL_CA_BUNDLE', '')
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function convertTo9Router(string $input_json): array
    {
        try {
            $data = $this->decodeInput($input_json);
            if ($data === null) {
                return [
                    'status' => 'error',
                    'message' => 'JSON không hợp lệ hoặc không tìm thấy bản ghi tài khoản.'
                ];
            }

            $items = $this->normalizeInputItems($data);
            $results = [];

            foreach ($items as $account) {
                $converted = $this->processAccount($account);
                if ($converted !== null) {
                    $results[] = $converted;
                }
            }

            return [
                'status' => 'success',
                'format' => '9router',
                'accounts' => count($results),
                'data' => $results,
                'skipped' => count($items) - count($results)
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    private function normalizeInputItems(array $data): array
    {
        if (isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        if (isset($data['accounts']) && is_array($data['accounts'])) {
            return $data['accounts'];
        }

        if (isset($data['data']) && is_array($data['data']) && isset($data['data'][0])) {
            return $data['data'];
        }

        return [$data];
    }

    /**
     * Accept one JSON document and JSONL exports with an optional title line.
     */
    private function decodeInput(string $input): ?array
    {
        $decoded = json_decode($input, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $items = [];
        $lines = explode("\n", str_replace("\r", "", $input));
        foreach ($lines as $line) {
            $line = trim($line);
            if (substr($line, 0, 3) === pack('CCC', 239, 187, 191)) {
                $line = substr($line, 3);
            }

            if ($line === '' || $line[0] !== '{') {
                continue;
            }

            $item = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($item)) {
                return null;
            }

            $items[] = $item;
        }

        return $items !== [] ? $items : null;
    }
    private function processAccount(array $account): ?array
    {
        $session = isset($account['session']) && is_array($account['session']) ? $account['session'] : [];
        $credentials = isset($account['credentials']) && is_array($account['credentials']) ? $account['credentials'] : [];
        $extra = isset($account['extra']) && is_array($account['extra']) ? $account['extra'] : [];

        $idToken = $account['id_token']
            ?? $account['idToken']
            ?? $session['id_token']
            ?? $session['idToken']
            ?? $credentials['id_token']
            ?? $credentials['idToken']
            ?? null;

        $accessToken = $account['access_token']
            ?? $account['accessToken']
            ?? $account['accessTokenRaw']
            ?? $session['access_token']
            ?? $session['accessToken']
            ?? $credentials['access_token']
            ?? $credentials['accessToken']
            ?? $idToken;

        if (!$accessToken || !is_string($accessToken)) {
            return null;
        }

        $refreshToken = $account['refresh_token']
            ?? $account['refreshToken']
            ?? $session['refresh_token']
            ?? $session['refreshToken']
            ?? $credentials['refresh_token']
            ?? $credentials['refreshToken']
            ?? '';

        $payload = $this->parseJWT($accessToken) ?? [];
        $auth = isset($payload['https://api.openai.com/auth']) && is_array($payload['https://api.openai.com/auth'])
            ? $payload['https://api.openai.com/auth']
            : [];
        $profile = isset($payload['https://api.openai.com/profile']) && is_array($payload['https://api.openai.com/profile'])
            ? $payload['https://api.openai.com/profile']
            : [];

        $email = $account['email']
            ?? $account['user']['email']
            ?? $session['user']['email']
            ?? $credentials['email']
            ?? $extra['email']
            ?? $profile['email']
            ?? $payload['email']
            ?? $payload['sub']
            ?? 'unknown';

        $accountId = $account['account_id']
            ?? $account['accountId']
            ?? $account['chatgpt_account_id']
            ?? $credentials['account_id']
            ?? $credentials['accountId']
            ?? $credentials['chatgpt_account_id']
            ?? $extra['account_id']
            ?? $extra['chatgpt_account_id']
            ?? $auth['chatgpt_account_id']
            ?? $payload['account_id']
            ?? '';

        $userId = $account['user_id']
            ?? $account['chatgpt_user_id']
            ?? $credentials['user_id']
            ?? $credentials['chatgpt_user_id']
            ?? $extra['user_id']
            ?? $extra['chatgpt_user_id']
            ?? $auth['chatgpt_user_id']
            ?? $auth['user_id']
            ?? '';

        $planType = $account['plan_type']
            ?? $account['chatgpt_plan_type']
            ?? $credentials['plan_type']
            ?? $credentials['chatgpt_plan_type']
            ?? $extra['plan_type']
            ?? $extra['chatgpt_plan_type']
            ?? $auth['chatgpt_plan_type']
            ?? 'free';

        $expiresAt = isset($payload['exp']) ? (int) $payload['exp'] : (time() + 86400 * 7);
        $expiresAtIso = $this->formatUtcIso($account['expired'] ?? $account['expires_at'] ?? $account['expiresAt'] ?? null, $expiresAt);
        $createdAt = $this->formatUtcIso($account['createdAt'] ?? $account['created_at'] ?? null);
        $updatedAt = $this->formatUtcIso(
            $account['updatedAt']
                ?? $account['updated_at']
                ?? $account['last_refresh']
                ?? $account['lastRefresh']
                ?? $extra['last_refresh']
                ?? $extra['lastRefresh']
                ?? null
        );
        $accountId = is_string($accountId) ? $accountId : (string) $accountId;
        $userId = is_string($userId) ? $userId : (string) $userId;
        $planType = is_string($planType) ? $planType : (string) $planType;
        $email = (string) $email;

        $sourceType = is_string($account['type'] ?? null) ? trim($account['type']) : '';
        $knownAuthTypes = ['oauth', 'agentidentity', 'token', 'bearer', 'api_key', 'apikey'];
        $sourcePlatform = is_string($account['platform'] ?? null) ? strtolower(trim($account['platform'])) : '';
        $provider = $account['provider'] ?? null;
        if ((!is_string($provider) || trim($provider) === '') && $sourcePlatform === 'openai') {
            $provider = 'codex';
        }
        if ((!is_string($provider) || trim($provider) === '')
            && $sourceType !== ''
            && !in_array(strtolower($sourceType), $knownAuthTypes, true)) {
            $provider = $sourceType;
        }
        if (!is_string($provider) || trim($provider) === '') {
            $provider = 'codex';
        }

        $authMode = $credentials['auth_mode'] ?? $credentials['authMode'] ?? null;
        $authType = $account['authType'] ?? $account['auth_type'] ?? null;
        if ((!is_string($authType) || trim($authType) === '')
            && $sourceType !== ''
            && in_array(strtolower($sourceType), $knownAuthTypes, true)) {
            $authType = $sourceType;
        }
        if (!is_string($authType) || trim($authType) === '') {
            $authType = is_string($authMode) && trim($authMode) !== '' ? $authMode : 'oauth';
        }

        $providerSpecificData = [
            'chatgptAccountId' => $accountId,
            'chatgptPlanType' => $planType
        ];
        if ($userId !== '') {
            $providerSpecificData['chatgptUserId'] = $userId;
        }

        return [
            'accessToken' => $accessToken,
            'refreshToken' => is_string($refreshToken) ? $refreshToken : '',
            'expiresAt' => $expiresAtIso,
            'testStatus' => time() < $expiresAt ? 'active' : 'expired',
            'expiresIn' => max(0, $expiresAt - time()),
            'providerSpecificData' => $providerSpecificData,
            'id' => (string) ($account['id'] ?? $accountId),
            'provider' => trim($provider),
            'authType' => trim($authType),
            'name' => (string) ($account['name'] ?? $extra['name'] ?? $email),
            'email' => $email,
            'priority' => (int) ($account['priority'] ?? 9),
            'isActive' => (bool) ($account['isActive'] ?? $account['is_active'] ?? true),
            'createdAt' => $createdAt,
            'updatedAt' => $updatedAt
        ];
    }

    public function convert1(string $input_json): array
    {
        try {
            $data = $this->decodeInput($input_json);
            if ($data === null) {
                return [
                    'status' => 'error',
                    'message' => 'JSON không hợp lệ hoặc không tìm thấy bản ghi tài khoản.'
                ];
            }

            $items = $this->normalizeInputItems($data);
            $results = [];

            foreach ($items as $account) {
                if (!is_array($account)) {
                    continue;
                }

                $converted = $this->processConvert1Account($account);
                if ($converted !== null) {
                    $results[] = $converted;
                }
            }

            return [
                'status' => 'success',
                'format' => 'convert1',
                'accounts' => count($results),
                'data' => $results,
                'skipped' => count($items) - count($results)
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    private function processConvert1Account(array $account): ?array
    {
        $credentials = isset($account['credentials']) && is_array($account['credentials'])
            ? $account['credentials']
            : [];
        $extra = isset($account['extra']) && is_array($account['extra'])
            ? $account['extra']
            : [];
        $providerSpecific = isset($account['providerSpecificData']) && is_array($account['providerSpecificData'])
            ? $account['providerSpecificData']
            : [];

        $accessToken = $account['access_token']
            ?? $account['accessToken']
            ?? $credentials['access_token']
            ?? $credentials['accessToken']
            ?? null;

        if (!$accessToken || !is_string($accessToken)) {
            return null;
        }

        $refreshToken = $account['refresh_token']
            ?? $account['refreshToken']
            ?? $credentials['refresh_token']
            ?? $credentials['refreshToken']
            ?? '';

        $idToken = $account['id_token']
            ?? $account['idToken']
            ?? $credentials['id_token']
            ?? $credentials['idToken']
            ?? $providerSpecific['idToken']
            ?? $providerSpecific['id_token']
            ?? null;

        $accessPayload = $this->parseJWT($accessToken) ?? [];
        $idPayload = is_string($idToken) ? ($this->parseJWT($idToken) ?? []) : [];

        $email = $account['email']
            ?? $credentials['email']
            ?? $extra['email']
            ?? $providerSpecific['email']
            ?? $idPayload['email']
            ?? $accessPayload['email']
            ?? null;

        if ((!is_string($email) || trim($email) === '') && is_string($account['name'] ?? null) && str_contains((string) $account['name'], '@')) {
            $email = $account['name'];
        }
        if (!is_string($email) || trim($email) === '') {
            $email = 'unknown';
        }
        $email = trim($email);

        $userId = $account['user_id']
            ?? $account['userId']
            ?? $credentials['sub']
            ?? $credentials['user_id']
            ?? $credentials['userId']
            ?? $providerSpecific['userId']
            ?? $providerSpecific['user_id']
            ?? $accessPayload['sub']
            ?? $accessPayload['principal_id']
            ?? $idPayload['sub']
            ?? '';
        $userId = is_string($userId) ? trim($userId) : (string) $userId;

        $scope = $account['scope']
            ?? $credentials['scope']
            ?? $accessPayload['scope']
            ?? 'openid profile email offline_access grok-cli:access api:access conversations:read conversations:write';
        $scope = is_string($scope) ? trim($scope) : '';

        $expiresAtTs = null;
        $expiresSource = $account['expires_at']
            ?? $account['expiresAt']
            ?? $credentials['expires_at']
            ?? $credentials['expiresAt']
            ?? null;
        if (is_string($expiresSource) && trim($expiresSource) !== '') {
            try {
                $expiresAtTs = (new DateTimeImmutable($expiresSource))->getTimestamp();
            } catch (Throwable $e) {
                $expiresAtTs = null;
            }
        }
        if ($expiresAtTs === null && isset($accessPayload['exp'])) {
            $expiresAtTs = (int) $accessPayload['exp'];
        }
        if ($expiresAtTs === null) {
            $expiresAtTs = time() + 21600;
        }

        $expiresAtIso = $this->formatUtcIso(
            is_string($expiresSource) ? $expiresSource : null,
            $expiresAtTs
        );
        $nowIso = $this->formatUtcIso(null, time());
        $createdAt = $this->formatUtcIso($account['createdAt'] ?? $account['created_at'] ?? null, time());
        $updatedAt = $this->formatUtcIso($account['updatedAt'] ?? $account['updated_at'] ?? null, time());

        $givenName = trim((string) ($idPayload['given_name'] ?? ''));
        $familyName = trim((string) ($idPayload['family_name'] ?? ''));
        $displayName = trim($givenName . ($givenName !== '' && $familyName !== '' ? ' ' : '') . $familyName);
        if ($displayName === '') {
            $displayName = (string) ($account['displayName']
                ?? $account['display_name']
                ?? $account['name']
                ?? $email);
        }

        $authType = $account['authType']
            ?? $account['auth_type']
            ?? $account['type']
            ?? 'oauth';
        $authType = is_string($authType) && trim($authType) !== '' ? trim($authType) : 'oauth';

        $provider = $account['provider'] ?? null;
        $platform = is_string($account['platform'] ?? null) ? strtolower(trim((string) $account['platform'])) : '';
        if ((!is_string($provider) || trim($provider) === '') && in_array($platform, ['grok', 'grok-cli'], true)) {
            $provider = 'grok-cli';
        }
        if (!is_string($provider) || trim($provider) === '') {
            $provider = 'grok-cli';
        }

        $authMethod = $providerSpecific['authMethod']
            ?? $providerSpecific['auth_method']
            ?? $credentials['auth_method']
            ?? $credentials['authMethod']
            ?? 'device_code';

        $id = $account['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            $id = $this->generateUuidV4();
        }

        $priority = (int) ($account['priority'] ?? 1);
        $isActive = (bool) ($account['isActive'] ?? $account['is_active'] ?? true);
        $expiresIn = max(0, $expiresAtTs - time());

        return [
            'displayName' => $displayName,
            'accessToken' => $accessToken,
            'refreshToken' => is_string($refreshToken) ? $refreshToken : '',
            'expiresAt' => $expiresAtIso,
            'scope' => $scope,
            'testStatus' => time() < $expiresAtTs ? 'active' : 'expired',
            'expiresIn' => $expiresIn,
            'providerSpecificData' => [
                'authMethod' => is_string($authMethod) && trim($authMethod) !== '' ? trim($authMethod) : 'device_code',
                'idToken' => is_string($idToken) ? $idToken : '',
                'email' => $email,
                'userId' => $userId,
                'hasGrokCodeAccess' => (bool) ($providerSpecific['hasGrokCodeAccess'] ?? true),
                'subscriptionTier' => $providerSpecific['subscriptionTier'] ?? null
            ],
            'id' => (string) $id,
            'provider' => trim((string) $provider),
            'authType' => $authType,
            'name' => $email,
            'email' => $email,
            'priority' => $priority,
            'isActive' => $isActive,
            'createdAt' => $createdAt !== '' ? $createdAt : $nowIso,
            'updatedAt' => $updatedAt !== '' ? $updatedAt : $nowIso
        ];
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function convertToSub2API(string $input_json): array
    {
        try {
            $conversion = $this->convertTo9Router($input_json);
            if ($conversion['status'] !== 'success') {
                return $conversion;
            }

            $results = [];
            foreach ($conversion['data'] as $account) {
                $results[] = [
                    'platform' => 'openai',
                    'credentials' => [
                        'email' => $account['email'],
                        'access_token' => $account['accessToken'],
                        'refresh_token' => $account['refreshToken']
                    ]
                ];
            }

            return [
                'status' => 'success',
                'format' => 'sub2api',
                'accounts' => count($results),
                'data' => $results
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function convertToCPA(string $input_json): array
    {
        try {
            $conversion = $this->convertTo9Router($input_json);
            if ($conversion['status'] !== 'success') {
                return $conversion;
            }

            $results = [];
            foreach ($conversion['data'] as $account) {
                $results[] = [
                    'account_id' => $account['providerSpecificData']['chatgptAccountId'] ?? $account['id'],
                    'email' => $account['email'],
                    'access_token' => $account['accessToken'],
                    'refresh_token' => $account['refreshToken'],
                    'type' => 'gpt',
                    'status' => 'active'
                ];
            }

            return [
                'status' => 'success',
                'format' => 'cpa',
                'accounts' => count($results),
                'data' => $results
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function exportToTXT(string $input_json): array
    {
        try {
            $conversion = $this->convertTo9Router($input_json);
            if ($conversion['status'] !== 'success') {
                return $conversion;
            }

            $txtOutput = "=== Chuyển đổi ChatGPT Session ===\n";
            $txtOutput .= "Ngày tạo: " . date('Y-m-d H:i:s') . "\n";
            $txtOutput .= "Tổng tài khoản: " . count($conversion['data']) . "\n";
            $txtOutput .= str_repeat('=', 50) . "\n\n";

            foreach ($conversion['data'] as $index => $account) {
                $txtOutput .= 'Tài khoản #' . ($index + 1) . ":\n";
                $txtOutput .= 'Email: ' . $account['email'] . "\n";
                $txtOutput .= 'Access Token: ' . substr($account['accessToken'], 0, 20) . "...\n";
                $txtOutput .= 'Account ID: ' . ($account['providerSpecificData']['chatgptAccountId'] ?? $account['id']) . "\n";
                $txtOutput .= 'Hạn token: ' . ($account['expired'] ?? 'Không xác định') . "\n";
                $txtOutput .= str_repeat('-', 50) . "\n\n";
            }

            return [
                'status' => 'success',
                'format' => 'txt',
                'content' => $txtOutput
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function logAction(string $action, array $data = []): void
    {
        dvproLog('convert', 'info', $action, $data);
    }

    public function getStatistics(): array
    {
        $db = dvproDatabase();
        $totalConversions = (int)$db->query("SELECT COUNT(*) FROM app_logs WHERE channel = 'convert' AND message = 'convert'")->fetchColumn();
        $logDays = (int)$db->query("SELECT COUNT(DISTINCT DATE(created_at)) FROM app_logs WHERE channel = 'convert'")->fetchColumn();
        $cacheCount = (int)$db->query("SELECT COUNT(*) FROM app_cache WHERE cache_key LIKE 'convert:%'")->fetchColumn();

        return [
            'total_conversions' => $totalConversions,
            'log_files' => $logDays,
            'cache_files' => $cacheCount
        ];
    }
}
