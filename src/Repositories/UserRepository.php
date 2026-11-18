<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByLogin(string $login): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE login = :login LIMIT 1');
        $stmt->execute([':login' => strtolower(trim($login))]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(
        string $id,
        string $login,
        string $passwordHash,
        string $role,
        string $nowMysql,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (id, login, password_hash, role, contact_email, created_at, updated_at)
             VALUES (:id, :login, :password_hash, :role, :contact_email, :created_at, :updated_at)'
        );
        $normalized = strtolower(trim($login));
        $stmt->execute([
            ':id' => $id,
            ':login' => $normalized,
            ':password_hash' => $passwordHash,
            ':role' => $role,
            ':contact_email' => str_contains($normalized, '@') ? $normalized : null,
            ':created_at' => $nowMysql,
            ':updated_at' => $nowMysql,
        ]);
    }

    public function updatePassword(string $id, string $passwordHash, string $nowMysql): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':hash' => $passwordHash,
            ':updated_at' => $nowMysql,
            ':id' => $id,
        ]);
    }

    /** @param array<string, mixed> $fields */
    public function updateProfile(string $id, array $fields, string $nowMysql): void
    {
        $allowed = ['first_name', 'last_name', 'phone', 'contact_email', 'position', 'settings_json'];
        $sets = [];
        $params = [':id' => $id, ':updated_at' => $nowMysql];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = :updated_at';
        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->db->pdo()->prepare($sql)->execute($params);
    }
}
