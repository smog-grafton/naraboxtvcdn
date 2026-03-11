<?php

namespace App\Http\Middleware;

use App\Models\MediaApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMediaApiToken
{
    public function handle(Request $request, Closure $next, string $ability = '*'): Response
    {
        $header = (string) $request->header('Authorization', '');
        $token = '';

        if (str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));
        }

        if ($token === '') {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => 'Missing API token.',
            ], 401);
        }

        $hashed = hash('sha256', $token);
        $apiToken = MediaApiToken::where('token_hash', $hashed)->first();

        if (! $apiToken || ! $apiToken->isUsable() || ! $apiToken->can($ability)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => 'Invalid or expired API token.',
            ], 401);
        }

        $apiToken->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('media_api_token', $apiToken);

        return $next($request);
    }
}

