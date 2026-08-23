<?php

namespace TypechoPlugin\FriendLinks\Infrastructure;

use PHPMailer\PHPMailer\PHPMailer;

final class EmailNotificationChannel implements NotificationChannelInterface
{
    public function send(array $notification, array $settings, ?float $deadline = null): void
    {
        $recipients = array_values(array_filter(array_map('trim', explode(
            ',',
            (string) ($settings['email_recipients'] ?? '')
        ))));
        if (!$recipients) {
            throw new \RuntimeException('邮件通知没有有效收件地址。');
        }
        $timeout = $this->commandTimeout($settings, $deadline, count($recipients));

        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'UTF-8';
        $mailer->isSMTP();
        $host = (string) ($settings['smtp_host'] ?? '');
        $mailer->Host = $host;
        $mailer->Port = (int) ($settings['smtp_port'] ?? 587);
        $mailer->Timeout = $timeout;
        $mailer->getSMTPInstance()->Timelimit = $timeout;

        $encryption = (string) ($settings['smtp_encryption'] ?? 'starttls');
        if (!in_array($encryption, ['none', 'starttls', 'smtps'], true)) {
            throw new \RuntimeException('SMTP 加密方式无效。');
        }
        $username = (string) ($settings['smtp_username'] ?? '');
        $password = (string) ($settings['smtp_password'] ?? '');
        $hasUsername = '' !== $username;
        $hasPassword = '' !== $password;
        if ($hasUsername !== $hasPassword) {
            throw new \RuntimeException('SMTP 用户名和密码必须同时填写。');
        }
        if ('none' === $encryption && ($hasUsername || $hasPassword)) {
            throw new \RuntimeException('无加密 SMTP 不允许发送认证凭据。');
        }
        if ('none' === $encryption && !$this->isLoopbackHost($host)) {
            throw new \RuntimeException('无加密 SMTP 主机必须是本机回环地址。');
        }
        $mailer->SMTPAuth = $hasUsername && $hasPassword;
        $mailer->Username = $username;
        $mailer->Password = $password;

        if ('smtps' === $encryption) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ('starttls' === $encryption) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        }

        $from = (string) ($settings['smtp_from_address'] ?? '');
        $fromName = (string) ($settings['smtp_from_name'] ?? 'FriendLinks');
        $mailer->setFrom($from, $fromName);
        foreach ($recipients as $recipient) {
            $mailer->addAddress($recipient);
        }

        $mailer->isHTML(false);
        $mailer->Subject = (string) ($notification['subject'] ?? '');
        $mailer->Body = (string) ($notification['message'] ?? '');
        $mailer->send();
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));
        if ('localhost' === $host || '::1' === $host) {
            return true;
        }
        return 1 === preg_match('/^127(?:\.\d{1,3}){3}$/D', $host)
            && false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }

    private function commandTimeout(array $settings, ?float $deadline, int $recipients): int
    {
        $configured = max(2, min(30, (int) ($settings['request_timeout'] ?? 10)));
        if (null === $deadline) {
            return $configured;
        }

        $remaining = (int) floor($deadline - microtime(true));
        $protocolSteps = $recipients + 16;
        if ($remaining <= $protocolSteps) {
            throw new \RuntimeException('剩余时间不足以开始 SMTP 投递。');
        }
        return max(1, min($configured, intdiv($remaining - 1, $protocolSteps)));
    }
}
