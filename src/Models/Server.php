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

        // Общее количество серверов
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM servers");
        $stats['total_servers'] = $stmt->fetch()['total'];

        // Количество групп
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM server_groups");
        $stats['total_groups'] = $stmt->fetch()['total'];

        // Активные алерты (warning)
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM alerts WHERE resolved = FALSE AND severity = 'warning'");
        $stats['warnings'] = $stmt->fetch()['total'];

        // Активные алерты (critical)
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM alerts WHERE resolved = FALSE AND severity = 'critical'");
        $stats['criticals'] = $stmt->fetch()['total'];

        return $stats;
    }
}