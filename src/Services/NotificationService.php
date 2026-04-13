<?php
// src/Services/NotificationService.php

namespace App\Services;

use Config\DatabaseConfig;

class NotificationService
{
    private $pdo;
    private $settings;

    public function __construct()
    {
        $this->pdo = DatabaseConfig::getInstance();
        $this->loadSettings();
    }

    private function loadSettings()
    {
        $stmt = $this->pdo->prepare("SELECT * FROM global_notification_settings WHERE id = 1");
        $stmt->execute();
        $this->settings = $stmt->fetch();
    }

    /**
     * Отправить уведомление о превышении порога
     */
    public function sendThresholdNotification($serverName, $metricName, $value, $severity, $threshold)
    {
        if ($severity === 'warning') {
            $severityText = 'ПРЕДУПРЕЖДЕНИЕ';
            $emoji = '⚠️';
        } else {
            $severityText = 'КРИТИЧЕСКИЙ';
            $emoji = '🚨';
        }
        
        $subject = "{$emoji} {$severityText}: {$metricName}";
        $message = "🖥 Сервер: {$serverName}\n";
        $message .= "📊 Метрика: {$metricName}\n";
        $message .= "📈 Значение: {$value}\n";
        $message .= "📏 Порог: {$threshold}\n";
        $message .= "🕒 Время: " . date('d.m.Y H:i:s') . "\n";
        $message .= "🏷 Серьёзность: {$severityText}";

        $this->sendNotification($subject, $message);
    }

    /**
     * Отправить уведомление о восстановлении (порог в норме)
     */
    public function sendRecoveryNotification($serverName, $metricName, $value)
    {
        $subject = "✅ Восстановление: {$metricName} в норме";
        $message = "🖥 Сервер: {$serverName}\n";
        $message .= "📊 Метрика: {$metricName}\n";
        $message .= "📈 Текущее значение: {$value}\n";
        $message .= "🟢 Статус: Порог более не превышен\n";
        $message .= "🕒 Время: " . date('d.m.Y H:i:s');

        $this->sendNotification($subject, $message);
    }

    /**
     * Отправить уведомление о сервисе (остановка/запуск)
     */
    public function sendServiceNotification($serverName, $serviceName, $action)
    {
        // $action: 'stopped' или 'running'
        if ($action === 'running') {
            $emoji = '✅';
            $actionText = 'Запуск сервиса';
            $descText = "Сервис {$serviceName} запущен";
            $severityText = 'ВОССТАНОВЛЕНИЕ';
        } else {
            $emoji = '🛑';
            $actionText = 'Остановка сервиса';
            $descText = "Сервис {$serviceName} остановлен";
            $severityText = 'ОСТАНОВЛЕН';
        }

        $subject = "{$emoji} {$actionText}: {$serviceName}";
        $message = "🖥 Сервер: {$serverName}\n";
        $message .= "⚙️ Сервис: {$serviceName}\n";
        $message .= "📝 Действие: {$descText}\n";
        $message .= "🏷 Статус: {$severityText}\n";
        $message .= "🕒 Время: " . date('d.m.Y H:i:s');

        $this->sendNotification($subject, $message);
    }

    /**
     * Внутренний метод отправки (Email + Telegram)
     */
    private function sendNotification($subject, $message)
    {
        // Отправка Email
        if (!empty($this->settings['email_enabled']) && !empty($this->settings['smtp_host'])) {
            $this->sendEmail($subject, $message);
        }

        // Отправка Telegram
        if (!empty($this->settings['telegram_enabled']) && !empty($this->settings['telegram_bot_token'])) {
            $this->sendTelegram($subject, $message);
        }
    }

    /**
     * Отправить уведомление о алерте (для обратной совместимости)
     * @deprecated Используйте sendThresholdNotification или sendServiceNotification
     */
    public function sendAlertNotification($serverName, $metricName, $value, $severity, $threshold)
    {
        if ($severity === 'resolved') {
            $this->sendRecoveryNotification($serverName, $metricName, $value);
        } else {
            $this->sendThresholdNotification($serverName, $metricName, $value, $severity, $threshold);
        }
    }

    /**
     * Отправить тестовое уведомление
     */
    public function sendTestNotification($email, $telegramChatId)
    {
        $subject = "✅ Тест уведомлений — Система мониторинга";
        $message = "Это тестовое сообщение от системы мониторинга.\n";
        $message .= "Время: " . date('d.m.Y H:i:s') . "\n";
        $message .= "Если вы получили это сообщение — уведомления работают корректно.";

        $results = [];

        // Тест Email
        if (!empty($email)) {
            $results['email'] = $this->sendEmail($subject, $message, $email);
        }

        // Тест Telegram
        if (!empty($telegramChatId)) {
            $results['telegram'] = $this->sendTelegram($subject, $message, $telegramChatId);
        }

        return $results;
    }

    /**
     * Отправить Email
     */
    private function sendEmail($subject, $message, $overrideEmail = null)
    {
        $to = $overrideEmail ?? $this->settings['smtp_from_email'];
        if (empty($to) || empty($this->settings['smtp_host'])) {
            return ['success' => false, 'error' => 'Не настроен SMTP'];
        }

        $headers = "From: {$this->settings['smtp_from_email']}\r\n";
        $headers .= "Reply-To: {$this->settings['smtp_from_email']}\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Если SMTP с авторизацией — используем PHPMailer через curl
        if (!empty($this->settings['smtp_username'])) {
            return $this->sendEmailViaSmtp($to, $subject, $message);
        }

        // Простая отправка через mail()
        if (mail($to, $subject, $message, $headers)) {
            return ['success' => true, 'message' => 'Email отправлен'];
        } else {
            return ['success' => false, 'error' => 'Ошибка отправки Email'];
        }
    }

    /**
     * Отправить через SMTP с авторизацией (curl)
     */
    private function sendEmailViaSmtp($to, $subject, $message)
    {
        $host = $this->settings['smtp_host'];
        $port = $this->settings['smtp_port'];
        $username = $this->settings['smtp_username'];
        $password = $this->settings['smtp_password'];
        $from = $this->settings['smtp_from_email'];
        $encryption = $this->settings['smtp_encryption'];

        // Формируем URL
        $scheme = ($encryption === 'ssl') ? 'smtps' : 'smtp';
        $url = "{$scheme}://{$host}:{$port}";

        // Формируем email
        $email = "Date: " . date('r') . "\r\n";
        $email .= "From: {$from}\r\n";
        $email .= "To: {$to}\r\n";
        $email .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $email .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $email .= "\r\n" . $message . "\r\n";

        // Используем curl для отправки через SMTP
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_MAIL_FROM, $from);
        curl_setopt($ch, CURLOPT_MAIL_RCPT, [$to]);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_READDATA, fopen('php://temp', 'r+'));
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($email));
        curl_setopt($ch, CURLOPT_USERNAME, $username);
        curl_setopt($ch, CURLOPT_PASSWORD, $password);
        curl_setopt($ch, CURLOPT_USE_SSL, ($encryption === 'none') ? CURLUSESSL_NONE : CURLUSESSL_ALL);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Для отправки через curl нужно записать данные в stream
        $temp = fopen('php://temp', 'w+');
        fwrite($temp, $email);
        rewind($temp);
        curl_setopt($ch, CURLOPT_INFILE, $temp);
        curl_setopt($ch, CURLOPT_UPLOAD, true);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($temp);

        if ($result !== false && in_array($httpCode, [250, 221])) {
            return ['success' => true, 'message' => 'Email отправлен через SMTP'];
        } else {
            return ['success' => false, 'error' => "SMTP ошибка: " . ($error ?: "HTTP {$httpCode}")];
        }
    }

    /**
     * Отправить Telegram сообщение
     */
    private function sendTelegram($subject, $message, $overrideChatId = null)
    {
        $botToken = $this->settings['telegram_bot_token'];
        $chatId = $overrideChatId ?? $this->settings['telegram_chat_id'];

        if (empty($botToken) || empty($chatId)) {
            return ['success' => false, 'error' => 'Не настроен Telegram'];
        }

        $text = "<b>{$subject}</b>\n\n" . str_replace("\n", "\n", $message);

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $postData = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        // Настройки прокси для Telegram
        $proxy = $this->settings['telegram_proxy'] ?? 'http://127.0.0.1:1081';
        if (!empty($proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => "Ошибка соединения: {$error}"];
        }

        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['ok']) && $data['ok']) {
            return ['success' => true, 'message' => 'Telegram сообщение отправлено'];
        } else {
            $errorMsg = $data['description'] ?? 'Неизвестная ошибка';
            return ['success' => false, 'error' => "Telegram API: {$errorMsg}"];
        }
    }

    /**
     * Получить настройки
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * Сохранить настройки
     */
    public function saveSettings($data)
    {
        $stmt = $this->pdo->prepare("
            UPDATE global_notification_settings SET
                smtp_host = :smtp_host,
                smtp_port = :smtp_port,
                smtp_username = :smtp_username,
                smtp_password = :smtp_password,
                smtp_encryption = :smtp_encryption,
                smtp_from_email = :smtp_from_email,
                telegram_bot_token = :telegram_bot_token,
                telegram_chat_id = :telegram_chat_id,
                telegram_proxy = :telegram_proxy,
                email_enabled = :email_enabled,
                telegram_enabled = :telegram_enabled,
                notify_on_warning = :notify_on_warning,
                notify_on_critical = :notify_on_critical
            WHERE id = 1
        ");

        $stmt->execute([
            ':smtp_host' => $data['smtp_host'] ?? '',
            ':smtp_port' => (int)($data['smtp_port'] ?? 587),
            ':smtp_username' => $data['smtp_username'] ?? '',
            ':smtp_password' => $data['smtp_password'] ?? '',
            ':smtp_encryption' => $data['smtp_encryption'] ?? 'tls',
            ':smtp_from_email' => $data['smtp_from_email'] ?? '',
            ':telegram_bot_token' => $data['telegram_bot_token'] ?? '',
            ':telegram_chat_id' => $data['telegram_chat_id'] ?? '',
            ':telegram_proxy' => $data['telegram_proxy'] ?? 'http://127.0.0.1:1081',
            ':email_enabled' => !empty($data['email_enabled']) ? 1 : 0,
            ':telegram_enabled' => !empty($data['telegram_enabled']) ? 1 : 0,
            ':notify_on_warning' => !empty($data['notify_on_warning']) ? 1 : 0,
            ':notify_on_critical' => !empty($data['notify_on_critical']) ? 1 : 0,
        ]);

        // Перезагружаем настройки
        $this->loadSettings();

        return $this->settings;
    }
}
