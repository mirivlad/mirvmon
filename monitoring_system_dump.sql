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
-- Dumping data for table `agent_configs`
--

LOCK TABLES `agent_configs` WRITE;
/*!40000 ALTER TABLE `agent_configs` DISABLE KEYS */;
INSERT INTO `agent_configs` VALUES
(1,1,60,'[]',1,'2026-02-14 05:49:36','2026-02-14 05:49:36'),
(2,2,60,'[\"containerd.service\",\"nginx.service\",\"php8.3-fpm.service\"]',1,'2026-02-14 13:44:26','2026-02-14 13:52:50');
/*!40000 ALTER TABLE `agent_configs` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `agent_tokens`
--

LOCK TABLES `agent_tokens` WRITE;
/*!40000 ALTER TABLE `agent_tokens` DISABLE KEYS */;
INSERT INTO `agent_tokens` VALUES
(2,1,'37bbd66034cf35cdad603126c85c7c432cbb8ccac0873c237524a1aebfda7ccc','tPj3ysWuvze/grJ91skKP/iUD46n6mqMm/iXqjrQ3dKbBbm0/wmGrVKe8FggurfXBZ5zue3d4hE8t9qyYjBMNw==','2026-02-03 07:55:08',NULL),
(3,2,'09e4fcabdc8aa915a75ffaecfb3a7589755847f59326abf1fdfaff14e284ee5c',NULL,'2026-02-14 09:55:18','2026-02-14 10:08:14');
/*!40000 ALTER TABLE `agent_tokens` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `alerts`
--

LOCK TABLES `alerts` WRITE;
/*!40000 ALTER TABLE `alerts` DISABLE KEYS */;
/*!40000 ALTER TABLE `alerts` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `metric_names`
--

LOCK TABLES `metric_names` WRITE;
/*!40000 ALTER TABLE `metric_names` DISABLE KEYS */;
INSERT INTO `metric_names` VALUES
(1,'cpu_load','%','Загрузка процессора'),
(2,'ram_used','%','Использование оперативной памяти'),
(3,'disk_used','%','Использование диска'),
(4,'network_in','MB/s','Скорость приема сети'),
(5,'network_out','MB/s','Скорость передачи сети');
/*!40000 ALTER TABLE `metric_names` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `metric_thresholds`
--

LOCK TABLES `metric_thresholds` WRITE;
/*!40000 ALTER TABLE `metric_thresholds` DISABLE KEYS */;
/*!40000 ALTER TABLE `metric_thresholds` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `server_groups`
--

LOCK TABLES `server_groups` WRITE;
/*!40000 ALTER TABLE `server_groups` DISABLE KEYS */;
INSERT INTO `server_groups` VALUES
(1,'Default','Общая группа','fa-servers','#5fad47'),
(2,'1','','','#5b2067');
/*!40000 ALTER TABLE `server_groups` ENABLE KEYS */;
UNLOCK TABLES;

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
  `value` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_server_metric_time` (`server_id`,`metric_name_id`,`created_at`),
  KEY `metric_name_id` (`metric_name_id`),
  CONSTRAINT `server_metrics_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `server_metrics_ibfk_2` FOREIGN KEY (`metric_name_id`) REFERENCES `metric_names` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `server_metrics`
--

LOCK TABLES `server_metrics` WRITE;
/*!40000 ALTER TABLE `server_metrics` DISABLE KEYS */;
INSERT INTO `server_metrics` VALUES
(1,2,1,15.50,'2026-02-14 10:05:07'),
(2,2,2,40.20,'2026-02-14 10:05:07'),
(3,2,3,25.30,'2026-02-14 10:05:07'),
(4,2,1,1.80,'2026-02-14 10:05:13'),
(5,2,2,39.50,'2026-02-14 10:05:13'),
(6,2,3,17.80,'2026-02-14 10:05:13'),
(7,2,1,1.50,'2026-02-14 10:06:14'),
(8,2,2,39.60,'2026-02-14 10:06:14'),
(9,2,3,17.80,'2026-02-14 10:06:14'),
(10,2,1,0.50,'2026-02-14 10:06:19'),
(11,2,2,39.70,'2026-02-14 10:06:19'),
(12,2,3,17.80,'2026-02-14 10:06:19'),
(13,2,1,45.50,'2026-02-14 10:07:10'),
(14,2,2,55.20,'2026-02-14 10:07:10'),
(15,2,3,65.10,'2026-02-14 10:07:10'),
(16,2,1,0.80,'2026-02-14 10:07:15'),
(17,2,2,39.50,'2026-02-14 10:07:15'),
(18,2,3,17.80,'2026-02-14 10:07:15'),
(19,2,1,45.50,'2026-02-14 10:08:14'),
(20,2,2,55.20,'2026-02-14 10:08:14'),
(21,2,1,0.70,'2026-02-14 10:08:17'),
(22,2,2,39.70,'2026-02-14 10:08:17'),
(23,2,3,17.80,'2026-02-14 10:08:17'),
(24,2,1,0.30,'2026-02-14 10:09:18'),
(25,2,2,39.80,'2026-02-14 10:09:18'),
(26,2,3,17.80,'2026-02-14 10:09:18'),
(27,2,1,0.50,'2026-02-14 10:10:19'),
(28,2,2,40.80,'2026-02-14 10:10:19'),
(29,2,3,17.80,'2026-02-14 10:10:19'),
(30,2,1,0.20,'2026-02-14 10:11:21'),
(31,2,2,39.00,'2026-02-14 10:11:21'),
(32,2,3,17.80,'2026-02-14 10:11:21'),
(33,2,1,0.50,'2026-02-14 10:12:22'),
(34,2,2,39.00,'2026-02-14 10:12:22'),
(35,2,3,17.80,'2026-02-14 10:12:22'),
(36,2,1,5.80,'2026-02-14 10:13:24'),
(37,2,2,39.20,'2026-02-14 10:13:24'),
(38,2,3,17.80,'2026-02-14 10:13:24'),
(39,2,1,1.00,'2026-02-14 10:14:25'),
(40,2,2,39.50,'2026-02-14 10:14:25'),
(41,2,3,17.80,'2026-02-14 10:14:25'),
(42,2,1,0.50,'2026-02-14 10:15:26'),
(43,2,2,39.60,'2026-02-14 10:15:26'),
(44,2,3,17.80,'2026-02-14 10:15:26'),
(45,2,1,0.30,'2026-02-14 10:16:28'),
(46,2,2,39.60,'2026-02-14 10:16:28'),
(47,2,3,17.80,'2026-02-14 10:16:28'),
(48,2,1,0.50,'2026-02-14 10:17:29'),
(49,2,2,39.60,'2026-02-14 10:17:29'),
(50,2,3,17.80,'2026-02-14 10:17:29'),
(51,2,1,0.50,'2026-02-14 10:18:30'),
(52,2,2,39.80,'2026-02-14 10:18:30'),
(53,2,3,17.80,'2026-02-14 10:18:30'),
(54,2,1,0.30,'2026-02-14 10:19:32'),
(55,2,2,39.50,'2026-02-14 10:19:32'),
(56,2,3,17.80,'2026-02-14 10:19:32'),
(57,2,1,0.30,'2026-02-14 10:20:33'),
(58,2,2,39.70,'2026-02-14 10:20:33'),
(59,2,3,17.80,'2026-02-14 10:20:33'),
(60,2,1,1.00,'2026-02-14 10:21:35'),
(61,2,2,39.90,'2026-02-14 10:21:35'),
(62,2,3,17.80,'2026-02-14 10:21:35'),
(63,2,1,1.00,'2026-02-14 10:22:36'),
(64,2,2,39.90,'2026-02-14 10:22:36'),
(65,2,3,17.80,'2026-02-14 10:22:36'),
(66,2,1,0.80,'2026-02-14 10:23:37'),
(67,2,2,39.90,'2026-02-14 10:23:37'),
(68,2,3,17.80,'2026-02-14 10:23:37'),
(69,2,1,0.80,'2026-02-14 10:24:39'),
(70,2,2,39.90,'2026-02-14 10:24:39'),
(71,2,3,17.80,'2026-02-14 10:24:39'),
(72,2,1,0.00,'2026-02-14 10:25:40'),
(73,2,2,40.20,'2026-02-14 10:25:40'),
(74,2,3,17.80,'2026-02-14 10:25:40');
/*!40000 ALTER TABLE `server_metrics` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `servers`
--

LOCK TABLES `servers` WRITE;
/*!40000 ALTER TABLE `servers` DISABLE KEYS */;
INSERT INTO `servers` VALUES
(1,'Work_PC','',1,'',NULL,'2026-02-03 07:24:02',NULL,0),
(2,'tomas','localhost',1,'Main OpenClaw server','2026-02-14 10:25:40','2026-02-14 09:55:18','2026-02-14 10:07:10',0);
/*!40000 ALTER TABLE `servers` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_alerts`
--

LOCK TABLES `service_alerts` WRITE;
/*!40000 ALTER TABLE `service_alerts` DISABLE KEYS */;
INSERT INTO `service_alerts` VALUES
(1,2,'apport-autoreport.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(2,2,'apt-daily-upgrade.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(3,2,'apt-daily.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(4,2,'certbot.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(5,2,'cloud-init-local.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(6,2,'dm-event.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(7,2,'dmesg.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(8,2,'dpkg-db-backup.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(9,2,'e2scrub_all.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(10,2,'e2scrub_reap.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(11,2,'emergency.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(12,2,'fstrim.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(13,2,'fwupd-refresh.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(14,2,'getty-static.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(15,2,'grub-common.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(16,2,'grub-initrd-fallback.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(17,2,'initrd-cleanup.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(18,2,'initrd-parse-etc.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(19,2,'initrd-switch-root.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(20,2,'initrd-udevadm-cleanup-db.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(21,2,'iscsid.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(22,2,'ldconfig.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(23,2,'logrotate.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(24,2,'lvm2-lvmpolld.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(25,2,'man-db.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(26,2,'modprobe@configfs.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(27,2,'modprobe@dm_mod.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(28,2,'modprobe@drm.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(29,2,'modprobe@efi_pstore.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(30,2,'modprobe@fuse.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(31,2,'modprobe@loop.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(32,2,'motd-news.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(33,2,'netplan-ovs-cleanup.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(34,2,'networkd-dispatcher.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(35,2,'nftables.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(36,2,'open-iscsi.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(37,2,'open-vm-tools.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(38,2,'phpsessionclean.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(39,2,'plymouth-start.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(40,2,'plymouth-switch-root.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(41,2,'pollinate.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(42,2,'rc-local.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(43,2,'rescue.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(44,2,'secureboot-db.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(45,2,'snapd.autoimport.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(46,2,'snapd.core-fixup.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(47,2,'snapd.failure.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(48,2,'snapd.recovery-chooser-trigger.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(49,2,'snapd.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(50,2,'snapd.snap-repair.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(51,2,'snapd.system-shutdown.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(52,2,'sysstat-collect.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(53,2,'sysstat-summary.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(54,2,'systemd-ask-password-console.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(55,2,'systemd-ask-password-plymouth.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(56,2,'systemd-ask-password-wall.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(57,2,'systemd-battery-check.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(58,2,'systemd-bsod.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(59,2,'systemd-firstboot.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(60,2,'systemd-fsck-root.service','stopped','critical',0,'2026-02-14 10:05:13',NULL),
(61,2,'server-monitor-agent.service','stopped','critical',0,'2026-02-14 10:06:19',NULL);
/*!40000 ALTER TABLE `service_alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_status`
--

DROP TABLE IF EXISTS `service_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `service_name` varchar(100) NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=2467 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_status`
--

LOCK TABLES `service_status` WRITE;
/*!40000 ALTER TABLE `service_status` DISABLE KEYS */;
INSERT INTO `service_status` VALUES
(1,2,'ssh','running','loaded','active','running','2026-02-14 10:05:07','2026-02-14 10:05:07'),
(2,2,'apparmor.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(3,2,'apport-autoreport.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(4,2,'apport.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(5,2,'apt-daily-upgrade.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(6,2,'apt-daily.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(7,2,'●','unknown','rbdmap.service','not-found','inactive','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(8,2,'blk-availability.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(9,2,'certbot.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(10,2,'cloud-init-local.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(13,2,'console-setup.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(14,2,'containerd.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(15,2,'cron.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(16,2,'dbus.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(18,2,'dm-event.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(19,2,'dmesg.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(20,2,'docker.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(21,2,'dpkg-db-backup.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(22,2,'e2scrub_all.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(23,2,'e2scrub_reap.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(24,2,'emergency.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(25,2,'fail2ban.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(27,2,'finalrd.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(29,2,'fstrim.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(30,2,'fwupd-refresh.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(31,2,'fwupd.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(32,2,'getty-static.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(33,2,'getty@tty1.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(34,2,'grub-common.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(35,2,'grub-initrd-fallback.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(37,2,'initrd-cleanup.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(38,2,'initrd-parse-etc.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(39,2,'initrd-switch-root.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(40,2,'initrd-udevadm-cleanup-db.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(45,2,'iscsid.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(47,2,'keyboard-setup.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(48,2,'kmod-static-nodes.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(49,2,'ldconfig.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(50,2,'logrotate.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(52,2,'lvm2-lvmpolld.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(53,2,'lvm2-monitor.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(55,2,'man-db.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(56,2,'mariadb.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(57,2,'ModemManager.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(58,2,'modprobe@configfs.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(59,2,'modprobe@dm_mod.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(60,2,'modprobe@drm.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(61,2,'modprobe@efi_pstore.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(62,2,'modprobe@fuse.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(63,2,'modprobe@loop.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(64,2,'mon-server.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(67,2,'motd-news.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(68,2,'multipathd.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(69,2,'netplan-ovs-cleanup.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(70,2,'networkd-dispatcher.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(72,2,'nftables.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(73,2,'nginx.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(74,2,'open-iscsi.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(75,2,'open-vm-tools.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(77,2,'php8.3-fpm.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(78,2,'phpsessionclean.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(79,2,'plymouth-quit-wait.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(80,2,'plymouth-quit.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(81,2,'plymouth-read-write.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(82,2,'plymouth-start.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(83,2,'plymouth-switch-root.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(84,2,'polkit.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(85,2,'pollinate.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(87,2,'rc-local.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(88,2,'rescue.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(89,2,'rsyslog.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(90,2,'secureboot-db.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(91,2,'server-monitor-agent.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(92,2,'setvtrgb.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(93,2,'snapd.apparmor.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(94,2,'snapd.autoimport.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(95,2,'snapd.core-fixup.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(96,2,'snapd.failure.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(97,2,'snapd.recovery-chooser-trigger.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(98,2,'snapd.seeded.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(99,2,'snapd.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(100,2,'snapd.snap-repair.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(101,2,'snapd.system-shutdown.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(102,2,'ssh.service','running','loaded','active','running','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(103,2,'sysstat-collect.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(104,2,'sysstat-summary.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(105,2,'sysstat.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(106,2,'systemd-ask-password-console.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(107,2,'systemd-ask-password-plymouth.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(108,2,'systemd-ask-password-wall.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(109,2,'systemd-battery-check.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(110,2,'systemd-binfmt.service','running','loaded','active','exited','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(111,2,'systemd-bsod.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(112,2,'systemd-firstboot.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(113,2,'systemd-fsck-root.service','stopped','loaded','inactive','dead','2026-02-14 10:25:40','2026-02-14 10:05:13'),
(338,2,'test','running','','','','2026-02-14 10:07:10','2026-02-14 10:07:10');
/*!40000 ALTER TABLE `service_status` ENABLE KEYS */;
UNLOCK TABLES;

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
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_notification_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_notification_settings`
--

LOCK TABLES `user_notification_settings` WRITE;
/*!40000 ALTER TABLE `user_notification_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_notification_settings` ENABLE KEYS */;
UNLOCK TABLES;

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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'admin','$2y$10$5PhDSHiF1J6yxcEldOsluOSmUYaO1bWa7swFmfmP/Slj.HJOh5t2O','admin@example.com','admin','2026-02-03 06:58:49'),
(2,'jarvis','$2y$10$vtqwvVd4Sd/RqZI33eW94Oxk8k343hUbHkIJEkjP18u5z6Ugj8VSy','jarvis@mon.mirv.top','user','2026-02-14 09:51:02');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-02-14 15:09:12
