<?php

/**
 * Установка Webhook для Manager Bot
 * Запустить ОДИН РАЗ: https://yourdomain.com/set_webhook.php?token=ВАШ_ТОКЕН
 */

require_once __DIR__ . '/manager-config.php';

$token = $_GET['token'] ?? BOT_TOKEN;

if (!$token) {
    die("❌ Укажи токен бота: ?token=ВАШ_ТОКЕН");
}

function tg_curl(string $token, string $method, array $params = []): array {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST           => !empty($params),
        CURLOPT_POSTFIELDS     => !empty($params) ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ];
    if (defined('PROXY_HOST') && PROXY_HOST !== '') {
        $opts[CURLOPT_PROXY]        = PROXY_HOST;
        $opts[CURLOPT_PROXYTYPE]    = CURLPROXY_SOCKS5_HOSTNAME;
        $opts[CURLOPT_PROXYUSERPWD] = PROXY_AUTH;
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok' => false, 'curl_error' => $err];
    return json_decode($res ?: '{}', true) ?? [];
}

// Автоматически определяем URL manager-bot.php
$protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'];
$dir        = dirname($_SERVER['REQUEST_URI']);
$webhookUrl = $protocol . '://' . $host . $dir . '/manager-bot.php';

// Устанавливаем webhook
$data = tg_curl($token, 'setWebhook', [
    'url'                  => $webhookUrl,
    'allowed_updates'      => ['message', 'callback_query', 'chat_member', 'my_chat_member'],
    'drop_pending_updates' => true,
]);

// Получаем инфо о боте
$me          = tg_curl($token, 'getMe');
$botName     = $me['result']['first_name'] ?? 'Неизвестно';
$botUsername = $me['result']['username']   ?? 'Неизвестно';

// Проверяем текущий статус webhook
$info        = tg_curl($token, 'getWebhookInfo');
$webhookInfo = $info['result'] ?? [];

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Manager Bot — Webhook Setup</title>
    <style>
        body   { font-family: monospace; background: #1e1e2e; color: #cdd6f4; padding: 40px; }
        h2     { color: #cba6f7; }
        .ok    { color: #a6e3a1; }
        .err   { color: #f38ba8; }
        .warn  { color: #f9e2af; }
        pre    { background: #313244; padding: 16px; border-radius: 8px; overflow-x: auto; }
        .label { color: #89b4fa; font-weight: bold; }
        .block { margin-bottom: 24px; }
    </style>
</head>
<body>
    <h2>🤖 Manager Bot — Webhook Setup</h2>

    <div class="block">
        <p><span class="label">Бот:</span> <?= htmlspecialchars($botName) ?> (@<?= htmlspecialchars($botUsername) ?>)</p>
        <p><span class="label">Webhook URL:</span> <?= htmlspecialchars($webhookUrl) ?></p>
    </div>

    <div class="block">
        <?php if ($data['ok'] ?? false): ?>
            <p class="ok">✅ Webhook успешно установлен!</p>
            <p>Теперь добавь бота в группу и назначь администратором.</p>
        <?php else: ?>
            <p class="err">❌ Ошибка установки webhook:</p>
            <?php if (isset($data['curl_error'])): ?>
                <p class="err">🔌 cURL ошибка: <?= htmlspecialchars($data['curl_error']) ?></p>
                <p class="warn">⚠️ Хостинг блокирует исходящие запросы к api.telegram.org.<br>
                Обратитесь в поддержку хостинга и попросите открыть порт 443 для api.telegram.org.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="block">
        <p class="label">Ответ setWebhook:</p>
        <pre><?= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></pre>
    </div>

    <div class="block">
        <p class="label">Статус Webhook (getWebhookInfo):</p>
        <pre><?= json_encode($webhookInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></pre>
        <?php if (!empty($webhookInfo['last_error_message'])): ?>
            <p class="err">⚠️ Последняя ошибка: <?= htmlspecialchars($webhookInfo['last_error_message']) ?></p>
        <?php endif; ?>
    </div>

    <p style="color:#6c7086; font-size:12px;">Удали этот файл с сервера после успешной установки.</p>
</body>
</html>
