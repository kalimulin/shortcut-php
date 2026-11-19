<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use Throwable;

final class MailerService
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Отправка письма с кодом и ссылкой подтверждения email.
     */
    public function sendVerificationEmail(string $toEmail, string $code, string $token): bool
    {
        $appUrl = rtrim($this->config->appUrl(), '/');
        $confirmUrl = "{$appUrl}/login?verifyEmail=" . rawurlencode($toEmail) . '&code=' . rawurlencode($code);

        $subject = 'Подтверждение регистрации на омни.рус';

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #12233f;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f4f6f9; padding: 40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 560px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); overflow: hidden;">
                    <!-- Шапка -->
                    <tr>
                        <td style="padding: 32px 36px 20px; text-align: center; border-bottom: 1px solid #edf2f7;">
                            <h2 style="margin: 0; font-size: 24px; font-weight: 700; color: #0056d6;">ОМНИ.РУС</h2>
                        </td>
                    </tr>
                    <!-- Основной контент -->
                    <tr>
                        <td style="padding: 32px 36px;">
                            <h1 style="margin: 0 0 16px; font-size: 20px; font-weight: 600; color: #12233f;">Здравствуйте!</h1>
                            <p style="margin: 0 0 20px; font-size: 15px; line-height: 24px; color: #4a5568;">
                                Вы (или кто-то, указавший этот адрес) зарегистрировались в сервисе сокращения ссылок ОМНИ.РУС.
                            </p>
                            
                            <!-- Блок с кодом -->
                            <div style="background-color: #f0f4f8; border-radius: 8px; padding: 20px; text-align: center; margin: 24px 0;">
                                <div style="font-size: 13px; color: #718096; margin-bottom: 8px;">Ваш проверочный код:</div>
                                <span style="font-size: 34px; font-weight: 800; letter-spacing: 8px; color: #0056d6; font-family: Consolas, monospace; display: inline-block;">{$code}</span>
                            </div>

                            <p style="margin: 0 0 24px; font-size: 14px; line-height: 22px; color: #718096; text-align: center;">
                                Или нажмите кнопку ниже для подтверждения email:
                            </p>

                            <!-- Кнопка перехода -->
                            <div style="text-align: center; margin-bottom: 24px;">
                                <a href="{$confirmUrl}" style="display: inline-block; background-color: #0056d6; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-size: 15px; font-weight: 600;">Подтвердить email</a>
                            </div>

                            <p style="margin: 0 0 16px; font-size: 14px; line-height: 22px; color: #4a5568;">
                                <strong>Ссылка действует 24 часа.</strong>
                            </p>

                            <p style="margin: 0 0 16px; font-size: 13px; line-height: 20px; color: #718096;">
                                Вы дали согласие на обработку персональных данных (Политика: <a href="https://омни.рус/privacy" style="color: #0056d6; text-decoration: underline;">https://омни.рус/privacy</a>) и приняли Соглашение (<a href="https://омни.рус/terms" style="color: #0056d6; text-decoration: underline;">https://омни.рус/terms</a>).
                            </p>

                            <p style="margin: 0 0 20px; font-size: 13px; line-height: 20px; color: #a0aec0;">
                                Если это были не вы — просто проигнорируйте письмо, аккаунт не активируется.
                            </p>

                            <p style="margin: 0; font-size: 14px; color: #12233f; font-weight: 500;">
                                — Администрация омни.рус
                            </p>
                        </td>
                    </tr>
                    <!-- Подвал -->
                    <tr>
                        <td style="padding: 16px 36px; background-color: #fafbfc; border-top: 1px solid #edf2f7; text-align: center; font-size: 12px; color: #a0aec0;">
                            Отписаться от новостей: <a href="{$appUrl}/profile" style="color: #718096; text-decoration: underline;">настройки в Личном кабинете</a> (подтверждение email — не рассылка)
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $textBody = "Здравствуйте!\n\n"
            . "Вы (или кто-то, указавший этот адрес) зарегистрировались в сервисе сокращения ссылок ОМНИ.РУС.\n\n"
            . "Ваш проверочный код: {$code}\n"
            . "Подтвердите email по ссылке: {$confirmUrl}\n\n"
            . "Ссылка действует 24 часа.\n\n"
            . "Вы дали согласие на обработку персональных данных (Политика: https://омни.рус/privacy) и приняли Соглашение (https://омни.рус/terms).\n\n"
            . "Если это были не вы — просто проигнорируйте письмо, аккаунт не активируется.\n\n"
            . "— Администрация омни.рус\n"
            . "Отписаться от новостей: {$appUrl}/profile (подтверждение email — не рассылка)\n";

        return $this->send($toEmail, $subject, $htmlBody, $textBody);
    }

    /**
     * Отправка письма с кодом и ссылкой восстановления/сброса пароля.
     */
    public function sendPasswordResetEmail(string $toEmail, string $code, string $token, ?string $userName = null): bool
    {
        $appUrl = rtrim($this->config->appUrl(), '/');
        $appName = $this->config->get('MAIL_FROM_NAME', 'Омни Ссылки');
        $resetUrl = "{$appUrl}/forgot-password?email=" . rawurlencode($toEmail) . '&code=' . rawurlencode($code) . '&token=' . rawurlencode($token);

        $greeting = $userName ? "Здравствуйте, {$userName}!" : 'Здравствуйте!';
        $subject = "Восстановление пароля: {$code}";

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #12233f;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f4f6f9; padding: 40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 540px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); overflow: hidden;">
                    <!-- Шапка -->
                    <tr>
                        <td style="padding: 32px 36px 20px; text-align: center; border-bottom: 1px solid #edf2f7;">
                            <h2 style="margin: 0; font-size: 24px; font-weight: 700; color: #0056d6;">{$appName}</h2>
                        </td>
                    </tr>
                    <!-- Основной контент -->
                    <tr>
                        <td style="padding: 32px 36px;">
                            <h1 style="margin: 0 0 16px; font-size: 20px; font-weight: 600; color: #12233f;">Восстановление доступа к аккаунту</h1>
                            <p style="margin: 0 0 20px; font-size: 15px; line-height: 24px; color: #4a5568;">
                                {$greeting} Вы запросили сброс пароля на сервисе {$appName}. Для установки нового пароля используйте проверочный код:
                            </p>
                            
                            <!-- Блок с кодом -->
                            <div style="background-color: #f0f4f8; border-radius: 8px; padding: 20px; text-align: center; margin: 28px 0;">
                                <span style="font-size: 34px; font-weight: 800; letter-spacing: 8px; color: #0056d6; font-family: Consolas, monospace; display: inline-block;">{$code}</span>
                            </div>

                            <p style="margin: 0 0 24px; font-size: 14px; line-height: 22px; color: #718096; text-align: center;">
                                Или нажмите кнопку ниже для быстрого перехода к форме смены пароля:
                            </p>

                            <!-- Кнопка перехода -->
                            <div style="text-align: center; margin-bottom: 28px;">
                                <a href="{$resetUrl}" style="display: inline-block; background-color: #0056d6; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-size: 15px; font-weight: 600;">Сбросить пароль</a>
                            </div>

                            <p style="margin: 0; font-size: 13px; line-height: 20px; color: #a0aec0;">
                                Код и ссылка действительны 30 минут. Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо — ваш текущий пароль останется без изменений.
                            </p>
                        </td>
                    </tr>
                    <!-- Подвал -->
                    <tr>
                        <td style="padding: 20px 36px; background-color: #fafbfc; border-top: 1px solid #edf2f7; text-align: center; font-size: 12px; color: #a0aec0;">
                            &copy; {$appName} — сервис сокращения ссылок и аналитики переходов
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $textBody = "{$greeting}\n\n"
            . "Ваш проверочный код для сброса пароля в {$appName}: {$code}\n\n"
            . "Или перейдите по ссылке:\n{$resetUrl}\n\n"
            . "Код действителен 30 минут.\n";

        return $this->send($toEmail, $subject, $htmlBody, $textBody);
    }

    /**
     * Отправка письма выбранным драйвером (mail или smtp).
     */
    public function send(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $logOnly = $this->config->getBool('MAIL_LOG_ONLY', false);
        if ($logOnly) {
            $this->logMail($toEmail, $subject, $htmlBody);
            return true;
        }

        $driver = strtolower($this->config->get('MAIL_DRIVER', 'mail'));

        try {
            if ($driver === 'smtp') {
                return $this->sendSmtp($toEmail, $subject, $htmlBody, $textBody);
            }
            return $this->sendNativeMail($toEmail, $subject, $htmlBody, $textBody);
        } catch (Throwable $e) {
            error_log('Ошибка отправки email: ' . $e->getMessage());
            // В случае ошибки драйвера, также запишем в резервный лог
            $this->logMail($toEmail, $subject, "ERROR: {$e->getMessage()}\n\n" . $htmlBody);
            return false;
        }
    }

    private function sendNativeMail(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        if (!function_exists('mail')) {
            $this->logMail($toEmail, $subject, "FUNCTION mail() IS NOT AVAILABLE:\n" . $htmlBody);
            return false;
        }

        $fromEmail = $this->getFromEmail();
        $fromName = $this->config->get('MAIL_FROM_NAME', 'Омни Ссылки');

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $boundary = 'bnd_' . md5(uniqid((string) microtime(true), true));

        $headers = [
            'MIME-Version: 1.0',
            "From: {$encodedFromName} <{$fromEmail}>",
            "Reply-To: <{$fromEmail}>",
            'X-Mailer: PHP/' . PHP_VERSION,
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];

        $message = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody ?? strip_tags($htmlBody))) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody)) . "\r\n"
            . "--{$boundary}--\r\n";

        // Попытка отправки с 5-м параметром -f (требуется на многих хостингах)
        $cleanFrom = preg_replace('/[^a-zA-Z0-9@._-]/', '', $fromEmail);
        $sent = @mail($toEmail, $encodedSubject, $message, implode("\r\n", $headers), "-f" . ($cleanFrom ?: $fromEmail));
        if (!$sent) {
            // Если хостинг запрещает 5-й параметр, пробуем без него
            $sent = @mail($toEmail, $encodedSubject, $message, implode("\r\n", $headers));
        }

        if (!$sent) {
            $this->logMail($toEmail, $subject, "mail() returned false\n\n" . $htmlBody);
        }

        return $sent;
    }

    private function sendSmtp(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $host = $this->config->get('SMTP_HOST', '');
        $port = (int) $this->config->get('SMTP_PORT', '465');
        $user = $this->config->get('SMTP_USER', '');
        $pass = $this->config->get('SMTP_PASS', '');
        $encryption = strtolower($this->config->get('SMTP_ENCRYPTION', 'ssl'));

        if ($host === '') {
            // Если SMTP хост не заполнен, переключаемся на native mail
            return $this->sendNativeMail($toEmail, $subject, $htmlBody, $textBody);
        }

        $remoteSocket = ($encryption === 'ssl' ? "ssl://{$host}" : "tcp://{$host}") . ":{$port}";
        $socket = @stream_socket_client($remoteSocket, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new \RuntimeException("Не удалось подключиться к SMTP {$remoteSocket}: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 10);
        $this->readSmtpResponse($socket, [220]);

        $this->writeSmtpCommand($socket, 'EHLO ' . gethostname(), [250]);

        if ($encryption === 'tls') {
            $this->writeSmtpCommand($socket, 'STARTTLS', [220]);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->writeSmtpCommand($socket, 'EHLO ' . gethostname(), [250]);
        }

        if ($user !== '' && $pass !== '') {
            $this->writeSmtpCommand($socket, 'AUTH LOGIN', [334]);
            $this->writeSmtpCommand($socket, base64_encode($user), [334]);
            $this->writeSmtpCommand($socket, base64_encode($pass), [235]);
        }

        $fromEmail = $this->getFromEmail();
        $this->writeSmtpCommand($socket, "MAIL FROM:<{$fromEmail}>", [250]);
        $this->writeSmtpCommand($socket, "RCPT TO:<{$toEmail}>", [250, 251]);
        $this->writeSmtpCommand($socket, 'DATA', [354]);

        $fromName = $this->config->get('MAIL_FROM_NAME', 'Омни Ссылки');
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $boundary = 'bnd_' . md5(uniqid((string) microtime(true), true));

        $dataHeaders = [
            'MIME-Version: 1.0',
            "From: {$encodedFromName} <{$fromEmail}>",
            "To: <{$toEmail}>",
            "Subject: {$encodedSubject}",
            "Reply-To: <{$fromEmail}>",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];

        $content = implode("\r\n", $dataHeaders) . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody ?? strip_tags($htmlBody))) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody)) . "\r\n"
            . "--{$boundary}--\r\n.\r\n";

        fwrite($socket, $content);
        $this->readSmtpResponse($socket, [250]);

        $this->writeSmtpCommand($socket, 'QUIT', [221]);
        fclose($socket);

        return true;
    }

    /** @param resource $socket */
    private function readSmtpResponse($socket, array $expectedCodes): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException("SMTP ошибка (ожидались " . implode(',', $expectedCodes) . ", получено {$code}): {$response}");
        }
        return $response;
    }

    /** @param resource $socket */
    private function writeSmtpCommand($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->readSmtpResponse($socket, $expectedCodes);
    }

    private function getFromEmail(): string
    {
        $configured = $this->config->get('MAIL_FROM_ADDRESS', '');
        if ($configured !== '') {
            return $configured;
        }

        $host = $this->config->shortBaseHost() ?: 'омни.рус';
        $cleanHost = explode(':', $host)[0];
        return "no-reply@{$cleanHost}";
    }

    private function logMail(string $to, string $subject, string $body): void
    {
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/mail.log';
        $entry = sprintf(
            "[%s] TO: %s | SUBJECT: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            strip_tags($body),
            str_repeat('-', 60)
        );
        @file_put_contents($logFile, $entry, FILE_APPEND);
    }
}
