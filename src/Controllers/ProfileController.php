<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use App\Services\DateHelper;

final class ProfileController
{
    public function __construct(private readonly UserRepository $userRepo)
    {
    }

    public function get(Request $request): never
    {
        $userId = (string) $request->getAttribute('userId');
        $user = $this->userRepo->findById($userId);
        if ($user === null) {
            throw ApiException::notFound('USER_NOT_FOUND', 'Пользователь не найден');
        }

        Response::json($this->serializeProfile($user));
    }

    public function update(Request $request): never
    {
        $userId = (string) $request->getAttribute('userId');
        $body = $request->json();

        $fields = [];
        $map = [
            'firstName' => ['col' => 'first_name', 'max' => 120],
            'lastName' => ['col' => 'last_name', 'max' => 120],
            'phone' => ['col' => 'phone', 'max' => 64],
            'email' => ['col' => 'contact_email', 'max' => 255],
            'position' => ['col' => 'position', 'max' => 150],
        ];

        foreach ($map as $inputKey => $meta) {
            if (!array_key_exists($inputKey, $body)) {
                continue;
            }
            $val = $body[$inputKey] !== null ? trim((string) $body[$inputKey]) : null;
            if ($val === '') {
                $val = null;
            } elseif ($val !== null && mb_strlen($val) > $meta['max']) {
                throw ApiException::badRequest('VALIDATION_ERROR', "Поле {$inputKey} не длиннее {$meta['max']} символов");
            }
            $fields[$meta['col']] = $val;
        }

        if (array_key_exists('settings', $body)) {
            if ($body['settings'] === null) {
                $fields['settings_json'] = null;
            } elseif (is_array($body['settings'])) {
                $fields['settings_json'] = json_encode($body['settings'], JSON_UNESCAPED_UNICODE);
            } else {
                throw ApiException::badRequest('VALIDATION_ERROR', 'Поле settings должно быть объектом JSON');
            }
        }

        $this->userRepo->updateProfile($userId, $fields, DateHelper::toMysqlUtc(DateHelper::nowUtc()));

        $updated = $this->userRepo->findById($userId);
        if ($updated === null) {
            throw ApiException::notFound('USER_NOT_FOUND', 'Пользователь не найден');
        }
        Response::json($this->serializeProfile($updated));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function serializeProfile(array $user): array
    {
        $settings = is_string($user['settings_json'] ?? null) ? json_decode($user['settings_json'], true) : null;

        return [
            'userId' => (string) $user['id'],
            'login' => (string) $user['login'],
            'role' => (string) $user['role'],
            'firstName' => $user['first_name'] !== null ? (string) $user['first_name'] : null,
            'lastName' => $user['last_name'] !== null ? (string) $user['last_name'] : null,
            'phone' => $user['phone'] !== null ? (string) $user['phone'] : null,
            'email' => $user['contact_email'] !== null ? (string) $user['contact_email'] : null,
            'position' => $user['position'] !== null ? (string) $user['position'] : null,
            'settings' => is_array($settings) ? $settings : null,
            'createdAt' => DateHelper::mysqlToIso($user['created_at'] ?? null),
            'modifiedAt' => DateHelper::mysqlToIso($user['updated_at'] ?? null),
        ];
    }
}
