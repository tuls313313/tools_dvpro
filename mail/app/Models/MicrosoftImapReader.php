<?php
require_once __DIR__ . '/MicrosoftMailMessage.php';

class MicrosoftImapReader
{
    private $socket = null;
    private int $sequence = 0;

    public function __construct(private float $deadline, private ?string $caFile = null) {}

    public function read(string $email, string $accessToken, string $listMail, int $top): array
    {
        $options = ['verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => 'outlook.office365.com', 'SNI_enabled' => true];
        if ($this->caFile !== null) $options['cafile'] = $this->caFile;
        $context = stream_context_create(['ssl' => $options]);
        $this->socket = @stream_socket_client('tls://outlook.office365.com:993', $errno, $error,
            min(5.0, $this->remaining()), STREAM_CLIENT_CONNECT, $context);
        if ($this->socket === false) throw new RuntimeException('Không kết nối được Microsoft IMAP qua TLS. Kiểm tra kết nối và chứng chỉ trên server.');
        try {
            if (!str_starts_with($this->readLine(), '* OK')) throw new RuntimeException('Microsoft IMAP chưa sẵn sàng.');
            $auth = base64_encode('user=' . $email . "\x01auth=Bearer " . $accessToken . "\x01\x01");
            $this->command('AUTHENTICATE XOAUTH2', $auth);
            $folders = $listMail === 'all' ? $this->parseFolders($this->command('LIST "" "*"')) : ['INBOX'];
            $candidates = [];
            foreach ($folders as $folder) {
                // EXAMINE is read-only; no mailbox flags can be changed by this connection.
                $this->command('EXAMINE ' . $this->quote($folder));
                $uids = $this->parseUids($this->command('UID SEARCH ALL'));
                foreach (array_slice($uids, -$top) as $uid) $candidates[] = ['folder' => $folder, 'uid' => $uid];
            }
            $messages = [];
            $selected = null;
            foreach ($candidates as $candidate) {
                if ($selected !== $candidate['folder']) {
                    $this->command('EXAMINE ' . $this->quote($candidate['folder']));
                    $selected = $candidate['folder'];
                }
                $response = $this->command('UID FETCH ' . $candidate['uid'] . ' (BODY.PEEK[])');
                $messages[] = MicrosoftMailMessage::fromMime($this->extractLiteral($response));
            }
            usort($messages, static fn(array $a, array $b): int => (strtotime($b['date']) ?: 0) <=> (strtotime($a['date']) ?: 0));
            return array_slice($messages, 0, $top);
        } finally {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function command(string $command, ?string $auth = null): string
    {
        $tag = 'M' . str_pad((string)++$this->sequence, 4, '0', STR_PAD_LEFT);
        $this->write($tag . ' ' . $command . "\r\n");
        $response = '';
        $sentAuth = false;
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, '+')) {
                if ($auth === null) throw new RuntimeException('Microsoft IMAP trả phản hồi ngoài dự kiến.');
                $this->write(($sentAuth ? '' : $auth) . "\r\n");
                $sentAuth = true;
                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . preg_quote($tag, '/') . ' OK\b/i', $line)) {
                    if ($auth !== null) throw new RuntimeException('Microsoft IMAP từ chối đăng nhập. Kiểm tra quyền IMAP và token của ứng dụng.');
                    throw new RuntimeException('Microsoft IMAP từ chối yêu cầu đọc thư.');
                }
                return $response;
            }
            if (str_starts_with($line, '* BYE')) throw new RuntimeException('Microsoft IMAP đã đóng kết nối. Vui lòng đọc lại.');
            $response .= $line;
            if (preg_match('/\{([0-9]+)\}\r?\n$/', $line, $match)) {
                $length = (int)$match[1];
                if ($length > 20 * 1024 * 1024) throw new RuntimeException('Thư vượt giới hạn đọc 20 MB.');
                $response .= $this->readBytes($length);
            }
        }
    }

    private function remaining(): float
    {
        $seconds = $this->deadline - microtime(true);
        if ($seconds <= 0) throw new RuntimeException('Hết thời gian chờ Microsoft IMAP. Vui lòng đọc lại.');
        return $seconds;
    }

    private function applyTimeout(): void
    {
        $seconds = $this->remaining();
        stream_set_timeout($this->socket, (int)$seconds, (int)(($seconds - (int)$seconds) * 1000000));
    }

    private function readLine(): string
    {
        $this->applyTimeout();
        $line = fgets($this->socket, 65536);
        if ($line === false || !str_ends_with($line, "\n")) $this->readFailure();
        return $line;
    }

    private function readBytes(int $length): string
    {
        $body = '';
        while (strlen($body) < $length) {
            $this->applyTimeout();
            $chunk = fread($this->socket, min(65536, $length - strlen($body)));
            if ($chunk === false || $chunk === '') $this->readFailure();
            $body .= $chunk;
        }
        return $body;
    }

    private function readFailure(): never
    {
        $meta = stream_get_meta_data($this->socket);
        if (!empty($meta['timed_out'])) throw new RuntimeException('Hết thời gian chờ Microsoft IMAP. Vui lòng đọc lại.');
        throw new RuntimeException('Microsoft IMAP trả phản hồi không đầy đủ.');
    }

    private function write(string $data): void
    {
        while ($data !== '') {
            $this->applyTimeout();
            $written = fwrite($this->socket, $data);
            if ($written === false || $written === 0) $this->readFailure();
            $data = substr($data, $written);
        }
    }

    private function quote(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) throw new RuntimeException('Tên thư mục Microsoft không hợp lệ.');
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function parseFolders(string $listing): array
    {
        $folders = [];
        foreach (preg_split('/\r?\n/', $listing) as $line) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:NIL|"(?:[^"\\\\]|\\\\.)*") (.+)$/i', trim($line), $match)) continue;
            if (stripos($match[1], '\\Noselect') !== false) continue;
            $folder = trim($match[2]);
            if (str_starts_with($folder, '"') && str_ends_with($folder, '"')) $folder = preg_replace('/\\\\(["\\\\])/', '$1', substr($folder, 1, -1));
            if ($folder !== '' && !preg_match('/[\x00-\x1F\x7F]/', $folder)) $folders[] = $folder;
        }
        if (!in_array('INBOX', $folders, true)) array_unshift($folders, 'INBOX');
        return array_values(array_unique($folders));
    }

    private function parseUids(string $response): array
    {
        if (!preg_match('/^\* SEARCH(?: ([0-9 ]+))?\r?$/mi', $response, $match)) throw new RuntimeException('Microsoft IMAP trả danh sách thư không hợp lệ.');
        $uids = preg_split('/ +/', trim($match[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter($uids, static fn(string $uid): bool => ctype_digit($uid) && $uid !== '0')));
    }

    private function extractLiteral(string $response): string
    {
        if (preg_match('/\{([0-9]+)\}\r?\n/', $response, $match, PREG_OFFSET_CAPTURE)) {
            $length = (int)$match[1][0];
            $offset = $match[0][1] + strlen($match[0][0]);
            if ($length <= 0 || strlen($response) - $offset < $length) throw new RuntimeException('Microsoft IMAP trả nội dung thư không đầy đủ.');
            return substr($response, $offset, $length);
        }
        throw new RuntimeException('Không đọc được nội dung thư Microsoft IMAP.');
    }
}
