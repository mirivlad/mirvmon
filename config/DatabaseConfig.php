<?php
// config/DatabaseConfig.php

namespace Config;

use PDO;
use PDOException;

class DatabaseConfig 
{
    private static $instance = null;
    private $connection;

    private $host = 'localhost';
    private $db_name = 'monitoring_system';
    private $username = "mon_user";
    private $password = 'mon_password_123';
    private $charset = 'utf8mb4';

    private function __construct() 
    {
        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance->connection;
    }
}