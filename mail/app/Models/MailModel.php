<?php
require_once dirname(__DIR__, 3) . '/env.php';
require_once __DIR__ . '/MicrosoftImapReader.php';

class MailModel
{
    private int $timeout;
    private int $maxTop;
    private float $deadline = 0;

    public function __construct()
    {
        $this->timeout = max(10, min(120, (int)dvproEnv('MAIL_TIMEOUT', '30')));
        $this->maxTop = max(1, min(50, (int)dvproEnv('MAIL_MAX_TOP', '10')));
    }

    public function readMail(string $account, string $listMail = 'all', int $top = 10): array
    {
        $parsed = $this->parseAccount($account);
        if (!$parsed['status']) return $this->failure($parsed['email'], $parsed['error']);
        $credentials = $parsed['data'];
        $this->deadline = microtime(true) + $this->timeout;
        $top = max(1, min($top, $this->maxTop));
        $listMail = $listMail === 'inbox' ? 'inbox' : 'all';
        try {
            $grant = $this->refreshAccessToken($credentials, 'graph');
            if ($grant !== null) {
                $messages = $this->readGraph($grant, $listMail, $top);
                if ($messages !== null) return $this->success($credentials['email'], $messages, 'microsoft_graph');
            }
            // Some OAuth applications grant IMAP mail access only.
            $grant = $this->refreshAccessToken($credentials, 'imap');
            if ($grant === null) throw new RuntimeException('Ứng dụng chưa được cấp quyền đọc thư Microsoft. Cần cấp Mail.Read hoặc IMAP.AccessAsUser.All.');
            $reader = new MicrosoftImapReader($this->deadline, $this->availableCaFile());
            $messages = $reader->read($credentials['email'], $grant, $listMail, $top);
            return $this->success($credentials['email'], $messages, 'microsoft_imap');
        } catch (RuntimeException $e) {
            return $this->failure($credentials['email'], $e->getMessage());
        }
    }

    private function success(string $email, array $messages, string $source): array
    {
        return ['email' => $email, 'status' => true, 'messages' => $messages, 'source' => $source];
    }

    private function failure(string $email, string $error): array
    {
        return ['email' => $email, 'status' => false, 'error' => $error, 'messages' => []];
    }

    private function parseAccount(string $account): array
    {
        $parts = array_map('trim', explode('|', trim($account)));
        $email = filter_var($parts[0] ?? '', FILTER_VALIDATE_EMAIL) ? $parts[0] : '';
        $fail = static fn(string $error): array => ['status' => false, 'email' => $email, 'error' => $error];
        if (strlen($account) > 8500 || preg_match('/[\x00-\x1F\x7F]/', trim($account))) return $fail('Dòng tài khoản quá dài hoặc chứa ký tự không hợp lệ.');
        if (count($parts) === 3) {
            [$address, $refreshToken, $clientId] = $parts;
        } elseif (count($parts) === 4) {
            [$address, $password, $refreshToken, $clientId] = $parts;
            if (strlen($password) > 500) return $fail('Password quá dài.');
        } else {
            return $fail('Chỉ hỗ trợ email|refresh_token|client_id hoặc email|password|refresh_token|client_id.');
        }
        if ($email === '') return $fail('Email không hợp lệ.');
        if (strlen($refreshToken) < 20 || strlen($refreshToken) > 7000 || preg_match('/\s/', $refreshToken)) return $fail('Refresh token không hợp lệ.');
        if (!preg_match('/^(?:[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}|[a-f0-9]{16}|[a-f0-9]{32})$/i', $clientId)) return $fail('Client ID không hợp lệ.');
        // Password is accepted for the four-field format but is never used or sent.
        return ['status' => true, 'data' => ['email' => $address, 'refresh_token' => $refreshToken, 'client_id' => $clientId]];
    }

    private function refreshAccessToken(array $credentials, string $resource): ?string
    {
        $scope = $resource === 'graph'
            ? 'https://graph.microsoft.com/Mail.Read offline_access'
            : 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access';
        [$http, $json] = $this->requestJson('https://login.microsoftonline.com/consumers/oauth2/v2.0/token', [
            'client_id' => $credentials['client_id'],
            'refresh_token' => $credentials['refresh_token'],
            'grant_type' => 'refresh_token',
            'scope' => $scope,
        ]);
        if ($http === 200 && is_string($json['access_token'] ?? null) && $json['access_token'] !== '') return $json['access_token'];
        $error = (string)($json['error'] ?? '');
        $codes = array_map('intval', (array)($json['error_codes'] ?? []));
        if ($error === 'invalid_scope' || in_array(65001, $codes, true) || in_array(65004, $codes, true)) return null;
        if ($error === 'invalid_grant' || in_array(70000, $codes, true)) {
            throw new RuntimeException('Microsoft từ chối refresh token (invalid_grant): token đã hết hiệu lực, bị thu hồi hoặc không khớp client_id. Cần đăng nhập lại để lấy refresh token mới.');
        }
        if (in_array($error, ['invalid_client', 'unauthorized_client'], true)) throw new RuntimeException('Microsoft không chấp nhận client_id hoặc ứng dụng OAuth này.');
        if ($http === 429) throw new RuntimeException('Microsoft đang giới hạn yêu cầu. Vui lòng chờ rồi đọc lại.');
        if ($error === 'interaction_required') throw new RuntimeException('Microsoft yêu cầu đăng nhập hoặc xác minh lại tài khoản trước khi đọc mail.');
        throw new RuntimeException('Không đổi được access token Microsoft (HTTP ' . $http . ').');
    }

    private function readGraph(string $accessToken, string $listMail, int $top): ?array
    {
        $path = $listMail === 'inbox' ? 'me/mailFolders/inbox/messages' : 'me/messages';
        $query = http_build_query([
            '$top' => $top,
            '$select' => 'id,from,subject,body,bodyPreview,receivedDateTime',
            '$orderby' => 'receivedDateTime desc',
        ], '', '&', PHP_QUERY_RFC3986);
        [$http, $json] = $this->requestJson('https://graph.microsoft.com/v1.0/' . $path . '?' . $query, null, $accessToken);
        if (in_array($http, [401, 403], true)) return null;
        if ($http === 429) throw new RuntimeException('Microsoft đang giới hạn yêu cầu đọc thư. Vui lòng chờ rồi đọc lại.');
        if ($http !== 200 || !is_array($json['value'] ?? null)) throw new RuntimeException('Không đọc được thư từ Microsoft (HTTP ' . $http . ').');
        $messages = [];
        foreach (array_slice($json['value'], 0, $top) as $item) {
            if (!is_array($item)) continue;
            $body = (string)($item['body']['content'] ?? $item['bodyPreview'] ?? '');
            if (strtolower((string)($item['body']['contentType'] ?? 'text')) !== 'html') {
                $body = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
            $subject = (string)($item['subject'] ?? '');
            $sender = $item['from']['emailAddress'] ?? [];
            $messages[] = [
                'from' => [['name' => (string)($sender['name'] ?? ''), 'address' => (string)($sender['address'] ?? '')]],
                'subject' => $subject, 'message' => $body,
                'date' => (string)($item['receivedDateTime'] ?? ''),
                'code' => MicrosoftMailMessage::extractCode($subject, $body),
            ];
        }
        return $messages;
    }

    private function requestJson(string $url, ?array $form = null, ?string $accessToken = null): array
    {
        $remaining = (int)(($this->deadline - microtime(true)) * 1000);
        if ($remaining <= 0) throw new RuntimeException('Hết thời gian chờ Microsoft. Vui lòng đọc lại.');
        $handle = curl_init($url);
        $headers = ['Accept: application/json', 'User-Agent: DVPro-Mail-Direct/1.0'];
        if ($accessToken !== null) $headers[] = 'Authorization: Bearer ' . $accessToken;
        if ($form !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => $remaining,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $remaining),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_PROXY => '',
            CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => $headers,
        ];
        if ($form !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        }
        $ca = $this->availableCaFile();
        if ($ca !== null) $options[CURLOPT_CAINFO] = $ca;
        try {
            curl_setopt_array($handle, $options);
            $body = curl_exec($handle);
            $errno = curl_errno($handle);
            $http = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        } finally { curl_close($handle); }
        // Never return Microsoft's raw errors, which can contain authentication data.
        if ($body === false) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) throw new RuntimeException('Hết thời gian chờ Microsoft. Vui lòng đọc lại.');
            if (in_array($errno, [CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE], true)) throw new RuntimeException('Không xác minh được chứng chỉ Microsoft. Kiểm tra CURL_CA_BUNDLE trên server.');
            throw new RuntimeException('Không kết nối được Microsoft (cURL ' . $errno . ').');
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) throw new RuntimeException('Microsoft trả phản hồi không hợp lệ (HTTP ' . $http . ').');
        return [$http, $json];
    }

    private function availableCaFile(): ?string
    {
        $path = (string)dvproEnv('CURL_CA_BUNDLE', '');
        return $path !== '' && is_file($path) && is_readable($path) ? $path : null;
    }

    public function sanitizePublicMailResult(array $result, ?string $fallbackEmail = null): array
    {
        $email = trim(explode('|', (string)($result['email'] ?? $fallbackEmail ?? ''), 2)[0]);
        $messages = [];
        foreach (($result['messages'] ?? []) as $message) {
            if (!is_array($message)) continue;
            $from = [];
            foreach (($message['from'] ?? []) as $sender) {
                if (is_array($sender)) $from[] = ['name' => (string)($sender['name'] ?? ''), 'address' => (string)($sender['address'] ?? '')];
            }
            $messages[] = [
                'from' => $from, 'subject' => (string)($message['subject'] ?? ''),
                'message' => (string)($message['message'] ?? ''), 'date' => (string)($message['date'] ?? ''),
                'code' => (string)($message['code'] ?? ''),
            ];
        }
        return [
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'status' => (bool)($result['status'] ?? false), 'messages' => $messages,
            'source' => in_array($result['source'] ?? '', ['microsoft_graph', 'microsoft_imap'], true) ? $result['source'] : null,
            'error' => isset($result['error']) ? (string)$result['error'] : null,
        ];
    }
}
