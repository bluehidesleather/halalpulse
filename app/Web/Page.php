<?php

declare(strict_types=1);

namespace HalalPulse\Web;

use HalalPulse\Auth\User;

final class Page
{
    public static function begin(
        string $title,
        string $appName,
        ?User $user,
        string $active = '',
        ?string $csrfToken = null,
        string $bodyClass = '',
    ): void {
        self::securityHeaders();
        $title = self::escape($title);
        $appName = self::escape($appName);
        $bodyClass = self::escape($bodyClass);

        echo "<!doctype html>\n";
        echo '<html lang="en"><head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo "<title>{$title} · {$appName}</title>";
        echo '<link rel="stylesheet" href="/assets/app.css">';
        echo '</head><body class="' . $bodyClass . '">';

        if ($user !== null) {
            self::navigation($appName, $user, $active, (string) $csrfToken);
            echo '<main class="app-main">';
        } else {
            echo '<main class="public-main">';
        }
    }

    public static function end(): void
    {
        echo '</main></body></html>';
    }

    /** @param array{type: string, message: string}|null $flash */
    public static function flash(?array $flash): void
    {
        if ($flash === null) {
            return;
        }

        $allowed = ['success', 'warning', 'error', 'info'];
        $type = in_array($flash['type'], $allowed, true) ? $flash['type'] : 'info';
        echo '<div class="flash flash-' . self::escape($type) . '" role="status">';
        echo self::escape($flash['message']);
        echo '</div>';
    }

    public static function escape(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function navigation(string $appName, User $user, string $active, string $csrfToken): void
    {
        $links = [
            'dashboard' => ['/dashboard.php', 'Overview'],
            'filings' => ['/filings.php', 'Filings'],
            'documents' => ['/documents.php', 'Documents'],
            'sharia' => ['/sharia.php', 'Sharia'],
            'multibagger' => ['/multibagger.php', 'Potential'],
            'government' => ['/government.php', 'Tailwinds'],
            'alerts' => ['/alerts.php', 'Alerts'],
            'settings' => ['/settings.php', 'Security'],
        ];

        echo '<header class="topbar"><div class="topbar-inner">';
        echo '<a class="brand" href="/dashboard.php"><span class="brand-mark">HP</span><span>' . $appName . '</span></a>';
        echo '<nav class="primary-nav" aria-label="Primary">';

        foreach ($links as $key => [$href, $label]) {
            $class = $active === $key ? 'nav-link active' : 'nav-link';
            echo '<a class="' . $class . '" href="' . $href . '">' . self::escape($label) . '</a>';
        }

        echo '</nav><div class="user-menu">';
        echo '<span class="user-name">' . self::escape($user->displayName) . '</span>';
        echo '<form method="post" action="/logout.php">';
        echo '<input type="hidden" name="csrf_token" value="' . self::escape($csrfToken) . '">';
        echo '<button class="button button-quiet button-small" type="submit">Sign out</button>';
        echo '</form></div></div></header>';
    }

    private static function securityHeaders(): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; script-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    }
}
