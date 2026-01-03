<?php /* Seeder: gumagawa ng default admin account kung wala pa. */




require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain');


$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1','::1'], true)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

try {
    $pdo = getDBConnection();
    $email = 'admin@example.com';
    $username = 'admin';
    $passwordPlain = 'admin123';
    $hash = password_hash($passwordPlain, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
    $stmt->execute([$email, $username]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        echo "Admin already exists (email: $email).\n";
        exit;
    }

    $insert = $pdo->prepare('INSERT INTO users (username, email, password, first_name, last_name, role, department, position, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([$username, $email, $hash, 'System', 'Administrator', 'admin', 'ICT', 'admin', 'active']);

    echo "Admin created successfully.\n";
    echo "Login with:\n";
    echo "  Username or Email: $username / $email\n";
    echo "  Password: $passwordPlain\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}

?>
