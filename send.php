<?php
/**
 * Отправка заявок с сайта ЮНА Фитнес Первомайская на почту.
 * Как настроить - в README.txt. Менять нужно только блок $CONFIG.
 * Нужен PHP 7.4+ с расширением openssl (есть на любом хостинге).
 */

$CONFIG = [
    'to'        => 'yuna.life@mail.ru', // куда приходят заявки
    'smtp_user' => 'yuna.life@mail.ru', // ящик, с которого уходят письма
    'smtp_pass' => '',                           // пароль для внешнего приложения Mail.ru (не основной пароль от ящика)
    'smtp_host' => 'smtp.mail.ru',
    'smtp_port' => 465,
    'from_name' => 'Сайт ЮНА Первомайская',
    'phone'     => '+7 (499) 550-14-62',
];

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Moscow');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply($code, $data)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function field($in, $key)
{
    return isset($in[$key]) && is_scalar($in[$key]) ? trim((string) $in[$key]) : '';
}

// Длинная тема режется на куски по RFC 2047, чтобы её правильно показывал любой почтовик
function mime($s)
{
    $parts = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    $buf = '';
    foreach ($parts ?: [$s] as $ch) {
        if ($buf !== '' && strlen($buf . $ch) > 45) {
            $out[] = '=?UTF-8?B?' . base64_encode($buf) . '?=';
            $buf = '';
        }
        $buf .= $ch;
    }
    $out[] = '=?UTF-8?B?' . base64_encode($buf) . '?=';
    return implode("\r\n ", $out);
}

// Не больше 3 заявок с одного IP за 10 минут и 40 заявок в час всего
function rate_ok($ip)
{
    $fp = @fopen(rtrim(sys_get_temp_dir(), '/\\') . '/yuna-pervomayskaya-form.json', 'c+');
    if (!$fp) {
        return true;
    }
    flock($fp, LOCK_EX);
    $now = time();
    $key = substr(hash('sha256', $ip . __FILE__), 0, 16);
    $log = json_decode((string) stream_get_contents($fp), true);
    $log = array_values(array_filter(is_array($log) ? $log : [], function ($e) use ($now) {
        return is_array($e) && isset($e[0], $e[1]) && $now - (int) $e[1] < 3600;
    }));
    $mine = count(array_filter($log, function ($e) use ($now, $key) {
        return $e[0] === $key && $now - (int) $e[1] < 600;
    }));
    $ok = $mine < 3 && count($log) < 40;
    if ($ok) {
        $log[] = [$key, $now];
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($log));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function smtp_send($c, $from, $to, $data, &$err)
{
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client('ssl://' . $c['smtp_host'] . ':' . $c['smtp_port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $err = 'нет соединения с ' . $c['smtp_host'] . ': ' . $errstr;
        return false;
    }
    stream_set_timeout($fp, 15);
    $step = function ($cmd, $want) use ($fp, &$err) {
        if ($cmd !== null) {
            fwrite($fp, $cmd . "\r\n");
        }
        $resp = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (!in_array((int) substr($resp, 0, 3), (array) $want, true)) {
            $err = trim($resp) !== '' ? trim($resp) : 'сервер не ответил';
            return false;
        }
        return true;
    };
    $ok = $step(null, 220)
        && $step('EHLO ' . (gethostname() ?: 'localhost'), 250)
        && $step('AUTH LOGIN', 334)
        && $step(base64_encode($c['smtp_user']), 334)
        && $step(base64_encode($c['smtp_pass']), 235)
        && $step('MAIL FROM:<' . $from . '>', 250)
        && $step('RCPT TO:<' . $to . '>', [250, 251])
        && $step('DATA', 354)
        && $step($data . "\r\n.", 250);
    if ($ok) {
        $step('QUIT', 221);
    }
    fclose($fp);
    return $ok;
}

$FAIL = 'Не удалось отправить. Позвоните: ' . $CONFIG['phone'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'error' => $FAIL]);
}

// Принимаем заявки только со своего сайта
$host = strtolower((string) preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origin !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== $host) {
    reply(403, ['ok' => false, 'error' => $FAIL]);
}

$in = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true);
if (!is_array($in)) {
    $in = $_POST;
}

// Боты: заполнили скрытое поле или отправили быстрее 1,5 с. Отвечаем «ок», письмо не шлём.
if (field($in, 'website') !== '' || (int) field($in, 't') < 1500) {
    reply(200, ['ok' => true]);
}

$name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F<>]/u', ' ', field($in, 'name'))));
$digits = (string) preg_replace('/\D+/', '', field($in, 'phone'));
if (strlen($digits) === 11 && $digits[0] === '8') {
    $digits = '7' . substr($digits, 1);
}
if (!preg_match('/^.{2,60}$/u', $name)) {
    reply(422, ['ok' => false, 'error' => 'Напишите, как к вам обращаться']);
}
if (!preg_match('/^7\d{10}$/', $digits)) {
    reply(422, ['ok' => false, 'error' => 'Проверьте номер - нужно 10 цифр после +7']);
}
if (!filter_var(field($in, 'consent'), FILTER_VALIDATE_BOOLEAN)) {
    reply(422, ['ok' => false, 'error' => 'Нужно согласие на обработку персональных данных']);
}
if (!rate_ok($_SERVER['REMOTE_ADDR'] ?? '')) {
    reply(429, ['ok' => false, 'error' => 'Слишком много заявок подряд. Позвоните: ' . $CONFIG['phone']]);
}

$phone = sprintf('+7 (%s) %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 2), substr($digits, 9, 2));
$when = date('d.m.Y H:i');
$page = substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', $_SERVER['HTTP_REFERER'] ?? ''), 0, 300);
$offer = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F<>]/u', ' ', field($in, 'offer'))));
if (!preg_match('/^.{2,80}$/u', $offer)) {
    $offer = 'Первый гостевой визит бесплатно';
}
$subject = 'Заявка «' . $offer . '»: ' . $name . ', ' . $phone;
$lines = [
    'Новая заявка с сайта ЮНА Фитнес Первомайская',
    '',
    'Имя: ' . $name,
    'Телефон: ' . $phone,
    'Заявка: ' . $offer,
    'Время: ' . $when . ' (МСК)',
    'Согласие на обработку персональных данных: отмечено в форме',
];
if ($page !== '') {
    $lines[] = 'Страница: ' . $page;
}
array_push($lines, '', 'Перезвоните в часы работы отдела продаж.', 'После обработки удалите письмо в срок, указанный в политике обработки персональных данных.');
$body = rtrim(chunk_split(base64_encode(implode("\r\n", $lines))));

$useSmtp = $CONFIG['smtp_pass'] !== '';
if ($useSmtp) {
    $from = $CONFIG['smtp_user'];
    $domain = substr((string) strrchr($from, '@'), 1);
} else {
    $domain = (string) preg_replace('/^www\./', '', (string) preg_replace('/[^a-z0-9.\-]/', '', $host));
    $domain = $domain !== '' ? $domain : 'localhost';
    $from = 'noreply@' . $domain;
}
$headers = [
    'Date: ' . date('r'),
    'From: ' . mime($CONFIG['from_name']) . ' <' . $from . '>',
    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
];

$err = '';
if ($useSmtp) {
    $msg = implode("\r\n", array_merge($headers, ['To: <' . $CONFIG['to'] . '>', 'Subject: ' . mime($subject)])) . "\r\n\r\n" . $body;
    $sent = smtp_send($CONFIG, $from, $CONFIG['to'], $msg, $err);
} else {
    // Запасной вариант без пароля: встроенная почта хостинга. Письма могут попадать в спам.
    $h = implode("\r\n", $headers);
    $sent = @mail($CONFIG['to'], mime($subject), $body, $h, '-f' . $from) || @mail($CONFIG['to'], mime($subject), $body, $h);
    if (!$sent) {
        $err = 'mail() не сработал';
    }
}

if (!$sent) {
    error_log('Форма ЮНА Первомайская: ' . $err);
    reply(502, ['ok' => false, 'error' => $FAIL]);
}
reply(200, ['ok' => true]);
