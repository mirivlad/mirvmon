<?php
// src/Models/Model.php

namespace App\Models;

use Config\DatabaseConfig;

abstract class Model
{
    protected $pdo;

    public function __construct()
    {
        $this->pdo = DatabaseConfig::getInstance();
    }

    /**
     * Экранирование данных для безопасности
     */
    protected function sanitize($data)
    {
        if (is_array($data)) {
            return array_map('htmlspecialchars', $data);
        }
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
}