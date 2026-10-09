<?php
// Приём заявок с формы contact.html. Письмо уходит на info@q17.tech
// Если в smtp-config.php указан пароль ящика - отправка идёт через SMTP, иначе через mail().
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
date_default_timezone_set('Europe/Moscow');

const DIAG     = true;   // диагностика доступна только с секретным ключом из smtp-config.php
const TO_EMAIL = 'info@q17.tech';

function log_error($reason, $detail = '') {
    $dir = __DIR__ . '/leads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    @file_put_contents($dir . '/error.log', date('c') . ' ' . $reason . ' ' . $detail . "\n", FILE_APPEND | LOCK_EX);
}
function out($ok, $message = '') {
    if (!$ok && $message !== '' && $message !== 'method') { log_error($message); }
    echo json_encode(array('ok' => $ok, 'message' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}
function clean($v, $max, $multiline = false) {
    $v = is_string($v) ? trim($v) : '';
    $v = $multiline ? preg_replace('/[^\P{C}\n]+/u', '', $v) : preg_replace('/\p{C}+/u', ' ', $v);
    return mb_substr($v, 0, $max, 'UTF-8');
}
function raw_config() {
    $f = __DIR__ . '/smtp-config.php';
    if (!is_file($f)) return array();
    $c = include $f;
    return is_array($c) ? $c : array();
}
function load_smtp_config() {
    $c = raw_config();
    if (!is_array($c) || empty($c['host']) || empty($c['user']) || empty($c['password'])) return null;
    if (strpos($c['password'], 'ВСТАВЬТЕ') !== false) return null;
    return $c;
}

function mime_body($text, $html) {
    if (!$html) return array("Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64", chunk_split(base64_encode($text), 76, "\r\n"));
    $b = '=_q17_' . md5(uniqid('', true));
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text), 76, "\r\n")
          . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n") . "--$b--\r\n";
    return array("Content-Type: multipart/alternative; boundary=\"$b\"", $body);
}
function build_email_html($d) {
    $h = function ($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); };
    $tel = '+' . preg_replace('/\D/', '', $d['phone']);
    $row = function ($k, $v) use ($h) { return '<tr><td style="padding:8px 0;color:#6c757d;font-size:13px;width:90px;vertical-align:top">' . $h($k) . '</td><td style="padding:8px 0;font-size:15px;color:#1B1D1E">' . $v . '</td></tr>'; };
    $o  = '<div style="background:#f4f2ff;padding:20px 10px;font-family:Arial,Helvetica,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden">';
    $o .= '<tr><td style="background:#4928FD;color:#ffffff;padding:16px 24px;font-size:14px">Q17.tech &middot; новая заявка</td></tr><tr><td style="padding:24px">';
    $o .= '<div style="font-size:22px;font-weight:bold;color:#1B1D1E">' . $h($d['name']) . '</div><div style="color:#6c757d;font-size:13px;margin:4px 0 14px">Бесплатный аудит &middot; ' . $h($d['date']) . ' (МСК)</div>';
    $o .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #eee">';
    $o .= $row('Телефон', '<a href="tel:' . $h($tel) . '" style="color:#4928FD;text-decoration:none;font-weight:bold">' . $h($d['phone']) . '</a>');
    $o .= $row('Email', '<a href="mailto:' . $h($d['email']) . '" style="color:#4928FD;text-decoration:none">' . $h($d['email']) . '</a>');
    $o .= $row('Источник', $h($d['source'] !== '' ? $d['source'] : '—')) . '</table>';
    if ($d['message'] !== '') $o .= '<div style="margin-top:14px;padding:14px 16px;background:#f4f2ff;border-radius:10px;font-size:15px;line-height:1.5;color:#1B1D1E">' . nl2br($h($d['message'])) . '</div>';
    $o .= '<div style="margin-top:20px"><a href="tel:' . $h($tel) . '" style="display:inline-block;background:#1B1D1E;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;font-size:14px">Позвонить</a></div>';
    $o .= '<div style="margin-top:20px;padding-top:14px;border-top:1px solid #eee;color:#8a8f94;font-size:12px;line-height:1.5">Согласие на обработку ПД: да<br>Согласие на рекламные материалы: ' . ($d['ads'] ? 'да' : 'нет') . '<br>IP: ' . $h($d['ip']) . '</div>';
    return $o . '</td></tr></table></div>';
}

// ---- минимальный SMTP-клиент (без внешних библиотек) ----
function smtp_send($c, $to, $subject, $text, $replyTo, &$err, $html = null) {
    $enc  = isset($c['encryption']) ? $c['encryption'] : 'ssl';
    $port = isset($c['port']) ? (int) $c['port'] : 465;
    $verify = !isset($c['verify']) || $c['verify'];
    $ctx = stream_context_create(array('ssl' => array('verify_peer' => $verify, 'verify_peer_name' => $verify)));
    $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = "connect $errno $errstr"; return false; }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($send, $ok) use ($fp, $read, &$err) {
        if ($send !== null) fwrite($fp, $send . "\r\n");
        $r = $read();
        if ($r === '' || strpos($ok, substr($r, 0, 3)) === false) { $err = trim($r) === '' ? 'нет ответа сервера' : trim($r); return false; }
        return $r;
    };

    if (!$cmd(null, '220')) { fclose($fp); return false; }
    if (!$cmd('EHLO q17.tech', '250')) { fclose($fp); return false; }
    if ($enc === 'tls') {
        if (!$cmd('STARTTLS', '220')) { fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $err = 'STARTTLS: не удалось включить шифрование'; fclose($fp); return false; }
        if (!$cmd('EHLO q17.tech', '250')) { fclose($fp); return false; }
    }
    if (!$cmd('AUTH LOGIN', '334') || !$cmd(base64_encode($c['user']), '334') || !$cmd(base64_encode($c['password']), '235')) {
        $err = 'авторизация: ' . $err; fclose($fp); return false;
    }
    if (!$cmd('MAIL FROM:<' . $c['user'] . '>', '250') || !$cmd('RCPT TO:<' . $to . '>', '250 251') || !$cmd('DATA', '354')) { fclose($fp); return false; }

    $msg  = 'Date: ' . date('r') . "\r\n";
    $msg .= 'From: Q17.tech <' . $c['user'] . ">\r\n";
    $msg .= 'To: <' . $to . ">\r\n";
    if ($replyTo) $msg .= 'Reply-To: <' . $replyTo . ">\r\n";
    $msg .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
    $msg .= 'Message-ID: <' . md5(uniqid('', true)) . "@q17.tech>\r\n";
    list($ct, $mb) = mime_body($text, $html);
    $msg .= "MIME-Version: 1.0\r\n" . $ct . "\r\n\r\n" . $mb;
    fwrite($fp, $msg . ".\r\n");
    $r = $read();
    if (strpos($r, '250') !== 0) { $err = 'отправка: ' . trim($r); fclose($fp); return false; }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

function tg_send($c, $text, &$err) {
    $token = isset($c['tg_token']) ? trim($c['tg_token']) : '';
    $chat  = isset($c['tg_chat']) ? trim($c['tg_chat']) : '';
    if ($token === '' || $chat === '' || strpos($token, 'ВСТАВЬТЕ') !== false) return null; // Telegram не настроен
    $url  = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $data = http_build_query(array('chat_id' => $chat, 'text' => mb_substr($text, 0, 3800, 'UTF-8'), 'disable_web_page_preview' => 1));
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 10));
        $r = curl_exec($ch);
        $e = curl_error($ch);
        curl_close($ch);
        if ($r === false) { $err = 'telegram: ' . $e; return false; }
    } else {
        $ctx = stream_context_create(array('http' => array('method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $data, 'timeout' => 10, 'ignore_errors' => true)));
        $r = @file_get_contents($url, false, $ctx);
        if ($r === false) { $err = 'telegram: нет соединения'; return false; }
    }
    $j = json_decode($r, true);
    if (!$j || empty($j['ok'])) { $err = 'telegram: ' . (isset($j['description']) ? $j['description'] : substr($r, 0, 100)); return false; }
    return true;
}

function send_lead($subject, $text, $replyTo, &$err, $html = null) {
    $raw = raw_config();
    $errs = array();
    $any = false;
    // 1) Telegram (если настроен)
    $r = tg_send($raw, $text, $e1);
    if ($r === true) { $any = true; } elseif ($r === false) { $errs[] = $e1; }
    // 2) SMTP (если указан пароль ящика)
    $c = load_smtp_config();
    if ($c) {
        if (smtp_send($c, TO_EMAIL, $subject, $text, $replyTo, $e2, $html)) { $any = true; } else { $errs[] = 'smtp: ' . $e2; }
    } elseif (!$any) {
        // 3) обычный mail() как последний вариант
        list($ct, $mb) = mime_body($text, $html);
        $headers = "From: Q17.tech <" . TO_EMAIL . ">\r\n" . ($replyTo ? "Reply-To: $replyTo\r\n" : '') . "MIME-Version: 1.0\r\n" . $ct . "\r\n";
        $sj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = @mail(TO_EMAIL, $sj, $mb, $headers, '-f' . TO_EMAIL);
        if (!$ok) $ok = @mail(TO_EMAIL, $sj, $mb, $headers);
        if ($ok) { $any = true; } else { $le = error_get_last(); $errs[] = 'mail(): ' . ($le ? $le['message'] : 'отказ'); }
    }
    $err = implode(' | ', $errs);
    return $any;
}

// ---- диагностика: https://q17.tech/send.php?test=ВАШ_КЛЮЧ (ключ задан в smtp-config.php, diag_key) ----
$rc = raw_config();
if (DIAG && isset($_GET['test']) && !empty($rc['diag_key']) && hash_equals((string) $rc['diag_key'], (string) $_GET['test'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "PHP: " . PHP_VERSION . "\n";
    echo "mbstring: " . (function_exists('mb_substr') ? 'есть' : 'НЕТ') . "\n";
    echo "openssl: " . (extension_loaded('openssl') ? 'есть' : 'НЕТ (SSL SMTP не заработает)') . "\n";
    echo "Telegram настроен: " . ((!empty($rc['tg_token']) && !empty($rc['tg_chat']) && strpos($rc['tg_token'], 'ВСТАВЬТЕ') === false) ? 'да' : 'нет') . "\n";
    $c = load_smtp_config();
    echo "SMTP настроен: " . ($c ? 'да (' . $c['host'] . ':' . $c['port'] . ', ' . $c['encryption'] . ', ' . $c['user'] . ')' : 'нет, используется mail()') . "\n";
    $err = '';
    $ok = send_lead('Тест send.php', 'Тестовое письмо ' . date('c'), '', $err);
    echo "Отправка: " . ($ok ? 'принято' : 'ОШИБКА') . ($err !== '' ? ' (' . $err . ')' : '') . "\n";
    echo "Проверьте ящик " . TO_EMAIL . " (и Спам).\n";
    if (isset($_GET['probe'])) {
        echo "\nКакие почтовые серверы доступны с хостинга (пароль не передаётся):\n";
        $cands = array(
            array('smtp.sweb.ru', 465, 'ssl'), array('smtp.sweb.ru', 587, 'tcp'), array('smtp.sweb.ru', 25, 'tcp'), array('smtp.sweb.ru', 2525, 'tcp'),
            array('mail.sweb.ru', 465, 'ssl'), array('mail.sweb.ru', 587, 'tcp'), array('mail.sweb.ru', 25, 'tcp'),
            array('smtp.q17.tech', 465, 'ssl'), array('smtp.q17.tech', 587, 'tcp'),
            array('mail.q17.tech', 465, 'ssl'), array('mail.q17.tech', 587, 'tcp'), array('mail.q17.tech', 25, 'tcp'),
            array('localhost', 25, 'tcp'), array('localhost', 587, 'tcp'), array('localhost', 465, 'ssl'),
            array('api.telegram.org', 443, 'ssl')
        );
        $ctx = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
        foreach ($cands as $k) {
            $fp = @stream_socket_client($k[2] . '://' . $k[0] . ':' . $k[1], $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
            if ($fp) {
                stream_set_timeout($fp, 4);
                $b = trim((string) fgets($fp, 200));
                fclose($fp);
                echo $k[0] . ':' . $k[1] . ' (' . $k[2] . ') - ОТКРЫТ. Ответ: ' . $b . "\n";
            } else {
                echo $k[0] . ':' . $k[1] . ' (' . $k[2] . ') - нет (' . $en . ' ' . $es . ")\n";
            }
        }
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); out(false, 'method'); }

// защита от ботов: скрытое поле должно быть пустым
if (!empty($_POST['website'])) { out(true); }

// запросы только с этого же сайта
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origin !== '') {
    $oh = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $hh = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($oh !== $hh) { http_response_code(403); out(false, 'origin'); }
}

// не чаще одной заявки в 15 секунд с одного IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$stamp = sys_get_temp_dir() . '/q17_lead_' . md5($ip);
if (is_file($stamp) && (time() - (int) @filemtime($stamp)) < 15) { http_response_code(429); out(false, 'rate'); }
@touch($stamp);

$name    = clean($_POST['Fname'] ?? '', 100);
$phone   = clean($_POST['phone'] ?? '', 30);
$email   = clean($_POST['email'] ?? '', 120);
$message = clean($_POST['message'] ?? '', 300, true);
$source  = clean($_POST['source'] ?? '', 150);
$consent = ($_POST['pd_consent'] ?? '') === 'yes';
$ads     = ($_POST['marketing_consent'] ?? '') === 'yes';

if (mb_strlen($name, 'UTF-8') < 2 || strlen(preg_replace('/\D/', '', $phone)) < 10
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$consent) {
    http_response_code(422);
    out(false, 'validation');
}

$body  = "Новая заявка на бесплатный аудит — Q17.tech\r\n\r\n";
$body .= "Имя: $name\r\nТелефон: $phone\r\nEmail: $email\r\n";
$body .= "Сообщение: " . ($message !== '' ? $message : '—') . "\r\n";
$body .= "Источник: " . ($source !== '' ? $source : '—') . "\r\n";
$body .= "Согласие на обработку ПД: да\r\n";
$body .= "Согласие на рекламные материалы: " . ($ads ? 'да' : 'нет') . "\r\n";
$body .= "Дата: " . date('d.m.Y H:i:s') . " (МСК)\r\nIP: $ip\r\n";

$err = '';
if (!send_lead('Заявка на бесплатный аудит — Q17.tech', $body, $email, $err, build_email_html(array('name' => $name, 'phone' => $phone, 'email' => $email, 'message' => $message, 'source' => $source, 'ads' => $ads, 'ip' => $ip, 'date' => date('d.m.Y H:i'))))) {
    // запасной вариант: заявка не теряется, лежит в закрытой папке
    $dir = __DIR__ . '/leads';
    log_error('send-failed', $err);
    @file_put_contents($dir . '/failed-leads.log', "---- " . date('c') . "\n" . $body . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    out(false, 'mail');
}
out(true);
