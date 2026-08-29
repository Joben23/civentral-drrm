<?php
// Function to load .env file into getenv/$_ENV
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

loadEnv(__DIR__ . '/../.env');

class LegacyDatabaseConfigurationException extends \RuntimeException {}

/**
 * Explicit configuration gate for the legacy CIVENTRAL MySQL fallback.
 *
 * The normal DRRM runtime uses remote CIVENTRAL identity APIs and Supabase.
 * Merely including this file must never open a database connection.
 */
final class LegacyDatabaseConfig
{
    private const ENABLED_VARIABLE = 'CIVENTRAL_LEGACY_DB_ENABLED';

    public static function isEnabled(): bool
    {
        $value = self::environmentValue(self::ENABLED_VARIABLE);
        if ($value === false || trim($value) === '') {
            return false;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new LegacyDatabaseConfigurationException(
                self::ENABLED_VARIABLE . ' must be a valid boolean value.'
            ),
        };
    }

    /**
     * @return array{host: string, port: int, database: string, user: string, password: string}
     */
    public static function connectionParameters(): array
    {
        if (!self::isEnabled()) {
            throw new LegacyDatabaseConfigurationException(
                'The legacy CIVENTRAL database fallback is disabled.'
            );
        }

        $host = self::requiredValue('DB_HOST');
        $portValue = self::requiredValue('DB_PORT');
        $database = self::requiredValue('DB_NAME');
        $user = self::requiredValue('DB_USER');
        $password = self::requiredValue('DB_PASSWORD', true);

        $port = filter_var($portValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new LegacyDatabaseConfigurationException(
                'DB_PORT must be an integer between 1 and 65535.'
            );
        }

        foreach (['DB_HOST' => $host, 'DB_NAME' => $database] as $name => $value) {
            if (preg_match('/[;\x00-\x1F\x7F]/', $value) === 1) {
                throw new LegacyDatabaseConfigurationException(
                    $name . ' contains unsupported characters.'
                );
            }
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'user' => $user,
            'password' => $password,
        ];
    }

    private static function requiredValue(string $name, bool $preserveWhitespace = false): string
    {
        $value = self::environmentValue($name);
        if ($value === false || trim($value) === '') {
            throw new LegacyDatabaseConfigurationException(
                $name . ' is required when the legacy database fallback is enabled.'
            );
        }

        return $preserveWhitespace ? $value : trim($value);
    }

    private static function environmentValue(string $name): string|false
    {
        $value = getenv($name);
        if ($value === false && array_key_exists($name, $_ENV)) {
            $value = (string) $_ENV[$name];
        }
        if ($value === false && array_key_exists($name, $_SERVER)) {
            $value = (string) $_SERVER[$name];
        }

        return is_string($value) ? $value : false;
    }
}

class Database {
    private static $instance = null;
    private $pdo;

    public function __construct() {
        $configuration = LegacyDatabaseConfig::connectionParameters();
        $host = $configuration['host'];
        $port = $configuration['port'];
        $db = $configuration['database'];
        $user = $configuration['user'];
        $pass = $configuration['password'];
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException) {
            throw new \PDOException('Legacy CIVENTRAL database connection failed.');
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    /**
     * Run a SQL query with parameter binding and return result array
     */
    public function query($sql, $params = [], $ignoredMethodParam = null) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a SQL statement (INSERT/UPDATE/DELETE) with parameters returning affected row count
     */
    public function exec($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Helper: Select rows from a table with filters
     */
    public function select($table, $filters = [], $columns = '*', $orderBy = '') {
        $sql = "SELECT {$columns} FROM `{$table}`";
        $where = [];
        $params = [];

        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                $where[] = "`{$key}` = :{$key}";
                $params[$key] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        if (!empty($orderBy)) {
            $sql .= " ORDER BY {$orderBy}";
        }

        return $this->query($sql, $params);
    }

    /**
     * Helper: Insert record into a table
     */
    public function insert($table, $data) {
        $keys = array_keys($data);
        $fields = implode('`, `', $keys);
        $placeholders = ':' . implode(', :', $keys);

        $sql = "INSERT INTO `{$table}` (`{$fields}`) VALUES ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        $lastId = $this->pdo->lastInsertId();
        return $lastId ? (int)$lastId : true;
    }

    /**
     * Helper: Update record in a table
     */
    public function update($table, $data, $filters) {
        $set = [];
        $params = [];

        foreach ($data as $key => $value) {
            $set[] = "`{$key}` = :set_{$key}";
            $params["set_{$key}"] = $value;
        }

        $where = [];
        foreach ($filters as $key => $value) {
            $where[] = "`{$key}` = :where_{$key}";
            $params["where_{$key}"] = $value;
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Helper: Delete record from a table
     */
    public function delete($table, $filters) {
        $where = [];
        $params = [];

        foreach ($filters as $key => $value) {
            $where[] = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }

        $sql = "DELETE FROM `{$table}` WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

class DatabaseDB extends Database {}

/**
 * Return the legacy database only when the server explicitly enables it.
 * Disabled means no PDO constructor is called.
 */
function legacyDatabaseIfEnabled(): ?Database
{
    return LegacyDatabaseConfig::isEnabled()
        ? Database::getInstance()
        : null;
}
?>
