<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Простая проверка, что PHP работает
echo "Debug: PHP is working<br>";

try {
    // Попробуем подключить ваш config
    require_once __DIR__ . '/manager-config.php';
    echo "Config loaded<br>";
} catch (Throwable $e) {
    echo "Error loading config: " . $e->getMessage() . "<br>";
    exit;
}

// Проверка PDO
try {
    $db = new PDO('sqlite:' . DB_FILE);
    echo "PDO SQLite connection OK<br>";
} catch (Throwable $e) {
    echo "PDO Error: " . $e->getMessage() . "<br>";
    exit;
}

// Попробуем выполнить функцию обработки (но без получения update – просто проверим, что нет фатальных ошибок)
if (function_exists('handleMessage')) {
    echo "handleMessage function exists<br>";
} else {
    echo "handleMessage function not found<br>";
}

echo "Debug finished – no fatal errors yet.";