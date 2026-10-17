<?php

declare(strict_types=1);

namespace App\Http;

use App\Db\Database;
use App\Support\Clock;

/**
 * Single admin login. The password hash lives in .env (ADMIN_PASSWORD_HASH);
 * failed attempts are throttled per IP.
 */
final class Auth
{
    public const MAX_FAILURES = 5;
    public const WINDOW_MINUTES = 15;

    public function __construct(
        private Session $session,
        private Database $db,
        private Clock $clock,
        private string $username,
        private string $passwordHash,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->passwordHash !== '' && password_get_info($this->passwordHash)['algo'] !== null;
    }

    public function check(): bool
    {
        return $this->session->get('_admin') === true;
    }

    public function isThrottled(string $ip): bool
    {
        $since = $this->clock->now()->modify('-' . self::WINDOW_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        return (int) $this->db->value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ?', [$ip, $since]) >= self::MAX_FAILURES;
    }

    public function attempt(string $username, string $password, string $ip): bool
    {
        if (!$this->isConfigured() || $this->isThrottled($ip)) {
            return false;
        }
        $ok = hash_equals($this->username, $username) && password_verify($password, $this->passwordHash);
        if (!$ok) {
            $this->db->insert('login_attempts', ['ip' => $ip, 'created_at' => $this->clock->nowString()]);

            return false;
        }
        $this->db->run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        $this->session->regenerate();
        $this->session->set('_admin', true);

        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
