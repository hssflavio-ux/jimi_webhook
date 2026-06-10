<?php
/**
 * JIMI IoT Hub — Configuração de Banco de Dados
 * Versão: 2.0.0
 *
 * Singleton PDO que lê variáveis do arquivo .env.
 * Configura timezone UTC e charset utf8mb4 na conexão.
 */
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
                list($key, $value) = explode('=', $line, 2);
                if (!getenv(trim($key))) putenv(trim($key) . '=' . trim($value));
            }
        }
        
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';
        $dbname = getenv('DB_NAME') ?: 'jimi_tracker';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '1029384756';
        
        try {
            $this->connection = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'"
            ]);
        } catch (PDOException $e) {
            error_log("FATAL: Database connection failed - " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['code' => 500, 'message' => 'Database Connection Error']);
            exit;
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    
    public function getConnection() { return $this->connection; }
    private function __clone() {}
    public function __wakeup() { throw new Exception("Cannot unserialize singleton"); }
}
