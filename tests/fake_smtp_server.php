<?php
// A minimal SMTP server for testing Mailer.php locally — NOT part of
// the shipped project. Accepts one connection, walks through the
// EHLO/AUTH LOGIN/MAIL FROM/RCPT TO/DATA sequence, and prints what it
// received so a test script can assert on it.

$port = (int)($argv[1] ?? 2525);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Could not start test SMTP server: {$errstr}\n");
    exit(1);
}

$conn = stream_socket_accept($server, 30);
if (!$conn) {
    fwrite(STDERR, "No connection received within timeout.\n");
    exit(1);
}

function respond($conn, string $line): void {
    fwrite($conn, $line . "\r\n");
}

respond($conn, "220 fake-smtp.local Simple Mail Transfer Service Ready");

$received = ['auth_user' => null, 'auth_pass' => null, 'mail_from' => null, 'rcpt_to' => null, 'data' => ''];
$state = 'command';

while (($line = fgets($conn, 4096)) !== false) {
    $line = rtrim($line, "\r\n");

    if ($state === 'data') {
        if ($line === '.') {
            $state = 'command';
            respond($conn, "250 OK: message accepted");
            continue;
        }
        $received['data'] .= $line . "\n";
        continue;
    }

    if (stripos($line, 'EHLO') === 0) {
        respond($conn, "250-fake-smtp.local\r\n250-STARTTLS\r\n250 AUTH LOGIN");
    } elseif (stripos($line, 'STARTTLS') === 0) {
        // Correctly ack per RFC 3207, but this fake server has no cert to
        // complete a real TLS handshake — see README note on this file.
        // Use SMTP_ENCRYPTION=none against this harness to test the
        // plaintext command sequence; TLS itself needs a real mail server.
        respond($conn, "220 Ready to start TLS");
    } elseif (stripos($line, 'AUTH LOGIN') === 0) {
        respond($conn, "334 VXNlcm5hbWU6"); // "Username:"
        $state = 'auth_user';
    } elseif ($state === 'auth_user') {
        $received['auth_user'] = base64_decode($line);
        respond($conn, "334 UGFzc3dvcmQ6"); // "Password:"
        $state = 'auth_pass';
    } elseif ($state === 'auth_pass') {
        $received['auth_pass'] = base64_decode($line);
        respond($conn, "235 Authentication successful");
        $state = 'command';
    } elseif (stripos($line, 'MAIL FROM:') === 0) {
        $received['mail_from'] = $line;
        respond($conn, "250 OK");
    } elseif (stripos($line, 'RCPT TO:') === 0) {
        $received['rcpt_to'] = $line;
        respond($conn, "250 OK");
    } elseif (stripos($line, 'DATA') === 0) {
        respond($conn, "354 Start mail input; end with <CRLF>.<CRLF>");
        $state = 'data';
    } elseif (stripos($line, 'QUIT') === 0) {
        respond($conn, "221 Bye");
        break;
    } else {
        respond($conn, "250 OK");
    }
}

fclose($conn);
fclose($server);

file_put_contents('/tmp/smtp_test_result.json', json_encode($received, JSON_PRETTY_PRINT));
