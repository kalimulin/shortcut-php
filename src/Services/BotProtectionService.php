<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Http\ApiException;

final class BotProtectionService
{
    /** @var string[] */
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com',
        '10minutemail.com',
        '10minutemail.net',
        'temp-mail.org',
        'tempmail.com',
        'tempmail.net',
        'guerrillamail.com',
        'guerrillamail.net',
        'guerrillamail.biz',
        'sharklasers.com',
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
        'trashmail.com',
        'trashmail.net',
        'trashmail.me',
        'dispostable.com',
        'fakeinbox.com',
        'getairmail.com',
        'mytemp.email',
        'mohmal.com',
        'crazymailing.com',
        'dropmail.me',
        'generator.email',
        'emailondeck.com',
        'tempinbox.com',
        'fakemailgenerator.com',
        'throwawaymail.com',
        'nada.ltd',
        'getnada.com',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Проверка на заполнение невидимого поля-ловушки (honeypot).
     * Обычные пользователи его не видят и не заполняют; боты заполняют все поля подряд.
     *
     * @param array<string, mixed> $body
     */
    public function checkHoneypot(array $body): void
    {
        $honeypotKeys = ['website', 'phone_hp', 'company_url'];
        foreach ($honeypotKeys as $key) {
            if (!empty($body[$key])) {
                // Имитируем небольшую задержку и выдаём ошибку
                usleep(500000);
                throw ApiException::badRequest('BOT_DETECTED', 'Регистрация заблокирована системой защиты от ботов');
            }
        }
    }

    /**
     * Валидация адреса электронной почты: синтаксис, запрет временных доменов, проверка MX записей.
     */
    public function validateEmail(string $email): string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || mb_strlen($normalized) > 255) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите корректный e-mail');
        }

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Неверный формат адреса электронной почты');
        }

        $parts = explode('@', $normalized);
        if (count($parts) !== 2) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Неверный формат адреса электронной почты');
        }

        $domain = $parts[1];

        // Проверка по чёрному списку одноразовых почтовых сервисов
        if ($this->isDisposableDomain($domain)) {
            throw ApiException::badRequest(
                'DISPOSABLE_EMAIL_FORBIDDEN',
                'Использование временных почтовых ящиков запрещено. Пожалуйста, укажите постоянный рабочий e-mail.'
            );
        }

        // Проверка наличия почтовых MX-записей у домена (если доступна функция checkdnsrr)
        if (
            $this->config->getBool('EMAIL_VERIFY_DNS_CHECK', false)
            && function_exists('checkdnsrr')
            && !checkdnsrr($domain, 'MX')
            && !checkdnsrr($domain, 'A')
        ) {
            throw ApiException::badRequest(
                'INVALID_EMAIL_DOMAIN',
                'Почтовый домен не существует или не может принимать письма'
            );
        }

        return $normalized;
    }

    public function isDisposableDomain(string $domain): bool
    {
        $normalized = strtolower(trim($domain));
        if (in_array($normalized, self::DISPOSABLE_DOMAINS, true)) {
            return true;
        }

        // Дополнительные домены из .env (если заданы)
        $custom = $this->config->getList('DISPOSABLE_EMAIL_DOMAINS', []);
        foreach ($custom as $item) {
            if ($normalized === strtolower(trim($item))) {
                return true;
            }
        }

        return false;
    }
}
