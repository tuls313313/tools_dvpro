<?php

// Free proxy selection is configured only by the server environment.
class TiktokFreeProxyPool
{
    private string $scope;
    private array $availability = [];
    private float $availabilityAt = 0;

    public function __construct(string $scope = 'video')
    {
        if (!in_array($scope, ['video', 'username'], true)) {
            throw new InvalidArgumentException('Nhóm proxy TikTok không hợp lệ.');
        }
        $this->scope = $scope;
    }

    private function config(string $name, string $default): ?string
    {
        $prefix = $this->scope === 'video' ? 'VIDEO_TIKTOK_PROXY_' : 'USERNAME_TIKTOK_PROXY_';
        return dvproEnv($prefix . $name, $default);
    }

    private function cachePath(string $name, bool $other = false): string
    {
        $username = ($this->scope === 'username') !== $other;
        return dirname(__DIR__, 2) . '/tmp/tiktok-' . ($username ? 'username-' : '')
            . 'free-proxy-' . $name . '.json';
    }

    private function storedProxies(bool $other = false): array
    {
        $handle = @fopen($this->cachePath('active', $other), 'r');
        if ($handle === false) return [];
        try {
            if (!flock($handle, LOCK_SH)) return [];
            return $this->readWorkingProxies($handle);
        } finally {
            fclose($handle);
        }
    }

    public function isAvailable(string $proxy): bool
    {
        $state = $this->availability();
        return !isset($state['failed'][$proxy])
            && !isset($state['other_hosts'][(string)parse_url($proxy, PHP_URL_HOST)]);
    }

    private function availability(): array
    {
        // Share one short-lived snapshot rather than rereading two files for every candidate.
        if (microtime(true) - $this->availabilityAt >= 1) {
            $hosts = array_map(static fn(string $value): string => (string)parse_url($value, PHP_URL_HOST),
                $this->storedProxies(true));
            $this->availability = ['failed' => $this->failedProxies(), 'other_hosts' => array_fill_keys($hosts, true)];
            $this->availabilityAt = microtime(true);
        }
        return $this->availability;
    }

    public function getProxies(int $count, float $deadline, array $exclude = []): array
    {
        $count = max(1, min(30, $count));
        $state = $this->availability();
        $failed = $state['failed'];
        $otherHosts = $state['other_hosts'];
        $available = static fn(string $proxy): bool => !isset($failed[$proxy])
            && !in_array($proxy, $exclude, true)
            && !isset($otherHosts[(string)parse_url($proxy, PHP_URL_HOST)]);
        $saved = array_values(array_filter($this->storedProxies(), $available));
        // Reuse the preferred working proxy alone; discover replacements only after saved proxies fail.
        if ($saved !== []) return [$saved[0]];
        $candidates = array_values(array_filter($this->loadCandidates($deadline), $available));
        if ($candidates === [] && microtime(true) < $deadline) {
            $candidates = array_values(array_filter($this->loadCandidates($deadline, true), $available));
        }
        shuffle($candidates);
        $limit = max(3, min(500, (int)$this->config('CANDIDATES', '200')));
        return array_slice(array_slice($candidates, 0, $limit), 0, $count);
    }

    public function race(callable $createHandle, callable $parseResponse, float $deadline): array
    {
        $attempts = max(3, min(30, (int)$this->config('ATTEMPTS', '30')));
        $concurrent = max(1, min(20, (int)$this->config('CONCURRENT', '10')));
        $used = [];
        $queue = [];
        $jobs = [];
        $lastError = 'Chưa tìm được proxy miễn phí kết nối được.';
        $multi = curl_multi_init();
        try {
            while (microtime(true) < $deadline) {
                if ($jobs === [] && $queue === []) {
                    if (count($used) >= $attempts) break;
                    // Load candidates before starting transfers so source downloads cannot stall active proxies.
                    // Saved working proxies still return a single candidate and are tried alone first.
                    $queue = array_slice(array_values(array_diff(array_unique(
                        $this->getProxies($attempts - count($used), $deadline, $used)), $used)),
                        0, $attempts - count($used));
                    if ($queue === []) break;
                }
                while ($queue !== [] && count($jobs) < $concurrent && count($used) < $attempts) {
                    $proxy = array_shift($queue);
                    $used[] = $proxy;
                    if (!$this->isAvailable($proxy)) continue;
                    // This budget includes CONNECT, redirects and any follow-up TikTok page.
                    $timeout = min(5.0, max(0.1, (float)$this->config('CHECK_TIMEOUT', '5')),
                        $deadline - microtime(true));
                    if ($timeout <= 0) break;
                    $handle = $createHandle($proxy, $timeout);
                    curl_setopt_array($handle, [
                        CURLOPT_TIMEOUT_MS => max(1, (int)($timeout * 1000)),
                        CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)($timeout * 1000)),
                    ]);
                    $jobs[spl_object_id($handle)] = ['handle' => $handle, 'proxy' => $proxy,
                        'deadline' => microtime(true) + $timeout, 'followups' => 0, 'elapsed_ms' => 0.0];
                    curl_multi_add_handle($multi, $handle);
                }
                if ($jobs === []) continue;
                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);
                if ($status !== CURLM_OK) throw new RuntimeException('Không chạy được nhóm proxy TikTok.');
                $result = null;
                $fastestMs = INF;
                while ($message = curl_multi_info_read($multi)) {
                    $handle = $message['handle'];
                    $key = spl_object_id($handle);
                    $job = $jobs[$key];
                    curl_multi_remove_handle($multi, $handle);
                    unset($jobs[$key]);
                    try {
                        if ($message['result'] !== CURLE_OK) {
                            throw new RuntimeException('Proxy không trả dữ liệu TikTok trong 5 giây hoặc lỗi kết nối (cURL '
                                . $message['result'] . ').');
                        }
                        $job['elapsed_ms'] += (float)curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
                        $parsed = $parseResponse($handle, (string)curl_multi_getcontent($handle), $job['followups']);
                        if (is_string($parsed)) {
                            $remaining = min($deadline, $job['deadline']) - microtime(true);
                            if ($remaining <= 0 || $job['followups'] >= 1) {
                                throw new RuntimeException('Proxy không trả dữ liệu TikTok trong 5 giây.');
                            }
                            curl_setopt_array($handle, [CURLOPT_URL => $parsed,
                                CURLOPT_TIMEOUT_MS => max(1, (int)($remaining * 1000)),
                                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)($remaining * 1000))]);
                            $job['followups']++;
                            $jobs[$key] = $job;
                            curl_multi_add_handle($multi, $handle);
                            continue;
                        }
                        if (!is_array($parsed)) throw new RuntimeException('Dữ liệu TikTok không hợp lệ.');
                        $this->markWorking($job['proxy'], $job['elapsed_ms']);
                        if ($job['elapsed_ms'] < $fastestMs) {
                            $fastestMs = $job['elapsed_ms'];
                            $result = $parsed;
                        }
                    } catch (RuntimeException $e) {
                        $lastError = $e->getMessage();
                        $this->markFailed($job['proxy']);
                    } finally {
                        if (!isset($jobs[$key])) curl_close($handle);
                    }
                }
                // Return the first valid result; refill failed slots without waiting for slower peers.
                if ($result !== null) return $result;
                if ($jobs === [] || ($queue !== [] && count($jobs) < $concurrent)) continue;
                if (microtime(true) >= $deadline) break;
                if (curl_multi_select($multi, 0.05) === -1) usleep(1000);
            }
        } finally {
            foreach ($jobs as $job) {
                curl_multi_remove_handle($multi, $job['handle']);
                curl_close($job['handle']);
                // A request cancelled because another proxy won is not a failed proxy.
                if (microtime(true) >= $job['deadline']) $this->markFailed($job['proxy']);
            }
            curl_multi_close($multi);
        }
        throw new RuntimeException('Đã thử ' . count($used)
            . ' proxy khác nhau, tối đa ' . $concurrent . ' proxy cùng lúc và 5 giây/proxy. ' . $lastError);
    }

    public function getActiveProxy(): ?string
    {
        foreach ($this->storedProxies() as $proxy) {
            if ($this->isAvailable($proxy)) return $proxy;
        }
        return null;
    }

    public function markWorking(string $proxy, ?float $elapsedMs = null): void
    {
        // Serialize both tools' saved lists so their proxy host IPs stay different.
        $selection = @fopen(dirname(__DIR__, 2) . '/tmp/tiktok-proxy-selection.lock', 'c+');
        if ($selection === false) return;
        $handle = null;
        try {
            if (!flock($selection, LOCK_EX)) return;
            $this->availabilityAt = 0;
            if (!$this->isAvailable($proxy)) return;
            $handle = @fopen($this->cachePath('active'), 'c+');
            if ($handle === false || !flock($handle, LOCK_EX)) return;
            $failed = $this->failedProxies();
            $state = $this->readWorkingState($handle);
            $saved = array_values(array_filter($state['proxies'],
                static fn(string $value): bool => !isset($failed[$value])));
            $saved = array_values(array_unique(array_merge([$proxy], $saved)));
            if ($elapsedMs !== null && is_finite($elapsedMs) && $elapsedMs > 0) {
                $state['latency_ms'][$proxy] = round($elapsedMs, 2);
            }
            $state['checked_at'][$proxy] = time();
            $this->writeWorkingProxies($handle, $saved, $state['latency_ms'], $state['checked_at'],
                $state['proxy'] ?? $proxy);
        } finally {
            if (is_resource($handle)) fclose($handle);
            fclose($selection);
        }
    }

    private function forgetWorking(string $proxy): void
    {
        $handle = @fopen($this->cachePath('active'), 'r+');
        if ($handle === false) return;
        try {
            if (!flock($handle, LOCK_EX)) return;
            $state = $this->readWorkingState($handle);
            if (!in_array($proxy, $state['proxies'], true)) return;
            $this->writeWorkingProxies($handle, array_values(array_diff($state['proxies'], [$proxy])),
                $state['latency_ms'], $state['checked_at'], $state['proxy']);
        } finally {
            fclose($handle);
        }
    }

    private function readWorkingProxies($handle): array
    {
        return $this->readWorkingState($handle)['proxies'];
    }

    private function readWorkingState($handle): array
    {
        $state = json_decode((string)stream_get_contents($handle), true);
        // Older caches may contain proxies from any country; preserve the file but do not reuse them.
        if (!is_array($state) || ($state['country'] ?? '') !== 'VN') {
            return ['proxy' => null, 'proxies' => [], 'latency_ms' => [], 'checked_at' => []];
        }
        $proxies = is_array($state['proxies'] ?? null) ? $state['proxies'] : [];
        $proxies = $this->parseList(implode("\n", array_filter($proxies, 'is_string')));
        $checks = is_array($state['checked_at'] ?? null) ? $state['checked_at'] : [];
        $legacyTime = (int)(fstat($handle)['mtime'] ?? 0);
        foreach ($proxies as $proxy) {
            $checks[$proxy] = is_int($checks[$proxy] ?? null) ? $checks[$proxy] : $legacyTime;
        }
        $cutoff = time() - 86400;
        $proxies = array_values(array_filter($proxies,
            static fn(string $proxy): bool => $checks[$proxy] > $cutoff));
        $checks = array_intersect_key($checks, array_flip($proxies));
        $times = is_array($state['latency_ms'] ?? null) ? $state['latency_ms'] : [];
        $times = array_filter($times, static fn($value): bool => is_numeric($value)
            && is_finite((float)$value) && (float)$value > 0);
        $times = array_intersect_key($times, array_flip($proxies));
        usort($proxies, static fn(string $a, string $b): int => ($times[$a] ?? INF) <=> ($times[$b] ?? INF));
        $preferred = $state['proxy'] ?? null;
        if (is_string($preferred) && in_array($preferred, $proxies, true)) {
            $proxies = array_values(array_unique(array_merge([$preferred], $proxies)));
        }
        return ['proxy' => $proxies[0] ?? null, 'proxies' => $proxies, 'latency_ms' => $times, 'checked_at' => $checks];
    }

    private function writeWorkingProxies($handle, array $proxies, array $times = [], array $checks = [],
        ?string $preferred = null): void
    {
        usort($proxies, static fn(string $a, string $b): int => ($times[$a] ?? INF) <=> ($times[$b] ?? INF));
        if ($preferred !== null && in_array($preferred, $proxies, true)) {
            $proxies = array_values(array_unique(array_merge([$preferred], $proxies)));
        }
        $proxies = array_slice($proxies, 0, 128);
        $times = array_intersect_key($times, array_flip($proxies));
        $checks = array_intersect_key($checks, array_flip($proxies));
        $json = json_encode(['country' => 'VN', 'proxy' => $proxies[0] ?? null,
            'proxies' => $proxies, 'latency_ms' => $times, 'checked_at' => $checks], JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
    }

    public function markFailed(string $proxy): void
    {
        try {
            $ttl = max(0, min(3600, (int)$this->config('FAILURE_TTL', '300')));
            if ($ttl === 0) return;
            $handle = @fopen($this->cachePath('failures'), 'c+');
            if ($handle === false) return;
            try {
                if (!flock($handle, LOCK_EX)) return;
                $failed = $this->readFailures($handle);
                $failed[$proxy] = time() + $ttl;
                $json = json_encode($failed, JSON_UNESCAPED_SLASHES);
                if ($json === false) return;
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, $json);
                fflush($handle);
            } finally {
                fclose($handle);
            }
        } finally {
            $this->availabilityAt = 0;
            $this->forgetWorking($proxy);
        }
    }

    private function failedProxies(): array
    {
        $handle = @fopen($this->cachePath('failures'), 'r');
        if ($handle === false) return [];
        try {
            if (!flock($handle, LOCK_SH)) return [];
            return $this->readFailures($handle);
        } finally {
            fclose($handle);
        }
    }

    private function readFailures($handle): array
    {
        $failed = json_decode((string)stream_get_contents($handle), true);
        if (!is_array($failed)) return [];
        $now = time();
        return array_filter($failed, static fn($until): bool => is_int($until) && $until > $now);
    }

    private function sourceUrl(): string
    {
        $source = trim((string)$this->config('SOURCE',
            'https://api.proxyscrape.com/v2/?request=getproxies&protocol=http&timeout=3000&country=vn&ssl=all&anonymity=all'));
        $parts = parse_url($source);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host'])) {
            throw new RuntimeException('Nguồn danh sách proxy trong cấu hình server không hợp lệ.');
        }
        parse_str($parts['query'] ?? '', $query);
        // Always request Vietnam, even if an older server setting still says country=all.
        $query['country'] = 'vn';
        return preg_replace('/[?#].*$/', '', $source) . '?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function loadCandidates(float $deadline, bool $refresh = false): array
    {
        $source = $this->sourceUrl();
        $cachePath = $this->cachePath('list');
        $ttl = max(0, min(3600, (int)$this->config('CACHE_TTL', '300')));
        $sourceHash = hash('sha256', $source);
        $cached = is_file($cachePath) ? json_decode((string)@file_get_contents($cachePath), true) : null;
        if (!$refresh && $ttl > 0 && is_array($cached) && ($cached['source'] ?? '') === $sourceHash
            && ($cached['country'] ?? '') === 'VN' && (int)($cached['fetched_at'] ?? 0) + $ttl > time()) {
            $proxies = $this->parseList(implode("\n", (array)($cached['proxies'] ?? [])));
            if ($proxies !== []) return $proxies;
        }

        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) return [];
        $handle = curl_init($source);
        curl_setopt_array($handle, $this->curlOptions(min(3, $remaining)) + [CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($handle);
        $httpCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($body === false || $httpCode !== 200) {
            throw new RuntimeException('Không tải được danh sách proxy Việt Nam miễn phí. Vui lòng thử lại sau.');
        }
        $proxies = $this->parseList($body);
        if ($proxies === []) {
            throw new RuntimeException('Nguồn proxy Việt Nam miễn phí hiện không có proxy hợp lệ.');
        }
        @file_put_contents($cachePath, json_encode([
            'source' => $sourceHash,
            'country' => 'VN',
            'fetched_at' => time(),
            'proxies' => $proxies,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $proxies;
    }

    private function parseList(string $body): array
    {
        $proxies = [];
        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim($line);
            if (!preg_match('~^(?:http://)?(\d+(?:\.\d+){3}):(\d{1,5})$~i', $line, $match)) continue;
            if (!filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4
                | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) continue;
            $port = (int)$match[2];
            if ($port < 1 || $port > 65535) continue;
            $proxies[] = 'http://' . $match[1] . ':' . $port;
        }
        return array_values(array_unique($proxies));
    }

    private function curlOptions(float $timeout): array
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => max(1, (int)($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)($timeout * 1000)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROXY => '',
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'DVPro-TikTok-Proxy/1.0',
        ];
        $caFile = (string)dvproEnv('CURL_CA_BUNDLE', '');
        if ($caFile !== '' && is_file($caFile) && is_readable($caFile)) {
            $options[CURLOPT_CAINFO] = $caFile;
        }
        return $options;
    }
}
