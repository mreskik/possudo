<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// RestrictToLocalhost: endpoint internal print (lihat PrintJobController) HARUS cuma bisa diakses
// dari server itu sendiri -- dipanggil via HTTP self-call dari OrderServices::SaveOrder() dkk
// (PrintServices::dispatchPrintJob()), TANPA validasi session/token (disepakati eksplisit user,
// 2026-11-23) KARENA dijamin gak pernah diakses dari luar lewat guard IP ini. '::1' = localhost
// IPv6 (jaga-jaga kalau Apache listen di IPv6 juga).
class RestrictToLocalhost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->ip(), ['127.0.0.1', '::1'])) {
            return response()->json(['code' => 403, 'message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
