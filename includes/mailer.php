<?php
/*
|--------------------------------------------------------------------------
| Nivlektech Company Portal
|--------------------------------------------------------------------------
| File : includes/mailer.php
| Small dependency-free mailer. Drivers:
|   log   - nothing is sent, the message is only stored in email_logs (testing)
|   mail  - PHP mail()
|   smtp  - direct SMTP (SSL / STARTTLS / none, AUTH LOGIN)
|--------------------------------------------------------------------------
*/

class Mailer
{
    private PDO $pdo;
    private array $cfg;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->cfg = getMailSettings($pdo);
    }

    public function isEnabled(): bool
    {
        return (int)$this->cfg['enabled'] === 1;
    }

    /**
     * Send one email. Always writes a row to email_logs.
     * Returns [bool $ok, string $error]
     */
    public function send(string $to, string $subject, string $htmlBody, string $event = 'general'): array
    {
        $to = trim($to);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->log($to, $subject, $event, 'failed', 'Invalid recipient address');
            return [false, 'Invalid recipient address'];
        }

        if (!$this->isEnabled()) {
            $this->log($to, $subject, $event, 'skipped', 'Email notifications are turned off');
            return [false, 'Email notifications are turned off'];
        }

        $driver = $this->cfg['driver'];

        try {
            if ($driver === 'log') {
                $this->log($to, $subject, $event, 'logged', 'Log-only mode, nothing was sent');
                return [true, ''];
            }

            $fromEmail = $this->cfg['from_email'] ?: 'no-reply@localhost';
            $fromName  = $this->cfg['from_name']  ?: 'Company Portal';

            $message = $this->buildMessage($to, $subject, $htmlBody, $fromEmail, $fromName);

            if ($driver === 'smtp') {
                $this->smtpSend($fromEmail, $to, $message);
            } else {
                $headers = $message['headers'];
                if (!@mail($to, $message['subject'], $message['body'], $headers)) {
                    throw new RuntimeException('PHP mail() returned false. Check the server mail configuration.');
                }
            }

            $this->log($to, $subject, $event, 'sent', '');
            return [true, ''];

        } catch (Throwable $e) {
            $this->log($to, $subject, $event, 'failed', $e->getMessage());
            return [false, $e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------ */

    private function buildMessage(string $to, string $subject, string $html, string $fromEmail, string $fromName): array
    {
        $enc = function (string $s): string {
            return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        };

        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $fromName = str_replace(["\r", "\n", '"'], '', $fromName);
        $fromEmail = str_replace(["\r", "\n"], '', $fromEmail);

        $text = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/(p|tr|h[1-6]|div)>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $boundary = 'b' . bin2hex(random_bytes(12));

        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $enc($fromName) . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost') . '>',
            'X-Mailer: Company Portal',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($text)) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html)) . "\r\n";
        $body .= "--$boundary--\r\n";

        return [
            'subject' => $enc($subject),
            'headers' => implode("\r\n", $headers),
            'body'    => $body,
            'to'      => $to,
        ];
    }

    private function smtpSend(string $from, string $to, array $m): void
    {
        $host = $this->cfg['smtp_host'];
        $port = (int)$this->cfg['smtp_port'] ?: 587;
        $sec  = $this->cfg['smtp_encryption'];   // ssl | tls | none

        if ($host === '') {
            throw new RuntimeException('SMTP host is not configured');
        }

        $remote = ($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 15);
        if (!$fp) {
            throw new RuntimeException("Cannot connect to $host:$port ($errstr)");
        }
        stream_set_timeout($fp, 15);

        try {
            $this->expect($fp, [220]);
            $ehlo = parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost';
            $this->cmd($fp, "EHLO $ehlo", [250]);

            if ($sec === 'tls') {
                $this->cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed');
                }
                $this->cmd($fp, "EHLO $ehlo", [250]);
            }

            if ($this->cfg['smtp_username'] !== '') {
                $this->cmd($fp, 'AUTH LOGIN', [334]);
                $this->cmd($fp, base64_encode($this->cfg['smtp_username']), [334]);
                $this->cmd($fp, base64_encode($this->cfg['smtp_password']), [235]);
            }

            $this->cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
            $this->cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd($fp, 'DATA', [354]);

            $data = 'To: ' . $to . "\r\n"
                  . 'Subject: ' . $m['subject'] . "\r\n"
                  . $m['headers'] . "\r\n\r\n"
                  . $m['body'];
            // dot-stuffing
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data));
            $data = str_replace("\n", "\r\n", $data);

            fwrite($fp, $data . "\r\n.\r\n");
            $this->expect($fp, [250]);
            $this->cmd($fp, 'QUIT', [221]);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    private function cmd($fp, string $line, array $ok): string
    {
        fwrite($fp, $line . "\r\n");
        return $this->expect($fp, $ok);
    }

    private function expect($fp, array $ok): string
    {
        $resp = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException('SMTP error: ' . trim($resp ?: 'no response'));
        }
        return $resp;
    }

    private function log(string $to, string $subject, string $event, string $status, string $error): void
    {
        try {
            $st = $this->pdo->prepare("
                INSERT INTO email_logs (recipient, subject, event, status, error_message, created_at)
                VALUES (?,?,?,?,?,?)
            ");
            $st->execute([$to, $subject, $event, $status, $error, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            // logging must never break the page
        }
    }
}
