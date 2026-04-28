<?php

/**
 * cron_check.php — проверка истёкших наказаний (мут / временный бан)
 *
 * Вызывать по URL раз в минуту через бесплатный сервис cron-job.org:
 *   https://твой-сайт.ru/cron_check.php?secret=МОЙ_СЕКРЕТ
 *
 * Замени CRON_SECRET ниже на любое своё слово/пароль.
 */

require_once __DIR__ . '/manager-config.php';

// Проверка секрета — защита от посторонних запросов
if (($_GET['secret'] ?? '') !== CRON_SECRET) {
    http_response_code(403);
    exit('Forbidden');
}

http_response_code(200);
echo "OK\n";


function debugLog(string $msg): void {
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] [CRON] ') . $msg . "\n", FILE_APPEND);
}

function getDB(): SQLite3 {
    static $db = null;
    if (!$db) {
        $db = new SQLite3(DB_FILE);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');
    }
    return $db;
}

function apiRequest(string $method, array $params): ?array {
    $url  = API_URL . $method;
    $body = json_encode($params, JSON_UNESCAPED_UNICODE);
    $ch   = curl_init($url);
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ];
    // Прокси — такой же как в основном боте
    if (defined('PROXY_HOST') && PROXY_HOST !== '') {
        $opts[CURLOPT_PROXY]        = PROXY_HOST;
        $opts[CURLOPT_PROXYTYPE]    = CURLPROXY_SOCKS5_HOSTNAME;
        $opts[CURLOPT_PROXYUSERPWD] = PROXY_AUTH;
    }
    curl_setopt_array($ch, $opts);
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) {
        debugLog("apiRequest $method CURL ERROR: $curlError");
    }
    return $response ? json_decode($response, true) : null;
}

function defaultPermissions(): array {
    return [
        'can_send_messages'         => true,
        'can_send_media_messages'   => true,
        'can_send_polls'            => true,
        'can_send_other_messages'   => true,
        'can_add_web_page_previews' => true,
        'can_change_info'           => false,
        'can_invite_users'          => true,
        'can_pin_messages'          => false,
    ];
}

function formatUserLink(int $userId, string $name): string {
    return '<a href="tg://user?id=' . $userId . '">' . htmlspecialchars($name, ENT_XML1) . '</a>';
}

// ─────────────────────────────────────────────
// 1. ИСТЁКШИЕ МУТЫ
// ─────────────────────────────────────────────
function checkExpiredMutes(): void {
    $db  = getDB();
    $now = time();

    $result = $db->query(
        "SELECT user_id, chat_id, until FROM mutes WHERE until > 0 AND until <= $now"
    );
    if (!$result) return;

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $userId = (int)$row['user_id'];
        $chatId = (int)$row['chat_id'];

        // Снимаем ограничения
        apiRequest('restrictChatMember', [
            'chat_id'     => $chatId,
            'user_id'     => $userId,
            'permissions' => defaultPermissions(),
        ]);

        // Удаляем из БД
        $db->exec("DELETE FROM mutes WHERE user_id = $userId AND chat_id = $chatId");

        // Получаем имя
        $userRow  = $db->querySingle(
            "SELECT name FROM users WHERE user_id = $userId AND chat_id = $chatId", true
        );
        $name     = $userRow['name'] ?? "пользователь $userId";
        $nameLink = formatUserLink($userId, $name);

        // Уведомляем чат
        apiRequest('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "🔊 Срок мута пользователя {$nameLink} истёк. Ограничения сняты.",
            'parse_mode' => 'HTML',
        ]);

        debugLog("Мут истёк: user=$userId chat=$chatId");
    }
}

// ─────────────────────────────────────────────
// 2. ИСТЁКШИЕ ВРЕМЕННЫЕ БАНЫ
// ─────────────────────────────────────────────
function checkExpiredTempbans(): void {
    $db  = getDB();
    $now = time();

    $result = $db->query(
        "SELECT user_id, chat_id FROM tempbans WHERE until <= $now"
    );
    if (!$result) return;

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $userId = (int)$row['user_id'];
        $chatId = (int)$row['chat_id'];

        // Удаляем из БД
        $db->exec("DELETE FROM tempbans WHERE user_id = $userId AND chat_id = $chatId");

        // Получаем имя и название чата
        $userRow   = $db->querySingle(
            "SELECT name FROM users WHERE user_id = $userId AND chat_id = $chatId", true
        );
        $name      = $userRow['name'] ?? "пользователь $userId";
        $nameLink  = formatUserLink($userId, $name);

        $chatInfo  = apiRequest('getChat', ['chat_id' => $chatId]);
        $chatTitle = htmlspecialchars($chatInfo['result']['title'] ?? "чат", ENT_XML1);

        // Уведомляем чат
        apiRequest('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "🟢 Срок временного бана пользователя {$nameLink} истёк. Он снова может вступить в чат.",
            'parse_mode' => 'HTML',
        ]);

        // Уведомляем пользователя в личку
        apiRequest('sendMessage', [
            'chat_id'    => $userId,
            'text'       => "✅ Ваш временный бан в чате <b>{$chatTitle}</b> истёк.\nВы снова можете вступить в чат.",
            'parse_mode' => 'HTML',
        ]);

        debugLog("Tempban истёк: user=$userId chat=$chatId");
    }
}

// ─────────────────────────────────────────────
// ЗАПУСК
// ─────────────────────────────────────────────
checkExpiredMutes();
checkExpiredTempbans();
// ─────────────────────────────────────────────
// 3. СРАБОТАВШИЕ НАПОМИНАНИЯ
// ─────────────────────────────────────────────
function checkFiredReminders(): void {
    $db  = getDB();
    $now = time();

    $result = $db->query(
        "SELECT * FROM reminders WHERE fire_at <= $now AND done = 0 LIMIT 50"
    );
    if (!$result) return;

    $successIds = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $userId = (int)$row['user_id'];
        $chatId = (int)$row['chat_id'];
        $text   = htmlspecialchars($row['text'], ENT_XML1);

        $chatInfo  = apiRequest('getChat', ['chat_id' => $chatId]);
        $chatTitle = htmlspecialchars($chatInfo['result']['title'] ?? "ID {$chatId}", ENT_XML1);

        $res = apiRequest('sendMessage', [
            'chat_id'    => $userId,
            'text'       => "⏰ <b>Напоминание!</b>\n💬 Чат: <b>{$chatTitle}</b>\n━━━━━━━━━━━━━━━━━━━━\n{$text}",
            'parse_mode' => 'HTML',
        ]);

        // Помечаем done=1 ТОЛЬКО если сообщение реально отправлено
        if (!empty($res['ok'])) {
            $successIds[] = (int)$row['id'];
            debugLog("Напоминание #{$row['id']} отправлено: user=$userId chat=$chatId");
        } else {
            debugLog("Напоминание #{$row['id']} НЕ отправлено (ошибка API): user=$userId — повторим в следующий раз");
        }
    }

    if (!empty($successIds)) {
        $idList = implode(',', $successIds);
        $db->exec("UPDATE reminders SET done = 1 WHERE id IN ($idList)");
    }
}

checkFiredReminders();

// ─────────────────────────────────────────────
// 4. АВТОКИК НЕ ПРОШЕДШИХ ВЕРИФИКАЦИЮ
// ─────────────────────────────────────────────
function checkExpiredVerifications(): void {
    $db      = getDB();
    $timeout = 5 * 60; // 5 минут на нажатие кнопки
    $now     = time();

    $result = $db->query(
        "SELECT user_id, chat_id, message_id FROM verification_pending WHERE joined_at < " . ($now - $timeout)
    );
    if (!$result) return;

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $userId    = (int)$row['user_id'];
        $chatId    = (int)$row['chat_id'];
        $messageId = (int)$row['message_id'];

        // Кикаем (бан + сразу разбан = участник ушёл, но может зайти по инвайту снова)
        apiRequest('banChatMember',   ['chat_id' => $chatId, 'user_id' => $userId]);
        apiRequest('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);

        // Удаляем приветственное сообщение с кнопкой верификации
        if ($messageId > 0) {
            apiRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        }

        $db->exec("DELETE FROM verification_pending WHERE user_id = $userId AND chat_id = $chatId");
        debugLog("Верификация истекла: auto-kicked user=$userId chat=$chatId");
    }
}

checkExpiredVerifications();
