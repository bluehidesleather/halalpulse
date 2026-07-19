<?php

declare(strict_types=1);

namespace HalalPulse\Auth;

use RuntimeException;

final class SessionManager
{
    public function __construct(
        private readonly string $name,
        private readonly int $idleSeconds,
        private readonly int $absoluteSeconds,
        private readonly bool $secureCookie,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException('Cannot start a secure session after output has begun.');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', (string) max($this->idleSeconds, $this->absoluteSeconds));
        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookie,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Unable to start the session.');
        }

        $this->expireStaleAuthentication();
    }

    public function login(User $user): void
    {
        session_regenerate_id(true);
        $now = time();
        $_SESSION['auth'] = [
            'user_id' => $user->id,
            'created_at' => $now,
            'last_activity_at' => $now,
        ];
        $this->rotateCsrfToken();
    }

    public function currentUser(UserRepository $users): ?User
    {
        $userId = $_SESSION['auth']['user_id'] ?? null;
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return null;
        }

        $user = $users->findActiveById((int) $userId);
        if ($user === null) {
            unset($_SESSION['auth']);
            return null;
        }

        $_SESSION['auth']['last_activity_at'] = time();

        return $user;
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
        }

        session_destroy();
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $this->rotateCsrfToken();
        }

        return $_SESSION['csrf_token'];
    }

    public function verifyCsrf(?string $token): bool
    {
        $stored = $_SESSION['csrf_token'] ?? null;

        return is_string($stored) && is_string($token) && hash_equals($stored, $token);
    }

    public function rotateCsrfToken(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type: string, message: string}|null */
    public function consumeFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($flash) && isset($flash['type'], $flash['message']) ? $flash : null;
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
        $this->rotateCsrfToken();
    }

    private function expireStaleAuthentication(): void
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth)) {
            return;
        }

        $now = time();
        $created = (int) ($auth['created_at'] ?? 0);
        $lastActivity = (int) ($auth['last_activity_at'] ?? 0);
        $idleExpired = $lastActivity <= 0 || ($now - $lastActivity) > $this->idleSeconds;
        $absoluteExpired = $created <= 0 || ($now - $created) > $this->absoluteSeconds;

        if ($idleExpired || $absoluteExpired) {
            unset($_SESSION['auth']);
            $this->rotateCsrfToken();
            $this->flash('warning', 'Your session expired. Please sign in again.');
        }
    }
}
