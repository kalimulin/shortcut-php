<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class EmailVerificationRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(
        string $id,
        string $userId,
        string $email,
        string $code,
        string $token,
        string $expiresAtMysql,
        string $nowMysql
    ): void {
        $pdo = $this->db->pdo();
        
        try {
            // Удаляем старые коды для этого email
            $del = $pdo->prepare('DELETE FROM email_verifications WHERE email = :email OR user_id = :user_id');
            $del->execute([
                ':email' => strtolower(trim($email)),
                ':user_id' => $userId,
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO email_verifications (id, user_id, email, code, token, attempts, expires_at, created_at)
                 VALUES (:id, :user_id, :email, :code, :token, 0, :expires_at, :created_at)'
            );
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $userId,
                ':email' => strtolower(trim($email)),
                ':code' => trim($code),
                ':token' => trim($token),
                ':expires_at' => $expiresAtMysql,
                ':created_at' => $nowMysql,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'email_verifications')) {
                $this->createTable($pdo);
                $stmt = $pdo->prepare(
                    'INSERT INTO email_verifications (id, user_id, email, code, token, attempts, expires_at, created_at)
                     VALUES (:id, :user_id, :email, :code, :token, 0, :expires_at, :created_at)'
                );
                $stmt->execute([
                    ':id' => $id,
                    ':user_id' => $userId,
                    ':email' => strtolower(trim($email)),
                    ':code' => trim($code),
                    ':token' => trim($token),
                    ':expires_at' => $expiresAtMysql,
                    ':created_at' => $nowMysql,
                ]);
                return;
            }
            throw $e;
        }
    }

    private function createTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
            id         CHAR(36)     NOT NULL PRIMARY KEY,
            user_id    CHAR(36)     NOT NULL,
            email      VARCHAR(255) NOT NULL,
            code       VARCHAR(16)  NOT NULL,
            token      VARCHAR(64)  NOT NULL,
            attempts   INT          NOT NULL DEFAULT 0,
            expires_at DATETIME     NOT NULL,
            created_at DATETIME     NOT NULL,
            INDEX idx_email_verif_lookup (email, code),
            INDEX idx_email_verif_token (token),
            INDEX idx_email_verif_expires (expires_at),
            CONSTRAINT fk_email_verif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return array<string, mixed>|null */
    public function findByEmailAndCode(string $email, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM email_verifications 
             WHERE email = :email AND code = :code 
             LIMIT 1'
        );
        $stmt->execute([
            ':email' => strtolower(trim($email)),
            ':code' => trim($code),
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM email_verifications 
             WHERE token = :token 
             LIMIT 1'
        );
        $stmt->execute([':token' => trim($token)]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findLatestByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM email_verifications 
             WHERE email = :email 
             ORDER BY created_at DESC 
             LIMIT 1'
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function incrementAttempts(string $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE email_verifications SET attempts = attempts + 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM email_verifications WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function deleteByEmail(string $email): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM email_verifications WHERE email = :email');
        $stmt->execute([':email' => strtolower(trim($email))]);
    }
}
