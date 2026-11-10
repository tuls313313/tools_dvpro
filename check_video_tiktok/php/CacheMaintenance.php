<?php

// Only temporary TikTok state is maintained here; administrative blocks and backups are untouched.
final class TiktokCacheMaintenance
{
    public static function run(bool $force = false): array
    {
        $root = dirname(__DIR__, 2);
        $path = $root . '/tmp/tiktok-cache-maintenance.lock';
        clearstatcache(true, $path);
        if (!$force && (int)@filemtime($path) + 60 > time()) return [];
        $lock = @fopen($path, 'c+');
        if ($lock === false) return [];
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return [];
            $now = time();
            $last = (int)stream_get_contents($lock);
            if (!$force && $last + 60 > $now) return [];
            $result = ['proxy_files_pruned' => 0, 'cache_rows_deleted' => 0, 'rate_rows_deleted' => 0];
            try {
                $result['proxy_files_pruned'] = self::pruneProxyFiles($root, $now);
                // Indexed, bounded deletes keep maintenance from delaying a large batch.
                $db = dvproDatabase();
                $result['cache_rows_deleted'] = $db->exec("DELETE FROM app_cache
                    WHERE cache_key LIKE 'tiktok:%' AND expires_at IS NOT NULL AND expires_at <= NOW() LIMIT 1000");
                $result['cache_rows_deleted'] += $db->exec("DELETE FROM app_cache
                    WHERE cache_key LIKE 'tiktok:%' AND updated_at <= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1000");
                $result['rate_rows_deleted'] = $db->exec('DELETE FROM app_rate_limits WHERE expires_at <= NOW() LIMIT 1000');
            } catch (Throwable $e) {
                // Cleanup must never break a check or cause an API fallback.
                $result['incomplete'] = true;
            }
            rewind($lock);
            ftruncate($lock, 0);
            fwrite($lock, (string)$now);
            fflush($lock);
            return $result;
        } finally {
            fclose($lock);
        }
    }

    private static function pruneProxyFiles(string $root, int $now): int
    {
        $changed = 0;
        foreach (['', 'username-'] as $scope) {
            $base = $root . '/tmp/tiktok-' . $scope . 'free-proxy-';
            $changed += self::pruneJson($base . 'failures.json', static fn(array $state, int $mtime): array =>
                array_filter($state, static fn($until): bool => is_int($until) && $until > $now));
            $ttl = max(0, min(3600, (int)dvproEnv(($scope === '' ? 'VIDEO' : 'USERNAME') . '_TIKTOK_PROXY_CACHE_TTL', '300')));
            $changed += self::pruneJson($base . 'list.json', static fn(array $state, int $mtime): array =>
                (int)($state['fetched_at'] ?? 0) + $ttl > $now ? $state : []);
            $changed += self::pruneJson($base . 'active.json', static function (array $state, int $mtime) use ($now): array {
                if (($state['country'] ?? '') !== 'VN') return [];
                $checks = is_array($state['checked_at'] ?? null) ? $state['checked_at'] : [];
                $proxies = array_values(array_filter((array)($state['proxies'] ?? []),
                    static fn($proxy): bool => is_string($proxy) && (int)($checks[$proxy] ?? $mtime) > $now - 86400));
                $checks = array_intersect_key($checks, array_flip($proxies));
                foreach ($proxies as $proxy) $checks[$proxy] = (int)($checks[$proxy] ?? $mtime);
                return ['country' => 'VN', 'proxy' => $proxies[0] ?? null, 'proxies' => $proxies,
                    'latency_ms' => array_intersect_key((array)($state['latency_ms'] ?? []), array_flip($proxies)),
                    'checked_at' => $checks];
            });
        }
        $base = $root . '/tmp/tiktok-video-proxy-';
        $changed += self::pruneJson($base . 'ranked.json', static function (array $state, int $mtime) use ($now): array {
            $entries = array_filter((array)($state['entries'] ?? []), static fn($entry): bool => is_array($entry)
                && (int)($entry['checked_at'] ?? 0) > $now - 86400);
            $preferred = $state['preferred'] ?? null;
            return ['preferred' => is_string($preferred) && isset($entries[$preferred]) ? $preferred : null,
                'entries' => $entries];
        });
        $changed += self::pruneJson($base . 'batches.json', static fn(array $state, int $mtime): array =>
            ['batches' => array_filter((array)($state['batches'] ?? []), static fn($batch): bool => is_array($batch)
                && (int)($batch['until'] ?? 0) > $now)]);
        $ttl = max(1, min(3600, (int)dvproEnv('VIDEO_TIKTOK_PROXY_CACHE_TTL', '300')));
        foreach (['vn', 'all'] as $country) {
            $changed += self::pruneJson($base . 'source-' . $country . '.json', static fn(array $state, int $mtime): array =>
                (int)($state['fetched_at'] ?? 0) + $ttl > $now ? $state : []);
        }
        return $changed;
    }

    private static function pruneJson(string $path, callable $filter): int
    {
        // Keep the file inode and lock intact while concurrent checks may still have it open.
        $handle = @fopen($path, 'r+');
        if ($handle === false) return 0;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) return 0;
            $state = json_decode((string)stream_get_contents($handle), true);
            if (!is_array($state)) return 0;
            $filtered = $filter($state, (int)(fstat($handle)['mtime'] ?? 0));
            if ($filtered === $state) return 0;
            $json = json_encode($filtered, JSON_UNESCAPED_SLASHES);
            if ($json === false) return 0;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $json);
            fflush($handle);
            return 1;
        } finally {
            fclose($handle);
        }
    }
}
