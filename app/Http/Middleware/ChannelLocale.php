<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ChannelLocale
{
    /** Set application locale from request or user preference */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language');

        if (!$locale && $user = $request->user()) {
            $locale = $user->locale ?? null;
        }

        $locale = $this->bestMatch($locale ?? 'en');

        App::setLocale($locale);
        setlocale(LC_TIME, $locale . '_' . strtoupper($locale) . '.UTF-8', $locale);

        return $next($request);
    }

    private function bestMatch(string $header): string
    {
        $supported = ['en', 'ar', 'fr', 'es', 'de', 'zh', 'pt', 'ru'];
        foreach (explode(',', $header) as $accept) {
            $lang = strtolower(trim(explode(';', $accept)[0]));
            if (in_array($lang, $supported, true)) return $lang;
            $base = explode('-', $lang)[0];
            if (in_array($base, $supported, true)) return $base;
        }
        return 'en';
    }
}
