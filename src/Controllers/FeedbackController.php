<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Services\BotProtectionService;
use App\Services\MailerService;

final class FeedbackController
{
    public function __construct(
        private readonly MailerService $mailerService,
        private readonly BotProtectionService $botProtection,
        private readonly Config $config,
    ) {
    }

    public function send(Request $request): never
    {
        $body = $request->json();

        // 1. Защита от ботов через honeypot
        $this->botProtection->checkHoneypot($body);

        // 2. Валидация полей
        $rawEmail = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        if ($rawEmail === '') {
            throw ApiException::badRequest('MISSING_EMAIL', 'Пожалуйста, укажите email для ответа');
        }

        // Валидация email
        $email = $this->botProtection->validateEmail($rawEmail);

        $rawMessage = isset($body['message']) && is_string($body['message']) ? trim($body['message']) : '';
        if ($rawMessage === '') {
            throw ApiException::badRequest('MISSING_MESSAGE', 'Пожалуйста, напишите текст обращения');
        }
        if (mb_strlen($rawMessage) < 10) {
            throw ApiException::badRequest('MESSAGE_TOO_SHORT', 'Текст обращения должен содержать не менее 10 символов');
        }
        if (mb_strlen($rawMessage) > 5000) {
            throw ApiException::badRequest('MESSAGE_TOO_LONG', 'Текст обращения не должен превышать 5000 символов');
        }

        $category = isset($body['category']) && is_string($body['category']) ? trim($body['category']) : 'question';
        if (!in_array($category, ['question', 'suggestion', 'complaint'], true)) {
            $category = 'question';
        }

        $name = isset($body['name']) && is_string($body['name']) && trim($body['name']) !== ''
            ? mb_substr(trim($body['name']), 0, 100)
            : null;

        $subject = isset($body['subject']) && is_string($body['subject']) && trim($body['subject']) !== ''
            ? mb_substr(trim($body['subject']), 0, 150)
            : null;

        // 3. Отправка через системный сервис MailerService
        $sent = $this->mailerService->sendFeedbackEmail($category, $email, $name, $subject, $rawMessage);

        if (!$sent) {
            throw ApiException::internal('Не удалось отправить обращение. Попробуйте позже или напишите напрямую в поддержку.');
        }

        Response::json([
            'ok' => true,
            'message' => 'Обращение успешно отправлено',
        ]);
    }
}
