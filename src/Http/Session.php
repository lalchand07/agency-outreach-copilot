<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Session wrapper with secure cookie settings and flash messages. Tests use
 * an in-memory array instead of $_SESSION.
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $memory = [];

    public function __construct(private bool $native = true)
    {
    }

    public function start(bool $secure): void
    {
        if (!$this->native || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        session_name('aoc_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        $now = time();
        // Idle timeout: 8 hours.
        if (isset($_SESSION['_last']) && is_int($_SESSION['_last']) && $now - $_SESSION['_last'] > 8 * 3600) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->native ? ($_SESSION[$key] ?? $default) : ($this->memory[$key] ?? $default);
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->native) {
            $_SESSION[$key] = $value;
        } else {
            $this->memory[$key] = $value;
        }
    }

    public function remove(string $key): void
    {
        if ($this->native) {
            unset($_SESSION[$key]);
        } else {
            unset($this->memory[$key]);
        }
    }

    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        $this->memory = [];
    }

    public function flash(string $type, string $message): void
    {
        $flashes = (array) $this->get('_flash', []);
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $flashes);
    }

    /** @return list<array{type: string, message: string}> */
    public function takeFlashes(): array
    {
        $flashes = (array) $this->get('_flash', []);
        $this->remove('_flash');

        return array_values(array_filter($flashes, 'is_array'));
    }
}
