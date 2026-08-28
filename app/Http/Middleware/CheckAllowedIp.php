<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAllowedIp
{
    public function handle(Request $request, Closure $next): Response
    {
        // default true (proteksi aktif) kalau USE_PROTECT_IP gak diisi/kosong ATAU nilainya
        // gak valid (typo dkk) -- fail-safe, jangan sampai proteksi mati diam-diam gara-gara
        // baris env kelewat atau salah ketik. Catatan: filter_var(null/"", ...) balikin
        // `false` langsung (BUKAN null) walau pakai FILTER_NULL_ON_FAILURE -- makanya kasus
        // "belum diisi" harus dicek manual duluan, gak bisa diserahin ke filter_var aja.
        $rawUseProtectIp = env('USE_PROTECT_IP');
        if ($rawUseProtectIp === null || $rawUseProtectIp === '') {
            $useProtectIp = true;
        } else {
            $useProtectIp = filter_var($rawUseProtectIp, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }
        if (!$useProtectIp) {
            return $next($request);
        }

        $allowedIps = array_filter(array_map('trim', explode(',', (string) env('POS_ALLOWED_IPS'))));

        if (!in_array($request->ip(), $allowedIps, true)) {
            return response()->json(['code' => 403, 'message' => 'IP tidak diizinkan mengakses.'], 403);
        }

        return $next($request);
    }
}
