<?php
// src/Models/Server.php

namespace App\Models;

use Config\DatabaseConfig;
use PDO;

class Server
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseConfig::getInstance();
    }

    public function getAll()
    {
        $stmt = $this->db->query("SELECT s.*, sg.name as group_name, sg.icon as group_icon, sg.color as group_color
                                   FROM servers s
                                   LEFT JOIN server_groups sg ON s.group_id = sg.id
                                   ORDER BY s.name");
        return $stmt->fetchAll();
    }

    public function getById($id)
    {
        $stmt = $this->db->prepare("SELECT s.*, sg.name as group_name, sg.icon as group_icon, sg.color as group_color
                                     FROM servers s
                                     LEFT JOIN server_groups sg ON s.group_id = sg.id
                                     WHERE s.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function getStats()
    {
        $stats = [];

        $stmt = $this->db->query("SELECT COUNT(*) as total FROM servers");
        $stats['total_servers'] = $stmt->fetch()['total'];

        $stmt = $this->db->query("SELECT COUNT(*) as total FROM servers WHERE last_metrics_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stats['servers_with_metrics'] = $stmt->fetch()['total'];

        $stmt = $this->db->query("SELECT COUNT(*) as total FROM server_groups");
        $stats['total_groups'] = $stmt->fetch()['total'];

        $stmt = $this->db->query("SELECT COUNT(*) as total FROM alerts WHERE resolved = FALSE");
        $stats['alerts_count'] = $stmt->fetch()['total'];

        $stmt = $this->db->query("SELECT COUNT(*) as total FROM alerts WHERE resolved = FALSE AND severity = 'warning'");
        $stats['warnings'] = $stmt->fetch()['total'];

        $stmt = $this->db->query("SELECT COUNT(*) as total FROM alerts WHERE resolved = FALSE AND severity = 'critical'");
        $stats['criticals'] = $stmt->fetch()['total'];

        return $stats;
    }

    /**
     * Получить серверы с вычисленным статусом
     * Status вычисляется в SQL через CASE WHEN
     */
    public function getServersWithStatus()
    {
        $stmt = $this->db->query("
            SELECT
                s.id,
                s.name,
                s.address,
                s.description,
                s.last_metrics_at,
                s.created_at,
                sg.name as group_name,
                sg.icon as group_icon,
                sg.color as group_color,
                TIMESTAMPDIFF(SECOND, s.last_metrics_at, NOW()) as seconds_since_update,
                CASE
                    WHEN s.last_metrics_at IS NULL THEN 'offline'
                    WHEN TIMESTAMPDIFF(SECOND, s.last_metrics_at, NOW()) > 300 THEN 'offline'
                    ELSE 'online'
                END as status
            FROM servers s
            LEFT JOIN server_groups sg ON s.group_id = sg.id
            ORDER BY s.name
        ");
        $servers = $stmt->fetchAll();

        foreach ($servers as &$server) {
            // Получаем последние метрики
            $stmt2 = $this->db->prepare("
                SELECT mn.name, sm.value, mn.unit
                FROM server_metrics sm
                JOIN metric_names mn ON sm.metric_name_id = mn.id
                WHERE sm.server_id = :server_id
                AND mn.name NOT LIKE '%_proc'
                ORDER BY sm.created_at DESC
                LIMIT 10
            ");
            $stmt2->execute([':server_id' => $server['id']]);
            $metrics = $stmt2->fetchAll();

            $server['latest_metrics'] = [];
            foreach ($metrics as $m) {
                if (!isset($server['latest_metrics'][$m['name']])) {
                    $server['latest_metrics'][$m['name']] = $m;
                }
            }

            // Проверяем активные алерты
            $stmt3 = $this->db->prepare("
                SELECT COUNT(*) as cnt FROM alerts
                WHERE server_id = :server_id AND resolved = FALSE
            ");
            $stmt3->execute([':server_id' => $server['id']]);
            $activeAlerts = $stmt3->fetch()['cnt'];

            if ($server['status'] === 'online' && $activeAlerts > 0) {
                $server['status'] = 'warning';
            }

            $server['active_alerts'] = (int)$activeAlerts;
        }

        return $servers;
    }
}
