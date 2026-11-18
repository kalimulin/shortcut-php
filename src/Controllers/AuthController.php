<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\EmailVerificationRepository;
use App\Repositories\UserRepository;
use App\Services\BotProtectionService;
use App\Services\DateHelper;
use App\Services\JwtService;
use App\Services\MailerService;
use App\Services\Uuid;
use DateTimeImmutable;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly JwtService $jwt,
        private readonly ?EmailVerificationRepository $verificationRepo = null,
        private readonly ?MailerService $mailer = null,
        private readonly ?BotProtectionService $botProtection = null,
        private readonly ?Config $config = null,
    ) {
    }

    public function register(Request $request): never
    {
        $body = $request->json();

        // 1. Проверка honeypot (защита от ботов)
        if ($this->botProtection !== null) {
            $this->botProtection->checkHoneypot($body);
        }

        $rawLogin = (string) ($body['login'] ?? $body['email'] ?? $body['username'] ?? '');
        
        // 2. Валидация email и отсев одноразовых доменов
        if ($this->botProtection !== null) {
            $login = $this->botProtection->validateEmail($rawLogin);
        } else {
            $login = strtolower(trim($rawLogin));
            if ($login === '' || mb_strlen($login) > 255 || filter_var($login, FILTER_VALIDATE_EMAIL) === false) {
                throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите корректный e-mail');
            }
        }

        $password = (string) ($body['password'] ?? '');
        if (mb_strlen($password) < 6) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Пароль должен содержать минимум 6 символов');
        }

        $isVerificationRequired = $this->config === null || $this->config->getBool('EMAIL_VERIFICATION_REQUIRED', true);

        $existing = $this->userRepo->findByLogin($login);
        $userId = null;
        $now = DateHelper::nowUtc();
        $nowMysql = DateHelper::toMysqlUtc($now);

        if ($existing !== null) {
            $isVerified = (int) ($existing['is_verified'] ?? 0);
            if ($isVerified === 1) {
                throw ApiException::conflict('USER_EXISTS', 'Пользователь с таким e-mail уже зарегистрирован');
            }

            // Пользователь уже создан, но ещё не подтвердил email — обновляем пароль и отправляем новый код
            $userId = (string) $existing['id'];
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $this->userRepo->updatePassword($userId, $newHash, $nowMysql);
        } else {
            // Создание нового пользователя
            $userId = Uuid::v4();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $initialVerified = $isVerificationRequired ? 0 : 1;
            $this->userRepo->create($userId, $login, $hash, 'user', $nowMysql, $initialVerified);
        }

        // Если верификация email отключена в конфигурации
        if (!$isVerificationRequired) {
            $token = $this->issueToken($userId, $login, 'user');
            Response::json([
                'accessToken' => $token,
                'user' => [
                    'userId' => $userId,
                    'login' => $login,
                    'role' => 'user',
                    'isVerified' => true,
                    'createdAt' => $now->format('Y-m-d\TH:i:s\Z'),
                ],
            ], 201);
        }

        // 3. Генерация 6-значного проверочного кода и токена подтверждения
        $code = (string) random_int(100000, 999999);
        $token = bin2hex(random_bytes(24));
        $verifId = Uuid::v4();
        $expiresAt = $now->modify('+15 minutes');
        $expiresAtMysql = DateHelper::toMysqlUtc($expiresAt);

        if ($this->verificationRepo !== null) {
            $this->verificationRepo->create(
                $verifId,
                $userId,
                $login,
                $code,
                $token,
                $expiresAtMysql,
                $nowMysql
            );
        }

        // 4. Отправка письма с кодом подтверждения
        if ($this->mailer !== null) {
            $this->mailer->sendVerificationEmail($login, $code, $token);
        }

        $isDebug = $this->config !== null && $this->config->isDebug();

        Response::json([
            'status' => 'verification_required',
            'verificationRequired' => true,
            'email' => $login,
            'message' => 'Код подтверждения отправлен на ваш e-mail',
            'expiresInSeconds' => 900,
            ...($isDebug ? ['debugCode' => $code] : []),
        ], 201);
    }

    public function verifyEmail(Request $request): never
    {
        $body = $request->json();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $code = trim((string) ($body['code'] ?? ''));
        $token = trim((string) ($body['token'] ?? ''));

        if ($this->verificationRepo === null) {
            throw ApiException::internal('Модуль верификации недоступен');
        }

        $record = null;
        if ($token !== '') {
            $record = $this->verificationRepo->findByToken($token);
        } elseif ($email !== '' && $code !== '') {
            $record = $this->verificationRepo->findByEmailAndCode($email, $code);
        } else {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите e-mail и проверочный код');
        }

        if ($record === null) {
            throw ApiException::badRequest('INVALID_CODE', 'Неверный или устаревший код подтверждения');
        }

        $expiresAt = new DateTimeImmutable((string) $record['expires_at']);
        $now = DateHelper::nowUtc();
        if ($now > $expiresAt) {
            $this->verificationRepo->delete((string) $record['id']);
            throw ApiException::badRequest('CODE_EXPIRED', 'Срок действия кода истёк. Запросите код повторно.');
        }

        $userId = (string) $record['user_id'];
        $verifiedEmail = (string) $record['email'];
        $nowMysql = DateHelper::toMysqlUtc($now);

        // Помечаем пользователя подтверждённым
        $this->userRepo->markVerified($userId, $nowMysql);
        $this->verificationRepo->delete((string) $record['id']);

        $user = $this->userRepo->findById($userId);
        $role = is_array($user) ? (string) ($user['role'] ?? 'user') : 'user';
        $authToken = $this->issueToken($userId, $verifiedEmail, $role);

        Response::json([
            'accessToken' => $authToken,
            'user' => [
                'userId' => $userId,
                'login' => $verifiedEmail,
                'role' => $role,
                'isVerified' => true,
                'firstName' => $user['first_name'] ?? null,
                'lastName' => $user['last_name'] ?? null,
            ],
        ]);
    }

    public function resendVerification(Request $request): never
    {
        $body = $request->json();

        if ($this->botProtection !== null) {
            $this->botProtection->checkHoneypot($body);
        }

        $email = strtolower(trim((string) ($body['email'] ?? $body['login'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите корректный e-mail');
        }

        $user = $this->userRepo->findByLogin($email);
        if ($user === null || (int) ($user['is_verified'] ?? 0) === 1) {
            // Для безопасности не раскрываем существование email
            Response::json([
                'ok' => true,
                'message' => 'Если аккаунт существует и ожидает подтверждения, проверочный код отправлен',
            ]);
        }

        $userId = (string) $user['id'];

        // Ограничение частоты повторной отправки (1 раз в 60 секунд)
        if ($this->verificationRepo !== null) {
            $latest = $this->verificationRepo->findLatestByEmail($email);
            if ($latest !== null) {
                $createdAt = new DateTimeImmutable((string) $latest['created_at']);
                $now = DateHelper::nowUtc();
                $diff = $now->getTimestamp() - $createdAt->getTimestamp();
                if ($diff < 60) {
                    $wait = 60 - $diff;
                    throw ApiException::tooManyRequests("Повторный код можно запросить через {$wait} сек.");
                }
            }
        }

        $code = (string) random_int(100000, 999999);
        $token = bin2hex(random_bytes(24));
        $verifId = Uuid::v4();
        $now = DateHelper::nowUtc();
        $expiresAt = $now->modify('+15 minutes');

        if ($this->verificationRepo !== null) {
            $this->verificationRepo->create(
                $verifId,
                $userId,
                $email,
                $code,
                $token,
                DateHelper::toMysqlUtc($expiresAt),
                DateHelper::toMysqlUtc($now)
            );
        }

        if ($this->mailer !== null) {
            $this->mailer->sendVerificationEmail($email, $code, $token);
        }

        $isDebug = $this->config !== null && $this->config->isDebug();

        Response::json([
            'ok' => true,
            'message' => 'Новый проверочный код отправлен на ваш e-mail',
            'expiresInSeconds' => 900,
            ...($isDebug ? ['debugCode' => $code] : []),
        ]);
    }

    public function login(Request $request): never
    {
        $body = $request->json();

        if ($this->botProtection !== null) {
            $this->botProtection->checkHoneypot($body);
        }

        $login = strtolower(trim((string) ($body['login'] ?? $body['username'] ?? $body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($login === '' || $password === '') {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите логин и пароль');
        }

        $user = $this->userRepo->findByLogin($login);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            throw ApiException::unauthorized('Неверный логин или пароль', 'INVALID_CREDENTIALS');
        }

        $isVerificationRequired = $this->config === null || $this->config->getBool('EMAIL_VERIFICATION_REQUIRED', true);
        $isVerified = (int) ($user['is_verified'] ?? 0);

        if ($isVerificationRequired && $isVerified === 0) {
            throw ApiException::forbidden(
                'EMAIL_NOT_VERIFIED',
                'Адрес e-mail не подтверждён. Пожалуйста, введите проверочный код из письма.',
                ['email' => (string) $user['login']]
            );
        }

        $token = $this->issueToken((string) $user['id'], (string) $user['login'], (string) $user['role']);
        Response::json([
            'accessToken' => $token,
            'user' => [
                'userId' => (string) $user['id'],
                'login' => (string) $user['login'],
                'role' => (string) $user['role'],
                'isVerified' => $isVerified === 1,
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
