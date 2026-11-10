<?php

class MicrosoftMailMessage
{
    public static function fromMime(string $mime): array
    {
        [$headers, $body] = self::splitMime($mime);
        $subject = self::decodeHeader($headers['subject'] ?? '');
        $from = self::parseFrom(self::decodeHeader($headers['from'] ?? ''));
        $parts = self::readPart($headers, $body, 0);
        $html = $parts['html'] !== '' ? $parts['html'] : nl2br(htmlspecialchars($parts['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        return ['from' => $from, 'subject' => $subject, 'message' => $html,
            'date' => $headers['date'] ?? '', 'code' => self::extractCode($subject, $html)];
    }

    private static function splitMime(string $mime): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $mime, 2);
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $parts[0] ?? '');
        $headers = [];
        foreach (preg_split('/\r?\n/', $unfolded) as $line) {
            if (!str_contains($line, ':')) continue;
            [$key, $value] = explode(':', $line, 2);
            $key = strtolower(trim($key));
            if (!isset($headers[$key])) $headers[$key] = trim($value);
        }
        return [$headers, $parts[1] ?? ''];
    }

    private static function readPart(array $headers, string $body, int $depth): array
    {
        $empty = ['html' => '', 'text' => ''];
        if ($depth > 12 || preg_match('/^attachment\b/i', $headers['content-disposition'] ?? '')) return $empty;
        $type = strtolower(trim(explode(';', $headers['content-type'] ?? 'text/plain')[0]));
        if (str_starts_with($type, 'multipart/')) {
            $boundary = self::parameter($headers['content-type'], 'boundary');
            if ($boundary === '') return $empty;
            $chunks = preg_split('/(?:^|\r?\n)--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?:\r?\n|$)/', $body);
            $result = $empty;
            foreach (array_slice($chunks, 1) as $chunk) {
                if (!str_contains($chunk, "\n\n") && !str_contains($chunk, "\r\n\r\n")) continue;
                [$partHeaders, $partBody] = self::splitMime($chunk);
                $part = self::readPart($partHeaders, $partBody, $depth + 1);
                foreach (['html', 'text'] as $key) if ($result[$key] === '' && $part[$key] !== '') $result[$key] = $part[$key];
            }
            return $result;
        }
        if (!in_array($type, ['text/plain', 'text/html'], true)) return $empty;
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? ''));
        if ($encoding === 'base64') $body = base64_decode(preg_replace('/\s+/', '', $body), true) ?: '';
        elseif ($encoding === 'quoted-printable') $body = quoted_printable_decode($body);
        $charset = self::parameter($headers['content-type'] ?? '', 'charset');
        if ($charset !== '' && strcasecmp($charset, 'UTF-8') !== 0) {
            try { $body = mb_convert_encoding($body, 'UTF-8', $charset); }
            catch (ValueError $e) { $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8'); }
        }
        return $type === 'text/html' ? ['html' => $body, 'text' => ''] : ['html' => '', 'text' => $body];
    }

    private static function parameter(string $value, string $name): string
    {
        return preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $value, $match)
            ? ($match[1] !== '' ? $match[1] : ($match[2] ?? '')) : '';
    }

    private static function decodeHeader(string $value): string
    {
        return str_contains($value, '=?') ? mb_decode_mimeheader($value) : $value;
    }

    private static function parseFrom(string $value): array
    {
        if (preg_match('/^(.*?)<([^<>]+)>/', $value, $match)) {
            return [['name' => trim($match[1], " \t\""), 'address' => trim($match[2])]];
        }
        return [['name' => '', 'address' => trim($value)]];
    }

    public static function extractCode(string $subject, string $body): string
    {
        $text = html_entity_decode(strip_tags($subject . ' ' . $body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        if (preg_match('/(?:\bcode\b|\botp\b|mã(?: xác (?:minh|nhận)| bảo mật)?)[^0-9]{0,60}(?<![0-9])([0-9]{4,8})(?![0-9])/iu', $text, $match)) return $match[1];
        return preg_match('/(?<![0-9])([0-9]{6})(?![0-9])/', $text, $match) ? $match[1] : '';
    }
}
