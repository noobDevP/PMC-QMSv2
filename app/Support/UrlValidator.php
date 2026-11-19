<?php

namespace App\Support;

class UrlValidator
{
    private const ALLOWED_FACEBOOK_HOSTS = [
        'facebook.com',
        'www.facebook.com',
        'm.facebook.com',
        'web.facebook.com',
        'fb.watch',
    ];

    /**
     * Validate either a Google Calendar ID or an HTTPS Google Calendar ICS URL.
     */
    public static function isValidGoogleCalendarSetting(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return true;
        }

        $trimmed = trim($value);
        if (str_contains($trimmed, '://') || stripos($trimmed, 'http') === 0) {
            return self::isValidGoogleCalendarIcsUrl($trimmed);
        }

        return preg_match('/^[a-zA-Z0-9._@+\-]{3,255}$/', $trimmed) === 1;
    }

    /**
     * Validate a Google Calendar ICS feed URL to prevent SSRF and unsafe schemes.
     */
    public static function isValidGoogleCalendarIcsUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';

        if ($scheme !== 'https' || $host !== 'calendar.google.com') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($port !== null && (int) $port !== 443) {
            return false;
        }

        return $path !== '' && str_starts_with($path, '/calendar/');
    }

    /**
     * Validate an HTTPS Facebook video URL.
     */
    public static function isValidFacebookUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return true;
        }

        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($scheme !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        return in_array($host, self::ALLOWED_FACEBOOK_HOSTS, true);
    }

    /**
     * Validate a YouTube video or playlist identifier.
     */
    public static function isValidYoutubeId(?string $id): bool
    {
        if ($id === null || trim($id) === '') {
            return true;
        }

        return preg_match('/^[a-zA-Z0-9_\-]{3,100}$/', trim($id)) === 1;
    }
}
