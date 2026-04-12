<?php
// src/Models/Group.php

namespace App\Models;

use Config\DatabaseConfig;
use PDO;

class Group
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseConfig::getInstance();
    }

    public function getAll()
    {
        $stmt = $this->db->query("SELECT * FROM server_groups ORDER BY name");
        return $stmt->fetchAll();
    }

    public function getById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM server_groups WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function create($data)
    {
        $stmt = $this->db->prepare("INSERT INTO server_groups (name, description, icon, color) 
                                     VALUES (?, ?, ?, ?)");
        return $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['icon'] ?? null,
            $data['color'] ?? null
        ]);
    }

    public function update($id, $data)
    {
        $stmt = $this->db->prepare("UPDATE server_groups 
                                     SET name = ?, description = ?, icon = ?, color = ? 
                                     WHERE id = ?");
        return $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['icon'] ?? null,
            $data['color'] ?? null,
            $id
        ]);
    }

    public function delete($id)
    {
        $stmt = $this->db->prepare("DELETE FROM server_groups WHERE id = ?");
        return $stmt->execute([$id]);
    }
}