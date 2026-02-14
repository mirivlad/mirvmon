<?php
session_start();

// Простая проверка пароля (для теста)
if (isset($_POST['username']) && isset($_POST['password'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Хешированный пароль для admin_test_2026
    $correctHash = '$2y$10$5PhDSHiF1J6yxcEldOsluOSmUYaO1bWa7swFmfmP/Slj.HJOh5t2O';
    $inputHash = password_hash($password, PASSWORD_DEFAULT);
    
    // Для теста используем прямое сравнение хешей
    if ($username === 'admin' && password_verify($password, $correctHash)) {
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'admin';
        $_SESSION['role'] = 'admin';
        $_SESSION['logged_in'] = time();
        
        header('Location: /');
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Тест входа</title>
    <meta charset="utf-8">
</head>
<body>
    <h1>Тестовый вход</h1>
    <form method="post">
        <p>
            Логин: <input type="text" name="username" value="admin">
        </p>
        <p>
            Пароль: <input type="password" name="password">
        </p>
        <p>
            <button type="submit">Войти</button>
        </p>
    </form>
    <p>Тестовые креды: admin / admin_test_2026</p>
</body>
</html>
