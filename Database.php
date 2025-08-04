<?php
class Database {
    private $pdo;

    public function __construct($config) {
        try {
            $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";
            $this->pdo = new PDO($dsn, $config['user'], $config['password']);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Помилка підключення до бази даних: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->pdo;
    }
}