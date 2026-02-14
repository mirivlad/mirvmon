<?php
// Простое логирование POST запросов для отладки

ini_set("session.save_path", "/var/www/mon/sessions");
session_start();

// Логируем все входящие данные
$timestamp = date('Y-m-d H:i:s');
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$postData = file_get_contents("php://input");

$logLine = "$timestamp - $method $uri\n";
$logLine .= "POST data: $postData\n";
$logLine .= "POST params: " . json_encode($_POST, JSON_PRETTY_PRINT) . "\n";
$logLine .= "SESSION user_id: " . ($_SESSION['user_id'] ?? 'null') . "\n";
$logLine .= "-------------------\n";

file_put_contents('/tmp/login_debug.log', $logLine, FILE_APPEND);

// Продолжаем с обычным кодом
require_once '/var/www/mon/vendor/autoload.php';

use App\Models\User;

if ($method === 'POST' && $uri === '/login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    echo "DEBUG: Processing login for user: $username\n";
    echo "DEBUG: POST data: $postData\n";

    $userModel = new User();
    $user = $userModel->authenticate($username, $password);

    if ($user) {
        echo "DEBUG: Auth successful!\n";

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        header('Location: /');
        exit;
    } else {
        echo "DEBUG: Auth failed!\n";
        header('Location: /login');
        exit;
    }
}
?>
<html>
<body>
    <h1>Debug Mode</h1>
    <p>Check /tmp/login_debug.log for details</p>
</body>
</html>
