<?php
// src/Models/User.php

namespace App\Models;

class User extends Model
{
    public function findByUsername($username)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        return $stmt->fetch();
    }

    public function authenticate($username, $password)
    {
        $user = $this->findByUsername($username);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }
        
        return false;
    }

    public function create($username, $password, $email, $role = 'user')
    {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, password_hash, email, role) 
            VALUES (:username, :password_hash, :email, :role)
        ");
        
        return $stmt->execute([
            ':username' => $username,
            ':password_hash' => $passwordHash,
            ':email' => $email,
            ':role' => $role
        ]);
    }
}