/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: monitoring_system
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `agent_configs`
--

DROP TABLE IF EXISTS `agent_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_configs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `interval_seconds` int(11) DEFAULT 60,
  `monitor_services` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Массив сервисов для мониторинга' CHECK (json_valid(`monitor_services`)),
  `enabled` tinyint(1) DEFAULT 1 COMMENT 'Включен ли агент',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_id` (`server_id`),
  CONSTRAINT `agent_configs_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `agent_tokens`
--

DROP TABLE IF EXISTS `agent_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `encrypted_token` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_id` (`server_id`),
  CONSTRAINT `agent_tokens_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alerts`
--

DROP TABLE IF EXISTS `alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `metric_name` varchar(50) NOT NULL,
  `value` decimal(8,2) DEFAULT NULL,
  `severity` enum('warning','critical') NOT NULL,
  `resolved` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `global_notification_settings`
--

DROP TABLE IF EXISTS `global_notification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `global_notification_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `smtp_host` varchar(255) DEFAULT '',
  `smtp_port` int(11) DEFAULT 587,
  `smtp_username` varchar(255) DEFAULT '',
  `smtp_password` varchar(255) DEFAULT '',
  `smtp_encryption` enum('tls','ssl','none') DEFAULT 'tls',
  `smtp_from_email` varchar(255) DEFAULT '',
  `telegram_bot_token` varchar(255) DEFAULT '',
  `telegram_chat_id` varchar(100) DEFAULT '',
  `telegram_proxy` varchar(255) DEFAULT 'http://127.0.0.1:1081',
  `email_enabled` tinyint(1) DEFAULT 0,
  `telegram_enabled` tinyint(1) DEFAULT 0,
  `notify_on_warning` tinyint(1) DEFAULT 1,
  `notify_on_critical` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `metric_names`
--

DROP TABLE IF EXISTS `metric_names`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `metric_names` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `metric_thresholds`
--

DROP TABLE IF EXISTS `metric_thresholds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `metric_thresholds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `metric_name_id` int(11) NOT NULL,
  `warning_threshold` decimal(8,2) DEFAULT NULL,
  `critical_threshold` decimal(8,2) DEFAULT NULL,
  `duration` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  KEY `metric_name_id` (`metric_name_id`),
  CONSTRAINT `metric_thresholds_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `metric_thresholds_ibfk_2` FOREIGN KEY (`metric_name_id`) REFERENCES `metric_names` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `server_groups`
--

DROP TABLE IF EXISTS `server_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `server_metrics`
--

DROP TABLE IF EXISTS `server_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_metrics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `metric_name_id` int(11) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_server_metric_time` (`server_id`,`metric_name_id`,`created_at`),
  KEY `metric_name_id` (`metric_name_id`),
  CONSTRAINT `server_metrics_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `server_metrics_ibfk_2` FOREIGN KEY (`metric_name_id`) REFERENCES `metric_names` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=395224 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `servers`
--

DROP TABLE IF EXISTS `servers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `group_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `last_metrics_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_service_check_at` timestamp NULL DEFAULT NULL COMMENT 'Время последней проверки сервисов',
  `service_check_enabled` tinyint(1) DEFAULT 0 COMMENT 'Включена ли проверка сервисов',
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `servers_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `server_groups` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_alerts`
--

DROP TABLE IF EXISTS `service_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `service_name` varchar(100) NOT NULL,
  `status` enum('stopped','running','unknown') NOT NULL,
  `severity` enum('warning','critical') DEFAULT 'warning' COMMENT 'Уровень важности (всегда critical для остановленных сервисов)',
  `resolved` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_server_service` (`server_id`,`service_name`),
  CONSTRAINT `service_alerts_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=104 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_status`
--

DROP TABLE IF EXISTS `service_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `service_name` varchar(255) NOT NULL,
  `status` enum('running','stopped','unknown') NOT NULL,
  `load_state` varchar(50) DEFAULT NULL,
  `active_state` varchar(50) DEFAULT NULL,
  `sub_state` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_server_service` (`server_id`,`service_name`),
  KEY `idx_server_updated` (`server_id`,`updated_at`),
  CONSTRAINT `service_status_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14919756 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_notification_settings`
--

DROP TABLE IF EXISTS `user_notification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_notification_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `telegram_chat_id` varchar(50) DEFAULT NULL,
  `email_for_alerts` varchar(100) DEFAULT NULL,
  `enabled_notifications` tinyint(1) DEFAULT 1,
  `notify_on_warning` tinyint(1) DEFAULT 1,
  `notify_on_critical` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_notification_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-13  0:54:46


--
-- Примеры данных для демонстрации и тестирования
--

-- Пользователи (пароль для обоих: admin123)
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `is_active`, `created_at`) VALUES
(1, 'admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW()),
(2, 'operator', 'operator@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'operator', 1, NOW());

-- Группы серверов
INSERT INTO `server_groups` (`id`, `name`, `description`, `icon`, `color`, `sort_order`) VALUES
(1, 'Production', 'Продакшн серверы', 'fa-server', '#dc3545', 1),
(2, 'Staging', 'Тестовые серверы', 'fa-flask', '#ffc107', 2),
(3, 'Database', 'Серверы баз данных', 'fa-database', '#0dcaf0', 3);

-- Серверы
INSERT INTO `servers` (`id`, `name`, `address`, `description`, `group_id`, `is_active`, `created_at`) VALUES
(1, 'web-server-01', '192.168.1.10', 'Основной веб-сервер', 1, 1, NOW()),
(2, 'web-server-02', '192.168.1.11', 'Веб-сервер (резервный)', 1, 1, NOW()),
(3, 'db-server-01', '192.168.1.20', 'PostgreSQL основной', 3, 1, NOW()),
(4, 'staging-app', '192.168.1.30', 'Staging окружение', 2, 1, NOW());

-- Настройки агентов
INSERT INTO `agent_configs` (`id`, `server_id`, `interval_seconds`, `monitor_services`, `enabled`) VALUES
(1, 1, 60, '["nginx", "php-fpm", "sshd"]', 1),
(2, 2, 60, '["nginx", "php-fpm"]', 1),
(3, 3, 30, '["postgresql", "sshd"]', 1),
(4, 4, 120, '["nginx"]', 1);

-- Названия метрик
INSERT INTO `metric_names` (`id`, `name`, `description`, `unit`) VALUES
(1, 'cpu_load', 'Загрузка CPU', '%'),
(2, 'ram_used', 'Использование RAM', '%'),
(3, 'disk_used', 'Использование диска', '%'),
(4, 'network_rx', 'Входящий трафик', 'Mbps'),
(5, 'network_tx', 'Исходящий трафик', 'Mbps'),
(6, 'disk_read', 'Чтение диска', 'MB/s'),
(7, 'disk_write', 'Запись диска', 'MB/s');

-- Примеры метрик (последние сутки)
INSERT INTO `server_metrics` (`server_id`, `metric_name`, `value`, `time_bucket`) VALUES
(1, 'cpu_load', 45.2, NOW() - INTERVAL 1 HOUR),
(1, 'cpu_load', 62.8, NOW() - INTERVAL 50 MINUTE),
(1, 'cpu_load', 78.1, NOW() - INTERVAL 40 MINUTE),
(1, 'cpu_load', 55.3, NOW() - INTERVAL 30 MINUTE),
(1, 'cpu_load', 41.7, NOW() - INTERVAL 20 MINUTE),
(1, 'cpu_load', 38.9, NOW() - INTERVAL 10 MINUTE),
(1, 'ram_used', 67.5, NOW() - INTERVAL 1 HOUR),
(1, 'ram_used', 68.2, NOW() - INTERVAL 30 MINUTE),
(1, 'ram_used', 69.1, NOW() - INTERVAL 10 MINUTE),
(1, 'disk_used', 72.3, NOW() - INTERVAL 1 HOUR),
(3, 'cpu_load', 23.4, NOW() - INTERVAL 1 HOUR),
(3, 'cpu_load', 31.2, NOW() - INTERVAL 30 MINUTE),
(3, 'ram_used', 82.1, NOW() - INTERVAL 1 HOUR),
(3, 'ram_used', 83.5, NOW() - INTERVAL 30 MINUTE);

-- Пороги
INSERT INTO `metric_thresholds` (`server_id`, `metric_name`, `warning_threshold`, `critical_threshold`, `duration_minutes`) VALUES
(1, 'cpu_load', 80.0, 90.0, 5),
(1, 'ram_used', 85.0, 95.0, 10),
(3, 'cpu_load', 75.0, 85.0, 5),
(3, 'ram_used', 80.0, 90.0, 5);

-- Алерты
INSERT INTO `alerts` (`id`, `server_id`, `metric_name`, `value`, `severity`, `resolved`, `created_at`, `resolved_at`) VALUES
(1, 3, 'ram_used', 82.10, 'warning', 1, NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 1 HOUR),
(2, 1, 'cpu_load', 91.50, 'critical', 0, NOW() - INTERVAL 30 MINUTE, NULL);

-- Статусы сервисов
INSERT INTO `service_status` (`server_id`, `service_name`, `load_state`, `active_state`, `last_check`, `is_monitored`) VALUES
(1, 'nginx', 'loaded', 'active', NOW(), 1),
(1, 'php8.2-fpm', 'loaded', 'active', NOW(), 1),
(1, 'sshd', 'loaded', 'active', NOW(), 1),
(3, 'postgresql', 'loaded', 'active', NOW(), 1),
(3, 'sshd', 'loaded', 'active', NOW(), 1);

-- Глобальные настройки уведомлений
INSERT INTO `global_notification_settings` (`id`, `smtp_host`, `smtp_port`, `smtp_username`, `smtp_from_email`, `telegram_bot_token`, `telegram_chat_id`, `telegram_proxy`, `email_enabled`, `telegram_enabled`) VALUES
(1, 'smtp.example.com', 587, 'noreply@example.com', 'noreply@example.com', '123456789:AAExampleBotToken', '-1001234567890', '', 0, 0);
