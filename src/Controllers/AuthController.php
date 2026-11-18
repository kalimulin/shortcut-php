<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use App\Services\DateHelper;
use App\Services\JwtService;
use App\Services\Uuid;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly JwtService $jwt,
    ) {
    }

    public function register(Request $request): never
    {
        $body = $request->json();
        $login = strtolower(trim((string) ($body['login'] ?? $body['email'] ?? $body['username'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($login === '' || mb_strlen($login) > 255) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите корректный логин / e-mail');
        }
        if (mb_strlen($password) < 6) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Пароль должен содержать минимум 6 символов');
        }

        if ($this->userRepo->findByLogin($login) !== null) {
            throw ApiException::conflict('USER_EXISTS', 'Пользователь с таким логином уже существует');
        }

        $id = Uuid::v4();
        $now = DateHelper::nowUtc();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->userRepo->create($id, $login, $hash, 'user', DateHelper::toMysqlUtc($now));

        $token = $this->issueToken($id, $login, 'user');
        Response::json([
            'accessToken' => $token,
            'user' => [
                'userId' => $id,
                'login' => $login,
                'role' => 'user',
                'createdAt' => $now->format('Y-m-d\TH:i:s\Z'),
            ],
        ], 201);
    }

    public function login(Request $request): never
    {
        $body = $request->json();
        $login = strtolower(trim((string) ($body['login'] ?? $body['username'] ?? $body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($login === '' || $password === '') {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите логин и пароль');
        }

        $user = $this->userRepo->findByLogin($login);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            throw ApiException::unauthorized('Неверный логин или пароль', 'INVALID_CREDENTIALS');
        }

        $token = $this->issueToken((string) $user['id'], (string) $user['login'], (string) $user['role']);
        Response::json([
            'accessToken' => $token,
            'user' => [
                'userId' => (string) $user['id'],
                'login' => (string) $user['login'],
                'role' => (string) $user['role'],
                'firstName' => $user['first_name'],
                'lastName' => $user['last_name'],
            ],
        ]);
    }

    public function changePassword(Request $request): never
    {
        $userId = (string) $request->getAttribute('userId');
        $body = $request->json();
        $oldPassword = (string) ($body['oldPassword'] ?? '');
        $newPassword = (string) ($body['newPassword'] ?? '');

        if ($oldPassword === '' || $newPassword === '') {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите текущий и новый пароли');
        }
        if (mb_strlen($newPassword) < 6) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Новый пароль должен содержать минимум 6 символов');
        }

        $user = $this->userRepo->findById($userId);
        if ($user === null) {
            throw ApiException::unauthorized('Пользователь не найден');
        }
        if (!password_verify($oldPassword, (string) $user['password_hash'])) {
            throw ApiException::badRequest('INVALID_PASSWORD', 'Текущий пароль указан неверно');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->userRepo->updatePassword($userId, $newHash, DateHelper::toMysqlUtc(DateHelper::nowUtc()));

        Response::json(['ok' => true]);
    }

    private function issueToken(string $userId, string $login, string $role): string
    {
        return $this->jwt->encode([
            'sub' => $userId,
            'userId' => $userId,
            'login' => $login,
            'role' => $role,
        ]);
    }
}
