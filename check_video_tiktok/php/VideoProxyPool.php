<?php
require_once __DIR__ . '/proxy.php';

// Video batches discover distinct proxy IPs, then reuse a proven proxy until it fails.
class TiktokVideoProxyPool extends TiktokFreeProxyPool
{
    private array $selected = [];

    public function __construct(private string $batch)
    {
        parent::__construct('video');
    }

    private function path(string $name): string
    {
        return dirname(__DIR__, 2) . '/tmp/tiktok-video-proxy-' . $name . '.json';
    }

    private function readState(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) return [];
        try {
            if (!flock($handle, LOCK_SH)) return [];
            $state = json_decode((string)stream_get_contents($handle), true);
            return is_array($state) ? $state : [];
        } finally { fclose($handle); }
    }

    private function writeState($handle, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
    }

    private function ranked(): array
    {
        $state = $this->readState($this->path('ranked'));
        $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];
        // Keep working VN proxies measured by the previous implementation.
        $oldPath = dirname(__DIR__, 2) . '/tmp/tiktok-free-proxy-active.json';
        $old = $this->readState($oldPath);
        if (($old['country'] ?? '') === 'VN') {
            foreach (($old['proxies'] ?? []) as $proxy) {
                if (!is_string($proxy) || isset($entries[$proxy])) continue;
                $entries[$proxy] = ['proxy' => $proxy, 'country' => 'VN',
                    'latency_ms' => $old['latency_ms'][$proxy] ?? 3000,
                    'checked_at' => $old['checked_at'][$proxy] ?? (int)@filemtime($oldPath)];
            }
        }
        $entries = array_filter($entries, fn($entry): bool => is_array($entry)
            && $this->validProxy((string)($entry['proxy'] ?? ''))
            && is_numeric($entry['latency_ms'] ?? null) && (float)$entry['latency_ms'] > 0
            && (int)($entry['checked_at'] ?? 0) > time() - 86400);
        uasort($entries, static fn(array $a, array $b): int => (float)$a['latency_ms'] <=> (float)$b['latency_ms']);
        $preferred = $state['preferred'] ?? null;
        if (is_string($preferred) && isset($entries[$preferred])) {
            $entries = [$preferred => $entries[$preferred]] + $entries;
        }
        return $entries;
    }

    public function getProxies(int $count, float $deadline, array $exclude = []): array
    {
        $count = max(1, min(30, $count));
        // A saved working proxy can serve every link and user; batch reservations only apply to discovery.
        foreach ($this->ranked() as $entry) {
            $proxy = $entry['proxy'];
            if (!in_array($proxy, $exclude, true) && $this->isAvailable($proxy)) {
                $this->selected[$proxy] = $entry;
                return [$proxy];
            }
        }
        $chosen = [];
        if (microtime(true) >= $deadline) return $chosen;
        $lastError = null;
        foreach (['VN', 'ALL'] as $country) {
            try {
                $entries = $this->loadSource($country, $deadline);
                $chosen = array_merge($chosen, $this->reserve($entries, $count - count($chosen),
                    array_merge($exclude, $chosen)));
            } catch (RuntimeException $e) {
                $lastError = $e;
            }
            if (count($chosen) >= $count || microtime(true) >= $deadline) break;
        }
        if ($chosen === [] && $lastError !== null) throw $lastError;
        return $chosen;
    }

    private function reserve(array $entries, int $count, array $exclude): array
    {
        if ($count <= 0) return [];
        $handle = @fopen($this->path('batches'), 'c+');
        if ($handle === false) throw new RuntimeException('Không lưu được phân bổ proxy cho các link.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Không khóa được danh sách proxy của đợt kiểm tra.');
            $state = json_decode((string)stream_get_contents($handle), true);
            $batches = is_array($state['batches'] ?? null) ? $state['batches'] : [];
            $batches = array_filter($batches, static fn($batch): bool => is_array($batch)
                && (int)($batch['until'] ?? 0) > time());
            $batch = $batches[$this->batch] ?? ['until' => 0, 'hosts' => []];
            $hosts = is_array($batch['hosts'] ?? null) ? $batch['hosts'] : [];
            $chosen = [];
            foreach ($entries as $entry) {
                $proxy = (string)($entry['proxy'] ?? '');
                if (!$this->validProxy($proxy) || in_array($proxy, $exclude, true)
                    || !$this->isAvailable($proxy)) continue;
                $host = (string)parse_url($proxy, PHP_URL_HOST);
                if (isset($hosts[$host])) continue;
                $hosts[$host] = true;
                $chosen[] = $proxy;
                $this->selected[$proxy] = $entry;
                if (count($chosen) >= $count) break;
            }
            $batches[$this->batch] = ['until' => time() + 600, 'hosts' => $hosts];
            $this->writeState($handle, ['batches' => $batches]);
            return $chosen;
        } finally { fclose($handle); }
    }

    public function markWorking(string $proxy, ?float $elapsedMs = null): void
    {
        if (!$this->isAvailable($proxy) || $elapsedMs === null || $elapsedMs <= 0 || !is_finite($elapsedMs)) return;
        $entry = $this->selected[$proxy] ?? null;
        if ($entry === null) return;
        $entry['latency_ms'] = round($elapsedMs, 2);
        $entry['checked_at'] = time();
        $handle = @fopen($this->path('ranked'), 'c+');
        if ($handle === false) return;
        try {
            if (!flock($handle, LOCK_EX)) return;
            $state = json_decode((string)stream_get_contents($handle), true);
            $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];
            $entries = array_filter($entries, static fn($value): bool => is_array($value)
                && (int)($value['checked_at'] ?? 0) > time() - 86400);
            $entries[$proxy] = $entry;
            uasort($entries, static fn(array $a, array $b): int => (float)$a['latency_ms'] <=> (float)$b['latency_ms']);
            $preferred = $state['preferred'] ?? null;
            if (!is_string($preferred) || !isset($entries[$preferred])) $preferred = $proxy;
            $entries = [$preferred => $entries[$preferred]] + $entries;
            $this->writeState($handle, ['preferred' => $preferred, 'entries' => array_slice($entries, 0, 128, true)]);
        } finally { fclose($handle); }
        // Publish VN successes to the old cache as well, keeping username proxy IPs separate.
        if (($entry['country'] ?? '') === 'VN') parent::markWorking($proxy, $elapsedMs);
    }

    public function markFailed(string $proxy): void
    {
        parent::markFailed($proxy);
        $handle = @fopen($this->path('ranked'), 'c+');
        if ($handle === false) return;
        try {
            if (!flock($handle, LOCK_EX)) return;
            $state = json_decode((string)stream_get_contents($handle), true);
            $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];
            unset($entries[$proxy]);
            $preferred = $state['preferred'] ?? null;
            $this->writeState($handle, ['preferred' => is_string($preferred) && isset($entries[$preferred]) ? $preferred : null,
                'entries' => $entries]);
        } finally { fclose($handle); }
    }

    private function validProxy(string $proxy): bool
    {
        if (!preg_match('~^http://([0-9.]+):([0-9]{1,5})$~', $proxy, $match)) return false;
        return filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4
            | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && (int)$match[2] > 0 && (int)$match[2] <= 65535;
    }

    private function sourceUrl(string $country): string
    {
        $default = 'https://api.proxyscrape.com/v3/free-proxy-list/get?request=displayproxies&protocol=http'
            . '&country=' . ($country === 'VN' ? 'VN' : 'all') . '&timeout=3000&format=json';
        $key = $country === 'VN' ? 'VIDEO_TIKTOK_PROXY_SOURCE' : 'VIDEO_TIKTOK_PROXY_FALLBACK_SOURCE';
        $source = trim((string)dvproEnv($key, $default));
        $parts = parse_url($source);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('Nguồn proxy video phải là URL HTTPS hợp lệ.');
        }
        parse_str($parts['query'] ?? '', $query);
        $query['country'] = $country === 'VN' ? 'VN' : 'all';
        $query['format'] = 'json';
        return preg_replace('/[?#].*$/', '', $source) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function loadSource(string $country, float $deadline): array
    {
        $url = $this->sourceUrl($country);
        $path = $this->path('source-' . strtolower($country));
        $hash = hash('sha256', $url);
        $ttl = max(1, min(3600, (int)dvproEnv('VIDEO_TIKTOK_PROXY_CACHE_TTL', '300')));
        $handle = @fopen($path, 'c+');
        if ($handle === false) throw new RuntimeException('Không mở được cache nguồn proxy video.');
        try {
            // Only one request refreshes this source; other links reuse its resulting cache.
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('Hết thời gian chờ danh sách proxy.');
                usleep(10000);
            }
            $state = json_decode((string)stream_get_contents($handle), true);
            if (is_array($state) && ($state['source'] ?? '') === $hash
                && (int)($state['fetched_at'] ?? 0) + $ttl > time() && is_array($state['entries'] ?? null)) {
                return $state['entries'];
            }
            $remaining = min(3.0, $deadline - microtime(true));
            if ($remaining <= 0) return [];
            $curl = curl_init($url);
            try {
                $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => max(1, (int)($remaining * 1000)),
                    CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)($remaining * 1000)), CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'DVPro-Video-Proxy/1.0'];
                $ca = (string)dvproEnv('CURL_CA_BUNDLE', '');
                if ($ca !== '' && is_file($ca) && is_readable($ca)) $options[CURLOPT_CAINFO] = $ca;
                curl_setopt_array($curl, $options);
                $body = curl_exec($curl);
                $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            } finally { curl_close($curl); }
            if ($body === false || $http !== 200) throw new RuntimeException('Không tải được nguồn proxy '
                . ($country === 'VN' ? 'Việt Nam' : 'quốc tế') . '.');
            $entries = $this->parseSource((string)$body, $country);
            $this->writeState($handle, ['source' => $hash, 'fetched_at' => time(), 'entries' => $entries]);
            return $entries;
        } finally { fclose($handle); }
    }

    private function parseSource(string $body, string $country): array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['proxies'] ?? null)) {
            throw new RuntimeException('Nguồn proxy không trả danh sách kèm latency hợp lệ.');
        }
        $entries = [];
        foreach ($data['proxies'] as $row) {
            if (!is_array($row) || ($row['alive'] ?? false) !== true || ($row['protocol'] ?? '') !== 'http') continue;
            $proxy = 'http://' . (string)($row['proxy'] ?? '');
            $code = strtoupper((string)($row['ip_data']['countryCode'] ?? ''));
            $time = $row['timeout'] ?? null;
            if (!$this->validProxy($proxy) || ($country === 'VN' && $code !== 'VN')
                || !is_numeric($time) || !is_finite((float)$time) || (float)$time <= 0 || (float)$time > 3000) continue;
            $entries[$proxy] = ['proxy' => $proxy, 'country' => $code, 'latency_ms' => (float)$time];
        }
        uasort($entries, static fn(array $a, array $b): int => $a['latency_ms'] <=> $b['latency_ms']);
        $limit = max(3, min(1000, (int)dvproEnv('VIDEO_TIKTOK_PROXY_CANDIDATES', '500')));
        return array_slice(array_values($entries), 0, $limit);
    }
}
