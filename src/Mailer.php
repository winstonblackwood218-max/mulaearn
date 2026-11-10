<?php
// src/Mailer.php — a minimal, dependency-free SMTP client.
//
// Why hand-rolled instead of PHPMailer/Symfony Mailer: this project's
// sandboxed build environment has no route to packagist.org, so
// Composer packages aren't installable here. This talks raw SMTP
// (EHLO / STARTTLS / AUTH LOGIN / MAIL FROM / RCPT TO / DATA) over a
// plain PHP stream socket — no external dependency, but it is a real
// client, not a stub.
//
// HONESTY NOTE FOR WHOEVER DEPLOYS THIS: if config('mail.smtp_host')
// is not set, send() does NOT silently pretend to succeed — it writes
// the email to storage/mail.log and returns ['sent' => false, 'logged'
// => true]. Callers (see WalletService/withdraw.php) must not tell a
// user "we emailed you" when this happens; log the fact that
// notification delivery isn't configured yet instead.

declare(strict_types=1);

final class Mailer
{
    private array $config;

    public function __construct()
    {
        $full = require __DIR__ . '/../config/config.php';
        $this->config = $full['mail'];
    }

    /**
     * @return array{sent:bool, logged:bool, error:?string}
     */
    public function send(string $toEmail, string $toName, string $subject, string $bodyText): array
    {
        if (empty($this->config['smtp_host'])) {
            $this->logToFile($toEmail, $subject, $bodyText);
            return ['sent' => false, 'logged' => true, 'error' => null];
        }

        try {
            $this->sendViaSmtp($toEmail, $toName, $subject, $bodyText);
            return ['sent' => true, 'logged' => false, 'error' => null];
        } catch (Throwable $e) {
            // Never let a notification failure break the underlying
            // action (e.g. a withdrawal request). Log locally and
            // report the failure back to the caller to display/handle.
            $this->logToFile($toEmail, $subject, $bodyText, $e->getMessage());
            return ['sent' => false, 'logged' => true, 'error' => $e->getMessage()];
        }
    }

    private function sendViaSmtp(string $toEmail, string $toName, string $subject, string $bodyText): void
    {
        $host = $this->config['smtp_host'];
        $port = $this->config['smtp_port'];
        $encryption = $this->config['encryption'];

        $transport = $encryption === 'ssl' ? "ssl://{$host}" : $host;
        $socket = @stream_socket_client("{$transport}:{$port}", $errno, $errstr, 15);
        if (!$socket) {
            throw new RuntimeException("Could not connect to SMTP host: {$errstr} ({$errno})");
        }

        $this->expect($socket, 220);
        $this->command($socket, "EHLO " . gethostname(), 250);

        if ($encryption === 'tls') {
            $this->command($socket, "STARTTLS", 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed.');
            }
            $this->command($socket, "EHLO " . gethostname(), 250);
        }

        if (!empty($this->config['smtp_user'])) {
            $this->command($socket, "AUTH LOGIN", 334);
            $this->command($socket, base64_encode($this->config['smtp_user']), 334);
            $this->command($socket, base64_encode((string)$this->config['smtp_pass']), 235);
        }

        $from = $this->config['from_email'];
        $this->command($socket, "MAIL FROM:<{$from}>", 250);
        $this->command($socket, "RCPT TO:<{$toEmail}>", 250);
        $this->command($socket, "DATA", 354);

        $headers = $this->buildHeaders($toEmail, $toName, $subject);
        $payload = $headers . "\r\n" . $this->escapeDot($bodyText) . "\r\n.";
        $this->command($socket, $payload, 250);

        $this->command($socket, "QUIT", 221);
        fclose($socket);
    }

    private function buildHeaders(string $toEmail, string $toName, string $subject): string
    {
        $fromName = $this->config['from_name'];
        $fromEmail = $this->config['from_email'];
        $safeSubject = str_replace(["\r", "\n"], '', $subject);

        return implode("\r\n", [
            "From: {$fromName} <{$fromEmail}>",
            "To: {$toName} <{$toEmail}>",
            "Subject: {$safeSubject}",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Date: " . date(DATE_RFC2822),
        ]);
    }

    /** RFC 5321: a line that is just "." must be escaped as ".." */
    private function escapeDot(string $body): string
    {
        return preg_replace('/^\./m', '..', $body);
    }

    private function command($socket, string $line, int $expectedCode): string
    {
        fwrite($socket, $line . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    private function expect($socket, int $expectedCode): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // Multi-line SMTP responses use "code-" until the final "code ".
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("Unexpected SMTP response (wanted {$expectedCode}): {$response}");
        }
        return $response;
    }

    private function logToFile(string $toEmail, string $subject, string $bodyText, ?string $error = null): void
    {
        $line = sprintf(
            "[%s] %sTo: %s | Subject: %s\n%s\n---\n",
            date('Y-m-d H:i:s'),
            $error ? "SEND FAILED ({$error}) — " : "NOT SENT (SMTP not configured) — ",
            $toEmail,
            $subject,
            $bodyText
        );
        @file_put_contents($this->config['log_path'], $line, FILE_APPEND);
    }
}
