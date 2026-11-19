<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request and attach security/cache-control headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }

        $response = $next($request);

        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; "
            . "script-src 'self' https://connect.facebook.net https://js.pusher.com; "
            . "style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
            . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
            . "img-src 'self' https://www.facebook.com https://i.ytimg.com; "
            . "media-src 'self'; "
            . "connect-src 'self' wss://ws-ap1.pusher.com https://sockjs-ap1.pusher.com https://sockjs.pusher.com https://stats.pusher.com https://connect.facebook.net https://www.facebook.com; "
            . "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://www.facebook.com https://web.facebook.com https://calendar.google.com; "
            . "manifest-src 'self'; worker-src 'self'; upgrade-insecure-requests"
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
