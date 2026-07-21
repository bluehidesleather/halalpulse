#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$appCssPath = $root . '/public_html/assets/app.css';
$operationsCssPath = $root . '/public_html/assets/operations.css';
$appCss = file_get_contents($appCssPath);
$operationsCss = file_get_contents($operationsCssPath);

if (!is_string($appCss) || !is_string($operationsCss)) {
    fwrite(STDERR, "Unable to read the application stylesheets.\n");
    exit(2);
}

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[PASS] {$message}\n";
        return;
    }

    $failed++;
    echo "[FAIL] {$message}\n";
};

$tokens = [];
if (preg_match_all('/--([a-z0-9-]+)\s*:\s*(#[0-9a-f]{6})\s*;/i', $appCss, $matches, PREG_SET_ORDER) !== false) {
    foreach ($matches as $match) {
        $tokens[$match[1]] = strtolower($match[2]);
    }
}

$relativeLuminance = static function (string $hex): float {
    $hex = ltrim($hex, '#');
    $channels = [];
    foreach ([0, 2, 4] as $offset) {
        $value = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $value <= 0.04045
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
};

$contrastRatio = static function (string $foreground, string $background) use ($relativeLuminance): float {
    $first = $relativeLuminance($foreground);
    $second = $relativeLuminance($background);
    $lighter = max($first, $second);
    $darker = min($first, $second);

    return ($lighter + 0.05) / ($darker + 0.05);
};

$requiredTokens = ['bg', 'surface', 'text', 'muted', 'accent', 'info', 'success', 'warning', 'danger'];
foreach ($requiredTokens as $token) {
    $assert(isset($tokens[$token]), "The {$token} design token is defined as a six-digit hex color.");
}

$assert(str_contains($appCss, 'color-scheme: light;'), 'The browser-native color scheme is explicitly light.');
$assert(!str_contains($appCss, 'color-scheme: dark'), 'The dark color scheme cannot return silently.');
$assert(substr_count($appCss, '{') === substr_count($appCss, '}'), 'The main stylesheet has balanced declaration blocks.');
$assert(substr_count($operationsCss, '{') === substr_count($operationsCss, '}'), 'The operations stylesheet has balanced declaration blocks.');

if (isset($tokens['surface'], $tokens['text'], $tokens['muted'], $tokens['accent'])) {
    $assert($relativeLuminance($tokens['surface']) >= 0.90, 'Primary surfaces remain visually light.');
    $assert($contrastRatio($tokens['text'], $tokens['surface']) >= 7.0, 'Primary text meets WCAG AAA contrast on the main surface.');
    $assert($contrastRatio($tokens['muted'], $tokens['surface']) >= 4.5, 'Muted text meets WCAG AA contrast on the main surface.');
    $assert($contrastRatio($tokens['accent'], $tokens['surface']) >= 4.5, 'Brand-link text meets WCAG AA contrast on the main surface.');
}

$assert(($tokens['accent'] ?? '') !== ($tokens['success'] ?? ''), 'The brand accent is distinct from the semantic success color.');
$assert(($tokens['accent'] ?? '') === '#7d623d', 'The locked brand accent is muted bronze rather than green.');

$legacyDarkGreenValues = ['#07100e', '#0d1916', '#12221d', '#183028', '#65d89b', '#39b877'];
foreach ($legacyDarkGreenValues as $legacyColor) {
    $assert(!str_contains(strtolower($appCss), $legacyColor), "Legacy dark-green color {$legacyColor} is absent.");
}

$brandBlock = '';
if (preg_match('/\.brand-mark\s*\{([^}]*)\}/s', $appCss, $brandMatch) === 1) {
    $brandBlock = $brandMatch[1];
}
$assert($brandBlock !== '' && !str_contains($brandBlock, 'var(--success)'), 'Brand styling does not reuse the semantic success green.');

$policyBlock = '';
if (preg_match('/\.policy-banner\s*\{([^}]*)\}/s', $appCss, $policyMatch) === 1) {
    $policyBlock = $policyMatch[1];
}
$assert($policyBlock !== '' && !str_contains($policyBlock, 'var(--success)'), 'Sharia policy banners are informational, not automatically green.');
$assert(!str_contains($operationsCss, 'rgba(0, 0, 0'), 'Operations cards no longer use dark translucent surfaces.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
