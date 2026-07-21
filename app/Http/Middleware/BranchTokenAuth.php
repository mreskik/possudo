<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class BranchTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['code' => 401, 'message' => 'Token tidak ditemukan.'], 401);
        }

        $branch = DB::table('mr_branch')->where('token', $token)->first();

        if (!$branch) {
            return response()->json(['code' => 401, 'message' => 'Token tidak valid.'], 401);
        }

        return $next($request);
    }
}
