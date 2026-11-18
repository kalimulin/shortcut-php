<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->config->get('DB_HOST', '127.0.0.1');
        $port = $this->config->get('DB_PORT', '3306');
        $name = $this->config->get('DB_NAME', 'shortcut_db');
        $user = $this->config->get('DB_USER', 'root');
        $pass = $this->config->get('DB_PASS', '');
        $charset = $this->config->get('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
            $this->pdo = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException('Ошибка подключения к базе данных: ' . $e->getMessage(), 0, $e);
        }
    }
}
