<?php
declare(strict_types=1);

if (!function_exists('tvr_is_https')) {
    function tvr_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }
        return false;
    }
}

if (!function_exists('tvr_client_ip')) {
    function tvr_client_ip(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return '0.0.0.0';
        }
        return substr($ip, 0, 45);
    }
}

if (!function_exists('tvr_secure_session_start')) {
    function tvr_secure_session_start(bool $adminArea = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', tvr_is_https() ? '1' : '0');

        $params = session_get_cookie_params();
        $sameSite = $adminArea ? 'Strict' : 'Lax';

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => tvr_is_https(),
                'httponly' => true,
                'samesite' => $sameSite,
            ]);
        } else {
            $path = ($params['path'] ?: '/') . '; samesite=' . $sameSite;
            session_set_cookie_params(
                0,
                $path,
                $params['domain'] ?? '',
                tvr_is_https(),
                true
            );
        }

        
        session_start();
    }
}

if (!function_exists('tvr_security_headers')) {
    function tvr_security_headers(bool $adminArea = false): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');

        if (tvr_is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $csp = implode(' ', [
            "default-src 'self';",
            "base-uri 'self';",
            "frame-ancestors 'self';",
            "form-action 'self';",
            "object-src 'none';",
            "script-src 'self' 'unsafe-inline';",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;",
            "font-src 'self' https://fonts.gstatic.com data:;",
            "img-src 'self' data: https: http:;",
            "connect-src 'self';",
        ]);
        header('Content-Security-Policy: ' . $csp);

        if ($adminArea) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }
}

if (!function_exists('tvr_csrf_get')) {
    function tvr_csrf_get(string $key = 'csrf_token'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION[$key];
    }
}

if (!function_exists('tvr_csrf_validate')) {
    function tvr_csrf_validate(string $token, string $key = 'csrf_token'): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION[$key])) {
            return false;
        }
        return hash_equals((string)$_SESSION[$key], $token);
    }
}

if (!function_exists('tvr_csrf_rotate')) {
    function tvr_csrf_rotate(string $key = 'csrf_token'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $_SESSION[$key] = bin2hex(random_bytes(32));
        return (string)$_SESSION[$key];
    }
}

if (!function_exists('tvr_is_same_origin_request')) {
    function tvr_is_same_origin_request(): bool
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return true;
        }

        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '') {
            $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
            if ($originHost !== '' && $originHost !== $host) {
                return false;
            }
        }

        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $refHost = strtolower((string)parse_url($referer, PHP_URL_HOST));
            if ($refHost !== '' && $refHost !== $host) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('tvr_rate_limit_hit')) {
    function tvr_rate_limit_hit(string $bucket, int $maxAttempts, int $windowSeconds): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);

        if (!isset($_SESSION['__tvr_rl']) || !is_array($_SESSION['__tvr_rl'])) {
            $_SESSION['__tvr_rl'] = [];
        }

        $ip = tvr_client_ip();
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80);
        $key = hash('sha256', $bucket . '|' . $ip . '|' . $ua);

        $now = time();
        $cutoff = $now - $windowSeconds;

        $hits = $_SESSION['__tvr_rl'][$key] ?? [];
        if (!is_array($hits)) {
            $hits = [];
        }

        $filtered = [];
        foreach ($hits as $ts) {
            $ts = (int)$ts;
            if ($ts >= $cutoff && $ts <= $now) {
                $filtered[] = $ts;
            }
        }

        $filtered[] = $now;
        $_SESSION['__tvr_rl'][$key] = $filtered;

        return count($filtered) > $maxAttempts;
    }
}
