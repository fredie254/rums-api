<?php
/**
 * RUMS — Mail Service
 *
 * Sends HTML emails via SMTP (with STARTTLS/SSL support) or PHP mail().
 * No external dependencies — uses PHP stream_socket_client for SMTP.
 *
 * Supports:
 *   - Port 465 (SSL)
 *   - Port 587 (STARTTLS, default)
 *   - Port 25  (plain, no encryption)
 *   - Fallback to PHP mail() if no SMTP config
 */
class MailService
{
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $encryption; // tls | ssl | none
    private string $fromEmail;
    private string $fromName;
    private int    $timeout = 30;

    public function __construct(array $config = [])
    {
        $this->host       = $config['smtp_host']      ?? '';
        $this->port       = (int)($config['smtp_port']      ?? 587);
        $this->user       = $config['smtp_user']      ?? '';
        $this->pass       = $config['smtp_pass']      ?? '';
        $this->encryption = strtolower($config['smtp_encryption'] ?? 'tls');
        $this->fromEmail  = $config['from_email']     ?? ($config['smtp_user'] ?? '');
        $this->fromName   = $config['from_name']      ?? 'RUMS';
    }

    /**
     * Send an HTML email.
     *
     * @param  string      $to        Recipient email address
     * @param  string      $subject   Subject line
     * @param  string      $htmlBody  HTML message body
     * @param  string|null $textBody  Plain-text fallback (auto-generated from HTML if null)
     * @return array  ['success'=>bool, 'error'=>string|null, 'provider'=>string]
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): array
    {
        // Sanitise
        $to      = filter_var(trim($to), FILTER_VALIDATE_EMAIL);
        $subject = mb_substr(trim($subject), 0, 255);

        if (!$to) {
            return ['success' => false, 'error' => 'Invalid recipient email address.', 'provider' => 'none'];
        }

        if (!$textBody) {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));
            $textBody = html_entity_decode(preg_replace('/\s{2,}/', "\n", $textBody), ENT_QUOTES, 'UTF-8');
        }

        if ($this->host && $this->user) {
            $result = $this->smtpSend($to, $subject, $htmlBody, $textBody);
            $result['provider'] = 'smtp';
            return $result;
        }

        // Fallback to PHP mail()
        return $this->phpMailFallback($to, $subject, $htmlBody, $textBody);
    }

    // ── SMTP implementation ────────────────────────────────────

    private function smtpSend(string $to, string $subject, string $html, string $text): array
    {
        try {
            // Determine connection string
            if ($this->encryption === 'ssl') {
                $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
                $sock = stream_socket_client("ssl://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
            } else {
                $sock = stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout);
            }

            if (!$sock) {
                return ['success' => false, 'error' => "SMTP connect failed: $errstr ($errno)"];
            }

            stream_set_timeout($sock, $this->timeout);

            // Read greeting
            $resp = $this->read($sock);
            if (!str_starts_with($resp, '220')) {
                fclose($sock); return ['success' => false, 'error' => "SMTP greeting error: $resp"];
            }

            // EHLO
            $resp = $this->cmd($sock, 'EHLO ' . gethostname());
            if (!str_starts_with($resp, '250')) {
                // Try HELO fallback
                $resp = $this->cmd($sock, 'HELO ' . gethostname());
            }

            // STARTTLS for port 587
            if ($this->encryption === 'tls' && str_contains($resp, 'STARTTLS')) {
                $resp = $this->cmd($sock, 'STARTTLS');
                if (!str_starts_with($resp, '220')) {
                    fclose($sock); return ['success' => false, 'error' => "STARTTLS failed: $resp"];
                }
                // Upgrade stream to TLS
                $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($sock); return ['success' => false, 'error' => 'TLS upgrade failed.'];
                }
                // Re-EHLO after STARTTLS
                $resp = $this->cmd($sock, 'EHLO ' . gethostname());
            }

            // AUTH LOGIN
            if ($this->user && $this->pass) {
                $resp = $this->cmd($sock, 'AUTH LOGIN');
                if (!str_starts_with($resp, '334')) {
                    fclose($sock); return ['success' => false, 'error' => "AUTH LOGIN error: $resp"];
                }
                $resp = $this->cmd($sock, base64_encode($this->user));
                if (!str_starts_with($resp, '334')) {
                    fclose($sock); return ['success' => false, 'error' => "AUTH username error: $resp"];
                }
                $resp = $this->cmd($sock, base64_encode($this->pass));
                if (!str_starts_with($resp, '235')) {
                    fclose($sock); return ['success' => false, 'error' => "AUTH failed: $resp"];
                }
            }

            // MAIL FROM
            $from = $this->fromEmail ?: $this->user;
            $resp = $this->cmd($sock, "MAIL FROM:<$from>");
            if (!str_starts_with($resp, '250')) {
                fclose($sock); return ['success' => false, 'error' => "MAIL FROM error: $resp"];
            }

            // RCPT TO
            $resp = $this->cmd($sock, "RCPT TO:<$to>");
            if (!str_starts_with($resp, '250') && !str_starts_with($resp, '251')) {
                fclose($sock); return ['success' => false, 'error' => "RCPT TO error: $resp"];
            }

            // DATA
            $resp = $this->cmd($sock, 'DATA');
            if (!str_starts_with($resp, '354')) {
                fclose($sock); return ['success' => false, 'error' => "DATA error: $resp"];
            }

            // Build MIME message
            $boundary  = '----=_Part_' . md5(uniqid('', true));
            $messageId = '<' . uniqid('rums.', true) . '@' . gethostname() . '>';
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $fromHeader = $this->fromName
                ? '=?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $from . '>'
                : $from;

            $headers  = "From: $fromHeader\r\n";
            $headers .= "To: $to\r\n";
            $headers .= "Subject: $encodedSubject\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "Message-ID: $messageId\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
            $headers .= "X-Mailer: RUMS/1.0\r\n";
            $headers .= "\r\n";

            $body  = "--$boundary\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($text)) . "\r\n";
            $body .= "--$boundary\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($html)) . "\r\n";
            $body .= "--$boundary--\r\n";

            // Escape single dots on their own line
            $message = $headers . $body;
            $message = preg_replace('/^\.$/m', '..', $message);

            fwrite($sock, $message . "\r\n.\r\n");
            $resp = $this->read($sock);

            $this->cmd($sock, 'QUIT');
            fclose($sock);

            if (!str_starts_with($resp, '250')) {
                return ['success' => false, 'error' => "Message rejected: $resp"];
            }

            return ['success' => true, 'error' => null, 'message_id' => $messageId];

        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function phpMailFallback(string $to, string $subject, string $html, string $text): array
    {
        $from      = $this->fromEmail ?: ini_get('sendmail_from') ?: 'noreply@localhost';
        $fromName  = $this->fromName ?: 'RUMS';
        $boundary  = '----=_Part_' . md5(uniqid('', true));
        $encodedFrom = $fromName
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>'
            : $from;

        $headers  = "From: $encodedFrom\r\n";
        $headers .= "Reply-To: $from\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $headers .= "X-Mailer: RUMS/1.0\r\n";

        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n\r\n";
        $body .= "--$boundary--";

        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);

        return [
            'success'  => $ok,
            'error'    => $ok ? null : 'mail() function returned false. Check server mail config.',
            'provider' => 'mail',
        ];
    }

    // ── Socket helpers ─────────────────────────────────────────

    private function cmd($sock, string $command): string
    {
        fwrite($sock, $command . "\r\n");
        return $this->read($sock);
    }

    private function read($sock): string
    {
        $resp = '';
        while ($line = fgets($sock, 4096)) {
            $resp .= $line;
            // Multi-line responses: "250-..." continues; "250 ..." ends
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return rtrim($resp);
    }
}
