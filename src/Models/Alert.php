<?php
// src/Models/Alert.php

namespace App\Models;

use Config\DatabaseConfig;
use PDO;

class Alert
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseConfig::getInstance();
    }

    public function getAll($resolved = false)
    {
        $stmt = $this->db->prepare("SELECT a.*, s.name as server_name, sg.name as group_name, sg.icon as group_icon 
                                     FROM alerts a 
                                     JOIN servers s ON a.server_id = s.id 
                                     LEFT JOIN server_groups sg ON s.group_id = sg.id 
                                     WHERE a.resolved = ? 
                                     ORDER BY a.created_at DESC");
        $stmt->execute([$resolved ? 1 : 0]);
        return $stmt->fetchAll();
    }

    public function getById($id)
    {
        $stmt = $this->db->prepare("SELECT a.*, s.name as server_name, sg.name as group_name, sg.icon as group_icon 
                                     FROM alerts a 
                                     JOIN servers s ON a.server_id = s.id 
                                     LEFT JOIN server_groups sg ON s.group_id = sg.id 
                                     WHERE a.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function markAsResolved($id)
    {
        $stmt = $this->db->prepare("UPDATE alerts SET resolved = TRUE, resolved_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }
}