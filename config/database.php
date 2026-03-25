<?php
// Database Configuration

// Development
// define('DB_HOST', 'localhost');
// define('DB_NAME', 'grading_system');
// define('DB_USER', 'root');
// define('DB_PASS', '');

// Production
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'unciano-grading-system');
define('DB_USER', 'unciano-grading');
define('DB_PASS', 'UncianoGrading18.');

class Database
{
    private static $instance = null;
    private $conn;

    private function __construct()
    {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->conn;
    }
}

function db()
{
    return Database::getInstance()->getConnection();
}
