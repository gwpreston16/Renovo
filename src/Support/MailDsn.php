<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Symfony Mailer DSN for the relay the environment describes.
 *
 * Its own class so the choice of scheme and the certificate check can be
 * tested without a container, and so the one rule that matters here is in one
 * place: whether the relay's certificate is checked (`MAIL_VERIFY_PEER`,
 * Phase 43). It used to be switched off for plain `smtp` on every instance,
 * which meant a STARTTLS session to a remote relay accepted any certificate
 * and the SMTP credentials could be read by whoever sat in the middle.
 */
final class MailDsn
{
    /**
     * @param array{
     *     host: string, port: int, user: string, password: string, encryption: string, verify_peer: bool
     * } $mail
     */
    public static function fromSettings(array $mail): string
    {
        $scheme = match ($mail['encryption']) {
            'tls', 'ssl' => 'smtps',
            default => 'smtp',
        };

        $credentials = $mail['user'] !== ''
            ? rawurlencode($mail['user']) . ':' . rawurlencode($mail['password']) . '@'
            : '';

        $dsn = sprintf('%s://%s%s:%d', $scheme, $credentials, $mail['host'], $mail['port']);

        return $mail['verify_peer'] ? $dsn : $dsn . '?verify_peer=0';
    }
}
