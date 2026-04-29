<?php
$update = json_decode(file_get_contents('php://input'), true);
if (isset($update['message'])) {
    $chatId = $update['message']['chat']['id'];
    $text = $update['message']['text'];
    $token = '8773904824:AAEGRkDAayHsgmzGW63LNlq4SZ6b0LtwUrA'; // замените на актуальный, неопубликованный
    file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?chat_id={$chatId}&text=Эхо: {$text}");
}
echo "OK";
