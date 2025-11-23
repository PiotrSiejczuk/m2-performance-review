<?php
namespace M2Performance\Service;

use PDO;
use PDOException;

class MagentoDbConnection
{
    private PDO $pdo;

    public function __construct(array $dbConfig)
    {
        // Example for MySQL; adjust DSN if using something else
        $host = $dbConfig['host']   ?? '127.0.0.1';
        $port = $dbConfig['port']   ?? 3306;
        $user = $dbConfig['username'] ?? 'root';
        $pass = $dbConfig['password'] ?? '';
        $dbname = $dbConfig['dbname']  ?? '';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', $host, $port, $dbname);
        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException("Unable to connect to Magento database: " . $e->getMessage());
        }
    }

    public function query(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }
}
