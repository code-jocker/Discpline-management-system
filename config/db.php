<?php
declare(strict_types=1);

if (!function_exists('loadEnvironment')) {
    function loadEnvironment(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (($value[0] ?? '') === '"' && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }

            if (($value[0] ?? '') === "'" && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }

        if (getenv('APP_URL') && getenv('SBMS_BASE_URL') === false) {
            putenv('SBMS_BASE_URL=' . getenv('APP_URL'));
            $_ENV['SBMS_BASE_URL'] = getenv('APP_URL');
        }
    }
}

// Load environment variables from .env when present
loadEnvironment(__DIR__ . '/../.env');

/**
 * Centralized PDO connection factory.
 *
 * Loads credentials from environment variables.
 * Supports both legacy SBMS_* names and newer DB_* names.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = getenv('SBMS_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('SBMS_DB_PORT') ?: getenv('DB_PORT') ?: '3306';
        $database = getenv('SBMS_DB_NAME') ?: getenv('DB_NAME') ?: 'sbms';
        $username = getenv('SBMS_DB_USER') ?: getenv('DB_USER') ?: 'root';
        $password = getenv('SBMS_DB_PASS') ?: getenv('DB_PASSWORD') ?: '';
        $charset = getenv('SBMS_DB_CHARSET') ?: getenv('DB_CHARSET') ?: 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $sslCa = getenv('SBMS_DB_SSL_CA') ?: getenv('DB_SSL_CA') ?: '';
        $sslMode = strtolower((string) (getenv('SBMS_DB_SSL_MODE') ?: getenv('DB_SSL_MODE') ?: ''));
        if ($sslCa !== '' || $sslMode === 'required' || $sslMode === 'verify-ca' || $sslMode === 'verify-identity') {
            if ($sslCa !== '' && is_file($sslCa)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET sql_mode=''";

        try {
            self::$connection = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $exception) {
            self::logError('Database connection failed', ['exception' => $exception->getMessage()]);
            throw new RuntimeException('The system is temporarily unable to connect to the database.');
        }

        return self::$connection;
    }

    public static function logError(string $message, array $context = []): void
    {
        $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s %s%s",
            date('Y-m-d H:i:s'),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );

        error_log($line, 3, $logDir . DIRECTORY_SEPARATOR . 'app.log');
    }

    public static function tableExists(string $tableName): bool
    {
        try {
            $statement = self::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name'
            );
            $statement->execute(['table_name' => $tableName]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable $exception) {
            return false;
        }
    }

    public static function columnExists(string $tableName, string $columnName): bool
    {
        if (!self::tableExists($tableName)) {
            return false;
        }

        try {
            $statement = self::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name'
            );
            $statement->execute([
                'table_name' => $tableName,
                'column_name' => $columnName,
            ]);

            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable $exception) {
            return false;
        }
    }
}

// Centralized autoloader for both namespaced and global model classes
spl_autoload_register(static function (string $class): void {
    // 1. Namespaced classes under App\
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
        return;
    }

    // 2. Global model classes
    $modelPath = dirname(__DIR__) . '/models/' . $class . '.php';
    if (is_file($modelPath)) {
        require_once $modelPath;
    }
});
