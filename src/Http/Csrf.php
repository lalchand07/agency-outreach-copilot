<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Synchroniser-token CSRF protection. Every POST must carry the session token
 * in the "_csrf" field or the "X-CSRF-Token" header (HTMX).
 */
final class Csrf
{
    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
        }

        return $token;
    }

    public function isValid(Request $request): bool
    {
        $expected = $this->session->get('_csrf');
        $given = $request->post['_csrf'] ?? $request->header('x-csrf-token');

        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }

    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($this->token()) . '">';
    }
}
