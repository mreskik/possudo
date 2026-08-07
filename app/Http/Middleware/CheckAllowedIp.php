<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAllowedIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = array_filter(array_map('trim', explode(',', (string) env('POS_ALLOWED_IPS'))));

        if (!in_array($request->ip(), $allowedIps, true)) {
            return response()->json(['code' => 403, 'message' => 'IP tidak diizinkan mengakses.'], 403);
        }

        return $next($request);
    }
}
