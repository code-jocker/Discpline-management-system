<?php
/**
 * Database Connection Handler
 * 
 * Handles PDO connections to MySQL (PlanetScale or local)
 * Uses environment variables for credentials
 */

declare(strict_types=1);

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        $this->connect();
    }
    
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function connection(): PDO {
        return self::getInstance()->getConnection();
    }
    
    private function connect(): void {
        try {
            $host = getenv('SBMS_DB_HOST') ?: getenv('DB_HOST') ?: 'localhost';
            $port = (int)(getenv('SBMS_DB_PORT') ?: getenv('DB_PORT') ?: 3306);
            $database = getenv('SBMS_DB_NAME') ?: getenv('DB_NAME') ?: 'sbms';
            $username = getenv('SBMS_DB_USER') ?: getenv('DB_USER') ?: 'root';
            $password = getenv('SBMS_DB_PASS') ?: getenv('DB_PASSWORD') ?: '';
            $sslCa = getenv('SBMS_DB_SSL_CA') ?: getenv('DB_SSL_CA') ?: '';
            $sslMode = strtolower((string) (getenv('SBMS_DB_SSL_MODE') ?: getenv('DB_SSL_MODE') ?: ''));

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ];

            // Managed MySQL (Aiven) TLS; clear sql_mode for ANSI_QUOTES compatibility.
            if ($sslCa !== '' || $sslMode === 'required' || $sslMode === 'verify-ca' || $sslMode === 'verify-identity') {
                if ($sslCa !== '' && is_readable($sslCa)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
                }
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                }
            }
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET sql_mode=''";

            $this->connection = new PDO($dsn, $username, $password, $options);

        } catch (PDOException $e) {
            error_log('[DB ERROR] ' . $e->getMessage());
            throw new RuntimeException('Database connection failed');
        }
    }
    
    public function getConnection(): PDO {
        return $this->connection;
    }
    
    /**
     * Execute a prepared query
     */
    public function query(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log('[DB QUERY ERROR] ' . $sql . ' | ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Execute and fetch single row
     */
    public function fetch(string $sql, array $params = []): ?array {
        return $this->query($sql, $params)->fetch();
    }
    
    /**
     * Execute and fetch all rows
     */
    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * Execute and get last insert ID
     */
    public function lastInsertId(): string {
        return $this->connection->lastInsertId();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction(): void {
        $this->connection->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit(): void {
        $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollBack(): void {
        $this->connection->rollBack();
    }
}

// Alias for compatibility
if (!function_exists('getDatabase')) {
    function getDatabase(): Database {
        return Database::getInstance();
    }
}
?>
