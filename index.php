<?php
// ─── ВРЕМЕННО: логируем все PHP-ошибки в файл ───────────────────────────────
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bot_error.log');
error_reporting(E_ALL);
// ────────────────────────────────────────────────────────────────────────────

// Московское время (UTC+3)
date_default_timezone_set('Europe/Moscow');

/**
 * Manager Bot — бот для управления чатом
 * Работает в группе/супергруппе. Команды используют администраторы прямо в чате.
 * Чистый PHP, cURL, PDO, без зависимостей. Webhook.
 */

require_once __DIR__ . '/manager-config.php';

// DEBUG_MODE: включите true только при отладке — логи замедляют обработку
define('DEBUG_MODE', true); // ВРЕМЕННО включён для диагностики

function debugLog(string $msg): void {
    if (!DEBUG_MODE) return;
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// Читаем входящие данные
$rawInput = file_get_contents('php://input');
$update   = json_decode($rawInput, true);

// Отвечаем Telegram немедленно и закрываем соединение —
// дальнейшая обработка идёт уже без ожидания со стороны Telegram
$responseBody = '{"ok":true}';
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: ' . strlen($responseBody));
header('Connection: close');
ob_start();
echo $responseBody;
ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if (!$update) { exit; }

if (isset($update['message'])) {
    handleMessage($update['message']);
}

if (isset($update['callback_query'])) {
    handleCallback($update['callback_query']);
}

// ─────────────────────────────────────────────
// ИНИЦИАЛИЗАЦИЯ БД
// ─────────────────────────────────────────────
function initDB(): void {
    $db = getDB();
    // PDO SQLite не поддерживает несколько операторов в одном exec() —
    // каждая таблица создаётся отдельным вызовом
    $db->exec("CREATE TABLE IF NOT EXISTS warns (
        user_id INTEGER,
        chat_id INTEGER,
        count   INTEGER DEFAULT 0,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS mutes (
        user_id    INTEGER,
        chat_id    INTEGER,
        until      INTEGER DEFAULT 0,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id       INTEGER,
        chat_id       INTEGER,
        username      TEXT,
        name          TEXT,
        message_count INTEGER DEFAULT 0,
        joined_at     INTEGER DEFAULT 0,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS verification_pending (
        user_id    INTEGER,
        chat_id    INTEGER,
        message_id INTEGER NOT NULL,
        joined_at  INTEGER NOT NULL,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rules (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id  INTEGER,
        text     TEXT,
        position INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS tempbans (
        user_id  INTEGER,
        chat_id  INTEGER,
        until    INTEGER NOT NULL,
        notified INTEGER DEFAULT 0,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS automod (
        chat_id       INTEGER PRIMARY KEY,
        block_links   INTEGER DEFAULT 0,
        block_arabic  INTEGER DEFAULT 0,
        block_caps    INTEGER DEFAULT 0,
        block_flood   INTEGER DEFAULT 0,
        flood_limit   INTEGER DEFAULT 5,
        flood_seconds INTEGER DEFAULT 10,
        mute_minutes  INTEGER DEFAULT 15,
        block_media   INTEGER DEFAULT 0,
        media_limit   INTEGER DEFAULT 5,
        media_seconds INTEGER DEFAULT 30
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS automod_words (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id INTEGER,
        word    TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS flood_tracker (
        user_id  INTEGER,
        chat_id  INTEGER,
        count    INTEGER DEFAULT 1,
        window   INTEGER DEFAULT 0,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS media_tracker (
        user_id  INTEGER,
        chat_id  INTEGER,
        count    INTEGER DEFAULT 1,
        window   INTEGER DEFAULT 0,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS bans (
        user_id    INTEGER,
        chat_id    INTEGER,
        banned_by  INTEGER NOT NULL,
        reason     TEXT DEFAULT '',
        banned_at  INTEGER NOT NULL,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS automod_whitelist (
        user_id INTEGER,
        chat_id INTEGER,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS reports (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id     INTEGER NOT NULL,
        reported_id INTEGER NOT NULL,
        reporter_id INTEGER NOT NULL,
        message_id  INTEGER NOT NULL,
        reason      TEXT DEFAULT '',
        reviewed    INTEGER DEFAULT 0,
        reviewer_id INTEGER DEFAULT 0,
        created_at  INTEGER NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS report_settings (
        chat_id     INTEGER PRIMARY KEY,
        target_chat INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS pending_actions (
        prompt_msg_id INTEGER PRIMARY KEY,
        chat_id       INTEGER NOT NULL,
        admin_id      INTEGER NOT NULL,
        action        TEXT NOT NULL,
        report_id     INTEGER NOT NULL,
        target_id     INTEGER NOT NULL,
        orig_msg_id   INTEGER NOT NULL,
        created_at    INTEGER NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS welcome_settings (
        chat_id  INTEGER PRIMARY KEY,
        enabled  INTEGER DEFAULT 1,
        template TEXT DEFAULT ''
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS bot_chats (
        chat_id   INTEGER PRIMARY KEY,
        title     TEXT DEFAULT '',
        joined_at INTEGER NOT NULL DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS reputation (
        user_id INTEGER,
        chat_id INTEGER,
        rep     INTEGER DEFAULT 0,
        PRIMARY KEY (user_id, chat_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rep_history (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        from_id    INTEGER NOT NULL,
        to_id      INTEGER NOT NULL,
        chat_id    INTEGER NOT NULL,
        value      INTEGER NOT NULL,
        created_at INTEGER NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS modlog (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id    INTEGER NOT NULL,
        admin_id   INTEGER NOT NULL,
        target_id  INTEGER NOT NULL,
        action     TEXT NOT NULL,
        reason     TEXT DEFAULT '',
        extra      TEXT DEFAULT '',
        created_at INTEGER NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS modlog_settings (
        chat_id     INTEGER PRIMARY KEY,
        log_chat_id INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS reminders (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id    INTEGER NOT NULL,
        user_id    INTEGER NOT NULL,
        message_id INTEGER NOT NULL,
        text       TEXT    NOT NULL,
        fire_at    INTEGER NOT NULL,
        done       INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS antiraid (
        chat_id        INTEGER PRIMARY KEY,
        enabled        INTEGER DEFAULT 0,
        threshold      INTEGER DEFAULT 10,
        window_seconds INTEGER DEFAULT 60,
        action         TEXT    DEFAULT 'mute',
        active_until   INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS antiraid_joins (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id   INTEGER NOT NULL,
        user_id   INTEGER NOT NULL,
        joined_at INTEGER NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS message_log (
        message_id INTEGER NOT NULL,
        chat_id    INTEGER NOT NULL,
        user_id    INTEGER NOT NULL,
        sent_at    INTEGER NOT NULL,
        PRIMARY KEY (message_id, chat_id)
    )");

    // Миграция: гарантируем наличие новых колонок в automod (антиспам медиа)
    $amCols = [];
    $amColRes = $db->query("PRAGMA table_info(automod)");
    if ($amColRes) {
        while ($col = $amColRes->fetch(PDO::FETCH_ASSOC)) {
            $amCols[] = $col['name'];
        }
    }
    $amRequired = [
        'block_media'   => "ALTER TABLE automod ADD COLUMN block_media   INTEGER DEFAULT 0",
        'media_limit'   => "ALTER TABLE automod ADD COLUMN media_limit   INTEGER DEFAULT 5",
        'media_seconds' => "ALTER TABLE automod ADD COLUMN media_seconds INTEGER DEFAULT 30",
    ];
    foreach ($amRequired as $col => $sql) {
        if (!in_array($col, $amCols, true)) {
            $db->exec($sql);
            debugLog("initDB migration: added column $col to automod");
        }
    }

    // Миграция: гарантируем наличие всех колонок в modlog (на случай старой БД)
    $existingCols = [];
    $colRes = $db->query("PRAGMA table_info(modlog)");
    if ($colRes) {
        while ($col = $colRes->fetch(PDO::FETCH_ASSOC)) {
            $existingCols[] = $col['name'];
        }
    }
    $requiredCols = [
        'reason'     => "ALTER TABLE modlog ADD COLUMN reason TEXT DEFAULT ''",
        'extra'      => "ALTER TABLE modlog ADD COLUMN extra TEXT DEFAULT ''",
        'created_at' => "ALTER TABLE modlog ADD COLUMN created_at INTEGER NOT NULL DEFAULT 0",
    ];
    foreach ($requiredCols as $col => $sql) {
        if (!in_array($col, $existingCols, true)) {
            $db->exec($sql);
            debugLog("initDB migration: added column $col to modlog");
        }
    }

    // Миграция: добавляем is_bot в таблицу users
    $usersCols = [];
    $usersColRes = $db->query("PRAGMA table_info(users)");
    if ($usersColRes) {
        while ($col = $usersColRes->fetch(PDO::FETCH_ASSOC)) {
            $usersCols[] = $col['name'];
        }
    }
    if (!in_array('is_bot', $usersCols, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN is_bot INTEGER DEFAULT 0");
        debugLog("initDB migration: added column is_bot to users");
    }
}

// ─────────────────────────────────────────────
// РЕГИСТРАЦИЯ ЧАТА В БД
// ─────────────────────────────────────────────
function registerBotChat(int $chatId, string $title): void {
    $db      = getDB();
    $escaped = addslashes($title);
    $now     = time();
    $db->exec("
        INSERT OR IGNORE INTO bot_chats (chat_id, title, joined_at) VALUES ($chatId, '$escaped', $now)
        ON CONFLICT(chat_id) DO UPDATE SET title = '$escaped'
    ");
}

// ─────────────────────────────────────────────
// ПАНЕЛЬ ВЛАДЕЛЬЦА — СТАТИСТИКА
// ─────────────────────────────────────────────
function isOwner(int $userId): bool {
    return $userId === OWNER_ID;
}

function sendOwnerPanel(int $chatId): void {
    $db = getDB();

    $totalUsers = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM users")->fetchColumn() ?: 0;
    $totalChats = (int)$db->query("SELECT COUNT(*) FROM bot_chats")->fetchColumn() ?: 0;

    // Общее число наказаний: варны + муты + баны + темпбаны
    $totalWarns    = (int)$db->query("SELECT COALESCE(SUM(count),0) FROM warns")->fetchColumn() ?: 0;
    $totalMutes    = (int)$db->query("SELECT COUNT(*) FROM mutes")->fetchColumn() ?: 0;
    $totalBans     = (int)$db->query("SELECT COUNT(*) FROM bans")->fetchColumn() ?: 0;
    $totalTempbans = (int)$db->query("SELECT COUNT(*) FROM tempbans")->fetchColumn() ?: 0;
    $totalPunishments = $totalWarns + $totalMutes + $totalBans + $totalTempbans;

    $text = "👑 <b>Панель владельца</b>\n\n"
        . "👥 <b>Пользователей в боте:</b> <code>{$totalUsers}</code>\n"
        . "💬 <b>Чатов бота:</b> <code>{$totalChats}</code>\n\n"
        . "⚖️ <b>Наказания (всего):</b> <code>{$totalPunishments}</code>\n"
        . "  ├ ⚠️ Варны: <code>{$totalWarns}</code>\n"
        . "  ├ 🔇 Муты: <code>{$totalMutes}</code>\n"
        . "  ├ 🔴 Баны: <code>{$totalBans}</code>\n"
        . "  └ ⏳ Временные баны: <code>{$totalTempbans}</code>\n\n"
        . "🕐 Обновлено: " . date('d.m.Y H:i:s');

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Обновить', 'callback_data' => 'owner:refresh'],
            ],
            [
                ['text' => '💬 Список чатов', 'callback_data' => 'owner:chats'],
            ],
        ],
    ];

    sendMessage($chatId, $text, $keyboard);
}

function sendOwnerChatList(int $chatId): void {
    $db     = getDB();
    $result = $db->query("SELECT chat_id, title, joined_at FROM bot_chats ORDER BY joined_at DESC LIMIT 30");

    if (!$result) {
        sendMessage($chatId, "📭 Список чатов пуст.");
        return;
    }

    $lines = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $title   = htmlspecialchars($row['title'] ?: 'Без названия', ENT_XML1);
        $cid     = (int)$row['chat_id'];
        $date    = date('d.m.Y', (int)$row['joined_at']);
        $lines[] = "• <b>{$title}</b> (<code>{$cid}</code>) — с {$date}";
    }

    if (empty($lines)) {
        sendMessage($chatId, "📭 Список чатов пуст.");
        return;
    }

    $text = "💬 <b>Чаты бота</b> (последние 30):\n━━━━━━━━━━━━━━━━━━━━\n\n"
        . implode("\n", $lines);

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '◀️ Назад', 'callback_data' => 'owner:back']],
        ],
    ];

    sendMessage($chatId, $text, $keyboard);
}


function saveUser(int $chatId, array $from, int $messageId = 0): void {
    $db       = getDB();
    $userId   = $from['id'];
    $username = strtolower(addslashes($from['username'] ?? ''));
    $name     = addslashes(trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')));
    $isBot    = !empty($from['is_bot']) ? 1 : 0;
    $now      = time();

    $db->exec("
        INSERT OR REPLACE INTO users (user_id, chat_id, username, name, message_count, joined_at, is_bot)
        VALUES ($userId, $chatId, '$username', '$name', 1, $now, $isBot)
        ON CONFLICT(user_id, chat_id) DO UPDATE SET
            username = '$username',
            name = '$name',
            is_bot = $isBot,
            message_count = message_count + 1,
            joined_at = CASE WHEN joined_at = 0 THEN $now ELSE joined_at END
    ");

    // Логируем message_id для /purge @user
    if ($messageId > 0) {
        $db->exec("
            INSERT OR IGNORE INTO message_log (message_id, chat_id, user_id, sent_at)
            VALUES ($messageId, $chatId, $userId, $now)
        ");
        // Чистим старые записи (старше 30 дней) раз в ~5% сообщений
        if (mt_rand(1, 20) === 1) {
            $cutoff = $now - 86400 * 30;
            $db->exec("DELETE FROM message_log WHERE chat_id = $chatId AND sent_at < $cutoff");
        }
    }
}

// ─────────────────────────────────────────────
// ОБРАБОТКА ВХОДЯЩИХ СООБЩЕНИЙ
// ─────────────────────────────────────────────
function handleMessage(array $msg): void {
    $chatType = $msg['chat']['type'] ?? '';

    // В личке
    if ($chatType === 'private') {
        $text   = trim($msg['text'] ?? '');
        $chatId = $msg['chat']['id'];
        $userId = $msg['from']['id'];

        // /debuglog — отправить лог владельцу прямо в Telegram
        if ($text === '/debuglog') {
            if (!isOwner($userId)) { sendMessage($chatId, "⛔ Нет доступа."); return; }
            $out = '';
            foreach ([['bot_error.log', __DIR__ . '/bot_error.log'], ['bot_debug.log', __DIR__ . '/bot_debug.log']] as [$name, $path]) {
                if (file_exists($path) && filesize($path) > 0) {
                    $tail = implode('', array_slice(file($path), -30));
                    $out .= "📄 <b>$name</b>:\n<pre>" . htmlspecialchars(mb_substr($tail, -3000)) . "</pre>\n\n";
                } else {
                    $out .= "📄 <b>$name</b>: пуст или не существует\n\n";
                }
            }
            sendMessage($chatId, $out ?: 'Логов нет.');
            return;
        }

        // /panel — панель владельца (только для владельца)
        if (strtolower(explode(' ', $text)[0]) === '/panel' || $text === '👑 Панель владельца') {
            if (!isOwner($userId)) {
                sendMessage($chatId, "⛔ Нет доступа.");
                return;
            }
            initDB();
            // Восстанавливаем кнопку ReplyKeyboard на случай если она пропала
            apiRequest('sendMessage', [
                'chat_id'    => $chatId,
                'text'       => "",
                'reply_markup' => [
                    'keyboard'        => [[['text' => '👑 Панель владельца']]],
                    'resize_keyboard' => true,
                    'persistent'      => true,
                ],
            ]);
            sendOwnerPanel($chatId);
            return;
        }

        if (strtolower(explode(' ', $text)[0]) === '/start') {
            // Регистрируем команды только раз в сутки (флаг-файл общий с групповым кодом)
            $flagFile = __DIR__ . '/commands_registered.flag';
            if (!file_exists($flagFile) || (time() - filemtime($flagFile)) > 86400) {
                registerBotCommands();
                registerOwnerCommands();
                touch($flagFile);
            }

            // Получаем username бота для ссылки добавления в группу
            $botInfo     = apiRequest('getMe', []);
            $botUsername = $botInfo['result']['username'] ?? '';

            $greeting = "👋 <b>Привет! Я " . BOT_NAME . " — бот для управления чатом.</b>\n\n"
                . "Нажми кнопку ниже, чтобы добавить меня в свою группу.\n"
                . "После добавления выдай мне права администратора и напиши в группе /help";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '➕ Добавить бота в группу',
                            'url'  => "https://t.me/{$botUsername}?startgroup=start&admin=delete_messages+ban_users+restrict_members+promote_members",
                        ],
                    ],
                ],
            ];

            // Для владельца — обычное сообщение с inline-кнопкой + ReplyKeyboard панели
            if (isOwner($userId)) {
                apiRequest('sendMessage', [
                    'chat_id'    => $chatId,
                    'text'       => $greeting,
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [[
                                'text' => '➕ Добавить бота в группу',
                                'url'  => "https://t.me/{$botUsername}?startgroup=start&admin=delete_messages+ban_users+restrict_members+promote_members",
                            ]],
                        ],
                    ],
                ]);
                apiRequest('sendMessage', [
                    'chat_id'    => $chatId,
                    'text'       => "Вы владелец данного менеджера, вам доступна панель владельца",
                    'reply_markup' => [
                        'keyboard'        => [[['text' => '👑 Панель владельца']]],
                        'resize_keyboard' => true,
                        'persistent'      => true,
                    ],
                ]);
            } else {
                sendMessage($chatId, $greeting, $keyboard);
            }
        }
        return;
    }

    initDB();

    // Регистрируем чат в БД (при первом сообщении и обновлении названия)
    $chatTitle = $msg['chat']['title'] ?? '';
    registerBotChat($msg['chat']['id'], $chatTitle);

    $chatId    = $msg['chat']['id'];
    // Пропускаем сообщения без отправителя (анонимные администраторы, каналы)
    if (empty($msg['from'])) {
        debugLog("handleMessage: no 'from' field, skipping. chat=$chatId msg_keys=" . implode(',', array_keys($msg)));
        return;
    }
    $userId    = $msg['from']['id'];
    $text      = trim($msg['text'] ?? $msg['caption'] ?? '');
    $messageId = $msg['message_id'];

// Приветствие новых участников
if (!empty($msg['new_chat_members'])) {
    initDB();
    $db = getDB();

    // Получаем ID бота (кешируем)
    static $botId = null;
    if ($botId === null) {
        $me = apiRequest('getMe', []);
        $botId = (int)($me['result']['id'] ?? 0);
    }

    foreach ($msg['new_chat_members'] as $newMember) {
        $memberId = $newMember['id'];
        $isBot    = !empty($newMember['is_bot']);

        // Если добавили нашего бота
        if ($isBot && $memberId === $botId) {
            // Отправляем приветственное сообщение
            sendMessage($chatId, "🤖 " . BOT_NAME . " готов к работе!\nИспользуйте /help для списка команд.");

            // Регистрируем чат в БД
            $chatTitle = $msg['chat']['title'] ?? '';
            registerBotChat($chatId, $chatTitle);

            // Добавляем создателя чата в белый список авто-модерации (опционально)
            $admins = apiRequest('getChatAdministrators', ['chat_id' => $chatId]);
            if (!empty($admins['result'])) {
                foreach ($admins['result'] as $admin) {
                    if (($admin['status'] ?? '') === 'creator') {
                        $ownerId = (int)$admin['user']['id'];
                        $db->exec("INSERT OR IGNORE INTO automod_whitelist (user_id, chat_id) VALUES ($ownerId, $chatId)");
                        break;
                    }
                }
            }

            // Регистрируем команды бота для этого чата
            registerBotCommands();

            // Пропускаем дальнейшую обработку (бот не должен проходить верификацию)
            continue;
        }

        // Для всех остальных ботов (не нашего) — пропускаем
        if ($isBot) continue;

        // --- Дальше идёт оригинальный код приветствия для обычных участников ---
        // Проверка анти-рейда
        if (checkAntiRaid($chatId, $memberId)) {
            // Пользователь заблокирован/замьючен антирейдом — пропускаем приветствие
            continue;
        }

        $wRow = $db->query("SELECT enabled, template FROM welcome_settings WHERE chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        $welcomeEnabled  = ($wRow === false) ? true : (bool)$wRow['enabled'];
        $welcomeTemplate = ($wRow && $wRow['template'] !== '') ? $wRow['template'] : null;

        // Верификация работает ВСЕГДА, независимо от настроек приветствия.
        // Если приветствие отключено — показываем только кнопку верификации без текста приветствия.

        $firstName = htmlspecialchars($newMember['first_name'] ?? '', ENT_XML1);
        $lastName  = htmlspecialchars($newMember['last_name'] ?? '', ENT_XML1);
        $fullName  = trim("$firstName $lastName");
        $username  = $newMember['username'] ?? '';

        // {userid} -> @username если есть, иначе кликабельное имя через tg://user
        if ($username !== '') {
            $userMention = "@{$username}";
        } else {
            $userMention = "<a href=\"tg://user?id={$memberId}\">{$fullName}</a>";
        }

        // {name} -> кликабельное имя (всегда ссылка)
        $nameMention = "<a href=\"tg://user?id={$memberId}\">{$fullName}</a>";

        if (!$welcomeEnabled) {
            // Приветствие отключено — только сообщение верификации без текста приветствия
            $welcome = "👤 {$nameMention}, пожалуйста, подтвердите вступление.";
        } elseif ($welcomeTemplate !== null) {
            $welcome = str_replace(
                ['{userid}', '{name}'],
                [$userMention, $nameMention],
                $welcomeTemplate
            );
        } else {
            $welcome = "👋 Добро пожаловать, {$nameMention}!";
        }

        // Мьютим нового участника до прохождения верификации
        apiRequest('restrictChatMember', [
            'chat_id'     => $chatId,
            'user_id'     => $memberId,
            'permissions' => [
                'can_send_messages'         => false,
                'can_send_audios'           => false,
                'can_send_documents'        => false,
                'can_send_photos'           => false,
                'can_send_videos'           => false,
                'can_send_video_notes'      => false,
                'can_send_voice_notes'      => false,
                'can_send_polls'            => false,
                'can_send_other_messages'   => false,
                'can_add_web_page_previews' => false,
                'can_change_info'           => false,
                'can_invite_users'          => false,
                'can_pin_messages'          => false,
            ],
        ]);

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '✅ Я не бот — подтвердить вступление', 'callback_data' => "verify:{$memberId}"],
            ]],
        ];

        $result = sendMessage($chatId, $welcome . "\n\n🔒 <b>Нажмите кнопку ниже, чтобы получить доступ к чату.</b>", $keyboard);

        // Сохраняем ожидающую верификацию
        $welcomeMsgId = $result['result']['message_id'] ?? 0;
        $now = time();
        $db->exec("
            INSERT OR REPLACE INTO verification_pending (user_id, chat_id, message_id, joined_at)
            VALUES ($memberId, $chatId, $welcomeMsgId, $now)
            ON CONFLICT(user_id, chat_id) DO UPDATE SET message_id = $welcomeMsgId, joined_at = $now
        ");

        // Сохраняем joined_at для нового участника
        $uname   = strtolower(addslashes($username));
        $eName   = addslashes($fullName);
        $db->exec("
            INSERT OR REPLACE INTO users (user_id, chat_id, username, name, message_count, joined_at)
            VALUES ($memberId, $chatId, '$uname', '$eName', 0, $now)
            ON CONFLICT(user_id, chat_id) DO UPDATE SET
                username = '$uname', name = '$eName',
                joined_at = CASE WHEN joined_at = 0 THEN $now ELSE joined_at END
        ");
    }
    return;
}

    // Сохраняем пользователя в БД
    saveUser($chatId, $msg['from'], $messageId);

    // Периодические проверки (случайно ~10% сообщений)
    // checkExpiredVerifications перенесена в cron_check.php
    if (mt_rand(1, 10) === 1) {
        checkAntiRaidDecay($chatId);
    }

    // Проверка мута
    if (isMuted($chatId, $userId)) {
        deleteMessage($chatId, $messageId);
        return;
    }

    // Обработка ответа администратора на ForceReply (ввод времени для жалобы)
    $replyToId = $msg['reply_to_message']['message_id'] ?? null;
    if ($replyToId && $text !== '') {
        $db = getDB();
        $pending = $db->query("SELECT * FROM pending_actions WHERE prompt_msg_id = $replyToId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        if ($pending && isChatAdmin($chatId, $userId)) {
            $db->exec("DELETE FROM pending_actions WHERE prompt_msg_id = $replyToId");
            $action    = $pending['action'];
            $reportId  = (int)$pending['report_id'];
            $targetId  = (int)$pending['target_id'];
            $origMsgId = (int)$pending['orig_msg_id'];

            // Парсим время: 2m, 1h, 7d или 0 = навсегда
            $inputTime = trim($text);
            $seconds   = -1;
            $durationStr = '';
            if ($inputTime === '0') {
                $seconds = 0;
                $durationStr = 'навсегда';
            } elseif (preg_match('/^(\d+)(m|h|d)$/i', $inputTime, $tm)) {
                $val  = (int)$tm[1];
                $unit = strtolower($tm[2]);
                switch ($unit) {
                    case 'm': $seconds = $val * 60;     $durationStr = formatMinutes($val); break;
                    case 'h': $seconds = $val * 3600;   $durationStr = formatHours($val);   break;
                    case 'd': $seconds = $val * 86400;  $durationStr = "{$val} дн.";        break;
                }
            }

            if ($seconds === -1) {
                sendReply($chatId, $messageId, "❌ Неверный формат времени. Примеры: <code>2m</code>, <code>1h</code>, <code>7d</code>, <code>0</code> — навсегда.");
                return;
            }

            // Удаляем prompt-сообщение и ответ пользователя
            deleteMessage($chatId, $replyToId);
            deleteMessage($chatId, $messageId);

            $until = $seconds > 0 ? time() + $seconds : 0;

            if ($action === 'mute') {
                $muteRes = apiRequest('restrictChatMember', [
                    'chat_id'     => $chatId,
                    'user_id'     => $targetId,
                    'until_date'  => $until,
                    'permissions' => [
                        'can_send_messages'         => false,
                        'can_send_audios'           => false,
                        'can_send_documents'        => false,
                        'can_send_photos'           => false,
                        'can_send_videos'           => false,
                        'can_send_video_notes'      => false,
                        'can_send_voice_notes'      => false,
                        'can_send_polls'            => false,
                        'can_send_other_messages'   => false,
                        'can_add_web_page_previews' => false,
                        'can_change_info'           => false,
                        'can_invite_users'          => false,
                        'can_pin_messages'          => false,
                    ],
                ]);
                if (!($muteRes['ok'] ?? false)) {
                    $muteErr = $muteRes['description'] ?? 'неизвестная ошибка';
                    if (str_contains($muteErr, 'administrator')) {
                        sendReply($chatId, $replyTo ?? 0, '⛔ Telegram не позволяет мютить администраторов. Сначала снимите права администратора с пользователя, затем выдайте мут.');
                    } else {
                        sendReply($chatId, $replyTo ?? 0, "⛔ Не удалось выдать мут: {$muteErr}");
                    }
                    return;
                }
                // Записываем в БД только после успешного ответа от Telegram
                $db->exec("
                    INSERT OR REPLACE INTO mutes (user_id, chat_id, until) VALUES ($targetId, $chatId, $until)
                    ON CONFLICT(user_id, chat_id) DO UPDATE SET until = $until
                ");
                $db->exec("UPDATE reports SET reviewed = 1, reviewer_id = $userId WHERE id = $reportId");
                $reportedRow = $db->query("SELECT name, username FROM users WHERE user_id = $targetId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
                $rName = htmlspecialchars($reportedRow['name'] ?? "ID {$targetId}", ENT_XML1);
                $rLink = formatUserName(['id' => $targetId, 'name' => $rName, 'username' => $reportedRow['username'] ?? '']);
                $suffix = $seconds > 0 ? " на {$durationStr}" : ' навсегда';
                $unmuteKb = ['inline_keyboard' => [[['text' => '🔊 Снять мут', 'callback_data' => "unmute:{$targetId}"]]]];
                sendMessage($chatId, "🔇 {$rLink} замьючен{$suffix} по жалобе #{$reportId}", $unmuteKb);
            } elseif ($action === 'ban') {
                $params = ['chat_id' => $chatId, 'user_id' => $targetId];
                if ($seconds > 0) $params['until_date'] = $until;
                $result = apiRequest('banChatMember', $params);
                if ($result['ok'] ?? false) {
                    $now = time();
                    if ($seconds > 0) {
                        $db->exec("
                            INSERT OR REPLACE INTO tempbans (user_id, chat_id, until, notified) VALUES ($targetId, $chatId, $until, 0)
                            ON CONFLICT(user_id, chat_id) DO UPDATE SET until = $until, notified = 0
                        ");
                    }
                    $db->exec("
                        INSERT OR REPLACE INTO bans (user_id, chat_id, banned_by, reason, banned_at)
                        VALUES ($targetId, $chatId, $userId, 'Репорт', $now)
                        ON CONFLICT(user_id, chat_id) DO UPDATE SET banned_by = $userId, banned_at = $now
                    ");
                    $db->exec("UPDATE reports SET reviewed = 1, reviewer_id = $userId WHERE id = $reportId");
                    $reportedRow = $db->query("SELECT name FROM users WHERE user_id = $targetId")->fetch(PDO::FETCH_ASSOC);
                    $rName = htmlspecialchars($reportedRow['name'] ?? "ID {$targetId}", ENT_XML1);
                    $suffix = $seconds > 0 ? " на {$durationStr}" : ' навсегда';
                    $unbanKb = ['inline_keyboard' => [[['text' => '🟢 Снять бан', 'callback_data' => "unban:{$targetId}"]]]];
                    sendMessage($chatId, "🔴 <a href=\"tg://user?id={$targetId}\">{$rName}</a> заблокирован{$suffix} по жалобе #{$reportId}", $unbanKb);
                } else {
                    $errDesc = $banResult['description'] ?? '';
                    if (str_contains($errDesc, 'USER_NOT_PARTICIPANT') || str_contains($errDesc, 'user not found')) {
                        sendMessage($chatId, "❌ Не удалось заблокировать: пользователь не состоит в чате.");
                    } elseif (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED')) {
                        sendMessage($chatId, "❌ Не удалось заблокировать: у бота недостаточно прав.");
                    } elseif (str_contains($errDesc, 'administrator')) {
                        sendMessage($chatId, "❌ Не удалось заблокировать: пользователь является администратором.");
                    } else {
                        sendMessage($chatId, "❌ Не удалось заблокировать: $errDesc");
                    }
                }
            }

            // Убираем кнопки с исходного сообщения жалобы
            apiRequest('editMessageReplyMarkup', [
                'chat_id'      => $chatId,
                'message_id'   => $origMsgId,
                'reply_markup' => ['inline_keyboard' => []],
            ]);
            return;
        }
    }

    // Авто-модерация (только не для администраторов и не для команд бота)
    $isCommand = $text !== '' && $text[0] === '/';
    // Пропускаем авто-мод для ! команд и русских команд
    $isBotCommand = $isCommand
        || ($text !== '' && $text[0] === '!' && parseRussianCommand(ltrim($text, '!')) !== null)
        || ($text !== '' && parseRussianCommand($text) !== null);
    if (!isChatAdmin($chatId, $userId) && !$isBotCommand) {
        if (checkAutomod($msg)) return;
    }

    // Триггер на русские команды или слово "правила" в обычном сообщении
    if ($text && $text[0] !== '/') {
        // Обработка команд с префиксом "!" (например: !Амнистия, !Банлист)
        if ($text[0] === '!') {
            $withoutBang = ltrim($text, '!');

            // !Репорт — доступно всем пользователям
            if (preg_match('/^репорт\b(.*)/iu', $withoutBang, $rm)) {
                // Если это не ответ на сообщение — подсказываем
                if (empty($msg['reply_to_message'])) {
                    sendReply($chatId, $messageId, "⚠️ Чтобы пожаловаться, ответьте командой <b>!репорт</b> на нужное сообщение.");
                    return;
                }
                // Нельзя жаловаться на сообщение которое само является репортом
                $replyText = $msg['reply_to_message']['text'] ?? $msg['reply_to_message']['caption'] ?? '';
                if (preg_match('/^!репорт\b/iu', trim($replyText))) {
                    sendReply($chatId, $messageId, "⛔ Нельзя пожаловаться на репорт.");
                    return;
                }
                $reason = trim($rm[1]);
                cmdReport($msg, $chatId, $messageId, $userId, $reason);
                return;
            }

            $ruCommand   = parseRussianCommand($withoutBang);
            if ($ruCommand !== null) {
                [$command, $args] = $ruCommand;
                if (!in_array($command, ['/admins', '/staff', '/top', '/rep_plus', '/rep_minus', '/myinfo'], true) && !isChatAdmin($chatId, $userId)) {
                    sendReply($chatId, $messageId, "⛔ Эта команда доступна только администраторам.");
                    return;
                }
                $target = getTarget($msg, $args, $chatId);
                dispatchCommand($command, $chatId, $messageId, $userId, $target, $args, $msg);
                return;
            }
        }
        $ruCommand = parseRussianCommand($text);
        if ($ruCommand !== null) {
            [$command, $args] = $ruCommand;
            // Проверяем права вызывающего (кроме публичных команд)
            if (!in_array($command, ['/admins', '/staff', '/top', '/rep_plus', '/rep_minus', '/myinfo'], true) && !isChatAdmin($chatId, $userId)) {
                sendReply($chatId, $messageId, "⛔ Эта команда доступна только администраторам.");
                return;
            }
            $target = getTarget($msg, $args, $chatId);
            dispatchCommand($command, $chatId, $messageId, $userId, $target, $args, $msg);
            return;
        }

        // Если не команда — просто слово "правила" в тексте
        if (preg_match('/\bправила\b/iu', $text)) {
            cmdRules($chatId, $messageId);
            return;
        }

        // Триггер "+" / "+реп" и "-" / "-реп" — репутация ответом на сообщение
        if (preg_match('/^\+\s*$|^\+реп\s*$|^\+репутация\s*$|^\+rep\s*$/iu', $text)) {
            if (isset($msg['reply_to_message'])) {
                $from = $msg['reply_to_message']['from'];
                $repTarget = [
                    'id'       => $from['id'],
                    'name'     => htmlspecialchars(trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')), ENT_XML1),
                    'username' => $from['username'] ?? '',
                ];
                cmdRep($chatId, $messageId, $userId, $repTarget, +1);
            }
            return;
        }
        if (preg_match('/^-\s*$|^-реп\s*$|^-репутация\s*$|^-rep\s*$/iu', $text)) {
            if (isset($msg['reply_to_message'])) {
                $from = $msg['reply_to_message']['from'];
                $repTarget = [
                    'id'       => $from['id'],
                    'name'     => htmlspecialchars(trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')), ENT_XML1),
                    'username' => $from['username'] ?? '',
                ];
                cmdRep($chatId, $messageId, $userId, $repTarget, -1);
            }
            return;
        }

        // Триггер "топ" / "топы" — показать топ участников
        if (preg_match('/^(?:топ|топы|top)\s*$/iu', $text)) {
            cmdTop($chatId, $messageId);
            return;
        }

        // Триггер "кто ты" / "кто ты такой" — показать инфо о пользователе (/info)
        if (preg_match('/^кто\s+ты\b/iu', $text)) {
            $target = getTarget($msg, [], $chatId);
            cmdInfo($chatId, $messageId, $target);
            return;
        }

        // Триггер "стафф" / "персонал" / "состав администрации" и т.д. — список администраторов (/admins)
        if (preg_match('/^(?:стафф|персонал|состав\s+администрации?|покажи\s+(?:стафф|персонал|админов?))\b/iu', $text)) {
            cmdAdmins($chatId, $messageId);
            return;
        }

        if (preg_match('/^мистерс?\s+кто\s+(.+)/iu', $text, $m)) {
            cmdWho($chatId, $messageId, trim($m[1]));
            return;
        }

        // Триггер "Мистер вероятность / какая вероятность / какова вероятность [чего-то]?"
        if (preg_match('/^мистерс?\s+(?:(?:какова|какая)\s+)?вероятность(?:\s+что)?\s+(.+)/iu', $text, $m)) {
            cmdChance($chatId, $messageId, trim($m[1]));
            return;
        }

        return;
    }

    // Обрабатываем только команды
    if (!$text || $text[0] !== '/') return;

    [$command, $args] = parseCommand($text);

    // Игнорируем /start
    if ($command === '/start') return;

    // /rules доступна всем без проверки прав
    if ($command === '/rules') {
        cmdRules($chatId, $messageId);
        return;
    }

    // /admins и /staff доступны всем без проверки прав
    if ($command === '/admins' || $command === '/staff') {
        cmdAdmins($chatId, $messageId);
        return;
    }

    // /top доступна всем без проверки прав
    if ($command === '/top') {
        cmdTop($chatId, $messageId);
        return;
    }

    // /topstat — отладка активности (только для администраторов)
    if ($command === '/topstat') {
        $db    = getDB();
        $total = (int)$db->query("SELECT COUNT(*) FROM users WHERE chat_id = $chatId")->fetchColumn();
        $withMsg = (int)$db->query("SELECT COUNT(*) FROM users WHERE chat_id = $chatId AND message_count > 0")->fetchColumn();
        $maxMsg  = (int)$db->query("SELECT MAX(message_count) FROM users WHERE chat_id = $chatId")->fetchColumn();
        $res = $db->query("SELECT name, message_count FROM users WHERE chat_id = $chatId ORDER BY message_count DESC LIMIT 5");
        $preview = [];
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $preview[] = htmlspecialchars($row['name'], ENT_XML1) . ': ' . $row['message_count'];
        }
        sendReply($chatId, $messageId,
            "📊 <b>Статистика БД (chat_id: <code>{$chatId}</code>)</b>\n"
            . "Всего в users: <b>{$total}</b>\n"
            . "С message_count > 0: <b>{$withMsg}</b>\n"
            . "Макс. сообщений: <b>{$maxMsg}</b>\n"
            . "Топ-5:\n" . implode("\n", $preview ?: ['нет данных'])
        );
        return;
    }

    // /rep доступна всем без проверки прав (ответом на сообщение)
    if ($command === '/rep' || $command === '/rep_plus') {
        $target = getTarget($msg, $args, $chatId);
        cmdRep($chatId, $messageId, $userId, $target, +1);
        return;
    }
    if ($command === '/rep_minus') {
        $target = getTarget($msg, $args, $chatId);
        cmdRep($chatId, $messageId, $userId, $target, -1);
        return;
    }

    // /setlog и /unsetlog — привязка лог-канала (только для владельца/полного админа)
    if ($command === '/setlog') {
        if (!isChatOwner($chatId, $userId) && !isFullAdmin($chatId, $userId)) {
            sendReply($chatId, $messageId, "⛔ Только для владельца или администратора с полными правами.");
            return;
        }
        cmdSetLogChannel($chatId, $messageId, $userId, $msg);
        return;
    }
    if ($command === '/unsetlog') {
        if (!isChatOwner($chatId, $userId) && !isFullAdmin($chatId, $userId)) {
            sendReply($chatId, $messageId, "⛔ Только для владельца или администратора с полными правами.");
            return;
        }
        cmdUnsetLogChannel($chatId, $messageId, $userId);
        return;
    }

    // /logs — лог конкретного пользователя (для всех администраторов)
    if ($command === '/logs') {
        if (!isChatAdmin($chatId, $userId)) {
            sendReply($chatId, $messageId, "⛔ Эта команда доступна только администраторам.");
            return;
        }
        $target = getTarget($msg, $args, $chatId);
        cmdLogs($chatId, $messageId, $target, $msg);
        return;
    }

    // /me доступна всем без проверки прав
    if ($command === '/myinfo') {
        cmdMe($chatId, $messageId, $userId, $msg['from'] ?? []);
        return;
    }

    // Проверяем права вызывающего
    if (!isChatAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Эта команда доступна только администраторам.");
        return;
    }

    // Цель: реплай на сообщение, либо первый аргумент = @username или числовой ID
    $target = getTarget($msg, $args, $chatId);

    // Если авто-модерация включена — автоматически добавляем администратора в белый список
    $amSettings = getAutomodSettings($chatId);
    $automodActive = $amSettings['block_links'] || $amSettings['block_arabic']
        || $amSettings['block_caps'] || $amSettings['block_flood'];
    if ($automodActive) {
        $db = getDB();
        $existing = $db->query(
            "SELECT 1 FROM automod_whitelist WHERE user_id = $userId AND chat_id = $chatId"
        )->fetchColumn();
        if (!$existing) {
            $db->exec("INSERT OR IGNORE INTO automod_whitelist (user_id, chat_id) VALUES ($userId, $chatId)");
            debugLog("Auto-whitelist: added admin $userId to whitelist in chat $chatId");
        }
    }

    dispatchCommand($command, $chatId, $messageId, $userId, $target, $args, $msg);
}

// ─────────────────────────────────────────────
// РАЗБОР РУССКИХ КОМАНД
// ─────────────────────────────────────────────

function parseRussianCommand(string $text): ?array {
    $text = trim($text);

    $patterns = [
        '/^(?:выдать\s+мут|замутить|выдай\s+мут|мут|не\s+говори)\b(.*)$/iu'              => '/mute',
        '/^(?:снять\s+мут|размутить|снять\s+мьют|снимите\s+мут|говори|размут)\b(.*)$/iu' => '/unmute',
        '/^(?:выдать\s+бан|забанить|заблокировать|выдай\s+бан)\b(.*)$/iu'               => '/ban',
        '/^(?:снять\s+бан|разбанить|разблокировать)\b(.*)$/iu'                          => '/unban',
        '/^(?:временный\s+бан|выдать\s+временный\s+бан|темпбан)\b(.*)$/iu'              => '/tempban',
        '/^(?:снять\s+временный\s+бан|снять\s+темпбан)\b(.*)$/iu'                       => '/untempban',
        '/^(?:кикнуть|кик|выгнать|вышвырнуть)\b(.*)$/iu'                                => '/kick',
        '/^(?:предупреждение|выдать\s+варн|варн|выдать\s+предупреждение)\b(.*)$/iu'     => '/warn',
        '/^(?:снять\s+варн|-варн|снять\s+предупреждение|-предупреждение)\b(.*)$/iu'     => '/unwarn',
        '/^(?:инфо|информация|инфа)\b(.*)$/iu'                                          => '/info',
        '/^(?:назначить(?:\s+(?:модератором|администратором|админом))?|повысить)\b(.*)$/iu' => '/promote',
        '/^(?:разжаловать|снять\s+права|снять\s+администратора|понизить)\b(.*)$/iu'     => '/demote',
        '/^(?:добавить\s+правило|добавить\s+правила)\b(.*)$/iu'                                    => '/addrule',
        '/^\+правила?\b(.*)$/iu'                                                                    => '/addrule',
        '/^(?:удалить\s+правила|удалить\s+правило|очистить\s+правила|убрать\s+правила?)\b(.*)$/iu' => '/delrule',
        '/^-правила?\b(.*)$/iu'                                                                     => '/delrule',
        '/^(?:помощь|команды|хелп)\b(.*)$/iu'                                           => '/help',
        '/^(?:список\s+адм|список\s+админов|админы)\b(.*)$/iu'                          => '/admins',
        '/^(?:стафф|персонал)\b(.*)$/iu'                                                 => '/staff',
        '/^(?:амнистия|амнистировать|снять\s+все\s+баны)\b(.*)$/iu'                     => '/amnesty',
        '/^(?:банлист|список\s+банов|список\s+забаненных)\b(.*)$/iu'                    => '/banlist',
        '/^(?:репортлист|список\s+репортов|список\s+жалоб)\b(.*)$/iu'                  => '/reportlist',
        '/^\+репорты\s+из\s+чата\b(.*)$/iu'                                            => '/reportfromchat',
        '/^\+репорты\s+из\s+сетки\b(.*)$/iu'                                           => '/reportfromnetwork',
        '/^\+репорты\s+сюда\b(.*)$/iu'                                                 => '/reportheresetup',
        '/^(?:сброс\s+репортов|очистить\s+репорты)\b(.*)$/iu'                          => '/reportreset',
        '/^(?:-смс)\b(.*)$/iu'                                                               => '/delmsg',
        '/^(?:-чат)\b(.*)$/iu'                                                               => '/closechat',
        '/^(?:\+чат)\b(.*)$/iu'                                                              => '/openchat',
        '/^(?:призыв|позвать всех|позови всех|всех призвать|call)\b(.*)$/iu'                 => '/call',
        '/^(?:\+реп|\+репутация|дать реп|\+rep)\b(.*)$/iu'                                  => '/rep_plus',
        '/^(?:-реп|-репутация|минус реп|-rep)\b(.*)$/iu'                                     => '/rep_minus',
        '/^(?:топ|топы|рейтинг|лидеры|top)\b(.*)$/iu'                                        => '/top',
        '/^(?:логканал|лог\s*канал|установить\s*лог(?:канал)?|привязать\s*лог)\b(.*)$/iu' => '/setlog',
        '/^(?:убрать\s*лог(?:канал)?|отвязать\s*лог(?:канал)?|снять\s*лог)\b(.*)$/iu'    => '/unsetlog',
        '/^(?:лог(?:и)?|история\s*наказаний|история\s*действий)\b(.*)$/iu'                => '/logs',
        '/^(?:кто\s+я|мой\s+профиль|моя\s+стата|моя\s+статистика)\b(.*)$/iu'            => '/myinfo',
        '/^(?:напомни|напоминание|remind|создать\s*заметку|заметка|добавить\s*заметку)\b(.*)$/iu' => '/remind',
        '/^(?:антирейд|анти.рейд|antiraid|рейд)\b(.*)$/iu'                               => '/antiraid',
        '/^(?:очистить\s+чат|очисти\s+чат|очистка|чистка|purge)\b(.*)$/iu'               => '/purge',
    ];

    foreach ($patterns as $pattern => $command) {
        if (preg_match($pattern, $text, $m)) {
            $tail = trim($m[1] ?? '');
            $args = $tail !== '' ? explode(' ', $tail) : [];
            return [$command, $args];
        }
    }

    return null;
}

// ─────────────────────────────────────────────
// ДИСПЕТЧЕР КОМАНД (общий для / и русских)
// ─────────────────────────────────────────────
function dispatchCommand(
    string $command,
    int $chatId,
    int $messageId,
    int $userId,
    ?array $target,
    array $args,
    ?array $msg = null
): void {
    switch ($command) {
        case '/mute':
            cmdMute($chatId, $messageId, $userId, $target, $args);
            break;
        case '/unmute':
            cmdUnmute($chatId, $messageId, $userId, $target);
            break;
        case '/ban':
            cmdBan($chatId, $messageId, $userId, $target, $args);
            break;
        case '/unban':
        case '/untempban':
            cmdUnban($chatId, $messageId, $userId, $target);
            break;
        case '/kick':
            cmdKick($chatId, $messageId, $userId, $target);
            break;
        case '/tempban':
            cmdTempban($chatId, $messageId, $userId, $target, $args);
            break;
        case '/warn':
            cmdWarn($chatId, $messageId, $userId, $target, $args);
            break;
        case '/unwarn':
            cmdUnwarn($chatId, $messageId, $userId, $target);
            break;
        case '/info':
            cmdInfo($chatId, $messageId, $target);
            break;
        case '/promote':
            cmdPromote($chatId, $messageId, $userId, $target);
            break;
        case '/demote':
            cmdDemote($chatId, $messageId, $userId, $target);
            break;
        case '/addrule':
            cmdAddRule($chatId, $messageId, $userId, $args);
            break;
        case '/delrule':
            cmdDelRule($chatId, $messageId, $userId, $args);
            break;
        case '/rules':
            cmdRules($chatId, $messageId);
            break;
        case '/help':
            cmdHelp($chatId, $messageId);
            break;
        case '/admins':
        case '/staff':
            cmdAdmins($chatId, $messageId);
            break;
        case '/automod':
            cmdAutomod($chatId, $messageId, $userId);
            break;
        case '/addword':
            cmdAddWord($chatId, $messageId, $userId, $args);
            break;
        case '/delword':
            cmdDelWord($chatId, $messageId, $userId, $args);
            break;
        case '/words':
            cmdListWords($chatId, $messageId, $userId);
            break;
        case '/amnesty':
            cmdAmnesty($chatId, $messageId, $userId);
            break;
        case '/banlist':
            cmdBanlist($chatId, $messageId);
            break;
        case '/whitelist':
            cmdWhitelist($chatId, $messageId, $userId, $target);
            break;
        case '/unwhitelist':
            cmdUnwhitelist($chatId, $messageId, $userId, $target);
            break;
        case '/whitelistshow':
            cmdWhitelistShow($chatId, $messageId, $userId);
            break;
        case '/reportlist':
            cmdReportList($chatId, $messageId, $userId);
            break;
        case '/reportfromchat':
            cmdReportFromChat($chatId, $messageId, $userId, $args);
            break;
        case '/reportfromnetwork':
            cmdReportFromNetwork($chatId, $messageId, $userId);
            break;
        case '/reportheresetup':
            cmdReportHereSetup($chatId, $messageId, $userId);
            break;
        case '/reportreset':
            cmdReportReset($chatId, $messageId, $userId);
            break;
        case '/delmsg':
            cmdDelMsg($chatId, $messageId, $userId, $msg ?? null);
            break;
        case '/closechat':
            cmdCloseChat($chatId, $messageId, $userId);
            break;
        case '/openchat':
            cmdOpenChat($chatId, $messageId, $userId);
            break;
        case '/call':
            $reason = implode(' ', array_filter($args, fn($a) => !str_starts_with($a, '@') && !is_numeric($a)));
            cmdCall($chatId, $messageId, $userId, $reason);
            break;
        case '/setwelcome':
            cmdSetWelcome($chatId, $messageId, $userId, $args, $msg);
            break;
        case '/welcomeoff':
            cmdWelcomeOff($chatId, $messageId, $userId);
            break;
        case '/welcomeon':
            cmdWelcomeOn($chatId, $messageId, $userId);
            break;
        case '/rep':
        case '/rep_plus':
            cmdRep($chatId, $messageId, $userId, $target, +1);
            break;
        case '/rep_minus':
            cmdRep($chatId, $messageId, $userId, $target, -1);
            break;
        case '/top':
            cmdTop($chatId, $messageId);
            break;
        case '/setlog':
            cmdSetLogChannel($chatId, $messageId, $userId, $msg);
            break;
        case '/unsetlog':
            cmdUnsetLogChannel($chatId, $messageId, $userId);
            break;
        case '/logs':
            cmdLogs($chatId, $messageId, $target, $msg);
            break;
        case '/myinfo':
            cmdMe($chatId, $messageId, $userId, $msg['from'] ?? []);
            break;
        case '/remind':
            cmdRemind($chatId, $messageId, $userId, $args, $msg);
            break;
        case '/antiraid':
            cmdAntiRaid($chatId, $messageId, $userId, $args);
            break;
        case '/purge':
            cmdPurge($chatId, $messageId, $userId, $target, $args);
            break;
    }
}

// ─────────────────────────────────────────────
// ОБРАБОТКА CALLBACK (кнопки выбора времени)
// ─────────────────────────────────────────────
function handleCallback(array $cb): void {
    initDB();

    $callbackId = $cb['id'];
    $data       = $cb['data'] ?? '';
    $chatId     = $cb['message']['chat']['id'];
    $messageId  = $cb['message']['message_id'];
    $adminId    = $cb['from']['id'];

    // ── Верификация нового участника ──────────────────────────────────────────
    if (str_starts_with($data, 'verify:')) {
        $targetId = (int)substr($data, 7);
        // Нажать может только сам пользователь
        if ($adminId !== $targetId) {
            apiRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => '⛔ Эта кнопка только для нового участника.',
                'show_alert' => true,
            ]);
            return;
        }

        $db      = getDB();
        $pending = $db->query("SELECT message_id FROM verification_pending WHERE user_id = $targetId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);

        // Снимаем ограничения
        apiRequest('restrictChatMember', [
            'chat_id'     => $chatId,
            'user_id'     => $targetId,
            'permissions' => defaultPermissions(),
        ]);

        // Убираем запись из ожидающих
        $db->exec("DELETE FROM verification_pending WHERE user_id = $targetId AND chat_id = $chatId");

        // Убираем кнопку с приветственного сообщения
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);

        apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => '✅ Верификация пройдена! Добро пожаловать!',
        ]);
        return;
    }
    // ──────────────────────────────────────────────────────────────────────

    // ── Панель владельца (только в личке, только для владельца) ───────────────
    if (str_starts_with($data, 'owner:')) {
        if (!isOwner($adminId)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Доступ запрещён', 'show_alert' => true]);
            return;
        }
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);

        if ($data === 'owner:refresh' || $data === 'owner:back') {
            // Пересчитываем статистику и редактируем сообщение
            $db = getDB();

            $totalUsers       = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM users")->fetchColumn() ?: 0;
            $totalChats       = (int)$db->query("SELECT COUNT(*) FROM bot_chats")->fetchColumn() ?: 0;
            $totalWarns       = (int)$db->query("SELECT COALESCE(SUM(count),0) FROM warns")->fetchColumn() ?: 0;
            $totalMutes       = (int)$db->query("SELECT COUNT(*) FROM mutes")->fetchColumn() ?: 0;
            $totalBans        = (int)$db->query("SELECT COUNT(*) FROM bans")->fetchColumn() ?: 0;
            $totalTempbans    = (int)$db->query("SELECT COUNT(*) FROM tempbans")->fetchColumn() ?: 0;
            $totalPunishments = $totalWarns + $totalMutes + $totalBans + $totalTempbans;

            $text = "👑 <b>Панель владельца</b>\n"
                . "━━━━━━━━━━━━━━━━━━━━\n\n"
                . "👥 <b>Пользователей в БД:</b> <code>{$totalUsers}</code>\n"
                . "💬 <b>Чатов бота:</b> <code>{$totalChats}</code>\n\n"
                . "⚖️ <b>Наказания (всего):</b> <code>{$totalPunishments}</code>\n"
                . "  ├ ⚠️ Варны: <code>{$totalWarns}</code>\n"
                . "  ├ 🔇 Муты: <code>{$totalMutes}</code>\n"
                . "  ├ 🔴 Баны: <code>{$totalBans}</code>\n"
                . "  └ ⏳ Временные баны: <code>{$totalTempbans}</code>\n\n"
                . "🕐 Обновлено: " . date('d.m.Y H:i:s');

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 Обновить', 'callback_data' => 'owner:refresh']],
                    [['text' => '💬 Список чатов', 'callback_data' => 'owner:chats']],
                ],
            ];

            apiRequest('editMessageText', [
                'chat_id'      => $chatId,
                'message_id'   => $messageId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => $keyboard,
            ]);
            return;
        }

        if ($data === 'owner:chats') {
            $db     = getDB();
            $result = $db->query("SELECT chat_id, title, joined_at FROM bot_chats ORDER BY joined_at DESC LIMIT 30");

            $lines = [];
            if ($result) {
                while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                    $title   = htmlspecialchars($row['title'] ?: 'Без названия', ENT_XML1);
                    $cid     = (int)$row['chat_id'];
                    $date    = date('d.m.Y', (int)$row['joined_at']);
                    $lines[] = "• <b>{$title}</b>\n  <code>{$cid}</code> — с {$date}";
                }
            }

            $text = empty($lines)
                ? "📭 Список чатов пуст."
                : "💬 <b>Чаты бота</b> (последние 30):\n━━━━━━━━━━━━━━━━━━━━\n\n" . implode("\n\n", $lines);

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '◀️ Назад', 'callback_data' => 'owner:back']],
                ],
            ];

            apiRequest('editMessageText', [
                'chat_id'      => $chatId,
                'message_id'   => $messageId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => $keyboard,
            ]);
            return;
        }

        return;
    }


    if ($data === 'help_public') {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $text = "👤 <b>Команды для всех участников</b>\n\n"
              . "/rules — посмотреть правила чата\n\n"
              . "/myinfo — ваш профиль в этом чате\n"
              . "   <b>кто я</b> — то же самое\n\n"
              . "/admins — список администраторов\n\n"
              . "/top — топ участников (репутация / активность)\n\n"
              . "/rep — дать репутацию (ответом на сообщение)\n"
              . "   <b>+реп</b> — добавить репутацию\n"
              . "   <b>-реп</b> — убрать репутацию\n\n"
              . "/help — показать это меню команд\n\n"
              . "❗ <b>!Репорт</b> — пожаловаться на нарушение\n"
              . "   Напишите ответом на сообщение нарушителя:\n"
              . "   <code>!Репорт\nПричина жалобы</code>\n\n"
              . "<i>💡 Также можно написать «правила», «Мистер кто ...?», «Мистер вероятность ...?»</i>";
        apiRequest('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'help_back']]]],
        ]);
        return;
    }

    if ($data === 'help_admin') {
        if (!isChatAdmin($chatId, $adminId)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Только для администраторов', 'show_alert' => true]);
            return;
        }
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $text = "🛡 <b>Команды администрации</b>\n\n"
              . "Все команды работают в реплае или с @username / ID\n\n"
              . "/mute @user 30m причина — мут (m=мин, h=часы, d=дни)\n"
              . "/mute @user причина — мут навсегда\n"
              . "/unmute @user — снять мут\n\n"
              . "/ban @user причина — заблокировать навсегда\n"
              . "/unban @user — разблокировать\n\n"
              . "/kick @user — выгнать из чата\n\n"
              . "/tempban @user 7d причина — временный бан\n"
              . "/untempban @user — снять временный бан\n\n"
              . "/warn @user причина — предупреждение (3-е = автобан)\n\n"
              . "/unwarn @user — снять одно предупреждение\n\n"
              . "/info @user — информация о пользователе\n\n"
              . "/banlist — список забаненных участников\n"
              . "/amnesty — снять баны со всех участников\n\n"
              . "📣 /call [причина] — призвать всех участников\n"
              . "   (упоминает всех пользователей, админов и владельцев)\n\n"
              . "🗑 <b>-смс</b> — удалить сообщение (ответом на него)\n\n"
              . "📣 <b>Репорты:</b>\n"
              . "Репортлист — список нерассмотренных жалоб\n"
              . "!Сброс репортов — очистить все жалобы\n"
              . "+Репорты сюда — принимать жалобы в этот чат\n"
              . "+Репорты из чата КОД — жалобы из другого чата сюда"
              . "\n\n📋 <b>Логи:</b>\n"
              . "/logs @user — история наказаний пользователя\n\n"
              . "🧹 <b>Очистка сообщений:</b>\n"
              . "/purge @user 50 — удалить последние 50 сообщений пользователя\n"
              . "/purge 100 — удалить последние 100 сообщений в чате\n"
              . "   (можно указать любое число; лимит за один раз — 200)";
        apiRequest('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'help_back']]]],
        ]);
        return;
    }

    if ($data === 'help_owner') {
        if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Только для владельца или администратора с полными правами', 'show_alert' => true]);
            return;
        }
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $text = "👑 <b>Команды для владельца</b>\n\n"
              . "/promote @user — назначить администратором\n"
              . "    (владелец выдаёт полные права, обычный админ — базовые)\n"
              . "/demote @user — снять права администратора\n\n"
              . "📋 <b>Управление правилами:</b>\n"
              . "/addrule Текст — добавить текст к правилам\n"
              . "/delrule — удалить все правила\n\n"
              . "💬 <b>Управление чатом:</b>\n"
              . "<b>-чат</b> — закрыть чат (только админы могут писать)\n"
              . "<b>+чат</b> — открыть чат для всех участников\n\n"
              . "🤖 <b>Авто-модерация:</b>\n"
              . "/automod — панель управления фильтрами\n"
              . "   • Ссылки, арабский, CAPS, антифлуд\n"
              . "   • 🖼 Антиспам медиа/стикеров — лимит фото/стикеров/гифок за N секунд\n"
              . "/addword слово — добавить запрещённое слово\n"
              . "/delword слово — удалить запрещённое слово\n"
              . "/words — список запрещённых слов\n\n"
              . "🛡 <b>Белый список авто-модерации:</b>\n"
              . "/whitelist @user — добавить пользователя (не будет получать мут от авто-мода)\n"
              . "/unwhitelist @user — убрать из белого списка\n"
              . "/whitelistshow — посмотреть список\n\n"
              . "👋 <b>Приветствие новых участников:</b>\n"
              . "/setwelcome текст — задать текст приветствия\n"
              . "   <code>{userid}</code> — код для отображения юзернейма пользователя или айди если нет юзернейма\n"
              . "/welcomeoff — отключить приветствие\n"
              . "/welcomeon — включить приветствие\n\n"
              . "📋 <b>Лог модераторских действий:</b>\n"
              . "/setlog ID — привязать канал/чат для логов\n"
              . "   Способ 1: <code>/setlog -1001234567890</code>\n"
              . "   Способ 2: ответьте командой на сообщение с ID\n"
              . "/unsetlog — отвязать лог-канал\n"
              . "/logs @user — история наказаний пользователя\n\n"
              . "<i>💡 Администраторы добавляются в белый список автоматически при первой команде, если авто-модерация активна.</i>";
        apiRequest('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'help_back']]]],
        ]);
        return;
    }

    if ($data === 'help_back') {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👤 Для всех', 'callback_data' => 'help_public'],
                    ['text' => '🛡 Для администрации', 'callback_data' => 'help_admin'],
                ],
                [
                    ['text' => '👑 Для владельца', 'callback_data' => 'help_owner'],
                ],
            ],
        ];
        apiRequest('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => '📋 <b>Выберите список команд:</b>',
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard,
        ]);
        return;
    }
    // ──────────────────────────────────────────────────────────────────────

    // ── Быстрое снятие наказания через кнопку ──────────────────────────────
    if (preg_match('/^(unmute|unban|unwarn):(\d+)$/', $data, $pm)) {
        if (!isChatAdmin($chatId, $adminId)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Только для администраторов', 'show_alert' => true]);
            return;
        }
        [, $pAction, $pTargetId] = $pm;
        $pTargetId = (int)$pTargetId;
        $db = getDB();
        $pRow = $db->query("SELECT name, username FROM users WHERE user_id = $pTargetId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        $pName = htmlspecialchars($pRow['name'] ?? "ID {$pTargetId}", ENT_XML1);
        $pLink = formatUserName(['id' => $pTargetId, 'name' => $pName, 'username' => $pRow['username'] ?? '']);

        if ($pAction === 'unmute') {
            if (!isMuted($chatId, $pTargetId)) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'У пользователя нет мута', 'show_alert' => false]);
                apiRequest('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]]);
                return;
            }
            $db->exec("DELETE FROM mutes WHERE user_id = $pTargetId AND chat_id = $chatId");
            apiRequest('restrictChatMember', [
                'chat_id'     => $chatId,
                'user_id'     => $pTargetId,
                'permissions' => defaultPermissions(),
            ]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '✅ Мут снят']);
            apiRequest('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]]);
            $punisher = getPunisherLabel($chatId, $adminId);
            sendMessage($chatId, "🔊 Мут снят с {$pLink}\n{$punisher}");
            writeModLog($chatId, $adminId, $pTargetId, 'unmute');

        } elseif ($pAction === 'unban') {
            $result = apiRequest('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $pTargetId, 'only_if_banned' => true]);
            $db->exec("DELETE FROM tempbans WHERE user_id = $pTargetId AND chat_id = $chatId");
            $db->exec("DELETE FROM bans WHERE user_id = $pTargetId AND chat_id = $chatId");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '✅ Бан снят']);
            apiRequest('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]]);
            $punisher = getPunisherLabel($chatId, $adminId);
            sendMessage($chatId, "🟢 Блокировка снята с {$pLink}\n{$punisher}");
            writeModLog($chatId, $adminId, $pTargetId, 'unban');

        } elseif ($pAction === 'unwarn') {
            $current = (int)$db->query("SELECT count FROM warns WHERE user_id = $pTargetId AND chat_id = $chatId")->fetchColumn();
            if ($current <= 0) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'У пользователя нет предупреждений', 'show_alert' => false]);
                apiRequest('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]]);
                return;
            }
            $newCount = $current - 1;
            if ($newCount === 0) {
                $db->exec("DELETE FROM warns WHERE user_id = $pTargetId AND chat_id = $chatId");
            } else {
                $db->exec("UPDATE warns SET count = $newCount WHERE user_id = $pTargetId AND chat_id = $chatId");
            }
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '✅ Предупреждение снято']);
            apiRequest('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]]);
            $punisher = getPunisherLabel($chatId, $adminId);
            sendMessage($chatId, "✅ Предупреждение снято с {$pLink} ({$newCount}/3)\n{$punisher}");
            writeModLog($chatId, $adminId, $pTargetId, 'unwarn', '', "{$newCount}/3");
        }
        return;
    }
    // ──────────────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────────────

    // ── Обработка кнопок /call ─────────────────────────────────────────────
    if (str_starts_with($data, 'call:')) {
        if (!isChatAdmin($chatId, $adminId)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Только для администраторов', 'show_alert' => true]);
            return;
        }
        // Формат: call:{mode}:{reasonBase64}
        $parts = explode(':', $data, 3);
        $mode       = $parts[1] ?? 'all';
        $reasonEnc  = $parts[2] ?? '';
        $reason     = $reasonEnc !== '' ? base64_decode($reasonEnc) : '';
        executeCmdCall($chatId, $callbackId, $messageId, $adminId, $mode, $reason);
        return;
    }
    // ──────────────────────────────────────────────────────────────────────

    // ── Обработка кнопок репортов ──────────────────────────────────────────
    if (str_starts_with($data, 'report_')) {
        if (!isChatAdmin($chatId, $adminId)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Только для администраторов', 'show_alert' => true]);
            return;
        }
        handleReportCallback($callbackId, $chatId, $messageId, $adminId, $data);
        return;
    }
    // ──────────────────────────────────────────────────────────────────────

    // ── Обработка кнопок авто-модерации ────────────────────────────────────
    if (str_starts_with($data, 'am_')) {
        if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Только для владельца или администратора с полными правами', 'show_alert' => true]);
            return;
        }
        handleAutomodCallback($callbackId, $chatId, $messageId, $adminId, $data);
        return;
    }
    // ──────────────────────────────────────────────────────────────────────

    // ── Обработка кнопок анти-рейда ───────────────────────────────────────
    if (str_starts_with($data, 'ar_')) {
        handleAntiRaidCallback($callbackId, $chatId, $messageId, $adminId, $data);
        return;
    }
    // ──────────────────────────────────────────────────────────────────────

    // ── Обработка кнопок топа ──────────────────────────────────────────────
    if ($data === 'top_rep' || $data === 'top_activity') {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $type = ($data === 'top_rep') ? 'rep' : 'activity';
        $text = buildTopText($chatId, $type);
        $keyboard = [
            'inline_keyboard' => [[
                ['text' => ($type === 'rep' ? '🏆 Репутация ✓' : '🏆 Репутация'), 'callback_data' => 'top_rep'],
                ['text' => ($type === 'activity' ? '💬 Активность ✓' : '💬 Активность'), 'callback_data' => 'top_activity'],
            ]],
        ];
        apiRequest('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard,
        ]);
        return;
    }
    // ──────────────────────────────────────────────────────────────────────

    // Проверяем права вызывающего (для остальных callbacks)
    if (!isChatAdmin($chatId, $adminId)) {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Только для администраторов', 'show_alert' => true]);
        return;
    }

    // Формат данных: action:targetId:param
    // action = mute_time | ban_time
    // param  = минуты (mute) или часы (ban), 0 = навсегда
    if (!preg_match('/^(mute_time|ban_time):(\d+):(\d+)$/', $data, $m)) return;

    [, $action, $targetId, $value] = $m;
    $targetId = (int)$targetId;
    $value    = (int)$value;

    // Получаем имя пользователя из БД
    $db   = getDB();
    $row  = $db->query("SELECT name, username FROM users WHERE user_id = $targetId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $name = htmlspecialchars($row['name'] ?? "пользователь {$targetId}", ENT_XML1);
    $target = ['id' => $targetId, 'name' => $name, 'username' => $row['username'] ?? ''];
    $nameLink = formatUserName($target);

    // Защита по иерархии прав
    $targetRank = getAdminRank($chatId, $targetId);
    $adminRank  = getAdminRank($chatId, $adminId);

    if ($targetRank > 0) {
        // Цель — администратор. Telegram запрещает банить/мьютить администраторов напрямую.
        // Владелец может сначала разжаловать цель вручную, потом применить наказание.
        if ($targetRank >= $adminRank) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ У цели равные или более высокие права администратора', 'show_alert' => true]);
        } else {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '⛔ Нельзя применить наказание к администратору. Сначала разжалуйте его через /demote', 'show_alert' => true]);
        }
        deleteMessage($chatId, $messageId);
        return;
    }

    // Удаляем сообщение с кнопками
    deleteMessage($chatId, $messageId);

    apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);

    if ($action === 'mute_time') {
        $until    = $value > 0 ? time() + ($value * 60) : 0;
        $untilStr = $value > 0 ? " на " . formatMinutes($value) : " навсегда";

        $muteRes = apiRequest('restrictChatMember', [
            'chat_id'     => $chatId,
            'user_id'     => $targetId,
            'until_date'  => $until,
            'permissions' => [
                'can_send_messages'         => false,
                'can_send_audios'           => false,
                'can_send_documents'        => false,
                'can_send_photos'           => false,
                'can_send_videos'           => false,
                'can_send_video_notes'      => false,
                'can_send_voice_notes'      => false,
                'can_send_polls'            => false,
                'can_send_other_messages'   => false,
                'can_add_web_page_previews' => false,
                'can_change_info'           => false,
                'can_invite_users'          => false,
                'can_pin_messages'          => false,
            ],
        ]);
        if (!($muteRes['ok'] ?? false)) {
            $muteErr = $muteRes['description'] ?? 'неизвестная ошибка';
            if (str_contains($muteErr, 'administrator')) {
                sendReply($chatId, $replyTo ?? 0, '⛔ Telegram не позволяет мютить администраторов. Сначала снимите права администратора с пользователя, затем выдайте мут.');
            } else {
                sendReply($chatId, $replyTo ?? 0, "⛔ Не удалось выдать мут: {$muteErr}");
            }
            return;
        }

        // Записываем в БД только после успешного ответа от Telegram
        $db->exec("
            INSERT OR REPLACE INTO mutes (user_id, chat_id, until) VALUES ($targetId, $chatId, $until)
            ON CONFLICT(user_id, chat_id) DO UPDATE SET until = $until
        ");

        $unmuteKeyboard = [
            'inline_keyboard' => [[
                ['text' => '🔊 Снять мут', 'callback_data' => "unmute:{$targetId}"],
            ]],
        ];
        $punisher = getPunisherLabel($chatId, $adminId);
        sendMessage($chatId, "🔇 Пользователь {$nameLink} замьючен{$untilStr}\n{$punisher}", $unmuteKeyboard);
        writeModLog($chatId, $adminId, $targetId, 'mute', '', $value > 0 ? formatMinutes($value) : 'навсегда');

    } elseif ($action === 'ban_time') {
        $db->exec("DELETE FROM mutes WHERE user_id = $targetId AND chat_id = $chatId");

        if ($value === 0) {
            // Перманентный бан
            $result = apiRequest('banChatMember', ['chat_id' => $chatId, 'user_id' => $targetId]);
            if ($result['ok'] ?? false) {
                $now2 = time();
                $db->exec("
                    INSERT OR REPLACE INTO bans (user_id, chat_id, banned_by, reason, banned_at)
                    VALUES ($targetId, $chatId, $adminId, '', $now2)
                    ON CONFLICT(user_id, chat_id) DO UPDATE SET banned_by = $adminId, banned_at = $now2
                ");
                $unbanKeyboard = [
                    'inline_keyboard' => [[
                        ['text' => '🟢 Снять бан', 'callback_data' => "unban:{$targetId}"],
                    ]],
                ];
                $punisher = getPunisherLabel($chatId, $adminId);
                sendMessage($chatId, "🔴 Пользователь {$nameLink} заблокирован навсегда\n{$punisher}", $unbanKeyboard);
                writeModLog($chatId, $adminId, $targetId, 'ban');
            } else {
                $errDesc = $banResult['description'] ?? '';
                if (str_contains($errDesc, 'USER_NOT_PARTICIPANT') || str_contains($errDesc, 'user not found')) {
                    sendMessage($chatId, "❌ Не удалось заблокировать: пользователь не состоит в чате.");
                } elseif (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED')) {
                    sendMessage($chatId, "❌ Не удалось заблокировать: у бота недостаточно прав.");
                } elseif (str_contains($errDesc, 'administrator')) {
                    sendMessage($chatId, "❌ Не удалось заблокировать: пользователь является администратором.");
                } else {
                    sendMessage($chatId, "❌ Не удалось заблокировать: $errDesc");
                }
            }
        } else {
            // Временный бан (value = часы)
            $until    = time() + ($value * 3600);
            $untilStr = date('d.m.Y H:i', $until);
            $result   = apiRequest('banChatMember', ['chat_id' => $chatId, 'user_id' => $targetId, 'until_date' => $until]);
            if ($result['ok'] ?? false) {
                // Сохраняем в БД для уведомления пользователя по истечении
                $db->exec("
                    INSERT OR REPLACE INTO tempbans (user_id, chat_id, until, notified) VALUES ($targetId, $chatId, $until, 0)
                    ON CONFLICT(user_id, chat_id) DO UPDATE SET until = $until, notified = 0
                ");
                $unbanKeyboard2 = [
                    'inline_keyboard' => [[
                        ['text' => '🟢 Снять бан', 'callback_data' => "unban:{$targetId}"],
                    ]],
                ];
                $punisher = getPunisherLabel($chatId, $adminId);
                sendMessage($chatId, "⏳ Пользователь {$nameLink} временно заблокирован на " . formatHours($value) . "\n📅 До: $untilStr\n{$punisher}", $unbanKeyboard2);
                writeModLog($chatId, $adminId, $targetId, 'tempban', '', formatHours($value));
            } else {
                $errDesc = $banResult['description'] ?? '';
                if (str_contains($errDesc, 'USER_NOT_PARTICIPANT') || str_contains($errDesc, 'user not found')) {
                    sendMessage($chatId, "❌ Не удалось заблокировать: пользователь не состоит в чате.");
                } elseif (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED')) {
                    sendMessage($chatId, "❌ Не удалось заблокировать: у бота недостаточно прав.");
                } elseif (str_contains($errDesc, 'administrator')) {
                    sendMessage($chatId, "❌ Не удалось заблокировать: пользователь является администратором.");
                } else {
                    sendMessage($chatId, "❌ Не удалось заблокировать: $errDesc");
                }
            }
        }
    }
}

// Форматирование минут для отображения
function formatMinutes(int $minutes): string {
    if ($minutes < 60) return "{$minutes} мин.";
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m > 0 ? "{$h} ч. {$m} мин." : formatHours($h);
}

// Форматирование часов
function formatHours(int $hours): string {
    if ($hours < 24) return "{$hours} ч.";
    $d = intdiv($hours, 24);
    $h = $hours % 24;
    return $h > 0 ? "{$d} дн. {$h} ч." : "{$d} дн.";
}

// Клавиатура выбора времени мута
function muteTimeKeyboard(int $targetId): array {
    return [
        'inline_keyboard' => [
            [
                ['text' => '5 мин',   'callback_data' => "mute_time:{$targetId}:5"],
                ['text' => '15 мин',  'callback_data' => "mute_time:{$targetId}:15"],
                ['text' => '30 мин',  'callback_data' => "mute_time:{$targetId}:30"],
                ['text' => '1 час',   'callback_data' => "mute_time:{$targetId}:60"],
            ],
            [
                ['text' => '3 часа',  'callback_data' => "mute_time:{$targetId}:180"],
                ['text' => '6 часов', 'callback_data' => "mute_time:{$targetId}:360"],
                ['text' => '12 часов','callback_data' => "mute_time:{$targetId}:720"],
                ['text' => '1 день',  'callback_data' => "mute_time:{$targetId}:1440"],
            ],
            [
                ['text' => '3 дня',   'callback_data' => "mute_time:{$targetId}:4320"],
                ['text' => '7 дней',  'callback_data' => "mute_time:{$targetId}:10080"],
                ['text' => '🔇 Навсегда', 'callback_data' => "mute_time:{$targetId}:0"],
            ],
        ],
    ];
}

// Клавиатура выбора времени бана
function banTimeKeyboard(int $targetId): array {
    return [
        'inline_keyboard' => [
            [
                ['text' => '1 час',   'callback_data' => "ban_time:{$targetId}:1"],
                ['text' => '6 часов', 'callback_data' => "ban_time:{$targetId}:6"],
                ['text' => '12 часов','callback_data' => "ban_time:{$targetId}:12"],
                ['text' => '1 день',  'callback_data' => "ban_time:{$targetId}:24"],
            ],
            [
                ['text' => '3 дня',   'callback_data' => "ban_time:{$targetId}:72"],
                ['text' => '7 дней',  'callback_data' => "ban_time:{$targetId}:168"],
                ['text' => '30 дней', 'callback_data' => "ban_time:{$targetId}:720"],
                ['text' => '🔴 Навсегда', 'callback_data' => "ban_time:{$targetId}:0"],
            ],
        ],
    ];
}



function cmdMute(int $chatId, int $replyTo, int $adminId, ?array $target, array $args): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /mute @username [время] [причина]\n⏱ Форматы времени: 30m (минуты), 12h (часы), 7d (дни)");
        return;
    }

    $targetId    = $target['id'];
    if (isAdminProtected($chatId, $targetId, $adminId, $replyTo, 'мут')) return;
    $seconds     = -1; // -1 = не указано, 0 = навсегда
    $durationStr = '';
    $reasonStart = 0;

    foreach ($args as $i => $arg) {
        // Пропускаем @username или числовой ID в первом аргументе
        if ($i === 0 && (str_starts_with($arg, '@') || (is_numeric($arg) && (int)$arg === $targetId))) {
            $reasonStart = 1;
            continue;
        }

        // Парсим формат: 30m, 12h, 7d (или просто число — считаем как минуты)
        if (preg_match('/^(\d+)(m|h|d)?$/i', $arg, $match)) {
            $val  = (int)$match[1];
            $unit = strtolower($match[2] ?? 'm');

            switch ($unit) {
                case 'm':
                    $seconds     = $val * 60;
                    $durationStr = "{$val} мин.";
                    break;
                case 'h':
                    $seconds     = $val * 3600;
                    $durationStr = formatHours($val);
                    break;
                case 'd':
                    $seconds     = $val * 86400;
                    $durationStr = "{$val} дн.";
                    break;
            }

            $reasonStart = $i + 1;
            break;
        }
    }

    // Если время не указано — мут навсегда
    if ($seconds === -1) {
        $seconds     = 0;
        $durationStr = '';
    }

    // Проверка: уже замьючен?
    if (isMuted($chatId, $targetId)) {
        $nameLink = formatUserName($target);
        sendReply($chatId, $replyTo, "⚠️ Пользователь {$nameLink} уже замьючен.");
        return;
    }

    $reason   = implode(' ', array_slice($args, $reasonStart)) ?: 'Не указана';
    $until    = $seconds > 0 ? time() + $seconds : 0;
    $untilStr = $seconds > 0 ? " на {$durationStr}" : " навсегда";

    $muteRes = apiRequest('restrictChatMember', [
        'chat_id'     => $chatId,
        'user_id'     => $targetId,
        'until_date'  => $until,
        'permissions' => [
            'can_send_messages'         => false,
            'can_send_audios'           => false,
            'can_send_documents'        => false,
            'can_send_photos'           => false,
            'can_send_videos'           => false,
            'can_send_video_notes'      => false,
            'can_send_voice_notes'      => false,
            'can_send_polls'            => false,
            'can_send_other_messages'   => false,
            'can_add_web_page_previews' => false,
            'can_change_info'           => false,
            'can_invite_users'          => false,
            'can_pin_messages'          => false,
        ],
    ]);
    if (!($muteRes['ok'] ?? false)) {
        $muteErr = $muteRes['description'] ?? 'неизвестная ошибка';
        if (str_contains($muteErr, 'administrator')) {
            sendReply($chatId, $replyTo, '⛔ Telegram не позволяет мютить администраторов. Сначала снимите права администратора с пользователя, затем выдайте мут.');
        } else {
            sendReply($chatId, $replyTo, "⛔ Не удалось выдать мут: {$muteErr}");
        }
        return;
    }

    // Записываем в БД только после успешного ответа от Telegram
    $db = getDB();
    $db->exec("
        INSERT OR REPLACE INTO mutes (user_id, chat_id, until) VALUES ($targetId, $chatId, $until)
        ON CONFLICT(user_id, chat_id) DO UPDATE SET until = $until
    ");

    $name = $target['name'];
    $nameLink = formatUserName($target);
    $unmuteKeyboard = [
        'inline_keyboard' => [[
            ['text' => '🔊 Снять мут', 'callback_data' => "unmute:{$targetId}"],
        ]],
    ];
    $punisher = getPunisherLabel($chatId, $adminId);
    sendMessage($chatId, "🔇 Пользователь {$nameLink} замьючен{$untilStr}\n📝 Причина: $reason\n{$punisher}", $unmuteKeyboard);
    writeModLog($chatId, $adminId, $targetId, 'mute', $reason, $seconds > 0 ? trim($untilStr, ' на') : 'навсегда');
}

function cmdUnmute(int $chatId, int $replyTo, int $adminId, ?array $target): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /unmute @username");
        return;
    }

    $targetId = $target['id'];
    $name     = $target['name'];
    $nameLink = formatUserName($target);

    if (!isMuted($chatId, $targetId)) {
        sendReply($chatId, $replyTo, "⚠️ У пользователя {$nameLink} нет мута.");
        return;
    }

    $db = getDB();
    $db->exec("DELETE FROM mutes WHERE user_id = $targetId AND chat_id = $chatId");

    apiRequest('restrictChatMember', [
        'chat_id'     => $chatId,
        'user_id'     => $targetId,
        'permissions' => defaultPermissions(),
    ]);

    $punisher = getPunisherLabel($chatId, $adminId);
    sendReply($chatId, $replyTo, "🔊 Мут снят с пользователя {$nameLink}\n{$punisher}");
    writeModLog($chatId, $adminId, $targetId, 'unmute');
}

function cmdBan(int $chatId, int $replyTo, int $adminId, ?array $target, array $args): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /ban @username [причина]");
        return;
    }

    $reasonArgs = array_filter($args, fn($a) => !str_starts_with($a, '@') && !is_numeric($a));
    $reason     = implode(' ', $reasonArgs) ?: 'Не указана';
    $targetId   = $target['id'];
    if (isAdminProtected($chatId, $targetId, $adminId, $replyTo, 'бан')) return;

    // Проверка: уже забанен?
    if (isBanned($chatId, $targetId)) {
        $nameLink = formatUserName($target);
        sendReply($chatId, $replyTo, "⚠️ Пользователь {$nameLink} уже заблокирован.");
        return;
    }

    getDB()->exec("DELETE FROM mutes WHERE user_id = $targetId AND chat_id = $chatId");

    $result = apiRequest('banChatMember', [
        'chat_id' => $chatId,
        'user_id' => $targetId,
    ]);

    if ($result['ok'] ?? false) {
        $db     = getDB();
        $now    = time();
        $rsn    = addslashes($reason);
        $db->exec("
            INSERT OR REPLACE INTO bans (user_id, chat_id, banned_by, reason, banned_at)
            VALUES ($targetId, $chatId, $adminId, '$rsn', $now)
            ON CONFLICT(user_id, chat_id) DO UPDATE SET banned_by = $adminId, reason = '$rsn', banned_at = $now
        ");
        $nameLink = formatUserName($target);
        $unbanKeyboard = [
            'inline_keyboard' => [[
                ['text' => '🟢 Снять бан', 'callback_data' => "unban:{$targetId}"],
            ]],
        ];
        $punisher = getPunisherLabel($chatId, $adminId);
        sendMessage($chatId, "🔴 Пользователь {$nameLink} заблокирован навсегда\n📝 Причина: $reason\n{$punisher}", $unbanKeyboard);
        writeModLog($chatId, $adminId, $targetId, 'ban', $reason);
    } else {
        $errDesc = $result['description'] ?? '';
        if (str_contains($errDesc, 'USER_NOT_PARTICIPANT') || str_contains($errDesc, 'user not found')) {
            sendReply($chatId, $replyTo, "❌ Не удалось заблокировать: пользователь не состоит в чате.");
        } elseif (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED')) {
            sendReply($chatId, $replyTo, "❌ Не удалось заблокировать: у бота недостаточно прав.");
        } elseif (str_contains($errDesc, 'administrator')) {
            sendReply($chatId, $replyTo, "❌ Не удалось заблокировать: пользователь является администратором.");
        } else {
            sendReply($chatId, $replyTo, "❌ Не удалось заблокировать: $errDesc");
        }
    }
}

function cmdUnban(int $chatId, int $replyTo, int $adminId, ?array $target): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /unban @username");
        return;
    }

    $nameLink = formatUserName($target);

    if (!isBanned($chatId, $target['id'])) {
        sendReply($chatId, $replyTo, "⚠️ У пользователя {$nameLink} нет блокировки.");
        return;
    }

    $result = apiRequest('unbanChatMember', [
        'chat_id'        => $chatId,
        'user_id'        => $target['id'],
        'only_if_banned' => true,
    ]);

    if ($result['ok'] ?? false) {
        getDB()->exec("DELETE FROM tempbans WHERE user_id = {$target['id']} AND chat_id = $chatId");
        getDB()->exec("DELETE FROM bans WHERE user_id = {$target['id']} AND chat_id = $chatId");
        $punisher = getPunisherLabel($chatId, $adminId);
        sendReply($chatId, $replyTo, "🟢 Блокировка снята с пользователя {$nameLink}\n{$punisher}");
        writeModLog($chatId, $adminId, $target['id'], 'unban');
    } else {
        $errDesc = $result['description'] ?? '';
        if (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED')) {
            sendReply($chatId, $replyTo, "❌ Не удалось снять блокировку: у бота недостаточно прав.");
        } else {
            sendReply($chatId, $replyTo, "❌ Не удалось снять блокировку: $errDesc");
        }
    }
}

function cmdKick(int $chatId, int $replyTo, int $adminId, ?array $target): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /kick @username");
        return;
    }

    $targetId = $target['id'];
    if (isAdminProtected($chatId, $targetId, $adminId, $replyTo, 'кик')) return;

    getDB()->exec("DELETE FROM mutes WHERE user_id = $targetId AND chat_id = $chatId");

    $result = apiRequest('banChatMember', [
        'chat_id' => $chatId,
        'user_id' => $targetId,
    ]);

    // Сразу снимаем бан — пользователь выгнан, но может вернуться в любой момент
    apiRequest('unbanChatMember', [
        'chat_id'        => $chatId,
        'user_id'        => $targetId,
        'only_if_banned' => true,
    ]);

    if ($result['ok'] ?? false) {
        $nameLink = formatUserName($target);
        $punisher = getPunisherLabel($chatId, $adminId);
        sendReply($chatId, $replyTo, "👢 Пользователь {$nameLink} кикнут из чата (может вернуться по ссылке)\n{$punisher}");
        writeModLog($chatId, $adminId, $targetId, 'kick');
    } else {
        $errDesc = $result['description'] ?? '';
        if (str_contains($errDesc, 'USER_NOT_PARTICIPANT') || str_contains($errDesc, 'user not found')) {
            sendReply($chatId, $replyTo, "❌ Не удалось кикнуть: пользователь не состоит в чате.");
        } elseif (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED')) {
            sendReply($chatId, $replyTo, "❌ Не удалось кикнуть: у бота недостаточно прав.");
        } elseif (str_contains($errDesc, 'administrator')) {
            sendReply($chatId, $replyTo, "❌ Не удалось кикнуть: пользователь является администратором.");
        } else {
            sendReply($chatId, $replyTo, "❌ Не удалось кикнуть: $errDesc");
        }
    }
}

function cmdTempban(int $chatId, int $replyTo, int $adminId, ?array $target, array $args): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /tempban @username [время] [причина]\n⏱ Форматы времени: 30m (минуты), 12h (часы), 7d (дни)");
        return;
    }

    $targetId    = $target['id'];
    if (isAdminProtected($chatId, $targetId, $adminId, $replyTo, 'временный бан')) return;
    $seconds     = -1; // -1 = не указано
    $durationStr = '';
    $reasonStart = 0;

    foreach ($args as $i => $arg) {
        // Пропускаем @username или числовой ID в первом аргументе
        if ($i === 0 && (str_starts_with($arg, '@') || (is_numeric($arg) && (int)$arg === $targetId))) {
            $reasonStart = 1;
            continue;
        }

        // Парсим формат: 30m, 12h, 7d (или просто число — считаем как часы)
        if (preg_match('/^(\d+)(m|h|d)?$/i', $arg, $match)) {
            $val  = (int)$match[1];
            $unit = strtolower($match[2] ?? 'h');

            switch ($unit) {
                case 'm':
                    $seconds     = $val * 60;
                    $durationStr = "{$val} мин.";
                    break;
                case 'h':
                    $seconds     = $val * 3600;
                    $durationStr = formatHours($val);
                    break;
                case 'd':
                    $seconds     = $val * 86400;
                    $durationStr = "{$val} дн.";
                    break;
            }

            $reasonStart = $i + 1;
            break;
        }
    }

    // Если время не указано — просим указать
    if ($seconds === -1) {
        sendReply($chatId, $replyTo, "❓ Укажите время: /tempban @username [время] [причина]\n⏱ Форматы: 30m (минуты), 12h (часы), 7d (дни)");
        return;
    }

    // Проверка: уже забанен?
    if (isBanned($chatId, $targetId)) {
        $nameLink = formatUserName($target);
        sendReply($chatId, $replyTo, "⚠️ Пользователь {$nameLink} уже заблокирован.");
        return;
    }

    $reason   = implode(' ', array_slice($args, $reasonStart)) ?: 'Не указана';
    $until    = time() + $seconds;
    $untilStr = date('d.m.Y H:i', $until);

    getDB()->exec("DELETE FROM mutes WHERE user_id = $targetId AND chat_id = $chatId");

    $result = apiRequest('banChatMember', [
        'chat_id'    => $chatId,
        'user_id'    => $targetId,
        'until_date' => $until,
    ]);

    if ($result['ok'] ?? false) {
        $db  = getDB();
        $now = time();
        $rsn = addslashes($reason);
        // Сохраняем временный бан в БД для уведомлений
        $db->exec("
            INSERT OR REPLACE INTO tempbans (user_id, chat_id, until, notified) VALUES ($targetId, $chatId, $until, 0)
            ON CONFLICT(user_id, chat_id) DO UPDATE SET until = $until, notified = 0
        ");
        $db->exec("
            INSERT OR REPLACE INTO bans (user_id, chat_id, banned_by, reason, banned_at)
            VALUES ($targetId, $chatId, $adminId, '$rsn', $now)
            ON CONFLICT(user_id, chat_id) DO UPDATE SET banned_by = $adminId, reason = '$rsn', banned_at = $now
        ");
        $nameLink = formatUserName($target);
        $untempbanKeyboard = [
            'inline_keyboard' => [[
                ['text' => '🟢 Снять бан', 'callback_data' => "unban:{$targetId}"],
            ]],
        ];
        $punisher = getPunisherLabel($chatId, $adminId);
        sendMessage($chatId,
            "⏳ Пользователь {$nameLink} временно заблокирован на {$durationStr}\n📅 До: $untilStr\n📝 Причина: $reason\n{$punisher}",
            $untempbanKeyboard
        );
        writeModLog($chatId, $adminId, $targetId, 'tempban', $reason, $durationStr);
    } else {
        $errDesc = $result['description'] ?? '';
        if (str_contains($errDesc, 'USER_NOT_PARTICIPANT') || str_contains($errDesc, 'user not found')) {
            sendReply($chatId, $replyTo, "❌ Не удалось выдать временный бан: пользователь не состоит в чате.");
        } elseif (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED')) {
            sendReply($chatId, $replyTo, "❌ Не удалось выдать временный бан: у бота недостаточно прав.");
        } elseif (str_contains($errDesc, 'administrator')) {
            sendReply($chatId, $replyTo, "❌ Не удалось выдать временный бан: пользователь является администратором.");
        } else {
            sendReply($chatId, $replyTo, "❌ Не удалось выдать временный бан: $errDesc");
        }
    }
}

function cmdWarn(int $chatId, int $replyTo, int $adminId, ?array $target, array $args): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /warn @username [причина]");
        return;
    }

    $db       = getDB();
    $targetId = $target['id'];
    if (isAdminProtected($chatId, $targetId, $adminId, $replyTo, 'предупреждение')) return;

    $current = (int)$db->query("SELECT count FROM warns WHERE user_id = $targetId AND chat_id = $chatId")->fetchColumn();
    $count   = $current + 1;

    $db->exec("
        INSERT OR REPLACE INTO warns (user_id, chat_id, count) VALUES ($targetId, $chatId, $count)
        ON CONFLICT(user_id, chat_id) DO UPDATE SET count = $count
    ");

    $reasonArgs = array_filter($args, fn($a) => !str_starts_with($a, '@') && !is_numeric($a));
    $reason     = implode(' ', $reasonArgs) ?: 'Не указана';
    $name       = $target['name'];
    $nameLink   = formatUserName($target);

    if ($count >= 3) {
        $banRes = apiRequest('banChatMember', ['chat_id' => $chatId, 'user_id' => $targetId]);
        if (!($banRes['ok'] ?? false)) {
            $banErr = $banRes['description'] ?? 'неизвестная ошибка';
            // Откатываем счётчик варнов (бан не выдался — откатываемся к предыдущему состоянию)
            if ($current > 0) {
                $db->exec("UPDATE warns SET count = $current WHERE user_id = $targetId AND chat_id = $chatId");
            } else {
                $db->exec("DELETE FROM warns WHERE user_id = $targetId AND chat_id = $chatId");
            }
            if (str_contains($banErr, 'administrator')) {
                sendReply($chatId, $replyTo, '⛔ Telegram не позволяет банить администраторов. Сначала снимите права администратора, затем выдайте бан.');
            } else {
                sendReply($chatId, $replyTo, "⛔ Не удалось выдать бан при 3 варнах: {$banErr}");
            }
            return;
        }
        $db->exec("DELETE FROM warns WHERE user_id = $targetId AND chat_id = $chatId");
        $now = time();
        $rsn = addslashes("3 предупреждения: $reason");
        $db->exec("
            INSERT OR REPLACE INTO bans (user_id, chat_id, banned_by, reason, banned_at)
            VALUES ($targetId, $chatId, $adminId, '$rsn', $now)
            ON CONFLICT(user_id, chat_id) DO UPDATE SET banned_by = $adminId, reason = '$rsn', banned_at = $now
        ");
        $unbanKeyboard = [
            'inline_keyboard' => [[
                ['text' => '🟢 Снять бан', 'callback_data' => "unban:{$targetId}"],
            ]],
        ];
        $punisher = getPunisherLabel($chatId, $adminId);
        sendMessage($chatId,
            "🚫 Пользователь {$nameLink} получил 3/3 предупреждения и заблокирован\n📝 Причина: $reason\n{$punisher}",
            $unbanKeyboard
        );
        writeModLog($chatId, $adminId, $targetId, 'autoban', $reason);
    } else {
        $unwarnKeyboard = [
            'inline_keyboard' => [[
                ['text' => '✅ Снять предупреждение', 'callback_data' => "unwarn:{$targetId}"],
            ]],
        ];
        $punisher = getPunisherLabel($chatId, $adminId);
        sendMessage($chatId,
            "⚠️ Предупреждение {$count}/3 для {$nameLink}\n📝 Причина: $reason\n{$punisher}",
            $unwarnKeyboard
        );
        writeModLog($chatId, $adminId, $targetId, 'warn', $reason, "{$count}/3");
    }
}

function cmdUnwarn(int $chatId, int $replyTo, int $adminId, ?array $target): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /unwarn @username");
        return;
    }

    $db       = getDB();
    $targetId = $target['id'];
    $nameLink = formatUserName($target);

    $current = (int)$db->query("SELECT count FROM warns WHERE user_id = $targetId AND chat_id = $chatId")->fetchColumn();

    if ($current <= 0) {
        sendReply($chatId, $replyTo, "ℹ️ У {$nameLink} нет предупреждений.");
        return;
    }

    $newCount = $current - 1;

    if ($newCount === 0) {
        $db->exec("DELETE FROM warns WHERE user_id = $targetId AND chat_id = $chatId");
    } else {
        $db->exec("UPDATE warns SET count = $newCount WHERE user_id = $targetId AND chat_id = $chatId");
    }

    sendReply($chatId, $replyTo,
        "✅ Одно предупреждение снято с {$nameLink}. Теперь: {$newCount}/3."
    );
    writeModLog($chatId, $adminId, $targetId, 'unwarn', '', "{$newCount}/3");
}

function cmdInfo(int $chatId, int $replyTo, ?array $target): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /info @username");
        return;
    }

    $targetId = $target['id'];
    $name     = $target['name'];
    $nameLink = formatUserName($target);

    $member = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $targetId]);
    $status = $member['result']['status'] ?? 'неизвестен';

    $statusMap = [
        'creator'       => '👑 Владелец',
        'administrator' => '⭐ Администратор',
        'member'        => '👤 Участник',
        'restricted'    => '🔇 Ограничен',
        'left'          => '🚪 Покинул чат',
        'kicked'        => '🔴 Заблокирован',
    ];
    $statusStr = $statusMap[$status] ?? $status;

    $db    = getDB();
    $warns = (int)$db->query("SELECT count FROM warns WHERE user_id = $targetId AND chat_id = $chatId")->fetchColumn();

    // Мут
    $muteRow  = $db->query("SELECT until FROM mutes WHERE user_id = $targetId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $isMuted  = $muteRow && ($muteRow['until'] === 0 || (int)$muteRow['until'] > time());
    $muteStr  = '🔊 Нет';
    if ($isMuted) {
        $until = (int)$muteRow['until'];
        $muteStr = $until > 0
            ? '🔇 Да (до ' . date('d.m.Y H:i', $until) . ')'
            : '🔇 Навсегда';
    }

    // Бан с причиной
    $banRow   = $db->query("SELECT reason, banned_at, banned_by FROM bans WHERE user_id = $targetId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $banStr   = '✅ Нет';
    if ($banRow) {
        $banReason = htmlspecialchars($banRow['reason'] ?: 'Не указана', ENT_XML1);
        $banDate   = date('d.m.Y H:i', (int)$banRow['banned_at']);
        $banStr    = "🔴 Да\n│  📝 Причина: {$banReason}\n│  📅 Дата: {$banDate}";
        // Имя забанившего администратора
        $bannerRow = $db->query("SELECT name FROM users WHERE user_id = {$banRow['banned_by']} AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        if ($bannerRow) {
            $bannerName = htmlspecialchars($bannerRow['name'], ENT_XML1);
            $banStr .= "\n│  👮 Выдал: {$bannerName}";
        }
    }

    // Статистика из таблицы users
    $userRow      = $db->query("SELECT message_count, joined_at FROM users WHERE user_id = $targetId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $msgCount     = $userRow ? (int)$userRow['message_count'] : 0;
    $joinedAt     = $userRow ? (int)$userRow['joined_at']     : 0;
    $joinedStr    = $joinedAt > 0 ? date('d.m.Y', $joinedAt) : 'Неизвестно';

    // Количество репортов на пользователя
    $reportsCount = (int)$db->query("SELECT COUNT(*) FROM reports WHERE reported_id = $targetId AND chat_id = $chatId")->fetchColumn();

    $text = "👤 <b>Информация о пользователе</b>\n"
          . "━━━━━━━━━━━━━━━━━━━━\n"
          . "├ 👤 Имя: {$nameLink}\n"
          . "├ 🆔 ID: <code>{$targetId}</code>\n"
          . "├ 📊 Статус: {$statusStr}\n"
          . "│\n"
          . "├ 💬 Сообщений: <b>{$msgCount}</b>\n"
          . "├ 📅 Первое сообщение: {$joinedStr}\n"
          . "│\n"
          . "├ 🔇 Мут: {$muteStr}\n"
          . "├ ⚠️ Варны: <b>{$warns}/3</b>\n"
          . "├ 🚫 Бан: {$banStr}\n"
          . "└ ❗ Репортов на пользователя: <b>{$reportsCount}</b>";

    sendReply($chatId, $replyTo, $text);
}

// ─────────────────────────────────────────────
// /me — профиль самого отправителя команды
// ─────────────────────────────────────────────
function cmdMe(int $chatId, int $replyTo, int $userId, array $fromUser): void {
    $name     = htmlspecialchars(trim(($fromUser['first_name'] ?? '') . ' ' . ($fromUser['last_name'] ?? '')), ENT_XML1);
    $username = $fromUser['username'] ?? '';
    $nameLink = $username !== ''
        ? "<a href=\"https://t.me/{$username}\">{$name}</a>"
        : "<a href=\"tg://user?id={$userId}\">{$name}</a>";

    $member    = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    $status    = $member['result']['status'] ?? 'неизвестен';

    $statusMap = [
        'creator'       => '👑 Владелец',
        'administrator' => '⭐ Администратор',
        'member'        => '👤 Участник',
        'restricted'    => '🔇 Ограничен',
        'left'          => '🚪 Покинул чат',
        'kicked'        => '🔴 Заблокирован',
    ];
    $statusStr = $statusMap[$status] ?? $status;

    $db    = getDB();
    $warns = (int)$db->query("SELECT count FROM warns WHERE user_id = $userId AND chat_id = $chatId")->fetchColumn();

    // Мут
    $muteRow = $db->query("SELECT until FROM mutes WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $isMuted = $muteRow && ($muteRow['until'] === 0 || (int)$muteRow['until'] > time());
    $muteStr = '🔊 Нет';
    if ($isMuted) {
        $until   = (int)$muteRow['until'];
        $muteStr = $until > 0
            ? '🔇 Да (до ' . date('d.m.Y H:i', $until) . ')'
            : '🔇 Навсегда';
    }

    // Бан
    $isBanned = (bool)$db->query("SELECT 1 FROM bans WHERE user_id = $userId AND chat_id = $chatId")->fetchColumn();
    $banStr   = $isBanned ? '🔴 Да' : '✅ Нет';

    // Репутация
    $rep      = (int)$db->query("SELECT rep FROM reputation WHERE user_id = $userId AND chat_id = $chatId")->fetchColumn();

    // Статистика сообщений
    $userRow  = $db->query("SELECT message_count, joined_at FROM users WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $msgCount = $userRow ? (int)$userRow['message_count'] : 0;
    $joinedAt = $userRow ? (int)$userRow['joined_at']     : 0;
    $joinedStr = $joinedAt > 0 ? date('d.m.Y', $joinedAt) : 'Неизвестно';

    $text = "🪪 <b>Ваш профиль в этом чате</b>\n"
          . "━━━━━━━━━━━━━━━━━━━━\n"
          . "├ 👤 Имя: {$nameLink}\n"
          . "├ 🆔 ID: <code>{$userId}</code>\n"
          . "├ 📊 Статус: {$statusStr}\n"
          . "│\n"
          . "├ 💬 Сообщений: <b>{$msgCount}</b>\n"
          . "├ 📅 Первое сообщение: {$joinedStr}\n"
          . "│\n"
          . "├ ⭐ Репутация: <b>{$rep}</b>\n"
          . "├ ⚠️ Варны: <b>{$warns}/3</b>\n"
          . "├ 🔇 Мут: {$muteStr}\n"
          . "└ 🚫 Бан: {$banStr}";

    sendReply($chatId, $replyTo, $text);
}

function cmdPromote(int $chatId, int $replyTo, int $adminId, ?array $target): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /promote @username");
        return;
    }

    $targetId  = $target['id'];
    $name      = $target['name'];
    $nameLink  = formatUserName($target);
    $isOwner   = isChatOwner($chatId, $adminId);

    // Запрашиваем у Telegram права самого бота в этом чате
    $botInfo    = apiRequest('getMe', []);
    $botId      = $botInfo['result']['id'] ?? 0;
    $botMember  = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
    $botRights  = $botMember['result'] ?? [];

    // Передаём только те права, которые есть у бота
    $params = ['chat_id' => $chatId, 'user_id' => $targetId];
    foreach ([
        'can_manage_chat',
        'can_delete_messages',
        'can_restrict_members',
        'can_invite_users',
        'can_pin_messages',
        'can_manage_video_chats',
    ] as $right) {
        if (!empty($botRights[$right])) {
            $params[$right] = true;
        }
    }
    // can_promote_members никогда не передаём — Telegram запрещает

    $result = apiRequest('promoteChatMember', $params);

    if (!($result['ok'] ?? false)) {
        $errDesc = $result['description'] ?? '';
        if (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED')) {
            sendReply($chatId, $replyTo, "❌ У бота недостаточно прав. Убедитесь что бот — администратор с правом «Добавление администраторов».");
        } elseif (str_contains($errDesc, 'USER_NOT_PARTICIPANT') || str_contains($errDesc, 'user not found') || str_contains($errDesc, 'Bad Request: user not found')) {
            sendReply($chatId, $replyTo, "❌ Пользователь не состоит в этом чате.");
        } elseif (str_contains($errDesc, 'administrator')) {
            sendReply($chatId, $replyTo, "❌ Пользователь уже является администратором.");
        } else {
            sendReply($chatId, $replyTo, "❌ Не удалось выдать права: $errDesc");
        }
        return;
    }

    sendReply($chatId, $replyTo, "⭐ Пользователь {$nameLink} назначен администратором");
    writeModLog($chatId, $adminId, $targetId, 'promote');
}

function cmdDemote(int $chatId, int $replyTo, int $adminId, ?array $target): void {
    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /demote @username");
        return;
    }

    $targetId = $target['id'];
    $name     = $target['name'];
    $nameLink = formatUserName($target);

    // Проверяем, что пользователь вообще является администратором
    $member = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $targetId]);
    $status = $member['result']['status'] ?? '';

    if ($status === 'creator') {
        sendReply($chatId, $replyTo, "⛔ Нельзя снять права с владельца чата.");
        return;
    }

    if ($status !== 'administrator') {
        sendReply($chatId, $replyTo, "⚠️ Пользователь {$nameLink} не является администратором.");
        return;
    }

    // Снимаем все права администратора (promoteChatMember без прав = demote)
    $result = apiRequest('promoteChatMember', [
        'chat_id'              => $chatId,
        'user_id'              => $targetId,
        'can_delete_messages'  => false,
        'can_restrict_members' => false,
        'can_invite_users'     => false,
        'can_pin_messages'     => false,
        'can_manage_chat'      => false,
        'can_manage_video_chats' => false,
        'can_change_info'      => false,
        'can_post_messages'    => false,
        'can_edit_messages'    => false,
        'can_promote_members'  => false,
    ]);

    if ($result['ok'] ?? false) {
        sendReply($chatId, $replyTo, "🔽 Пользователь {$nameLink} снят с должности администратора");
        writeModLog($chatId, $adminId, $targetId, 'demote');
    } else {
        sendReply($chatId, $replyTo, "❌ Не удалось снять права. Возможно, администратор назначен владельцем напрямую (не через бота).");
    }
}


// ─────────────────────────────────────────────
// ПРАВИЛА ЧАТА (только для владельца)
// ─────────────────────────────────────────────

function cmdRules(int $chatId, int $replyTo): void {
    $db      = getDB();
    $existing = $db->query("SELECT text FROM rules WHERE chat_id = $chatId LIMIT 1")->fetchColumn();

    if ($existing === false || $existing === null || $existing === '') {
        sendReply($chatId, $replyTo, "📋 Правила чата не установлены.");
        return;
    }

    $text = "📋 <b>Правила чата</b>\n\n"
          . htmlspecialchars($existing, ENT_XML1)
          . "\n\n<i>Нарушение правил может повлечь мут, бан или кик.</i>";

    sendReply($chatId, $replyTo, $text);
}

function cmdAddRule(int $chatId, int $replyTo, int $adminId, array $args): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Управлять правилами может только владелец или администратор с полными правами.");
        return;
    }

    // Берём весь текст после команды как есть (включая длинный текст)
    $newText = trim(implode(' ', $args));

    if ($newText === '') {
        sendReply($chatId, $replyTo, "❓ Укажите текст для добавления к правилам:\n/addrule Ваш текст правил");
        return;
    }

    $db = getDB();

    // Получаем текущий текст правил
    $existing = $db->query("SELECT text FROM rules WHERE chat_id = $chatId LIMIT 1")->fetchColumn();

    if ($existing !== false && $existing !== null && $existing !== '') {
        // Добавляем к существующему тексту через перенос строки
        $merged  = $existing . "\n" . $newText;
        $escaped = addslashes($merged);
        $db->exec("UPDATE rules SET text = '$escaped' WHERE chat_id = $chatId");
        sendReply($chatId, $replyTo, "✅ Текст добавлен к правилам чата.\n\n📋 Посмотреть: /rules");
    } else {
        // Первая запись
        $escaped = addslashes($newText);
        $db->exec("INSERT INTO rules (chat_id, text, position) VALUES ($chatId, '$escaped', 1)");
        sendReply($chatId, $replyTo, "✅ Правила чата установлены.\n\n📋 Посмотреть: /rules");
    }
}

function cmdDelRule(int $chatId, int $replyTo, int $adminId, array $args): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Управлять правилами может только владелец или администратор с полными правами.");
        return;
    }

    $db = getDB();

    $existing = $db->query("SELECT text FROM rules WHERE chat_id = $chatId LIMIT 1")->fetchColumn();

    if ($existing === false || $existing === null || $existing === '') {
        sendReply($chatId, $replyTo, "⚠️ Правила чата уже пусты.");
        return;
    }

    $db->exec("DELETE FROM rules WHERE chat_id = $chatId");
    sendReply($chatId, $replyTo, "🗑 Все правила чата удалены.");
}

function cmdHelp(int $chatId, int $replyTo): void {
    $text = "📋 <b>Выберите список команд:</b>";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '👤 Для всех', 'callback_data' => 'help_public'],
                ['text' => '🛡 Для администрации', 'callback_data' => 'help_admin'],
            ],
            [
                ['text' => '👑 Для владельца', 'callback_data' => 'help_owner'],
            ],
        ],
    ];

    sendMessage($chatId, $text, $keyboard);
}

// ─────────────────────────────────────────────
// СПИСОК АДМИНИСТРАТОРОВ ЧАТА
// ─────────────────────────────────────────────

function cmdAdmins(int $chatId, int $replyTo): void {
    $result = apiRequest('getChatAdministrators', ['chat_id' => $chatId]);

    if (!($result['ok'] ?? false) || empty($result['result'])) {
        sendReply($chatId, $replyTo, "❌ Не удалось получить список администраторов.");
        return;
    }

    $admins  = $result['result'];

    // Список прав, по которым определяем «полные права» (владелец = creator всегда полные)
    $fullRightsList = [
        'can_manage_chat',
        'can_delete_messages',
        'can_restrict_members',
        'can_invite_users',
        'can_pin_messages',
        'can_manage_video_chats',
        'can_change_info',
        'can_promote_members',
    ];

    $owners = [];
    $regular = [];

    foreach ($admins as $member) {
        $user   = $member['user'] ?? [];
        if (!empty($user['is_bot'])) continue; // пропускаем ботов

        $status = $member['status'] ?? '';
        $name   = htmlspecialchars(
            trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            ENT_XML1
        );
        $username = $user['username'] ?? '';
        $userId   = (int)($user['id'] ?? 0);

        // Формируем ссылку на пользователя
        $link = $username !== ''
            ? "<a href=\"https://t.me/{$username}\">{$name}</a>"
            : "<a href=\"tg://user?id={$userId}\">{$name}</a>";

        if ($status === 'creator') {
            $owners[] = "👑 Владелец: {$link}";
        } else {
            // Проверяем: все ли ключевые права включены?
            $allFull = true;
            foreach ($fullRightsList as $right) {
                if (empty($member[$right])) {
                    $allFull = false;
                    break;
                }
            }

            if ($allFull) {
                $owners[] = "👑 Владелец: {$link}";
            } else {
                $regular[] = "⚙️ Администратор: {$link}";
            }
        }
    }

    if (empty($owners) && empty($regular)) {
        sendReply($chatId, $replyTo, "👥 Администраторов не найдено.");
        return;
    }

    $lines = array_merge($owners, $regular);
    $total = count($lines);

    $text = "👥 <b>Список администрации</b> ({$total}):\n\n"
          . implode("\n", $lines);

    sendReply($chatId, $replyTo, $text);
}



// ─────────────────────────────────────────────
// /call — призыв с выбором кого созвать
// ─────────────────────────────────────────────

/**
 * Шаг 1: показывает inline-клавиатуру выбора целевой группы.
 * reason закодирован в callback_data через base64 (урезаем до 40 символов).
 */
function cmdCall(int $chatId, int $replyTo, int $adminId, string $reason): void {
    if (!isChatAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Команда доступна только администраторам.");
        return;
    }

    // Кодируем причину для передачи через callback_data (макс ~40 симв.)
    $reasonShort = mb_substr($reason, 0, 40);
    $reasonEnc   = base64_encode($reasonShort);

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '👥 Все участники',  'callback_data' => "call:users:{$reasonEnc}"],
                ['text' => '🛡 Администраторы', 'callback_data' => "call:admins:{$reasonEnc}"],
            ],
            [
                ['text' => '👑 Владельцы',       'callback_data' => "call:owners:{$reasonEnc}"],
                ['text' => '🌐 Все (вместе)',     'callback_data' => "call:all:{$reasonEnc}"],
            ],
        ],
    ];

    sendMessage($chatId, "📣 <b>Кого призвать?</b>" . ($reason !== '' ? "\n📝 <i>{$reason}</i>" : ''), $keyboard);
}

/**
 * Шаг 2: выполняет призыв выбранной группы.
 * $mode = users | admins | owners | all
 */
function executeCmdCall(int $chatId, int $callbackId, int $messageId, int $adminId, string $mode, string $reason): void {
    $db = getDB();

    // --- Получаем ID самого бота, чтобы исключить его из призыва ---
    $botInfo  = apiRequest('getMe', []);
    $botId    = (int)($botInfo['result']['id'] ?? 0);

    // Telegram service account (777000 — «Telegram» как отправитель системных сообщений)
    $excludeIds = array_filter([$botId, 777000]);

    // --- Получаем список администраторов/владельцев из API ---
    $adminsResult = apiRequest('getChatAdministrators', ['chat_id' => $chatId]);
    $adminsList   = ($adminsResult['ok'] ?? false) ? ($adminsResult['result'] ?? []) : [];

    $ownerIds = [];
    $adminIds = [];
    foreach ($adminsList as $member) {
        $user = $member['user'] ?? [];
        if (!empty($user['is_bot'])) continue;
        $uid = (int)($user['id'] ?? 0);
        if (!$uid) continue;
        if (in_array($uid, $excludeIds, true)) continue;
        $status = $member['status'] ?? '';

        // Определяем: владелец или обычный администратор
        $isCreator = ($status === 'creator');
        $isFullAdm = false;
        if (!$isCreator) {
            $fullRights = ['can_manage_chat','can_delete_messages','can_restrict_members',
                           'can_invite_users','can_pin_messages','can_manage_video_chats',
                           'can_change_info','can_promote_members'];
            $allFull = true;
            foreach ($fullRights as $r) {
                if (empty($member[$r])) { $allFull = false; break; }
            }
            $isFullAdm = $allFull;
        }

        $uname = $user['username'] ?? '';
        $uname_lc = strtolower($uname);
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if ($isCreator || $isFullAdm) {
            $ownerIds[$uid] = ['user_id' => $uid, 'name' => $name, 'username' => $uname_lc];
        } else {
            $adminIds[$uid] = ['user_id' => $uid, 'name' => $name, 'username' => $uname_lc];
        }
    }

    // --- Собираем нужную группу ---
    $targets = [];
    $label   = '';

    if ($mode === 'users') {
        // Только обычные пользователи (не в adminIds и не в ownerIds)
        $res = $db->query("SELECT DISTINCT user_id, name, username FROM users WHERE chat_id = $chatId");
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int)$row['user_id'];
                if (in_array($uid, $excludeIds, true)) continue;
                if (!isset($ownerIds[$uid]) && !isset($adminIds[$uid])) {
                    $targets[$uid] = $row;
                }
            }
        }
        $label = '👥 Участники';

    } elseif ($mode === 'admins') {
        // Только администраторы (без владельцев)
        $targets = $adminIds;
        $label   = '🛡 Администраторы';

    } elseif ($mode === 'owners') {
        // Только владельцы
        $targets = $ownerIds;
        $label   = '👑 Владельцы';

    } elseif ($mode === 'all') {
        // Все вместе: пользователи + администраторы + владельцы
        $res = $db->query("SELECT DISTINCT user_id, name, username FROM users WHERE chat_id = $chatId");
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int)$row['user_id'];
                if (in_array($uid, $excludeIds, true)) continue;
                $targets[$uid] = $row;
            }
        }
        // Добавляем тех из API, кого нет в БД
        foreach (array_merge($ownerIds, $adminIds) as $uid => $u) {
            if (!isset($targets[$uid])) $targets[$uid] = $u;
        }
        $label = '🌐 Все участники';
    }

    if (empty($targets)) {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Нет участников в выбранной группе', 'show_alert' => true]);
        return;
    }

    apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);

    // Удаляем сообщение с кнопками выбора
    deleteMessage($chatId, $messageId);

    // --- Формируем упоминания ---
    $mentions = [];
    foreach ($targets as $uid => $u) {
        $name = htmlspecialchars(trim($u['name'] ?? ''), ENT_XML1);
        if ($name === '') $name = 'участник';
        $username = $u['username'] ?? '';
        if ($username !== '') {
            $mentions[] = '<a href="https://t.me/' . htmlspecialchars($username, ENT_XML1) . '">' . $name . '</a>';
        } else {
            $mentions[] = '<a href="tg://user?id=' . $uid . '">' . $name . '</a>';
        }
    }

    $total  = count($mentions);
    $header = "📣 <b>Призыв: {$label}</b> ({$total})" . ($reason !== '' ? "\n📝 {$reason}" : '') . "\n\n";

    // --- Разбиваем на части по ~3800 символов ---
    $parts = [];
    $chunk = $header;

    foreach ($mentions as $i => $mention) {
        $separator = ($i === 0) ? '' : ' ';
        $candidate = $chunk . $separator . $mention;

        if (mb_strlen(strip_tags($candidate)) > 3800 && $chunk !== $header) {
            $parts[] = $chunk;
            $chunk = "📣 <b>Призыв (продолжение)</b>\n\n" . $mention;
        } else {
            $chunk = $candidate;
        }
    }
    if ($chunk !== '') {
        $parts[] = $chunk;
    }

    foreach ($parts as $part) {
        sendMessage($chatId, $part);
    }
}

/**
 * Возвращает случайного участника чата из таблицы users.
 * Если никого нет — возвращает null.
 */
function getRandomChatUser(int $chatId): ?array {
    $db  = getDB();
    $row = $db->query(
        "SELECT user_id, name, username FROM users
         WHERE chat_id = $chatId
           AND user_id NOT IN (777000, 136817688)
           AND (is_bot IS NULL OR is_bot = 0)
         ORDER BY RANDOM() LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * "кто любит Россию?" → "🎲 Даня любит Россию!"
 */
function cmdWho(int $chatId, int $replyTo, string $subject): void {
    $db = getDB();

    // Собираем пул участников из таблицы users
    $pool = [];
    $res  = $db->query(
        "SELECT user_id, name, username FROM users
         WHERE chat_id = $chatId
           AND user_id NOT IN (777000, 136817688)
           AND (is_bot IS NULL OR is_bot = 0)"
    );
    if ($res) {
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $pool[$row['user_id']] = $row;
        }
    }

    // Дополняем администраторами из Telegram API (они могут не писать сообщений)
    $adminsRes = apiRequest('getChatAdministrators', ['chat_id' => $chatId]);
    if (!empty($adminsRes['result'])) {
        foreach ($adminsRes['result'] as $member) {
            $u = $member['user'] ?? [];
            if (empty($u['id']) || !empty($u['is_bot'])) continue;
            $uid = (int)$u['id'];
            if ($uid === 777000 || $uid === 136817688) continue;
            if (!isset($pool[$uid])) {
                $pool[$uid] = [
                    'user_id'  => $uid,
                    'name'     => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
                    'username' => $u['username'] ?? '',
                ];
            }
        }
    }

    $pool = array_values($pool);

    if (empty($pool)) {
        sendReply($chatId, $replyTo, "🤷 Не знаю никого в этом чате.");
        return;
    }

    $makeLink = function(array $u): string {
        $name = htmlspecialchars(trim($u['name']), ENT_XML1);
        if (empty($name)) {
            $name = $u['username'] ? '@' . $u['username'] : "Кто-то";
        }
        return formatUserName(['id' => (int)$u['user_id'], 'name' => $name, 'username' => $u['username'] ?? '']);
    };

    shuffle($pool);

    $subject = rtrim($subject, '?!.');
    $emojis  = ['🎲', '🎯', '👀', '🔮', '🎰', '⚡'];
    $emoji   = $emojis[array_rand($emojis)];

    $link = $makeLink($pool[0]);
    sendReply($chatId, $replyTo, "{$emoji} <b>{$link}</b> {$subject}!");
}

/**
 * "какова вероятность что мистер имба?" → "🎲 Вероятность 20% что мистер имба!"
 */
function cmdChance(int $chatId, int $replyTo, string $subject): void {
    $percent = mt_rand(0, 100);
    $subject = rtrim($subject, '?!.');

    // Эмодзи в зависимости от процента
    if ($percent === 0) {
        $emoji = '💀';
    } elseif ($percent <= 10) {
        $emoji = '😬';
    } elseif ($percent <= 30) {
        $emoji = '🤔';
    } elseif ($percent <= 60) {
        $emoji = '😏';
    } elseif ($percent <= 90) {
        $emoji = '😎';
    } elseif ($percent < 100) {
        $emoji = '🔥';
    } else {
        $emoji = '💯';
    }

    sendReply($chatId, $replyTo, "{$emoji} Вероятность <b>{$percent}%</b>, что {$subject}!");
}

// ─────────────────────────────────────────────
// ФОРМАТИРОВАНИЕ ИМЕНИ ПОЛЬЗОВАТЕЛЯ
// ─────────────────────────────────────────────

/**
 * Возвращает HTML-ссылку на пользователя:
 * - Если есть username → <a href="https://t.me/username">Имя</a>
 * - Если нет username → <a href="tg://user?id=...">Имя</a>
 */
function formatUserName(array $target): string {
    $name     = $target['name'];
    $username = $target['username'] ?? '';
    $id       = $target['id'];

    if ($username !== '') {
        return '<a href="https://t.me/' . htmlspecialchars($username, ENT_XML1) . '">' . $name . '</a>';
    }

    return '<a href="tg://user?id=' . (int)$id . '">' . $name . '</a>';
}



// ─────────────────────────────────────────────
// АВТО-МОДЕРАЦИЯ
// ─────────────────────────────────────────────

function getAutomodSettings(int $chatId): array {
    $db  = getDB();
    $row = $db->query("SELECT * FROM automod WHERE chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->exec("INSERT OR IGNORE INTO automod (chat_id) VALUES ($chatId)");
        return [
            'chat_id'       => $chatId,
            'block_links'   => 0,
            'block_arabic'  => 0,
            'block_caps'    => 0,
            'block_flood'   => 0,
            'flood_limit'   => 5,
            'flood_seconds' => 10,
            'mute_minutes'  => 15,
            'block_media'   => 0,
            'media_limit'   => 5,
            'media_seconds' => 30,
        ];
    }
    // Подставляем дефолты для новых колонок на случай старой БД без миграции
    $row['block_media']   = isset($row['block_media'])   ? (int)$row['block_media']   : 0;
    $row['media_limit']   = isset($row['media_limit'])   ? (int)$row['media_limit']   : 5;
    $row['media_seconds'] = isset($row['media_seconds']) ? (int)$row['media_seconds'] : 30;
    return $row;
}

/**
 * Проверяет сообщение на нарушения авто-модерации.
 * Возвращает true, если сообщение удалено и пользователь замьючен.
 */
function checkAutomod(array $msg): bool {
    $chatId    = $msg['chat']['id'];
    $userId    = $msg['from']['id'];
    $messageId = $msg['message_id'];
    $text      = $msg['text'] ?? $msg['caption'] ?? '';
    $settings  = getAutomodSettings($chatId);
    $db        = getDB();

    $muteMins  = max(1, (int)$settings['mute_minutes']);
    $reason    = null;

    debugLog("checkAutomod: userId=$userId chatId=$chatId text=" . mb_substr($text, 0, 60));
    debugLog("checkAutomod settings: block_links={$settings['block_links']} block_arabic={$settings['block_arabic']} block_caps={$settings['block_caps']} block_flood={$settings['block_flood']} block_media={$settings['block_media']} mute_minutes={$settings['mute_minutes']}");

    // Системный аккаунт Telegram (777000) — никогда не модерируем
    if ($userId === 777000) {
        debugLog("checkAutomod: skipping Telegram system account 777000");
        return false;
    }

    // Белый список: пользователь освобождён от авто-модерации
    $inWhitelist = $db->query(
        "SELECT 1 FROM automod_whitelist WHERE user_id = $userId AND chat_id = $chatId"
    )->fetchColumn();
    if ($inWhitelist) {
        debugLog("checkAutomod: user $userId is in whitelist, skipping");
        return false;
    }

    // 1. Запрет ссылок (только реальные URL, без @упоминаний и tg://)
    if ($settings['block_links'] && $text !== '') {
        if (preg_match('/(?:https?:\/\/|t\.me\/|www\.)/iu', $text)) {
            $reason = '🔗 Ссылки запрещены в этом чате.';
        }
    }

    // 2. Запрет арабского / иероглифов
    if (!$reason && $settings['block_arabic'] && $text !== '') {
        if (preg_match('/[\x{0600}-\x{06FF}\x{4E00}-\x{9FFF}]/u', $text)) {
            $reason = '🌐 Сообщения на арабском/китайском запрещены в этом чате.';
        }
    }

    // 3. Запрет CAPS (>70% заглавных букв, минимум 6 символов)
    if (!$reason && $settings['block_caps'] && mb_strlen($text) >= 6) {
        $letters = preg_replace('/[^a-zA-Zа-яА-ЯёЁ]/u', '', $text);
        if (mb_strlen($letters) >= 4) {
            $upper = preg_replace('/[^A-ZА-ЯЁ]/u', '', $letters);
            if (mb_strlen($letters) > 0 && (mb_strlen($upper) / mb_strlen($letters)) >= 0.7) {
                $reason = '🔠 Сообщения CAPS-LOCK запрещены в этом чате.';
            }
        }
    }

    // 4. Запрещённые слова
    if (!$reason && $text !== '') {
        $words = $db->query("SELECT word FROM automod_words WHERE chat_id = $chatId");
        if ($words) {
            while ($row = $words->fetch(PDO::FETCH_ASSOC)) {
                $word = preg_quote($row['word'], '/');
                if (preg_match('/\b' . $word . '\b/iu', $text)) {
                    $reason = '🚫 Ваше сообщение содержит запрещённое слово.';
                    break;
                }
            }
        }
    }

    // 5. Флуд
    if (!$reason && $settings['block_flood']) {
        $limit   = max(2, (int)$settings['flood_limit']);
        $window  = max(3, (int)$settings['flood_seconds']);
        $nowTime = time();

        $row = $db->query("SELECT count, window FROM flood_tracker WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);

        if (!$row || ($nowTime - (int)$row['window']) > $window) {
            $db->exec("
                INSERT OR REPLACE INTO flood_tracker (user_id, chat_id, count, window)
                VALUES ($userId, $chatId, 1, $nowTime)
                ON CONFLICT(user_id, chat_id) DO UPDATE SET count = 1, window = $nowTime
            ");
        } else {
            $newCount = (int)$row['count'] + 1;
            $db->exec("UPDATE flood_tracker SET count = $newCount WHERE user_id = $userId AND chat_id = $chatId");
            if ($newCount >= $limit) {
                $reason = "💬 Флуд: слишком много сообщений подряд.";
                $db->exec("UPDATE flood_tracker SET count = 0 WHERE user_id = $userId AND chat_id = $chatId");
            }
        }
    }

    // 6. Антиспам фото/стикеров/гифок
    if (!$reason && $settings['block_media']) {
        // Только фото, стикеры, гифки — голосовые, кружки и пересылки не трогаем
        $isForwarded = isset($msg['forward_from']) || isset($msg['forward_from_chat']) || isset($msg['forward_sender_name']);
        $isMedia = !$isForwarded && (
            isset($msg['sticker'])
            || isset($msg['photo'])
            || isset($msg['animation'])   // GIF
            || (isset($msg['document']) && str_starts_with($msg['document']['mime_type'] ?? '', 'image/'))
        );

        debugLog("checkAutomod media: isForwarded=" . ($isForwarded?'yes':'no') . " isMedia=" . ($isMedia?'yes':'no') . " keys=" . implode(',', array_keys($msg)));

        if ($isMedia) {
            $mediaLimit   = max(2, (int)$settings['media_limit']);
            $mediaWindow  = max(5, (int)$settings['media_seconds']);
            $nowTime      = time();

            $mRow = $db->query("SELECT count, window FROM media_tracker WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);

            if (!$mRow || ($nowTime - (int)$mRow['window']) > $mediaWindow) {
                $db->exec("
                    INSERT OR REPLACE INTO media_tracker (user_id, chat_id, count, window)
                    VALUES ($userId, $chatId, 1, $nowTime)
                    ON CONFLICT(user_id, chat_id) DO UPDATE SET count = 1, window = $nowTime
                ");
            } else {
                $newMCount = (int)$mRow['count'] + 1;
                $db->exec("UPDATE media_tracker SET count = $newMCount WHERE user_id = $userId AND chat_id = $chatId");
                if ($newMCount >= $mediaLimit) {
                    $reason = "🖼 Спам медиа/стикерами: слишком много за короткое время.";
                    $db->exec("UPDATE media_tracker SET count = 0 WHERE user_id = $userId AND chat_id = $chatId");
                }
            }
        }
    }

    if (!$reason) return false;

    // Удаляем нарушающее сообщение
    deleteMessage($chatId, $messageId);

    // Выдаём мут на $muteMins минут
    $until = time() + $muteMins * 60;
    $muteRes = apiRequest('restrictChatMember', [
        'chat_id'     => $chatId,
        'user_id'     => $userId,
        'until_date'  => $until,
        'permissions' => [
            'can_send_messages'         => false,
            'can_send_audios'           => false,
            'can_send_documents'        => false,
            'can_send_photos'           => false,
            'can_send_videos'           => false,
            'can_send_video_notes'      => false,
            'can_send_voice_notes'      => false,
            'can_send_polls'            => false,
            'can_send_other_messages'   => false,
            'can_add_web_page_previews' => false,
            'can_change_info'           => false,
            'can_invite_users'          => false,
            'can_pin_messages'          => false,
        ],
    ]);

    if (!($muteRes['ok'] ?? false)) {
        // Авто-мод не смог замутить (возможно, администратор) — молча пропускаем
        debugLog("checkAutomod: failed to mute user $userId in chat $chatId: " . ($muteRes['description'] ?? 'unknown'));
        return true; // сообщение всё равно удалили
    }

    // Записываем в БД только после успешного ответа от Telegram
    $db->exec("
        INSERT OR REPLACE INTO mutes (user_id, chat_id, until) VALUES ($userId, $chatId, $until)
        ON CONFLICT(user_id, chat_id) DO UPDATE SET until = $until
    ");

    $userRow  = $db->query("SELECT name, username FROM users WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $nameLink = $userRow
        ? formatUserName(['id' => $userId, 'name' => htmlspecialchars($userRow['name'], ENT_XML1), 'username' => $userRow['username'] ?? ''])
        : "<a href=\"tg://user?id={$userId}\">пользователь</a>";

    sendMessage($chatId,
        "🤖 <b>Авто-модерация</b>\n"
        . "{$nameLink}, {$reason}\n"
        . "🔇 Вы получаете мут на <b>{$muteMins} мин.</b>"
    );

    return true;
}

// ─────────────────────────────────────────────
// КОМАНДЫ АВТО-МОДЕРАЦИИ
// ─────────────────────────────────────────────

function automodKeyboard(int $chatId): array {
    $s  = getAutomodSettings($chatId);
    $on  = '✅';
    $off = '❌';

    return [
        'inline_keyboard' => [
            [
                ['text' => ($s['block_links']  ? $on : $off) . ' Ссылки',   'callback_data' => 'am_toggle_block_links'],
                ['text' => ($s['block_arabic'] ? $on : $off) . ' Арабский', 'callback_data' => 'am_toggle_block_arabic'],
            ],
            [
                ['text' => ($s['block_caps']  ? $on : $off) . ' CAPS-LOCK', 'callback_data' => 'am_toggle_block_caps'],
                ['text' => ($s['block_flood'] ? $on : $off) . ' Антифлуд',  'callback_data' => 'am_toggle_block_flood'],
            ],
            [
                ['text' => ($s['block_media'] ? $on : $off) . ' Антиспам медиа', 'callback_data' => 'am_toggle_block_media'],
            ],
            [
                ['text' => '⏱ Мут: ' . $s['mute_minutes'] . ' мин.',  'callback_data' => 'am_mute_cycle'],
                ['text' => '🖼 Медиа: ' . $s['media_limit'] . '/' . $s['media_seconds'] . 'с', 'callback_data' => 'am_media_cycle'],
            ],
            [
                ['text' => '📋 Запрещённые слова',  'callback_data' => 'am_words'],
            ],
            [
                ['text' => '🛡 Белый список',  'callback_data' => 'am_whitelist'],
            ],
        ],
    ];
}

function cmdAutomod(int $chatId, int $replyTo, int $adminId): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Настраивать авто-модерацию может только владелец или администратор с полными правами.");
        return;
    }

    $text = "🤖 <b>Авто-модерация</b>\n\n"
          . "Нажмите на переключатель, чтобы включить/выключить фильтр.\n"
          . "При нарушении пользователь получает мут на указанное время.\n\n"
          . "✅ — включено   ❌ — выключено";

    sendMessage($chatId, $text, automodKeyboard($chatId));
}

function handleAutomodCallback(string $callbackId, int $chatId, int $messageId, int $adminId, string $data): void {
    $db = getDB();
    // Убедимся что запись существует
    getAutomodSettings($chatId);

    if ($data === 'am_whitelist') {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $users = [];
        $res   = $db->query("
            SELECT w.user_id, u.name, u.username
            FROM automod_whitelist w
            LEFT JOIN users u ON u.user_id = w.user_id AND u.chat_id = w.chat_id
            WHERE w.chat_id = $chatId
            ORDER BY u.name
        ");
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $name = htmlspecialchars($row['name'] ?? "ID {$row['user_id']}", ENT_XML1);
                $uname = $row['username'] ?? '';
                $link  = formatUserName(['id' => (int)$row['user_id'], 'name' => $name, 'username' => $uname]);
                $users[] = $link;
            }
        }
        $list = $users ? implode(', ', $users) : '<i>список пуст</i>';
        apiRequest('editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => "🛡 <b>Белый список авто-модерации:</b>\n\n"
                          . "Добавить: /whitelist @user\nУдалить: /unwhitelist @user\n"
                          . "Список: /whitelistshow\n\n"
                          . "<i>💡 Администраторов добавлять не нужно, они автоматически в белом списке.</i>",
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'am_back']]]],
        ]);
        return;
    }

    if ($data === 'am_words') {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $words = [];
        $res   = $db->query("SELECT word FROM automod_words WHERE chat_id = $chatId ORDER BY word");
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $words[] = htmlspecialchars($row['word'], ENT_XML1);
            }
        }
        $list = $words ? implode(', ', $words) : '<i>список пуст</i>';
        apiRequest('editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => "📋 <b>Запрещённые слова:</b>\n{$list}\n\n"
                          . "Добавить: /addword слово\nУдалить: /delword слово\nСписок: /words",
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'am_back']]]],
        ]);
        return;
    }

    if ($data === 'am_back') {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $text = "🤖 <b>Авто-модерация</b>\n\n"
              . "Нажмите на переключатель, чтобы включить/выключить фильтр.\n"
              . "При нарушении пользователь получает мут на указанное время.\n\n"
              . "✅ — включено   ❌ — выключено";
        apiRequest('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => automodKeyboard($chatId),
        ]);
        return;
    }

    if ($data === 'am_mute_cycle') {
        // Цикл: 5 → 15 → 30 → 60 → 120 → 5
        $s       = getAutomodSettings($chatId);
        $current = (int)$s['mute_minutes'];
        $steps   = [5, 15, 30, 60, 120];
        $idx     = array_search($current, $steps);
        $next    = $steps[($idx !== false ? $idx + 1 : 1) % count($steps)];
        $db->exec("UPDATE automod SET mute_minutes = $next WHERE chat_id = $chatId");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => "⏱ Мут изменён: {$next} мин."]);
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => automodKeyboard($chatId),
        ]);
        return;
    }

    if ($data === 'am_media_cycle') {
        // Цикл лимита медиа: 2 → 3 → 5 → 10 → 2
        // Цикл окна (секунд): при достижении последнего лимита — меняем окно 10 → 20 → 30 → 60 → 10
        $s       = getAutomodSettings($chatId);
        $curLim  = (int)$s['media_limit'];
        $curSec  = (int)$s['media_seconds'];
        $limSteps = [2, 3, 5, 10];
        $secSteps = [10, 20, 30, 60];
        $limIdx   = array_search($curLim, $limSteps);
        $secIdx   = array_search($curSec, $secSteps);
        // Переключаем окно, если лимит дошёл до конца
        if ($limIdx === count($limSteps) - 1) {
            $nextLim = $limSteps[0];
            $nextSec = $secSteps[($secIdx !== false ? $secIdx + 1 : 1) % count($secSteps)];
        } else {
            $nextLim = $limSteps[($limIdx !== false ? $limIdx + 1 : 1)];
            $nextSec = $curSec > 0 ? $curSec : 30;
        }
        $db->exec("UPDATE automod SET media_limit = $nextLim, media_seconds = $nextSec WHERE chat_id = $chatId");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => "🖼 Медиа: не более {$nextLim} за {$nextSec}с."]);
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => automodKeyboard($chatId),
        ]);
        return;
    }

    if (str_starts_with($data, 'am_toggle_')) {
        $field   = substr($data, 10); // e.g. block_links
        $allowed = ['block_links', 'block_arabic', 'block_caps', 'block_flood', 'block_media'];
        if (!in_array($field, $allowed, true)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
            return;
        }
        $s       = getAutomodSettings($chatId);
        $newVal  = $s[$field] ? 0 : 1;
        $db->exec("UPDATE automod SET $field = $newVal WHERE chat_id = $chatId");
        $status = $newVal ? 'включён' : 'выключен';
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => "Фильтр $status"]);
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => automodKeyboard($chatId),
        ]);
        return;
    }

    apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
}

function cmdAddWord(int $chatId, int $replyTo, int $adminId, array $args): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Управлять запрещёнными словами может только владелец или администратор с полными правами.");
        return;
    }

    $word = mb_strtolower(trim(implode(' ', $args)));
    if ($word === '') {
        sendReply($chatId, $replyTo, "❓ Укажите слово: /addword слово");
        return;
    }

    $db  = getDB();
    $esc = addslashes($word);
    $exists = $db->query("SELECT id FROM automod_words WHERE chat_id = $chatId AND word = '$esc'")->fetchColumn();
    if ($exists) {
        sendReply($chatId, $replyTo, "⚠️ Слово «{$word}» уже в списке.");
        return;
    }

    $db->exec("INSERT INTO automod_words (chat_id, word) VALUES ($chatId, '$esc')");
    sendReply($chatId, $replyTo, "✅ Слово «{$word}» добавлено в список запрещённых.");
}

function cmdDelWord(int $chatId, int $replyTo, int $adminId, array $args): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Управлять запрещёнными словами может только владелец или администратор с полными правами.");
        return;
    }

    $word = mb_strtolower(trim(implode(' ', $args)));
    if ($word === '') {
        sendReply($chatId, $replyTo, "❓ Укажите слово: /delword слово");
        return;
    }

    $db  = getDB();
    $esc = addslashes($word);
    $exists = $db->query("SELECT id FROM automod_words WHERE chat_id = $chatId AND word = '$esc'")->fetchColumn();
    if (!$exists) {
        sendReply($chatId, $replyTo, "⚠️ Слово «{$word}» не найдено в списке.");
        return;
    }

    $db->exec("DELETE FROM automod_words WHERE chat_id = $chatId AND word = '$esc'");
    sendReply($chatId, $replyTo, "🗑 Слово «{$word}» удалено из списка запрещённых.");
}

function cmdListWords(int $chatId, int $replyTo, int $adminId): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Только для владельца или администратора с полными правами.");
        return;
    }

    $db    = getDB();
    $res   = $db->query("SELECT word FROM automod_words WHERE chat_id = $chatId ORDER BY word");
    $words = [];
    if ($res) {
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $words[] = htmlspecialchars($row['word'], ENT_XML1);
        }
    }

    if (!$words) {
        sendReply($chatId, $replyTo, "📋 Список запрещённых слов пуст.\nДобавить: /addword слово");
        return;
    }

    $numbered = array_map(fn($i, $w) => ($i + 1) . ". {$w}", array_keys($words), $words);
    sendReply($chatId, $replyTo, "📋 <b>Запрещённые слова (" . count($words) . "):</b>\n" . implode("\n", $numbered));
}

// ─────────────────────────────────────────────
// БЕЛЫЙ СПИСОК АВТО-МОДЕРАЦИИ
// ─────────────────────────────────────────────

function cmdWhitelist(int $chatId, int $replyTo, int $adminId, ?array $target): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Управлять белым списком может только владелец или администратор с полными правами.");
        return;
    }

    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /whitelist @username");
        return;
    }

    $targetId = $target['id'];
    $nameLink = formatUserName($target);
    $db       = getDB();

    $exists = $db->query(
        "SELECT 1 FROM automod_whitelist WHERE user_id = $targetId AND chat_id = $chatId"
    )->fetchColumn();
    if ($exists) {
        sendReply($chatId, $replyTo, "⚠️ Пользователь {$nameLink} уже в белом списке.");
        return;
    }

    $db->exec("INSERT OR IGNORE INTO automod_whitelist (user_id, chat_id) VALUES ($targetId, $chatId)");
    sendReply($chatId, $replyTo, "🛡 Пользователь {$nameLink} добавлен в белый список авто-модерации.");
}

function cmdUnwhitelist(int $chatId, int $replyTo, int $adminId, ?array $target): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Управлять белым списком может только владелец или администратор с полными правами.");
        return;
    }

    if (!$target) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение или укажите: /unwhitelist @username");
        return;
    }

    $targetId = $target['id'];
    $nameLink = formatUserName($target);
    $db       = getDB();

    $exists = $db->query(
        "SELECT 1 FROM automod_whitelist WHERE user_id = $targetId AND chat_id = $chatId"
    )->fetchColumn();
    if (!$exists) {
        sendReply($chatId, $replyTo, "⚠️ Пользователь {$nameLink} не найден в белом списке.");
        return;
    }

    $db->exec("DELETE FROM automod_whitelist WHERE user_id = $targetId AND chat_id = $chatId");
    sendReply($chatId, $replyTo, "🔓 Пользователь {$nameLink} удалён из белого списка авто-модерации.");
}

function cmdWhitelistShow(int $chatId, int $replyTo, int $adminId): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Только для владельца или администратора с полными правами.");
        return;
    }

    $db  = getDB();
    $res = $db->query("
        SELECT w.user_id, u.name, u.username
        FROM automod_whitelist w
        LEFT JOIN users u ON u.user_id = w.user_id AND u.chat_id = w.chat_id
        WHERE w.chat_id = $chatId
        ORDER BY u.name
    ");

    $lines = [];
    $num   = 1;
    if ($res) {
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $name  = htmlspecialchars($row['name'] ?? "ID {$row['user_id']}", ENT_XML1);
            $uname = $row['username'] ?? '';
            $link  = formatUserName(['id' => (int)$row['user_id'], 'name' => $name, 'username' => $uname]);
            $lines[] = "{$num}. {$link}";
            $num++;
        }
    }

    if (!$lines) {
        sendReply($chatId, $replyTo, "🛡 Белый список авто-модерации пуст.\nДобавить: /whitelist @user");
        return;
    }

    sendReply($chatId, $replyTo,
        "🛡 <b>Белый список авто-модерации (" . count($lines) . "):</b>\n" . implode("\n", $lines)
    );
}

// ─────────────────────────────────────────────
// АМНИСТИЯ — снимает баны со всех участников чата
// ─────────────────────────────────────────────
function cmdAmnesty(int $chatId, int $replyTo, int $adminId): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Амнистия доступна только владельцу или администратору с полными правами.");
        return;
    }

    $db = getDB();

    // Собираем всех известных пользователей из БД
    $res     = $db->query("SELECT DISTINCT user_id FROM users WHERE chat_id = $chatId");
    $tempRes = $db->query("SELECT DISTINCT user_id FROM tempbans WHERE chat_id = $chatId");

    $candidates = [];
    if ($res) {
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $candidates[(int)$row['user_id']] = true;
        }
    }
    if ($tempRes) {
        while ($row = $tempRes->fetch(PDO::FETCH_ASSOC)) {
            $candidates[(int)$row['user_id']] = true;
        }
    }

    if (!$candidates) {
        sendReply($chatId, $replyTo, "ℹ️ В базе нет пользователей для проверки.");
        return;
    }

    $unbanned = 0;
    foreach (array_keys($candidates) as $uid) {
        $member = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $uid]);
        if (($member['result']['status'] ?? '') === 'kicked') {
            $res2 = apiRequest('unbanChatMember', [
                'chat_id'        => $chatId,
                'user_id'        => $uid,
                'only_if_banned' => true,
            ]);
            if ($res2['ok'] ?? false) {
                $unbanned++;
            }
        }
    }

    // Очищаем все tempbans и bans для этого чата
    $db->exec("DELETE FROM tempbans WHERE chat_id = $chatId");
    $db->exec("DELETE FROM bans WHERE chat_id = $chatId");

    if ($unbanned > 0) {
        sendReply($chatId, $replyTo, "✅ Амнистия выполнена! Снято банов: <b>{$unbanned}</b>");
    } else {
        sendReply($chatId, $replyTo, "ℹ️ Забаненных пользователей не найдено.");
    }
}

// ─────────────────────────────────────────────
// БАНЛИСТ — список забаненных пользователей
// ─────────────────────────────────────────────
function cmdBanlist(int $chatId, int $replyTo): void {
    $db = getDB();

    $res     = $db->query("SELECT DISTINCT user_id FROM users WHERE chat_id = $chatId");
    $tempRes = $db->query("SELECT user_id, until FROM tempbans WHERE chat_id = $chatId");
    $banRes  = $db->query("SELECT user_id, banned_by, reason, banned_at FROM bans WHERE chat_id = $chatId");

    $tempbans = [];
    if ($tempRes) {
        while ($row = $tempRes->fetch(PDO::FETCH_ASSOC)) {
            $tempbans[(int)$row['user_id']] = (int)$row['until'];
        }
    }

    $banInfo = [];
    if ($banRes) {
        while ($row = $banRes->fetch(PDO::FETCH_ASSOC)) {
            $banInfo[(int)$row['user_id']] = $row;
        }
    }

    $candidates = [];
    if ($res) {
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $candidates[(int)$row['user_id']] = true;
        }
    }
    foreach (array_keys($tempbans) as $uid) {
        $candidates[$uid] = true;
    }
    foreach (array_keys($banInfo) as $uid) {
        $candidates[$uid] = true;
    }

    if (!$candidates) {
        sendReply($chatId, $replyTo, "ℹ️ В базе нет пользователей.");
        return;
    }

    $lines = [];
    $num   = 1;
    foreach (array_keys($candidates) as $uid) {
        $member = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $uid]);
        if (($member['result']['status'] ?? '') !== 'kicked') continue;

        $userRow  = $db->query("SELECT name, username FROM users WHERE user_id = $uid AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        $name     = htmlspecialchars($userRow['name'] ?? "пользователь {$uid}", ENT_XML1);
        $uname    = $userRow['username'] ?? '';
        $nameLink = formatUserName(['id' => $uid, 'name' => $name, 'username' => $uname]);

        // Тип бана
        if (isset($tempbans[$uid]) && $tempbans[$uid] > time()) {
            $untilStr = date('d.m.Y H:i', $tempbans[$uid]);
            $banType  = "⏳ до {$untilStr}";
        } else {
            $banType = "🔴 навсегда";
        }

        // Кто выдал бан
        $info = $banInfo[$uid] ?? null;
        if ($info) {
            $byId     = (int)$info['banned_by'];
            $byRow    = $db->query("SELECT name, username FROM users WHERE user_id = $byId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
            $byName   = htmlspecialchars($byRow['name'] ?? "ID {$byId}", ENT_XML1);
            $byUname  = $byRow['username'] ?? '';
            $byLink   = formatUserName(['id' => $byId, 'name' => $byName, 'username' => $byUname]);
            $reason   = htmlspecialchars($info['reason'] ?: 'Не указана', ENT_XML1);
            $dateStr  = date('d.m.Y', (int)$info['banned_at']);
            $byStr    = "\n   👮 Выдал: {$byLink} ({$dateStr})\n   📝 Причина: {$reason}";
        } else {
            $byStr = '';
        }

        $lines[] = "{$num}. {$nameLink} — {$banType}{$byStr}";
        $num++;
    }

    if (!$lines) {
        sendReply($chatId, $replyTo, "✅ Забаненных пользователей нет.");
        return;
    }

    $count = count($lines);
    $text  = "🚫 <b>Банлист ({$count}):</b>\n\n" . implode("\n\n", $lines);
    sendReply($chatId, $replyTo, $text);
}

function isBanned(int $chatId, int $userId): bool {
    $result = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    return ($result['result']['status'] ?? '') === 'kicked';
}

function isMuted(int $chatId, int $userId): bool {
    $db  = getDB();
    $row = $db->query("SELECT until FROM mutes WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);

    if (!$row) return false;

    $until = (int)$row['until'];

    if ($until === 0) return true;
    if ($until > time()) return true;

    $db->exec("DELETE FROM mutes WHERE user_id = $userId AND chat_id = $chatId");
    apiRequest('restrictChatMember', [
        'chat_id'     => $chatId,
        'user_id'     => $userId,
        'permissions' => defaultPermissions(),
    ]);

    // Уведомляем чат об истечении мута
    $userRow  = $db->query("SELECT name, username FROM users WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $nameLink = $userRow
        ? formatUserName(['id' => $userId, 'name' => htmlspecialchars($userRow['name'], ENT_XML1), 'username' => $userRow['username'] ?? ''])
        : "<a href=\"tg://user?id={$userId}\">пользователь</a>";
    sendMessage($chatId, "🔊 Срок мута пользователя {$nameLink} истёк. Ограничения сняты.");

    return false;
}

function getTarget(array $msg, array $args, int $chatId): ?array {
    // Способ 1: реплай на сообщение
    if (isset($msg['reply_to_message'])) {
        $from = $msg['reply_to_message']['from'];
        return [
            'id'       => $from['id'],
            'name'     => htmlspecialchars(trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')), ENT_XML1),
            'username' => $from['username'] ?? '',
        ];
    }

    if (empty($args[0])) return null;

    $arg = $args[0];

    // Способ 2: @username — ищем в нашей БД
    if (str_starts_with($arg, '@')) {
        $username = strtolower(ltrim($arg, '@'));
        $db       = getDB();
        $row      = $db->query("SELECT user_id, name FROM users WHERE username = '$username' AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'id'       => (int)$row['user_id'],
                'name'     => htmlspecialchars($row['name'], ENT_XML1),
                'username' => $username,
            ];
        }
        return null;
    }

    // Способ 3: числовой ID
    if (is_numeric($arg)) {
        $userId = (int)$arg;
        $db     = getDB();
        $row    = $db->query("SELECT name, username FROM users WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        return [
            'id'       => $userId,
            'name'     => htmlspecialchars($row['name'] ?? "пользователь {$userId}", ENT_XML1),
            'username' => $row['username'] ?? '',
        ];
    }

    return null;
}

// Проверяет, что администратор имеет все ключевые права (как у владельца)
function isFullAdmin(int $chatId, int $userId): bool {
    $result = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    $member = $result['result'] ?? [];
    if (($member['status'] ?? '') !== 'administrator') return false;

    $fullRights = [
        'can_manage_chat', 'can_delete_messages', 'can_restrict_members',
        'can_invite_users', 'can_pin_messages', 'can_manage_video_chats',
        'can_change_info', 'can_promote_members',
    ];
    foreach ($fullRights as $right) {
        if (empty($member[$right])) return false;
    }
    return true;
}

function isChatOwner(int $chatId, int $userId): bool {
    $result = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    return ($result['result']['status'] ?? '') === 'creator';
}

function isChatAdmin(int $chatId, int $userId): bool {
    $result = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    $status = $result['result']['status'] ?? '';
    return in_array($status, ['administrator', 'creator'], true);
}

// Ранг администратора: владелец = 1000, админ = кол-во прав, остальные = 0
function getAdminRank(int $chatId, int $userId): int {
    $result = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    $member = $result['result'] ?? [];
    $status = $member['status'] ?? '';

    if ($status === 'creator') return 1000;
    if ($status !== 'administrator') return 0;

    $rights = [
        'can_manage_chat', 'can_delete_messages', 'can_restrict_members',
        'can_invite_users', 'can_pin_messages', 'can_manage_video_chats',
        'can_change_info', 'can_post_messages', 'can_edit_messages',
        'can_promote_members', 'can_post_stories', 'can_edit_stories',
        'can_delete_stories', 'can_manage_topics',
    ];
    $rank = 0;
    foreach ($rights as $r) {
        if (!empty($member[$r])) $rank++;
    }
    return max($rank, 1); // минимум 1, чтобы отличать от обычного участника
}

// Возвращает true и отправляет ошибку, если нельзя наказать цель
function isAdminProtected(int $chatId, int $targetId, int $adminId, int $replyTo, string $action): bool {
    $targetRank = getAdminRank($chatId, $targetId);
    if ($targetRank === 0) return false;
    $adminRank = getAdminRank($chatId, $adminId);
    if ($targetRank >= $adminRank) {
        sendReply($chatId, $replyTo, "⛔ Нельзя применить «{$action}»: у цели равные или более высокие права администратора.");
        return true;
    }
    return false;
}

function defaultPermissions(): array {
    return [
        'can_send_messages'         => true,
        'can_send_audios'           => true,
        'can_send_documents'        => true,
        'can_send_photos'           => true,
        'can_send_videos'           => true,
        'can_send_video_notes'      => true,
        'can_send_voice_notes'      => true,
        'can_send_polls'            => true,
        'can_send_other_messages'   => true,
        'can_add_web_page_previews' => true,
        'can_change_info'           => false,
        'can_invite_users'          => true,
        'can_pin_messages'          => false,
    ];
}

function parseCommand(string $text): array {
    $parts   = explode(' ', $text);
    $command = strtolower($parts[0]);
    $command = preg_replace('/@\w+$/', '', $command);
    $args    = array_slice($parts, 1);
    return [$command, $args];
}

// ─────────────────────────────────────────────
// РЕГИСТРАЦИЯ КОМАНД БОТА (кнопка "/" в чатах)
// ─────────────────────────────────────────────
function registerBotCommands(): void {
    // Команды для всех участников (видны в любом чате)
    $publicCommands = [
        ['command' => 'help',   'description' => '📋 Список команд бота'],
        ['command' => 'rules',  'description' => '📜 Правила чата'],
        ['command' => 'admins', 'description' => '🛡 Список администраторов'],
        ['command' => 'top',    'description' => '📊 Топ участников'],
    ];

    // Команды для администраторов (видны только в группах)
    $adminCommands = [
        ['command' => 'help',      'description' => '📋 Список команд бота'],
        ['command' => 'rules',     'description' => '📜 Правила чата'],
        ['command' => 'admins',    'description' => '🛡 Список администраторов'],
        ['command' => 'top',       'description' => '📊 Топ участников (репутация/активность)'],
        ['command' => 'rep',       'description' => '⭐ Дать +репутацию (ответом на сообщение)'],
        ['command' => 'mute',      'description' => '🔇 Замутить пользователя'],
        ['command' => 'unmute',    'description' => '🔊 Снять мут'],
        ['command' => 'ban',       'description' => '🚫 Заблокировать навсегда'],
        ['command' => 'unban',     'description' => '✅ Разблокировать'],
        ['command' => 'kick',      'description' => '👢 Выгнать из чата'],
        ['command' => 'tempban',   'description' => '⏳ Временный бан'],
        ['command' => 'warn',      'description' => '⚠️ Выдать предупреждение'],
        ['command' => 'info',      'description' => '🔍 Информация о пользователе'],
        ['command' => 'banlist',   'description' => '📋 Список заблокированных'],
        ['command' => 'amnesty',   'description' => '🕊 Снять все баны'],
        ['command' => 'promote',   'description' => '⬆️ Назначить администратором'],
        ['command' => 'demote',    'description' => '⬇️ Снять права администратора'],
        ['command' => 'addrule',   'description' => '➕ Добавить правило'],
        ['command' => 'delrule',   'description' => '🗑 Удалить правила'],
        ['command' => 'automod',   'description' => '🤖 Панель авто-модерации'],
        ['command' => 'addword',   'description' => '🚷 Добавить запрещённое слово'],
        ['command' => 'delword',   'description' => '✏️ Удалить запрещённое слово'],
        ['command' => 'words',        'description' => '📝 Список запрещённых слов'],
        ['command' => 'whitelist',    'description' => '🛡 Добавить в белый список авто-мода'],
        ['command' => 'unwhitelist',  'description' => '🔓 Убрать из белого списка авто-мода'],
        ['command' => 'whitelistshow','description' => '📋 Белый список авто-модерации'],
        ['command' => 'remind',       'description' => '📝 Заметка с напоминанием'],
        ['command' => 'antiraid',     'description' => '🛡 Управление анти-рейдом'],
        ['command' => 'purge',        'description' => '🧹 Удалить последние сообщения'],
    ];

    // Устанавливаем команды для всех чатов (публичные)
    apiRequest('setMyCommands', [
        'commands' => $publicCommands,
        'scope'    => ['type' => 'all_group_chats'],
    ]);

    // Устанавливаем команды для личных сообщений (только /start)
    apiRequest('setMyCommands', [
        'commands' => [
            ['command' => 'start', 'description' => '▶️ Запустить бота'],
        ],
        'scope' => ['type' => 'all_private_chats'],
    ]);
}

// ─────────────────────────────────────────────
// РЕГИСТРАЦИЯ КОМАНД ВЛАДЕЛЬЦА В МЕНЮ "/"
// ─────────────────────────────────────────────
function registerOwnerCommands(): void {
    // scope chat_member — команды видны только конкретному пользователю в личке
    apiRequest('setMyCommands', [
        'commands' => [
            ['command' => 'panel', 'description' => '👑 Панель владельца'],
            ['command' => 'start', 'description' => '▶️ Запустить бота'],
        ],
        'scope' => [
            'type'    => 'chat',
            'chat_id' => OWNER_ID,
        ],
    ]);
}

// ─────────────────────────────────────────────
// СИСТЕМА РЕПОРТОВ
// ─────────────────────────────────────────────

/**
 * !Репорт — команда для всех пользователей, ответом на сообщение
 * Причина с новой строки или в той же строке после "!Репорт"
 */
function cmdReport(array $msg, int $chatId, int $messageId, int $userId, string $inlineReason): void {
    $db = getDB();

    // Команда должна быть ответом на сообщение
    $replyMsg = $msg['reply_to_message'] ?? null;
    if (!$replyMsg) {
        sendReply($chatId, $messageId, "❓ Команда <b>!Репорт</b> должна быть написана <b>ответом на сообщение</b>, на которое подаётся жалоба.\n\n📝 Причина указывается с новой строки или после команды:\n<code>!Репорт\nСпамер</code>");
        return;
    }

    $reportedUser = $replyMsg['from'] ?? null;
    if (!$reportedUser) {
        sendReply($chatId, $messageId, "❌ Не удалось определить автора сообщения.");
        return;
    }

    $reportedId = $reportedUser['id'];

    // Нельзя репортить себя
    if ($reportedId === $userId) {
        sendReply($chatId, $messageId, "❌ Нельзя подать жалобу на самого себя.");
        return;
    }

    // Нельзя репортить ботов
    if (!empty($reportedUser['is_bot'])) {
        sendReply($chatId, $messageId, "❌ Нельзя подать жалобу на бота.");
        return;
    }

    // Нельзя репортить администраторов
    if (isChatAdmin($chatId, $reportedId)) {
        sendReply($chatId, $messageId, "❌ Нельзя подать жалобу на администратора.");
        return;
    }

    // Извлекаем причину: из текста сообщения после переноса строки или из inline
    $fullText = trim($msg['text'] ?? '');
    $reason   = '';
    if (str_contains($fullText, "\n")) {
        $lines  = explode("\n", $fullText, 2);
        $reason = trim($lines[1]);
    }
    if ($reason === '' && $inlineReason !== '') {
        $reason = $inlineReason;
    }
    $reason = $reason ?: 'Не указана';

    // Сохраняем репорт
    $reportedMsgId = $replyMsg['message_id'];
    $now           = time();
    $rsnEsc        = addslashes($reason);
    $db->exec("
        INSERT INTO reports (chat_id, reported_id, reporter_id, message_id, reason, reviewed, created_at)
        VALUES ($chatId, $reportedId, $userId, $reportedMsgId, '$rsnEsc', 0, $now)
    ");
    $reportId = $db->lastInsertId();

    // Формируем имена
    $reporterRow  = $db->query("SELECT name, username FROM users WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $reportedRow  = $db->query("SELECT name, username FROM users WHERE user_id = $reportedId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);

    $reporterName = htmlspecialchars($reporterRow['name'] ?? ($msg['from']['first_name'] ?? 'Пользователь'), ENT_XML1);
    $reportedName = htmlspecialchars($reportedRow['name'] ?? ($reportedUser['first_name'] ?? 'Пользователь'), ENT_XML1);

    $reporterLink = "<a href=\"tg://user?id={$userId}\">{$reporterName}</a>";
    $reportedLink = "<a href=\"tg://user?id={$reportedId}\">{$reportedName}</a>";

    // Кнопки быстрых действий
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '⚡ Наказать',  'callback_data' => "report_action_pick:{$reportId}:{$reportedId}"],
                ['text' => '— Смс',        'callback_data' => "report_delmsg:{$reportId}:{$reportedId}:{$reportedMsgId}"],
            ],
            [
                ['text' => '✅ Отметить проверенным', 'callback_data' => "report_reviewed:{$reportId}"],
            ],
        ],
    ];

    $reportText = "❗ <b>Жалоба на {$reportedLink}</b>\n"
        . "🆔 @" . htmlspecialchars($reportedRow['username'] ?? '', ENT_XML1) . " / <code>{$reportedId}</code>\n"
        . "👤 Отправил: {$reporterLink}\n"
        . "💬 {$reason}";

    // Определяем куда отправить жалобу
    $settings   = $db->query("SELECT target_chat FROM report_settings WHERE chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $targetChat = $settings ? (int)$settings['target_chat'] : 0;

    if ($targetChat && $targetChat !== $chatId) {
        // Пересылаем оригинальное сообщение в admin-chat
        apiRequest('forwardMessage', [
            'chat_id'      => $targetChat,
            'from_chat_id' => $chatId,
            'message_id'   => $reportedMsgId,
        ]);
        sendMessage($targetChat, $reportText, $keyboard);
    } else {
        // Отправляем жалобу в тот же чат ответом на оригинальное сообщение
        apiRequest('sendMessage', [
            'chat_id'             => $chatId,
            'text'                => $reportText,
            'parse_mode'          => 'HTML',
            'reply_to_message_id' => $reportedMsgId,
            'reply_markup'        => $keyboard,
        ]);
    }

    // Подтверждение отправителю (удаляем через ~5 сек — просто тихое уведомление)
    sendReply($chatId, $messageId, "✅ <b>Жалоба на {$reportedLink} отправлена.</b>");
}

/**
 * Репортлист — список нерассмотренных жалоб
 */
function cmdReportList(int $chatId, int $messageId, int $userId): void {
    if (!isChatAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Эта команда доступна только администраторам.");
        return;
    }

    $db      = getDB();
    $reports = $db->query(
        "SELECT r.id, r.reported_id, r.reporter_id, r.reason, r.created_at,
                u1.name AS reported_name, u1.username AS reported_uname,
                u2.name AS reporter_name
         FROM reports r
         LEFT JOIN users u1 ON u1.user_id = r.reported_id AND u1.chat_id = r.chat_id
         LEFT JOIN users u2 ON u2.user_id = r.reporter_id AND u2.chat_id = r.chat_id
         WHERE r.chat_id = $chatId AND r.reviewed = 0
         ORDER BY r.created_at DESC
         LIMIT 20"
    );

    if (!$reports) {
        sendReply($chatId, $messageId, "📋 Нет нерассмотренных жалоб.");
        return;
    }

    $list = [];
    while ($row = $reports->fetch(PDO::FETCH_ASSOC)) {
        $rId       = $row['id'];
        $rName     = htmlspecialchars($row['reported_name'] ?? "ID {$row['reported_id']}", ENT_XML1);
        $rLink     = "<a href=\"tg://user?id={$row['reported_id']}\">{$rName}</a>";
        $sName     = htmlspecialchars($row['reporter_name'] ?? "ID {$row['reporter_id']}", ENT_XML1);
        $date      = date('d.m H:i', $row['created_at']);
        $reason    = htmlspecialchars(mb_substr($row['reason'], 0, 60), ENT_XML1);
        $list[]    = "#{$rId} {$rLink} — {$reason}\n   👤 {$sName} · {$date}";
    }

    if (empty($list)) {
        sendReply($chatId, $messageId, "📋 Нет нерассмотренных жалоб.");
        return;
    }

    $text = "📋 <b>Нерассмотренные жалобы:</b>\n\n" . implode("\n\n", $list);
    sendReply($chatId, $messageId, $text);
}

/**
 * !Сброс репортов — очищает список жалоб
 */
function cmdReportReset(int $chatId, int $messageId, int $userId): void {
    if (!isChatAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Эта команда доступна только администраторам.");
        return;
    }

    $db = getDB();
    $db->exec("DELETE FROM reports WHERE chat_id = $chatId");
    sendReply($chatId, $messageId, "🗑 Все жалобы удалены.");
}

/**
 * +Репорты из чата {код} — привязывает чат к системе репортов этого admin-чата
 */
function cmdReportFromChat(int $chatId, int $messageId, int $userId, array $args): void {
    if (!isChatAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Эта команда доступна только администраторам.");
        return;
    }

    $code = trim($args[0] ?? '');
    if (!$code) {
        sendReply($chatId, $messageId, "❓ Укажите код чата: <code>+Репорты из чата КОД</code>");
        return;
    }

    // Ищем чат по коду (последние символы ID или хэш)
    $db  = getDB();
    // Сохраняем: жалобы из чата $code будут приходить сюда
    $codeEsc = addslashes($code);
    // Сохраняем как внешний ключ: тот чат шлёт репорты в этот
    $db->exec("
        INSERT OR IGNORE INTO report_settings (chat_id, target_chat) VALUES (0, $chatId)
        ON CONFLICT(chat_id) DO UPDATE SET target_chat = $chatId
    ");

    sendReply($chatId, $messageId, "✅ Жалобы из чата с кодом <code>{$code}</code> будут направляться в этот чат.");
}

/**
 * +Репорты из сетки — все чаты сетки присылают жалобы сюда
 */
function cmdReportFromNetwork(int $chatId, int $messageId, int $userId): void {
    if (!isChatAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Эта команда доступна только администраторам.");
        return;
    }

    sendReply($chatId, $messageId, "✅ Все чаты сетки будут направлять жалобы в этот чат.\n\n<i>💡 В каждом чате сетки используйте +Репорты сюда, чтобы направить репорты в этот admin-чат.</i>");
}

/**
 * +Репорты сюда — установить текущий чат как место приёма жалоб
 */
function cmdReportHereSetup(int $chatId, int $messageId, int $userId): void {
    if (!isChatAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Эта команда доступна только администраторам.");
        return;
    }

    $db = getDB();
    $db->exec("
        INSERT OR IGNORE INTO report_settings (chat_id, target_chat) VALUES ($chatId, $chatId)
        ON CONFLICT(chat_id) DO UPDATE SET target_chat = $chatId
    ");

    sendReply($chatId, $messageId, "✅ Этот чат установлен как место приёма всех жалоб.");
}

/**
 * Обработка кнопок-действий по репорту
 */
function handleReportCallback(string $callbackId, int $chatId, int $messageId, int $adminId, string $data): void {
    $db = getDB();

    // report_reviewed:{reportId}
    if (preg_match('/^report_reviewed:(\d+)$/', $data, $m)) {
        $reportId = (int)$m[1];

        $report = $db->query("SELECT * FROM reports WHERE id = $reportId")->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ Жалоба не найдена', 'show_alert' => true]);
            return;
        }

        $db->exec("UPDATE reports SET reviewed = 1, reviewer_id = $adminId WHERE id = $reportId");

        $adminRow      = $db->query("SELECT name, username FROM users WHERE user_id = $adminId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        $adminName     = htmlspecialchars($adminRow['name'] ?? "Администратор", ENT_XML1);
        $adminLink     = "<a href=\"tg://user?id={$adminId}\">{$adminName}</a>";

        $reportedId    = (int)$report['reported_id'];
        $reporterId    = (int)$report['reporter_id'];
        $reportedRow   = $db->query("SELECT name, username FROM users WHERE user_id = $reportedId")->fetch(PDO::FETCH_ASSOC);
        $reporterRow   = $db->query("SELECT name, username FROM users WHERE user_id = $reporterRow")->fetch(PDO::FETCH_ASSOC);
        $reportedName  = htmlspecialchars($reportedRow['name'] ?? "ID {$reportedId}", ENT_XML1);
        $reporterRow2  = $db->query("SELECT name FROM users WHERE user_id = $reporterRow")->fetch(PDO::FETCH_ASSOC);
        $reporterName  = '';
        $reporterRow3  = $db->query("SELECT name FROM users WHERE user_id = $reporterId")->fetch(PDO::FETCH_ASSOC);
        $reporterName  = htmlspecialchars($reporterRow3['name'] ?? "ID {$reporterId}", ENT_XML1);
        $reportedLink  = "<a href=\"tg://user?id={$reportedId}\">{$reportedName}</a>";
        $reporterLink  = "<a href=\"tg://user?id={$reporterRow3['name']}\">{$reporterName}</a>";
        $reason        = htmlspecialchars($report['reason'], ENT_XML1);

        $newText = "✅ <b>Жалоба на {$reportedLink} проверена.</b>\n"
            . "🆔 <code>{$reportedId}</code>\n"
            . "👤 Отправил: {$reporterName}\n"
            . "🛡 Проверил: {$adminLink}\n"
            . "💬 {$reason}";

        apiRequest('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $newText,
            'parse_mode'   => 'HTML',
        ]);

        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '✅ Жалоба отмечена как проверенная']);
        return;
    }

    // report_action_pick:{reportId}:{targetId} — выбор действия: Мут или Бан
    if (preg_match('/^report_action_pick:(\d+):(\d+)$/', $data, $m)) {
        [, $reportId, $targetId] = $m;
        $reportId = (int)$reportId;
        $targetId = (int)$targetId;

        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔇 Мут',  'callback_data' => "report_ask_time:{$reportId}:{$targetId}:mute"],
                    ['text' => '🔴 Бан',  'callback_data' => "report_ask_time:{$reportId}:{$targetId}:ban"],
                ],
                [
                    ['text' => '◀️ Назад', 'callback_data' => "report_back:{$reportId}:{$targetId}"],
                ],
            ],
        ];
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => $keyboard,
        ]);
        return;
    }

    // report_ask_time:{reportId}:{targetId}:{action} — запрашиваем время через ForceReply
    if (preg_match('/^report_ask_time:(\d+):(\d+):(mute|ban)$/', $data, $m)) {
        [, $reportId, $targetId, $action] = $m;
        $reportId = (int)$reportId;
        $targetId = (int)$targetId;

        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);

        $actionWord = $action === 'mute' ? '🔇 мут' : '🔴 бан';
        $promptText = "⏱ Укажите время {$actionWord} для <a href=\"tg://user?id={$targetId}\">пользователя</a>.\n\n"
            . "Форматы: <code>2m</code> (минуты), <code>1h</code> (часы), <code>7d</code> (дни), <code>0</code> — навсегда.";

        $result = apiRequest('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $promptText,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['force_reply' => true, 'selective' => false],
        ]);

        if ($result['ok'] ?? false) {
            $promptMsgId = (int)$result['result']['message_id'];
            $now = time();
            $db->exec("
                INSERT OR REPLACE INTO pending_actions
                    (prompt_msg_id, chat_id, admin_id, action, report_id, target_id, orig_msg_id, created_at)
                VALUES ($promptMsgId, $chatId, $adminId, '$action', $reportId, $targetId, $messageId, $now)
            ");
        }
        return;
    }

    // report_ban:{reportId}:{targetId}
    if (preg_match('/^report_ban:(\d+):(\d+)$/', $data, $m)) {
        [, $reportId, $targetId] = $m;
        $reportId = (int)$reportId;
        $targetId = (int)$targetId;

        // Показываем клавиатуру выбора времени бана
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '1 ч',    'callback_data' => "report_ban_do:{$reportId}:{$targetId}:1"],
                    ['text' => '6 ч',    'callback_data' => "report_ban_do:{$reportId}:{$targetId}:6"],
                    ['text' => '12 ч',   'callback_data' => "report_ban_do:{$reportId}:{$targetId}:12"],
                    ['text' => '1 день', 'callback_data' => "report_ban_do:{$reportId}:{$targetId}:24"],
                ],
                [
                    ['text' => '3 дня',  'callback_data' => "report_ban_do:{$reportId}:{$targetId}:72"],
                    ['text' => '7 дней', 'callback_data' => "report_ban_do:{$reportId}:{$targetId}:168"],
                    ['text' => '30 дней','callback_data' => "report_ban_do:{$reportId}:{$targetId}:720"],
                    ['text' => '🔴 Навсегда', 'callback_data' => "report_ban_do:{$reportId}:{$targetId}:0"],
                ],
                [
                    ['text' => '◀️ Назад', 'callback_data' => "report_back:{$reportId}:{$targetId}"],
                ],
            ],
        ];
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => $keyboard,
        ]);
        return;
    }

    // report_ban_do:{reportId}:{targetId}:{hours}
    if (preg_match('/^report_ban_do:(\d+):(\d+):(\d+)$/', $data, $m)) {
        [, $reportId, $targetId, $hours] = $m;
        $reportId = (int)$reportId;
        $targetId = (int)$targetId;
        $hours    = (int)$hours;

        $params = ['chat_id' => $chatId, 'user_id' => $targetId];
        if ($hours > 0) {
            $until = time() + $hours * 3600;
            $params['until_date'] = $until;
        }
        $result = apiRequest('banChatMember', $params);
        if ($result['ok'] ?? false) {
            $now = time();
            if ($hours > 0) {
                $db->exec("
                    INSERT OR REPLACE INTO tempbans (user_id, chat_id, until, notified) VALUES ($targetId, $chatId, $until, 0)
                    ON CONFLICT(user_id, chat_id) DO UPDATE SET until = $until, notified = 0
                ");
            }
            $db->exec("
                INSERT OR REPLACE INTO bans (user_id, chat_id, banned_by, reason, banned_at)
                VALUES ($targetId, $chatId, $adminId, 'Репорт', $now)
                ON CONFLICT(user_id, chat_id) DO UPDATE SET banned_by = $adminId, banned_at = $now
            ");
            $db->exec("UPDATE reports SET reviewed = 1, reviewer_id = $adminId WHERE id = $reportId");
            $durationStr = $hours > 0 ? ' на ' . formatHours($hours) : ' навсегда';
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => "🔴 Пользователь заблокирован{$durationStr}"]);
            $reportedRow = $db->query("SELECT name FROM users WHERE user_id = $targetId")->fetch(PDO::FETCH_ASSOC);
            $rName       = htmlspecialchars($reportedRow['name'] ?? "ID {$targetId}", ENT_XML1);
            apiRequest('editMessageReplyMarkup', [
                'chat_id'      => $chatId,
                'message_id'   => $messageId,
                'reply_markup' => ['inline_keyboard' => []],
            ]);
            sendMessage($chatId, "🔴 <a href=\"tg://user?id={$targetId}\">{$rName}</a> заблокирован{$durationStr} по жалобе #{$reportId}");
        } else {
            $cbBanErr = $result['description'] ?? 'неизвестная ошибка';
            if (str_contains($cbBanErr, 'USER_NOT_PARTICIPANT') || str_contains($cbBanErr, 'user not found')) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ Пользователь не состоит в чате', 'show_alert' => true]);
            } elseif (str_contains($cbBanErr, 'administrator')) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '❌ Нельзя заблокировать администратора', 'show_alert' => true]);
            } else {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => "❌ Не удалось заблокировать: $cbBanErr", 'show_alert' => true]);
            }
        }
        return;
    }

    // report_mute:{reportId}:{targetId}
    if (preg_match('/^report_mute:(\d+):(\d+)$/', $data, $m)) {
        [, $reportId, $targetId] = $m;
        $reportId = (int)$reportId;
        $targetId = (int)$targetId;

        // Показываем клавиатуру выбора времени мута
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '15 мин',  'callback_data' => "report_mute_do:{$reportId}:{$targetId}:15"],
                    ['text' => '30 мин',  'callback_data' => "report_mute_do:{$reportId}:{$targetId}:30"],
                    ['text' => '1 час',   'callback_data' => "report_mute_do:{$reportId}:{$targetId}:60"],
                    ['text' => '3 часа',  'callback_data' => "report_mute_do:{$reportId}:{$targetId}:180"],
                ],
                [
                    ['text' => '6 часов', 'callback_data' => "report_mute_do:{$reportId}:{$targetId}:360"],
                    ['text' => '12 часов','callback_data' => "report_mute_do:{$reportId}:{$targetId}:720"],
                    ['text' => '1 день',  'callback_data' => "report_mute_do:{$reportId}:{$targetId}:1440"],
                    ['text' => '7 дней',  'callback_data' => "report_mute_do:{$reportId}:{$targetId}:10080"],
                ],
                [
                    ['text' => '🔇 Навсегда', 'callback_data' => "report_mute_do:{$reportId}:{$targetId}:0"],
                    ['text' => '◀️ Назад',    'callback_data' => "report_back:{$reportId}:{$targetId}"],
                ],
            ],
        ];
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => $keyboard,
        ]);
        return;
    }

    // report_mute_do:{reportId}:{targetId}:{minutes}
    if (preg_match('/^report_mute_do:(\d+):(\d+):(\d+)$/', $data, $m)) {
        [, $reportId, $targetId, $minutes] = $m;
        $reportId = (int)$reportId;
        $targetId = (int)$targetId;
        $minutes  = (int)$minutes;

        $until = $minutes > 0 ? time() + $minutes * 60 : 0;
        $muteRes = apiRequest('restrictChatMember', [
            'chat_id'     => $chatId,
            'user_id'     => $targetId,
            'until_date'  => $until,
            'permissions' => [
                'can_send_messages'         => false,
                'can_send_audios'           => false,
                'can_send_documents'        => false,
                'can_send_photos'           => false,
                'can_send_videos'           => false,
                'can_send_video_notes'      => false,
                'can_send_voice_notes'      => false,
                'can_send_polls'            => false,
                'can_send_other_messages'   => false,
                'can_add_web_page_previews' => false,
                'can_change_info'           => false,
                'can_invite_users'          => false,
                'can_pin_messages'          => false,
            ],
        ]);
        if (!($muteRes['ok'] ?? false)) {
            $muteErr = $muteRes['description'] ?? 'неизвестная ошибка';
            if (str_contains($muteErr, 'administrator')) {
                sendReply($chatId, $replyTo ?? 0, '⛔ Telegram не позволяет мютить администраторов. Сначала снимите права администратора с пользователя, затем выдайте мут.');
            } else {
                sendReply($chatId, $replyTo ?? 0, "⛔ Не удалось выдать мут: {$muteErr}");
            }
            return;
        }
        // Записываем в БД только после успешного ответа от Telegram
        $db->exec("
            INSERT OR REPLACE INTO mutes (user_id, chat_id, until) VALUES ($targetId, $chatId, $until)
            ON CONFLICT(user_id, chat_id) DO UPDATE SET until = $until
        ");
        $db->exec("UPDATE reports SET reviewed = 1, reviewer_id = $adminId WHERE id = $reportId");
        $durationStr = $minutes > 0 ? ' на ' . formatMinutes($minutes) : ' навсегда';
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => "🔇 Пользователь замьючен{$durationStr}"]);
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
        $reportedRow = $db->query("SELECT name, username FROM users WHERE user_id = $targetId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        $rName       = htmlspecialchars($reportedRow['name'] ?? "ID {$targetId}", ENT_XML1);
        $rUname      = $reportedRow['username'] ?? '';
        $rLink       = formatUserName(['id' => $targetId, 'name' => $rName, 'username' => $rUname]);
        sendMessage($chatId, "🔇 {$rLink} замьючен{$durationStr} по жалобе #{$reportId}");
        return;
    }

    // report_back:{reportId}:{targetId} — возврат к основным кнопкам жалобы
    if (preg_match('/^report_back:(\d+):(\d+)$/', $data, $m)) {
        [, $reportId, $targetId] = $m;
        $reportId     = (int)$reportId;
        $targetId     = (int)$targetId;
        $report       = $db->query("SELECT * FROM reports WHERE id = $reportId")->fetch(PDO::FETCH_ASSOC);
        $reportedMsgId = $report ? (int)$report['message_id'] : 0;

        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⚡ Наказать',  'callback_data' => "report_action_pick:{$reportId}:{$targetId}"],
                    ['text' => '— Смс',        'callback_data' => "report_delmsg:{$reportId}:{$targetId}:{$reportedMsgId}"],
                ],
                [
                    ['text' => '✅ Отметить проверенным', 'callback_data' => "report_reviewed:{$reportId}"],
                ],
            ],
        ];
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => $keyboard,
        ]);
        return;
    }

    // report_delmsg:{reportId}:{targetId}:{msgId}
    if (preg_match('/^report_delmsg:(\d+):(\d+):(\d+)$/', $data, $m)) {
        [, $reportId, $targetId, $delMsgId] = $m;
        $reportId = (int)$reportId;
        $delMsgId = (int)$delMsgId;

        deleteMessage($chatId, $delMsgId);
        $db->exec("UPDATE reports SET reviewed = 1, reviewer_id = $adminId WHERE id = $reportId");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => '🗑 Сообщение удалено']);
        apiRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
        return;
    }

    apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
}

// ─────────────────────────────────────────────
// -СМС — удалить сообщение, на которое ответили
// ─────────────────────────────────────────────
function cmdDelMsg(int $chatId, int $replyTo, int $adminId, ?array $msg): void {
    if (!$msg || !isset($msg['reply_to_message'])) {
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение, которое нужно удалить, командой <b>-смс</b>");
        return;
    }

    $targetMsgId = $msg['reply_to_message']['message_id'];
    deleteMessage($chatId, $targetMsgId);
    // Удаляем и само сообщение с командой
    deleteMessage($chatId, $replyTo);
}

// ─────────────────────────────────────────────
// /purge — очистка сообщений (только для администраторов)
// Использование:
//   /purge 100          — удалить последние 100 сообщений в чате
//   /purge @user 50     — удалить последние 50 сообщений указанного пользователя
// Максимум 200 сообщений за один вызов.
// ─────────────────────────────────────────────
function cmdPurge(int $chatId, int $commandMsgId, int $adminId, ?array $target, array $args): void {
    // Только администраторы
    if (!isChatAdmin($chatId, $adminId)) {
        sendReply($chatId, $commandMsgId, "⛔ Команда доступна только администраторам.");
        return;
    }

    // Лимит: не более 200 сообщений за раз
    $purgeMax  = 200;
    $purgeBulk = 100; // deleteMessages принимает до 100 ID за раз

    // Разбираем аргументы самостоятельно, не доверяя $target из getTarget:
    // getTarget воспринимает любое число как user_id, но для /purge число — это всегда счётчик.
    // Пользователь задаётся только через @username, реплай или явный числовой ID после @.
    //
    //   /purge 100          → удалить 100 сообщений в чате
    //   /purge @user 50     → удалить 50 сообщений пользователя
    //   /purge @user        → удалить 50 сообщений пользователя (по умолчанию)
    //   реплай + /purge 30  → удалить 30 сообщений автора реплая

    $targetUserId = null;
    $targetName   = '';
    $targetUsername = '';

    // Реплай имеет приоритет как источник пользователя
    // (передаётся через $msg, но у нас нет $msg здесь — target уже пришёл из getTarget)
    // Поэтому: если $target установлен И первый аргумент НЕ является чистым числом — это юзер.
    // Если первый аргумент — чистое число (без @) — это счётчик, target игнорируем.
    $firstArg = $args[0] ?? '';
    $firstArgIsNumber = ctype_digit($firstArg) && (int)$firstArg > 0;

    if ($target !== null && !$firstArgIsNumber) {
        // target пришёл через реплай или @username — это реальный пользователь
        $targetUserId   = $target['id'] ?? null;
        $targetName     = $target['name'] ?? '';
        $targetUsername = $target['username'] ?? '';
    }
    // Если firstArgIsNumber — target мог быть ошибочно создан из числа, игнорируем его

    // Ищем число в аргументах (первый числовой аргумент)
    $count = 0;
    foreach ($args as $arg) {
        if (ctype_digit($arg) && (int)$arg > 0) {
            $count = (int)$arg;
            break;
        }
    }

    // Значения по умолчанию
    if ($count <= 0) {
        $count = $targetUserId ? 50 : 100;
    }
    $capped = false;
    if ($count > $purgeMax) {
        $count  = $purgeMax;
        $capped = true;
    }

    // Проверяем, что бот имеет право удалять сообщения
    $botInfo   = apiRequest('getMe', []);
    $botId     = (int)($botInfo['result']['id'] ?? 0);
    $botMember = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
    $canDelete = ($botMember['result']['status'] ?? '') === 'administrator'
              && !empty($botMember['result']['can_delete_messages']);

    if (!$canDelete) {
        sendReply($chatId, $commandMsgId, "❌ У бота нет прав на удаление сообщений. Выдайте право <b>Удалять сообщения</b> в настройках администратора.");
        return;
    }

    // Удаляем команду администратора сразу
    deleteMessage($chatId, $commandMsgId);

    if ($targetUserId) {
        // Режим: удалить N последних сообщений конкретного пользователя
        // Берём точные message_id из message_log — только сообщения этого пользователя
        $db  = getDB();
        $res = $db->query("
            SELECT message_id FROM message_log
            WHERE chat_id = $chatId AND user_id = $targetUserId
            ORDER BY message_id DESC
            LIMIT $count
        ");

        $idsRange = [];
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_NUM)) {
                $idsRange[] = (int)$row[0];
            }
        }

        if (empty($idsRange)) {
            sendMessage($chatId, "ℹ️ Нет сохранённых сообщений этого пользователя для удаления.\n<i>Сообщения логируются с момента последнего обновления бота.</i>");
            return;
        }

        // Строим ссылку на пользователя
        $targetLink = formatUserName([
            'id'       => $targetUserId,
            'name'     => $targetName ?: "пользователь",
            'username' => $targetUsername,
        ]);

        // Удаляем пачками по 100
        $deletedTotal = 0;
        foreach (array_chunk($idsRange, $purgeBulk) as $chunk) {
            $res2 = apiRequest('deleteMessages', [
                'chat_id'     => $chatId,
                'message_ids' => $chunk,
            ]);
            if ($res2['ok'] ?? false) {
                $deletedTotal += count($chunk);
                // Удаляем из лога
                $ids = implode(',', $chunk);
                $db->exec("DELETE FROM message_log WHERE chat_id = $chatId AND message_id IN ($ids)");
            }
        }

        $cappedNote = $capped ? "\n⚠️ Запрошено больше 200 — выполнено максимально допустимое количество." : "";
        sendMessage($chatId,
            "🧹 Удалено <b>{$deletedTotal}</b> сообщений пользователя {$targetLink}." . $cappedNote
        );

    } else {
        // ── Режим: удалить последние N сообщений в чате (любые авторы) ──
        // Берём из message_log — там точные ID всех сообщений
        $db  = getDB();
        $res = $db->query("
            SELECT message_id FROM message_log
            WHERE chat_id = $chatId
            ORDER BY message_id DESC
            LIMIT $count
        ");

        $idsRange = [];
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_NUM)) {
                $idsRange[] = (int)$row[0];
            }
        }

        if (empty($idsRange)) {
            sendMessage($chatId, "ℹ️ Нет сохранённых сообщений для удаления.\n<i>Сообщения логируются с момента последнего обновления бота.</i>");
            return;
        }

        foreach (array_chunk($idsRange, $purgeBulk) as $chunk) {
            apiRequest('deleteMessages', [
                'chat_id'     => $chatId,
                'message_ids' => $chunk,
            ]);
            $idList = implode(',', $chunk);
            $db->exec("DELETE FROM message_log WHERE chat_id = $chatId AND message_id IN ($idList)");
        }

        $deleted    = count($idsRange);
        $cappedNote = $capped ? "\n⚠️ Запрошено больше 200 — выполнено максимально допустимое количество." : "";
        sendMessage($chatId,
            "🧹 Удалено <b>{$deleted}</b> сообщений из чата." . $cappedNote
        );
    }

    debugLog("cmdPurge: chatId=$chatId adminId=$adminId targetUserId=" . ($targetUserId ?? 'all') . " count=$count");
}

// ─────────────────────────────────────────────
// -ЧАТ / +ЧАТ — закрыть/открыть чат для участников
// ─────────────────────────────────────────────
function cmdCloseChat(int $chatId, int $replyTo, int $adminId): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Закрывать чат может только владелец или администратор с полными правами.");
        return;
    }

    $result = apiRequest('setChatPermissions', [
        'chat_id'     => $chatId,
        'permissions' => [
            'can_send_messages'         => false,
            'can_send_audios'           => false,
            'can_send_documents'        => false,
            'can_send_photos'           => false,
            'can_send_videos'           => false,
            'can_send_video_notes'      => false,
            'can_send_voice_notes'      => false,
            'can_send_polls'            => false,
            'can_send_other_messages'   => false,
            'can_add_web_page_previews' => false,
            'can_change_info'           => false,
            'can_invite_users'          => false,
            'can_pin_messages'          => false,
            'can_change_info'           => false,
            'can_invite_users'          => false,
            'can_pin_messages'          => false,
        ],
    ]);

    if ($result['ok'] ?? false) {
        sendMessage($chatId, "🔒 <b>Чат закрыт.</b>\nТолько администраторы могут писать сообщения.");
    } else {
        $errDesc = $result['description'] ?? '';
        sendReply($chatId, $replyTo, "❌ Не удалось закрыть чат: " . (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED') ? 'у бота недостаточно прав.' : $errDesc));
    }
}

function cmdOpenChat(int $chatId, int $replyTo, int $adminId): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $replyTo, "⛔ Открывать чат может только владелец или администратор с полными правами.");
        return;
    }

    $result = apiRequest('setChatPermissions', [
        'chat_id'     => $chatId,
        'permissions' => [
            'can_send_messages'         => true,
            'can_send_audios'           => true,
        'can_send_documents'        => true,
        'can_send_photos'           => true,
        'can_send_videos'           => true,
        'can_send_video_notes'      => true,
        'can_send_voice_notes'      => true,
            'can_send_polls'            => true,
            'can_send_other_messages'   => true,
            'can_add_web_page_previews' => true,
            'can_change_info'           => false,
            'can_invite_users'          => true,
            'can_pin_messages'          => false,
        ],
    ]);

    if ($result['ok'] ?? false) {
        sendMessage($chatId, "🔓 <b>Чат открыт.</b>\nВсе участники снова могут писать сообщения.");
    } else {
        $errDesc = $result['description'] ?? '';
        sendReply($chatId, $replyTo, "❌ Не удалось открыть чат: " . (str_contains($errDesc, 'not enough rights') || str_contains($errDesc, 'CHAT_ADMIN_REQUIRED') ? 'у бота недостаточно прав.' : $errDesc));
    }
}

// ─────────────────────────────────────────────
// /setwelcome — задать шаблон приветствия
// /welcomeoff — отключить приветствие
// /welcomeon  — включить приветствие
// ─────────────────────────────────────────────
function cmdSetWelcome(int $chatId, int $messageId, int $userId, array $args, ?array $msg): void {
    if (!isChatOwner($chatId, $userId) && !isFullAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Настраивать приветствие может только владелец или администратор с полными правами.");
        return;
    }

    // Текст берётся из аргументов команды. Поддерживает многострочный текст после команды.
    $rawText = trim($msg['text'] ?? '');
    // Убираем первое слово (/setwelcome или его вариант с @bot)
    $template = trim(preg_replace('/^\/setwelcome\S*\s*/iu', '', $rawText));

    if ($template === '') {
        sendReply($chatId, $messageId,
            "❓ Укажи текст приветствия после команды.\n\n"
            . "Пример:\n<code>/setwelcome Привет, {name}! Добро пожаловать в чат 🎉</code>\n\n"
            . "📌 <b>{name}</b> — кликабельное имя нового участника (всегда ссылка на профиль).\n"
            . "📌 <b>{userid}</b> — @username участника, если есть, иначе тоже ссылка на имя.\n"
            . "Поддерживается многострочный текст и HTML-форматирование."
        );
        return;
    }

    $db = getDB();
    $escaped = addslashes($template);
    $db->exec("
        INSERT OR IGNORE INTO welcome_settings (chat_id, enabled, template) VALUES ($chatId, 1, '$escaped')
        ON CONFLICT(chat_id) DO UPDATE SET template = '$escaped', enabled = 1
    ");

    sendReply($chatId, $messageId,
        "✅ <b>Приветствие сохранено!</b>\n\n"
        . "Предпросмотр:\n"
        . str_replace(
            ['{userid}', '{name}'],
            ['<b>@username</b>', '<b>Имя Фамилия</b>'],
            htmlspecialchars($template, ENT_XML1)
        )
    );
}

function cmdWelcomeOff(int $chatId, int $messageId, int $userId): void {
    if (!isChatOwner($chatId, $userId) && !isFullAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Управлять приветствием может только владелец или администратор с полными правами.");
        return;
    }
    $db = getDB();
    $db->exec("
        INSERT OR IGNORE INTO welcome_settings (chat_id, enabled, template) VALUES ($chatId, 0, '')
        ON CONFLICT(chat_id) DO UPDATE SET enabled = 0
    ");
    sendReply($chatId, $messageId, "🔕 Приветствие новых участников <b>отключено</b>.");
}

function cmdWelcomeOn(int $chatId, int $messageId, int $userId): void {
    if (!isChatOwner($chatId, $userId) && !isFullAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Управлять приветствием может только владелец или администратор с полными правами.");
        return;
    }
    $db = getDB();
    $db->exec("
        INSERT OR IGNORE INTO welcome_settings (chat_id, enabled, template) VALUES ($chatId, 1, '')
        ON CONFLICT(chat_id) DO UPDATE SET enabled = 1
    ");
    sendReply($chatId, $messageId, "🔔 Приветствие новых участников <b>включено</b>.");
}

// ─────────────────────────────────────────────
// СИСТЕМА РЕПУТАЦИИ
// ─────────────────────────────────────────────

/**
 * Выдать/забрать репутацию. value = +1 или -1.
 * Доступно всем участникам ответом на сообщение.
 * Нельзя голосовать за себя. Кулдаун 1 час между голосами от одного юзера к одному получателю.
 */
function cmdRep(int $chatId, int $replyTo, int $userId, ?array $target, int $value): void {
    if (!$target) {
        $sign = $value > 0 ? '+' : '-';
        sendReply($chatId, $replyTo, "❓ Ответьте на сообщение пользователя командой <b>{$sign}реп</b> или <b>/rep</b>");
        return;
    }

    $targetId = $target['id'];

    if ($targetId === $userId) {
        sendReply($chatId, $replyTo, "❌ Нельзя давать репутацию самому себе.");
        return;
    }

    // Нельзя давать репутацию ботам
    $memberInfo = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $targetId]);
    if (!empty($memberInfo['result']['user']['is_bot'])) {
        sendReply($chatId, $replyTo, "❌ Нельзя давать репутацию ботам.");
        return;
    }

    $db  = getDB();
    $now = time();

    // Кулдаун: один и тот же from_id -> to_id в течение 24 часов
    $cooldown   = 86400; // 24 часа
    $lastVote   = $db->query(
        "SELECT created_at FROM rep_history WHERE from_id = $userId AND to_id = $targetId AND chat_id = $chatId ORDER BY created_at DESC LIMIT 1"
    )->fetchColumn();
    if ($lastVote && ($now - (int)$lastVote) < $cooldown) {
        $remaining = $cooldown - ($now - (int)$lastVote);
        $hours     = intdiv($remaining, 3600);
        $mins      = intdiv($remaining % 3600, 60);
        sendReply($chatId, $replyTo, "⏳ Вы уже голосовали за этого пользователя. Повторить можно через {$hours}ч {$mins}м.");
        return;
    }

    // Обновляем репутацию
    $db->exec("
        INSERT OR REPLACE INTO reputation (user_id, chat_id, rep) VALUES ($targetId, $chatId, $value)
        ON CONFLICT(user_id, chat_id) DO UPDATE SET rep = rep + $value
    ");

    // Записываем историю
    $db->exec("
        INSERT INTO rep_history (from_id, to_id, chat_id, value, created_at)
        VALUES ($userId, $targetId, $chatId, $value, $now)
    ");

    $newRep   = (int)$db->query("SELECT rep FROM reputation WHERE user_id = $targetId AND chat_id = $chatId")->fetchColumn();
    $nameLink = formatUserName($target);
    $voterRow = $db->query("SELECT name, username FROM users WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $voterName = htmlspecialchars($voterRow['name'] ?? "Пользователь", ENT_XML1);
    $voterLink = formatUserName(['id' => $userId, 'name' => $voterName, 'username' => $voterRow['username'] ?? '']);

    if ($value > 0) {
        $emoji = '⬆️';
        $word  = 'повысил';
    } else {
        $emoji = '⬇️';
        $word  = 'понизил';
    }

    sendReply($chatId, $replyTo,
        "{$emoji} {$voterLink} {$word} репутацию {$nameLink}\n"
        . "⭐ Репутация: <b>{$newRep}</b>"
    );
}

/**
 * Формирует текст топа (репутация или активность)
 */
function buildTopText(int $chatId, string $type): string {
    $db = getDB();

    // Системные и служебные ID которые не должны быть в топе
    static $botId = null;
    if ($botId === null) {
        $me    = apiRequest('getMe', []);
        $botId = (int)($me['result']['id'] ?? 0);
    }
    $excludeIds = implode(',', array_filter([$botId, 777000, 136817688]));

    if ($type === 'rep') {
        $res = $db->query("
            SELECT r.user_id, r.rep, u.name, u.username
            FROM reputation r
            LEFT JOIN users u ON u.user_id = r.user_id AND u.chat_id = r.chat_id
            WHERE r.chat_id = $chatId AND r.rep != 0
              AND r.user_id NOT IN ($excludeIds)
              AND (u.is_bot IS NULL OR u.is_bot = 0)
            ORDER BY r.rep DESC
            LIMIT 10
        ");
        $title  = '🏆 <b>Топ-10 по репутации</b>';
        $medals = ['🥇','🥈','🥉','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟'];
        $lines  = [];
        $i      = 0;
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $name = htmlspecialchars($row['name'] ?? "ID {$row['user_id']}", ENT_XML1);
                $link = formatUserName(['id' => (int)$row['user_id'], 'name' => $name, 'username' => $row['username'] ?? '']);
                $rep  = (int)$row['rep'];
                $sign = $rep > 0 ? '+' : '';
                $lines[] = ($medals[$i] ?? ($i+1).'.') . " {$link} — <b>{$sign}{$rep}</b> ⭐";
                $i++;
            }
        }
        if (empty($lines)) {
            $lines[] = '<i>Нет данных. Начните голосовать за участников командой +реп!</i>';
        }
    } else {
        $res = $db->query("
            SELECT user_id, name, username, message_count
            FROM users
            WHERE chat_id = $chatId
              AND user_id NOT IN ($excludeIds)
              AND (is_bot IS NULL OR is_bot = 0)
            ORDER BY message_count DESC
            LIMIT 10
        ");
        $title  = '💬 <b>Топ-10 по активности</b>';
        $medals = ['🥇','🥈','🥉','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟'];
        $lines  = [];
        $i      = 0;
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                if ((int)$row['message_count'] <= 0) continue;
                $name  = htmlspecialchars($row['name'] ?? "ID {$row['user_id']}", ENT_XML1);
                $link  = formatUserName(['id' => (int)$row['user_id'], 'name' => $name, 'username' => $row['username'] ?? '']);
                $count = (int)$row['message_count'];
                $lines[] = ($medals[$i] ?? ($i+1).'.') . " {$link} — <b>{$count}</b> ??";
                $i++;
            }
        }
        if (empty($lines)) {
            // Покажем сколько вообще записей в users для этого чата
            $total = (int)$db->query("SELECT COUNT(*) FROM users WHERE chat_id = $chatId")->fetchColumn();
            $lines[] = "<i>Нет данных об активности участников.</i>\n<i>Записей в базе для этого чата: {$total}</i>";
        }
    }

    return $title . "\n━━━━━━━━━━━━━━━━━━━━\n\n" . implode("\n", $lines);
}

/**
 * /top — показать топ с выбором категории через кнопки
 */
function cmdTop(int $chatId, int $messageId): void {
    $text = buildTopText($chatId, 'rep');
    $keyboard = [
        'inline_keyboard' => [[
            ['text' => '🏆 Репутация ✓', 'callback_data' => 'top_rep'],
            ['text' => '💬 Активность',   'callback_data' => 'top_activity'],
        ]],
    ];
    sendMessage($chatId, $text, $keyboard);
}

/**
 * Возвращает строку «Администратор: Имя» или «Владелец: Имя» со ссылкой на аккаунт.
 * Используется в сообщениях о наказаниях.
 */
function getPunisherLabel(int $chatId, int $adminId): string {
    $db       = getDB();
    $adminRow = $db->query("SELECT name, username FROM users WHERE user_id = $adminId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $name     = htmlspecialchars($adminRow['name'] ?? "ID {$adminId}", ENT_XML1);
    $username = $adminRow['username'] ?? '';
    $link     = formatUserName(['id' => $adminId, 'name' => $name, 'username' => $username]);

    if (isChatOwner($chatId, $adminId) || isFullAdmin($chatId, $adminId)) {
        $role = '👑 Владелец';
    } else {
        $role = '🛡 Администратор';
    }

    return "{$role}: {$link}";
}

// ─────────────────────────────────────────────
// ЛОГ МОДЕРАТОРСКИХ ДЕЙСТВИЙ
// ─────────────────────────────────────────────

function writeModLog(int $chatId, int $adminId, int $targetId, string $action, string $reason = '', string $extra = ''): void {
    $db  = getDB();
    $now = time();
    $a   = addslashes($action);
    $r   = addslashes($reason);
    $e   = addslashes($extra);

    $ok = $db->exec("
        INSERT INTO modlog (chat_id, admin_id, target_id, action, reason, extra, created_at)
        VALUES ($chatId, $adminId, $targetId, '$a', '$r', '$e', $now)
    ");

    if (!$ok) {
        debugLog("writeModLog INSERT FAILED: action=$action chat=$chatId admin=$adminId target=$targetId err=" . $db->lastErrorMsg());
    }

    // Отправляем в лог-канал, если привязан
    $setting   = $db->query("SELECT log_chat_id FROM modlog_settings WHERE chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $logChatId = (int)($setting['log_chat_id'] ?? 0);
    if ($logChatId === 0) return;

    // Данные участников
    $adminRow  = $db->query("SELECT name, username FROM users WHERE user_id = $adminId  AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    $targetRow = $db->query("SELECT name, username FROM users WHERE user_id = $targetId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);

    $adminName  = htmlspecialchars($adminRow['name']  ?? "ID {$adminId}",  ENT_XML1);
    $targetName = htmlspecialchars($targetRow['name'] ?? "ID {$targetId}", ENT_XML1);
    $adminLink  = formatUserName(['id' => $adminId,  'name' => $adminName,  'username' => $adminRow['username']  ?? '']);
    $targetLink = formatUserName(['id' => $targetId, 'name' => $targetName, 'username' => $targetRow['username'] ?? '']);

    // Роль администратора
    $adminMember = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $adminId]);
    $adminStatus = $adminMember['result']['status'] ?? 'administrator';
    $adminRole   = ($adminStatus === 'creator') ? '👑 Владелец' : '👮 Администратор';

    // Название чата
    $chatInfo  = apiRequest('getChat', ['chat_id' => $chatId]);
    $chatTitle = htmlspecialchars($chatInfo['result']['title'] ?? "чат {$chatId}", ENT_XML1);

    $actionLabels = [
        'ban'     => '🔴 Бан',
        'unban'   => '🟢 Разбан',
        'tempban' => '⏳ Временный бан',
        'mute'    => '🔇 Мут',
        'unmute'  => '🔊 Размут',
        'kick'    => '👢 Кик',
        'warn'    => '⚠️ Предупреждение',
        'unwarn'  => '✅ Снятие предупреждения',
        'promote' => '⭐ Назначен администратором',
        'demote'  => '🔽 Разжалован',
        'autoban' => '🚫 Автобан (3 варна)',
        'automod' => '🤖 Авто-мод',
    ];
    $label = $actionLabels[$action] ?? "📝 {$action}";

    $text  = "📋 <b>Логи чата</b> | <b>{$chatTitle}</b>\n";
    $text .= "🎯 Действие: {$label}\n";
    $text .= "👤 Цель: {$targetLink} (<code>{$targetId}</code>)\n";
    $text .= "{$adminRole}: {$adminLink}\n";
    if ($reason !== '') {
        $text .= "📝 Причина: " . htmlspecialchars($reason, ENT_XML1) . "\n";
    }
    if ($extra !== '') {
        $text .= "🕐 Срок: " . htmlspecialchars($extra, ENT_XML1) . "\n";
    }
    $text .= "🗓 " . date('d.m.Y H:i:s', $now);

    sendMessage($logChatId, $text);
}

function cmdSetLogChannel(int $chatId, int $messageId, int $adminId, ?array $msg): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $messageId, "⛔ Привязать лог-канал может только владелец или администратор с полными правами.");
        return;
    }

    $logTarget = null;

    // Способ 1: ответ на сообщение с ID
    if (isset($msg['reply_to_message'])) {
        $replyText = trim($msg['reply_to_message']['text'] ?? '');
        if (preg_match('/-?\d{5,}/', $replyText, $m)) {
            $logTarget = (int)$m[0];
        }
    }

    // Способ 2: ID прямо в команде
    if ($logTarget === null) {
        $cmdText = trim($msg['text'] ?? '');
        $cmdText = preg_replace('/^\/setlog\S*\s*/iu', '', $cmdText);
        $cmdText = preg_replace('/^(?:логканал|лог\s*канал|установить\s*лог(?:канал)?|привязать\s*лог)\s*/iu', '', $cmdText);
        $cmdText = trim($cmdText);
        if (preg_match('/-?\d{5,}/', $cmdText, $m)) {
            $logTarget = (int)$m[0];
        }
    }

    if ($logTarget === null) {
        sendReply($chatId, $messageId,
            "❓ Укажите ID канала/чата для логов:\n\n"
            . "<b>Способ 1:</b> <code>/setlog -1001234567890</code>\n"
            . "<b>Способ 2:</b> ответьте командой /setlog на сообщение, содержащее ID\n\n"
            . "<i>Бот должен быть администратором в канале/чате логов.</i>"
        );
        return;
    }

    // Проверяем доступ бота тестовым сообщением
    $chatInfo  = apiRequest('getChat', ['chat_id' => $chatId]);
    $chatTitle = htmlspecialchars($chatInfo['result']['title'] ?? "ID {$chatId}", ENT_XML1);

    $check = apiRequest('sendMessage', [
        'chat_id'    => $logTarget,
        'text'       => "✅ Этот чат привязан как лог-канал для <b>{$chatTitle}</b>\n🕐 " . date('d.m.Y H:i:s'),
        'parse_mode' => 'HTML',
    ]);

    if (!($check['ok'] ?? false)) {
        sendReply($chatId, $messageId,
            "❌ Не удалось отправить тестовое сообщение в чат <code>{$logTarget}</code>.\n"
            . "Убедитесь, что бот является администратором в этом чате/канале."
        );
        return;
    }

    $db = getDB();
    $db->exec("
        INSERT OR IGNORE INTO modlog_settings (chat_id, log_chat_id) VALUES ($chatId, $logTarget)
        ON CONFLICT(chat_id) DO UPDATE SET log_chat_id = $logTarget
    ");

    sendReply($chatId, $messageId, "✅ Лог-канал привязан (<code>{$logTarget}</code>). Все модераторские действия будут отправляться туда.");
}

function cmdUnsetLogChannel(int $chatId, int $messageId, int $adminId): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $messageId, "⛔ Только для владельца или администратора с полными правами.");
        return;
    }
    $db = getDB();
    $db->exec("UPDATE modlog_settings SET log_chat_id = 0 WHERE chat_id = $chatId");
    sendReply($chatId, $messageId, "🔕 Лог-канал отвязан. Действия записываются только в БД.");
}

function cmdLogs(int $chatId, int $messageId, ?array $target, ?array $msg): void {
    if (!$target) {
        sendReply($chatId, $messageId,
            "❓ Укажите пользователя:\n"
            . "<b>Ответом:</b> ответьте на его сообщение командой /logs\n"
            . "<b>По @username:</b> /logs @username\n"
            . "<b>По ID:</b> /logs 123456789\n\n"
            . "<i>Команда показывает историю наказаний пользователя.</i>"
        );
        return;
    }

    $db       = getDB();
    $targetId = $target['id'];

    $res = $db->query("
        SELECT action, reason, extra, admin_id, created_at
        FROM modlog
        WHERE chat_id = $chatId AND target_id = $targetId
        ORDER BY created_at DESC
        LIMIT 200
    ");

    $rows = [];
    if ($res) {
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = $row;
        }
    }

    if (empty($rows)) {
        $nameLink = formatUserName($target);
        sendReply($chatId, $messageId, "📋 История наказаний пуста для {$nameLink}.");
        return;
    }

    // Заголовок файла
    $targetName = $target['name'];
    $username   = $target['username'] ?? '';
    $userStr    = $username !== ''
        ? "{$targetName} (@{$username})"
        : "{$targetName} (ID {$targetId})";

    $header  = "Лог модераторских действий\n";
    $header .= "Пользователь: {$userStr}\n";
    $header .= "ID: {$targetId}\n";
    $header .= "Чат ID: {$chatId}\n";
    $header .= "Дата выгрузки: " . date('d.m.Y H:i:s') . "\n";
    $header .= str_repeat('=', 50) . "\n\n";

    $actionLabels = [
        'ban'     => 'Бан',
        'unban'   => 'Разбан',
        'tempban' => 'Временный бан',
        'mute'    => 'Мут',
        'unmute'  => 'Размут',
        'kick'    => 'Кик',
        'warn'    => 'Предупреждение',
        'unwarn'  => 'Снятие предупреждения',
        'promote' => 'Назначен администратором',
        'demote'  => 'Разжалован',
        'autoban' => 'Автобан (3 варна)',
        'automod' => 'Авто-мод',
    ];

    // Кэш администраторов — getChatMember вызывается один раз на каждый уникальный adminId
    $adminCache = [];

    $lines = [];
    foreach ($rows as $row) {
        $adminId2 = (int)$row['admin_id'];

        if (!isset($adminCache[$adminId2])) {
            $adminRow    = $db->query("SELECT name, username FROM users WHERE user_id = {$adminId2} AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
            $memberInfo  = apiRequest('getChatMember', ['chat_id' => $chatId, 'user_id' => $adminId2]);
            $adminStatus = $memberInfo['result']['status'] ?? 'administrator';

            $aName = $adminRow['name'] ?? '';
            $aUsr  = $adminRow['username'] ?? '';

            if ($aName === '') {
                $aName = "ID {$adminId2}";
            }

            $adminStr = $aUsr !== ''
                ? "{$aName} (@{$aUsr})"
                : "{$aName} (ID {$adminId2})";

            $role = ($adminStatus === 'creator') ? 'Владелец' : 'Администратор';

            $adminCache[$adminId2] = [
                'str'  => $adminStr,
                'role' => $role,
            ];
        }

        $cached      = $adminCache[$adminId2];
        $actionLabel = $actionLabels[$row['action']] ?? $row['action'];
        $date        = date('d.m.Y H:i:s', (int)$row['created_at']);

        $line  = "[{$date}]\n";
        $line .= "Действие:       {$actionLabel}\n";
        $line .= "{$cached['role']}:  {$cached['str']}\n";
        if ($row['reason'] !== '') {
            $line .= "Причина:        {$row['reason']}\n";
        }
        if ($row['extra'] !== '') {
            $line .= "Доп. инфо:      {$row['extra']}\n";
        }
        $lines[] = $line;
    }

    $content  = $header . implode(str_repeat('-', 40) . "\n", $lines);
    $count    = count($rows);
    $filename = "modlog_{$targetId}_" . date('Ymd_His') . ".txt";
    $tmpFile  = sys_get_temp_dir() . '/' . $filename;
    file_put_contents($tmpFile, $content);

    // Отправляем файл
    $ch = curl_init(API_URL . 'sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => [
            'chat_id'             => $chatId,
            'document'            => new CURLFile($tmpFile, 'text/plain', $filename),
            'caption'             => "📋 <b>История наказаний</b> для "
                                   . htmlspecialchars($targetName, ENT_XML1)
                                   . ($username !== '' ? " (@{$username})" : " (ID {$targetId})")
                                   . "\n🆔 ID: <code>{$targetId}</code>"
                                   . "\n📊 Записей: <b>{$count}</b>",
            'parse_mode'          => 'HTML',
            'reply_to_message_id' => $messageId,
        ],
    ]);

    if (defined('PROXY_HOST') && PROXY_HOST !== '') {
        curl_setopt($ch, CURLOPT_PROXY,        PROXY_HOST);
        curl_setopt($ch, CURLOPT_PROXYTYPE,    CURLPROXY_SOCKS5_HOSTNAME);
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, PROXY_AUTH);
    }

    curl_exec($ch);
    curl_close($ch);
    @unlink($tmpFile);
}

// ═════════════════════════════════════════════
// МОДУЛЬ: НАПОМИНАНИЯ С ЗАМЕТКОЙ (/remind)
// ═════════════════════════════════════════════

/**
 * /remind <время> <текст заметки>
 *
 * Создаёт личную заметку с напоминанием.
 * Команда удаляется из чата — никто кроме администратора не видит текст.
 * Когда время выходит — бот пишет администратору в личку.
 *
 * Форматы времени (те же что у /mute):
 *   30m  — 30 минут
 *   2h   — 2 часа
 *   1d   — 1 день
 *   2w   — 2 недели
 *
 * /remind          — показать список активных напоминаний
 * /remind отмена 5 — отменить напоминание #5
 */
function cmdRemind(int $chatId, int $messageId, int $userId, array $args, ?array $msg): void {
    if (!isChatAdmin($chatId, $userId)) {
        sendReply($chatId, $messageId, "⛔ Только для администраторов.");
        return;
    }

    $db = getDB();

    // Без аргументов — список активных напоминаний
    if (empty($args)) {
        $result = $db->query(
            "SELECT id, text, fire_at FROM reminders
             WHERE chat_id = $chatId AND user_id = $userId AND done = 0
             ORDER BY fire_at ASC LIMIT 15"
        );
        $lines = [];
        if ($result) {
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $timeLeft = max(0, (int)$row['fire_at'] - time());
                $lines[]  = "📌 <b>#{$row['id']}</b> — через <b>" . formatSecondsLeft($timeLeft) . "</b>\n"
                          . "📝 " . htmlspecialchars(mb_substr($row['text'], 0, 120), ENT_XML1);
            }
        }
        deleteMessage($chatId, $messageId);
        if (empty($lines)) {
            sendMessage($userId,
                "📭 У вас нет активных напоминаний.\n\n"
                . "Создать: <code>/remind 30m текст заметки</code>\n"
                . "Отменить: <code>/remind отмена 5</code>"
            );
        } else {
            sendMessage($userId,
                "📋 <b>Ваши активные напоминания</b> (чат " . htmlspecialchars($msg['chat']['title'] ?? "ID {$chatId}", ENT_XML1) . "):\n"
                . "━━━━━━━━━━━━━━━━━━━━\n\n"
                . implode("\n\n", $lines) . "\n\n"
                . "<i>Отменить: /remind отмена &lt;ID&gt;</i>"
            );
        }
        return;
    }

    // /remind отмена <ID>
    if (in_array(mb_strtolower($args[0]), ['отмена', 'cancel', 'удалить', 'del'], true)) {
        $remId = isset($args[1]) ? (int)$args[1] : 0;
        if ($remId <= 0) {
            deleteMessage($chatId, $messageId);
            sendMessage($userId, "❓ Укажите ID напоминания: <code>/remind отмена 5</code>");
            return;
        }
        $row = $db->query("SELECT id FROM reminders WHERE id = $remId AND chat_id = $chatId AND user_id = $userId AND done = 0")->fetch(PDO::FETCH_ASSOC);
        deleteMessage($chatId, $messageId);
        if (!$row) {
            sendMessage($userId, "❌ Напоминание <b>#{$remId}</b> не найдено или уже сработало.");
            return;
        }
        $db->exec("UPDATE reminders SET done = 1 WHERE id = $remId");
        sendMessage($userId, "🗑 Напоминание <b>#{$remId}</b> отменено.");
        return;
    }

    // /remind <время> <текст>
    $timeStr = $args[0];
    $seconds = parseRemindTime($timeStr);

    if ($seconds === null) {
        sendReply($chatId, $messageId,
            "❌ Неверный формат времени: <code>{$timeStr}</code>\n\n"
            . "Примеры:\n"
            . "  <code>/remind 30m Позвонить модератору</code>\n"
            . "  <code>/remind 2h Проверить жалобы</code>\n"
            . "  <code>/remind 1d Итоги недели</code>\n"
            . "  <code>/remind 2w Плановая проверка</code>"
        );
        return;
    }

    if ($seconds < 60) {
        sendReply($chatId, $messageId, "❌ Минимальное время напоминания — 1 минута.");
        return;
    }

    // Текст заметки — всё после времени
    array_shift($args);
    $noteText = implode(' ', $args);

    // Если текст пустой — берём из реплая
    if ($noteText === '' && isset($msg['reply_to_message']['text'])) {
        $noteText = mb_substr(trim($msg['reply_to_message']['text']), 0, 2000);
    }

    if ($noteText === '') {
        sendReply($chatId, $messageId,
            "❌ Напишите текст заметки после времени.\n"
            . "Пример: <code>/remind 30m Проверить репорты</code>"
        );
        return;
    }

    $fireAt  = time() + $seconds;
    $escaped = addslashes(mb_substr($noteText, 0, 2000));
    $db->exec("
        INSERT INTO reminders (chat_id, user_id, message_id, text, fire_at)
        VALUES ($chatId, $userId, $messageId, '$escaped', $fireAt)
    ");
    $id = $db->lastInsertId();

    $label = formatSecondsLeft($seconds);

    // Удаляем команду из чата (заметка конфиденциальна)
    deleteMessage($chatId, $messageId);

    // Подтверждение — в личку
    sendMessage($userId,
        "📝 <b>Заметка #{$id} сохранена</b>\n"
        . "⏰ Напомню через: <b>{$label}</b>\n"
        . "🕐 Время: " . date('d.m.Y H:i:s', $fireAt) . "\n"
        . "💬 Текст: " . htmlspecialchars($noteText, ENT_XML1)
    );
}

/**
 * Парсит строку времени в секунды.
 * Поддерживает: 30m, 2h, 1d, 2w
 * Возвращает null при ошибке.
 */
function parseRemindTime(string $str): ?int {
    if (!preg_match('/^(\d+)(m|h|d|w)$/i', trim($str), $m)) {
        return null;
    }
    $val  = (int)$m[1];
    $unit = strtolower($m[2]);
    return match ($unit) {
        'm' => $val * 60,
        'h' => $val * 3600,
        'd' => $val * 86400,
        'w' => $val * 604800,
        default => null,
    };
}

/**
 * Форматирует количество секунд в читаемую строку.
 */
function formatSecondsLeft(int $seconds): string {
    if ($seconds < 60)   return "{$seconds} сек.";
    if ($seconds < 3600) return round($seconds / 60) . " мин.";
    if ($seconds < 86400) {
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        return $m > 0 ? "{$h} ч. {$m} мин." : "{$h} ч.";
    }
    if ($seconds < 604800) return floor($seconds / 86400) . " дн.";
    return floor($seconds / 604800) . " нед.";
}

/**
 * Проверяет и отправляет сработавшие напоминания.
 * Вызывается периодически из handleMessage.
 */
function checkFiredReminders(): void {
    $db  = getDB();
    $now = time();

    $result = $db->query(
        "SELECT * FROM reminders WHERE fire_at <= $now AND done = 0 LIMIT 20"
    );
    if (!$result) return;

    $ids = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $ids[]  = (int)$row['id'];
        $userId = (int)$row['user_id'];
        $chatId = (int)$row['chat_id'];
        $text   = htmlspecialchars($row['text'], ENT_XML1);

        $chatInfo  = apiRequest('getChat', ['chat_id' => $chatId]);
        $chatTitle = htmlspecialchars($chatInfo['result']['title'] ?? "ID {$chatId}", ENT_XML1);

        // Отправляем в личку администратору
        sendMessage($userId,
            "⏰ <b>Напоминание!</b>\n"
            . "💬 Чат: <b>{$chatTitle}</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . $text
        );
    }

    if (!empty($ids)) {
        $idList = implode(',', $ids);
        $db->exec("UPDATE reminders SET done = 1 WHERE id IN ($idList)");
    }
}

// ═════════════════════════════════════════════
// МОДУЛЬ: АНТИ-РЕЙД (/antiraid)
// ═════════════════════════════════════════════

/**
 * Возвращает настройки анти-рейда, создаёт запись по умолчанию если нет.
 */
function getAntiRaidSettings(int $chatId): array {
    $db  = getDB();
    $row = $db->query("SELECT * FROM antiraid WHERE chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->exec("INSERT OR IGNORE INTO antiraid (chat_id) VALUES ($chatId)");
        return [
            'chat_id'        => $chatId,
            'enabled'        => 0,
            'threshold'      => 10,
            'window_seconds' => 60,
            'action'         => 'mute',
            'active_until'   => 0,
        ];
    }
    return $row;
}

/**
 * /antiraid — панель управления анти-рейдом
 * /antiraid on/off — включить/выключить
 * /antiraid set 15 60 mute — порог 15 вступлений за 60 сек., действие mute|ban|kick
 */
function cmdAntiRaid(int $chatId, int $messageId, int $adminId, array $args): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        sendReply($chatId, $messageId, "⛔ Только владелец или администратор с полными правами.");
        return;
    }

    $db  = getDB();
    $s   = getAntiRaidSettings($chatId);
    $sub = strtolower($args[0] ?? '');

    if ($sub === 'on' || $sub === 'вкл') {
        $db->exec("UPDATE antiraid SET enabled = 1 WHERE chat_id = $chatId");
        sendReply($chatId, $messageId, "🛡 Анти-рейд <b>включён</b>.\n"
            . "Порог: <b>{$s['threshold']}</b> вступлений за <b>{$s['window_seconds']} сек.</b>\n"
            . "Действие: <b>" . strtoupper($s['action']) . "</b>");
        return;
    }

    if ($sub === 'off' || $sub === 'выкл') {
        $db->exec("UPDATE antiraid SET enabled = 0, active_until = 0 WHERE chat_id = $chatId");
        sendReply($chatId, $messageId, "🔓 Анти-рейд <b>выключен</b>.");
        return;
    }

    if ($sub === 'set' || $sub === 'настроить') {
        // /antiraid set <порог> <окно_сек> <действие>
        $threshold = isset($args[1]) ? max(3, (int)$args[1]) : (int)$s['threshold'];
        $window    = isset($args[2]) ? max(10, (int)$args[2]) : (int)$s['window_seconds'];
        $action    = isset($args[3]) && in_array(strtolower($args[3]), ['mute','ban','kick'], true)
                     ? strtolower($args[3]) : $s['action'];

        $db->exec("
            UPDATE antiraid
            SET threshold = $threshold, window_seconds = $window, action = '{$action}'
            WHERE chat_id = $chatId
        ");

        $actionLabel = ['mute' => '🔇 Мут', 'ban' => '🔴 Бан', 'kick' => '👢 Кик'][$action] ?? $action;
        sendReply($chatId, $messageId,
            "✅ Анти-рейд настроен:\n"
            . "📊 Порог: <b>{$threshold}</b> вступлений за <b>{$window} сек.</b>\n"
            . "⚡ Действие: {$actionLabel}"
        );
        return;
    }

    // Статус
    $status     = $s['enabled'] ? '✅ Включён' : '❌ Выключен';
    $raidActive = $s['active_until'] > time();
    $raidStr    = $raidActive
        ? "\n🚨 <b>Рейд активен</b> до " . date('H:i:s', (int)$s['active_until'])
        : '';
    $actionLabel = ['mute' => '🔇 Мут', 'ban' => '🔴 Бан', 'kick' => '👢 Кик'][$s['action']] ?? $s['action'];

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => $s['enabled'] ? '🔴 Выключить' : '🟢 Включить', 'callback_data' => 'ar_toggle'],
            ],
            [
                ['text' => '⚡ Мут',  'callback_data' => 'ar_action_mute'],
                ['text' => '🔴 Бан',  'callback_data' => 'ar_action_ban'],
                ['text' => '👢 Кик',  'callback_data' => 'ar_action_kick'],
            ],
            [
                ['text' => '📊 Порог -1', 'callback_data' => 'ar_thr_down'],
                ['text' => "Порог: {$s['threshold']}", 'callback_data' => 'ar_noop'],
                ['text' => '📊 Порог +1', 'callback_data' => 'ar_thr_up'],
            ],
            [
                ['text' => '⏱ Окно -10с', 'callback_data' => 'ar_win_down'],
                ['text' => "Окно: {$s['window_seconds']}с", 'callback_data' => 'ar_noop'],
                ['text' => '⏱ Окно +10с', 'callback_data' => 'ar_win_up'],
            ],
        ],
    ];

    sendMessage($chatId,
        "🛡 <b>Анти-рейд</b>\n\n"
        . "Статус: {$status}{$raidStr}\n"
        . "📊 Порог: <b>{$s['threshold']}</b> вступлений за <b>{$s['window_seconds']} сек.</b>\n"
        . "⚡ Действие при рейде: {$actionLabel}\n\n"
        . "<i>При превышении порога чат автоматически переходит в режим защиты на 5 минут.</i>\n\n"
        . "Настройка вручную: <code>/antiraid set &lt;порог&gt; &lt;сек&gt; &lt;mute|ban|kick&gt;</code>",
        $keyboard
    );
}

/**
 * Проверяет вступление нового участника на рейд.
 * Возвращает true если участник заблокирован/замьючен антирейдом.
 */
function checkAntiRaid(int $chatId, int $userId): bool {
    $db = getDB();
    $s  = getAntiRaidSettings($chatId);

    if (!$s['enabled']) return false;

    $now = time();

    // Если рейд уже активен — применяем действие сразу
    if ((int)$s['active_until'] > $now) {
        applyAntiRaidAction($chatId, $userId, $s['action']);
        return true;
    }

    // Записываем вступление
    $db->exec("INSERT INTO antiraid_joins (chat_id, user_id, joined_at) VALUES ($chatId, $userId, $now)");

    // Считаем вступления за окно
    $window    = max(10, (int)$s['window_seconds']);
    $threshold = max(3, (int)$s['threshold']);
    $since     = $now - $window;

    $count = (int)$db->query(
        "SELECT COUNT(*) FROM antiraid_joins WHERE chat_id = $chatId AND joined_at >= $since"
    )->fetchColumn();

    if ($count >= $threshold) {
        // Активируем режим рейда на 5 минут
        $activeUntil = $now + 300;
        $db->exec("UPDATE antiraid SET active_until = $activeUntil WHERE chat_id = $chatId");

        $actionLabel = ['mute' => 'мут', 'ban' => 'бан', 'kick' => 'кик'][$s['action']] ?? $s['action'];
        sendMessage($chatId,
            "🚨 <b>АНТИ-РЕЙД АКТИВИРОВАН</b>\n\n"
            . "Обнаружено массовое вступление: <b>{$count}</b> участников за <b>{$window} сек.</b>\n"
            . "⚡ Действие: <b>{$actionLabel}</b> для всех новых участников.\n"
            . "⏱ Защита активна: <b>5 минут</b> (до " . date('H:i:s', $activeUntil) . ")\n\n"
            . "Отключить досрочно: <code>/antiraid off</code>"
        );

        // Применяем действие к текущему пользователю
        applyAntiRaidAction($chatId, $userId, $s['action']);
        return true;
    }

    return false;
}

/**
 * Применяет действие анти-рейда к участнику.
 */
function applyAntiRaidAction(int $chatId, int $userId, string $action): void {
    switch ($action) {
        case 'ban':
            apiRequest('banChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
            break;
        case 'kick':
            apiRequest('banChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
            apiRequest('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
            break;
        case 'mute':
        default:
            // Мут на 1 час
            $muteRes = apiRequest('restrictChatMember', [
                'chat_id'     => $chatId,
                'user_id'     => $userId,
                'until_date'  => time() + 3600,
                'permissions' => [
                    'can_send_messages'         => false,
                    'can_send_audios'           => false,
                    'can_send_documents'        => false,
                    'can_send_photos'           => false,
                    'can_send_videos'           => false,
                    'can_send_video_notes'      => false,
                    'can_send_voice_notes'      => false,
                    'can_send_polls'            => false,
                    'can_send_other_messages'   => false,
                    'can_add_web_page_previews' => false,
                    'can_change_info'           => false,
                    'can_invite_users'          => false,
                    'can_pin_messages'          => false,
                ],
            ]);
            if (!($muteRes['ok'] ?? false)) {
                // Для антирейда молча логируем — нет контекста сообщения для ответа
                debugLog("applyAntiRaidAction: failed to mute user $userId in chat $chatId: " . ($muteRes['description'] ?? 'unknown'));
                return;
            }
            break;
    }
}

/**
 * Снимает режим рейда и чистит старые записи вступлений.
 * Вызывается периодически.
 */
function checkAntiRaidDecay(int $chatId): void {
    $db  = getDB();
    $now = time();

    // Снимаем устаревший активный рейд
    $db->exec("UPDATE antiraid SET active_until = 0 WHERE chat_id = $chatId AND active_until > 0 AND active_until <= $now");

    // Удаляем старые записи вступлений (старше 10 минут)
    $cutoff = $now - 600;
    $db->exec("DELETE FROM antiraid_joins WHERE chat_id = $chatId AND joined_at < $cutoff");
}

/**
 * Обработка callback-кнопок анти-рейда.
 */
function handleAntiRaidCallback(string $callbackId, int $chatId, int $messageId, int $adminId, string $data): void {
    if (!isChatOwner($chatId, $adminId) && !isFullAdmin($chatId, $adminId)) {
        apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => '⛔ Только владелец или полный администратор.',
            'show_alert' => true,
        ]);
        return;
    }

    $db = getDB();
    $s  = getAntiRaidSettings($chatId);

    if ($data === 'ar_noop') {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        return;
    }

    if ($data === 'ar_toggle') {
        $newVal = $s['enabled'] ? 0 : 1;
        $db->exec("UPDATE antiraid SET enabled = $newVal WHERE chat_id = $chatId");
    } elseif ($data === 'ar_action_mute') {
        $db->exec("UPDATE antiraid SET action = 'mute' WHERE chat_id = $chatId");
    } elseif ($data === 'ar_action_ban') {
        $db->exec("UPDATE antiraid SET action = 'ban' WHERE chat_id = $chatId");
    } elseif ($data === 'ar_action_kick') {
        $db->exec("UPDATE antiraid SET action = 'kick' WHERE chat_id = $chatId");
    } elseif ($data === 'ar_thr_up') {
        $new = min(50, (int)$s['threshold'] + 1);
        $db->exec("UPDATE antiraid SET threshold = $new WHERE chat_id = $chatId");
    } elseif ($data === 'ar_thr_down') {
        $new = max(3, (int)$s['threshold'] - 1);
        $db->exec("UPDATE antiraid SET threshold = $new WHERE chat_id = $chatId");
    } elseif ($data === 'ar_win_up') {
        $new = min(300, (int)$s['window_seconds'] + 10);
        $db->exec("UPDATE antiraid SET window_seconds = $new WHERE chat_id = $chatId");
    } elseif ($data === 'ar_win_down') {
        $new = max(10, (int)$s['window_seconds'] - 10);
        $db->exec("UPDATE antiraid SET window_seconds = $new WHERE chat_id = $chatId");
    }

    apiRequest('answerCallbackQuery', ['callback_query_id' => $callbackId]);

    // Обновляем панель
    $s   = getAntiRaidSettings($chatId);
    $status     = $s['enabled'] ? '✅ Включён' : '❌ Выключен';
    $raidActive = $s['active_until'] > time();
    $raidStr    = $raidActive
        ? "\n🚨 <b>Рейд активен</b> до " . date('H:i:s', (int)$s['active_until'])
        : '';
    $actionLabel = ['mute' => '🔇 Мут', 'ban' => '🔴 Бан', 'kick' => '👢 Кик'][$s['action']] ?? $s['action'];

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => $s['enabled'] ? '🔴 Выключить' : '🟢 Включить', 'callback_data' => 'ar_toggle'],
            ],
            [
                ['text' => '⚡ Мут',  'callback_data' => 'ar_action_mute'],
                ['text' => '🔴 Бан',  'callback_data' => 'ar_action_ban'],
                ['text' => '👢 Кик',  'callback_data' => 'ar_action_kick'],
            ],
            [
                ['text' => '📊 Порог -1', 'callback_data' => 'ar_thr_down'],
                ['text' => "Порог: {$s['threshold']}", 'callback_data' => 'ar_noop'],
                ['text' => '📊 Порог +1', 'callback_data' => 'ar_thr_up'],
            ],
            [
                ['text' => '⏱ Окно -10с', 'callback_data' => 'ar_win_down'],
                ['text' => "Окно: {$s['window_seconds']}с", 'callback_data' => 'ar_noop'],
                ['text' => '⏱ Окно +10с', 'callback_data' => 'ar_win_up'],
            ],
        ],
    ];

    apiRequest('editMessageText', [
        'chat_id'      => $chatId,
        'message_id'   => $messageId,
        'text'         =>
            "🛡 <b>Анти-рейд</b>\n\n"
            . "Статус: {$status}{$raidStr}\n"
            . "📊 Порог: <b>{$s['threshold']}</b> вступлений за <b>{$s['window_seconds']} сек.</b>\n"
            . "⚡ Действие при рейде: {$actionLabel}\n\n"
            . "<i>При превышении порога чат автоматически переходит в режим защиты на 5 минут.</i>\n\n"
            . "Настройка вручную: <code>/antiraid set &lt;порог&gt; &lt;сек&gt; &lt;mute|ban|kick&gt;</code>",
        'parse_mode'   => 'HTML',
        'reply_markup' => $keyboard,
    ]);
}

function getDB(): PDO {
    static $db = null;
    if (!$db) {
        $db = new PDO('sqlite:' . DB_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');
    }
    return $db;
}

// ─────────────────────────────────────────────
// ПРОВЕРКА ИСТЁКШИХ ВРЕМЕННЫХ БАНОВ
// ─────────────────────────────────────────────
function checkExpiredTempbans(): void {
    $db  = getDB();
    $now = time();

    // Ищем истёкшие временные баны, о которых ещё не уведомляли
    $result = $db->query(
        "SELECT user_id, chat_id FROM tempbans WHERE until <= $now AND notified = 0"
    );
    if (!$result) return;

    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $userId = (int)$row['user_id'];
        $chatId = (int)$row['chat_id'];

        // Получаем имя пользователя из БД
        $userRow  = $db->query("SELECT name, username FROM users WHERE user_id = $userId AND chat_id = $chatId")->fetch(PDO::FETCH_ASSOC);
        $nameLink = $userRow
            ? formatUserName(['id' => $userId, 'name' => htmlspecialchars($userRow['name'], ENT_XML1), 'username' => $userRow['username'] ?? ''])
            : "<a href=\"tg://user?id={$userId}\">пользователь</a>";
        $chatName = htmlspecialchars($userRow['name'] ?? "чат {$chatId}", ENT_XML1);

        // Уведомляем чат
        sendMessage($chatId, "🟢 Срок временного бана пользователя {$nameLink} истёк. Он снова может вступить в чат.");

        // Уведомляем пользователя в личку
        $chatInfo = apiRequest('getChat', ['chat_id' => $chatId]);
        $chatTitle = htmlspecialchars($chatInfo['result']['title'] ?? "чат", ENT_XML1);
        sendMessage($userId,
            "✅ Ваш временный бан в чате <b>{$chatTitle}</b> истёк.\n"
            . "Вы снова можете вступить в чат."
        );

        // Помечаем как уведомлённый и удаляем запись
        $db->exec("DELETE FROM tempbans WHERE user_id = $userId AND chat_id = $chatId");
    }
}

// ─────────────────────────────────────────────
// АВТОКИК НЕ ПРОШЕДШИХ ВЕРИФИКАЦИЮ
// ─────────────────────────────────────────────
function checkExpiredVerifications(): void {
    $db      = getDB();
    $timeout = 5 * 60; // 5 минут на нажатие кнопки
    $now     = time();

    $result = $db->query(
        "SELECT user_id, chat_id, message_id FROM verification_pending WHERE joined_at < " . ($now - $timeout)
    );
    if (!$result) return;

    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $userId    = (int)$row['user_id'];
        $chatId    = (int)$row['chat_id'];
        $messageId = (int)$row['message_id'];

        // Кикаем (бан + сразу разбан = участник ушёл, но может зайти по инвайту снова)
        $banResult = apiRequest('banChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
        apiRequest('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);

        // Удаляем приветственное сообщение с кнопкой верификации
        if ($messageId > 0) {
            deleteMessage($chatId, $messageId);
        }

        $db->exec("DELETE FROM verification_pending WHERE user_id = $userId AND chat_id = $chatId");
        debugLog("checkExpiredVerifications: auto-kicked user $userId from chat $chatId (ban result: " . json_encode($banResult) . ")");
    }
}

// ─────────────────────────────────────────────
// API TELEGRAM
// ─────────────────────────────────────────────

function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): ?array {
    $params = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup) {
        $params['reply_markup'] = $replyMarkup;
    }
    return apiRequest('sendMessage', $params);
}

function sendReply(int $chatId, int $messageId, string $text): ?array {
    $result = apiRequest('sendMessage', [
        'chat_id'             => $chatId,
        'text'                => $text,
        'parse_mode'          => 'HTML',
        'reply_to_message_id' => $messageId,
    ]);
    // Если реплай не удался — отправляем обычным сообщением
    if (!($result['ok'] ?? false)) {
        $result = apiRequest('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }
    return $result;
}

function deleteMessage(int $chatId, int $messageId): void {
    apiRequest('deleteMessage', [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
    ]);
}

function apiRequest(string $method, array $params): ?array {
    $url  = API_URL . $method;
    $body = json_encode($params, JSON_UNESCAPED_UNICODE);
    $ch   = curl_init($url);
    $curlOpts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ];
    if (PROXY_HOST !== '') {
        $curlOpts[CURLOPT_PROXY]        = PROXY_HOST;
        $curlOpts[CURLOPT_PROXYTYPE]    = CURLPROXY_SOCKS5_HOSTNAME;
        $curlOpts[CURLOPT_PROXYUSERPWD] = PROXY_AUTH;
    }
    curl_setopt_array($ch, $curlOpts);
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    $decoded = $response ? json_decode($response, true) : null;
    if ($curlError) {
        file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . "apiRequest $method CURL ERROR: $curlError\n", FILE_APPEND);
    }
    if ($decoded && isset($decoded['ok']) && $decoded['ok'] === false) {
        $errCode = $decoded['error_code'] ?? '?';
        $errDesc = $decoded['description'] ?? '?';
        file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . "apiRequest $method FAILED [$errCode]: $errDesc | params: " . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
    return $decoded;
}
