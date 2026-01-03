<?php /* Database config: PDO connection sa MySQL. */
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'valucare_pms');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');

function getDBConnection() {
    try {
        $host = getenv('VC_DB_HOST') ?: DB_HOST;
        $port = (int)(getenv('VC_DB_PORT') ?: DB_PORT);
        $name = getenv('VC_DB_NAME') ?: DB_NAME;
        $user = getenv('VC_DB_USER') ?: DB_USERNAME;
        $pass = getenv('VC_DB_PASS') ?: DB_PASSWORD;
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        throw new RuntimeException('Database connection failed');
    }
}
?>
