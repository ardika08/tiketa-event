<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Pastikan user terautentikasi memiliki salah satu peran yang diizinkan.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Tidak terautentikasi.'], 401);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'Akun tidak aktif.'], 403);
        }

        $peran = $user->peran?->value;
        if (! in_array($peran, $roles, true)) {
            return response()->json(['message' => 'Akses ditolak untuk peran ini.'], 403);
        }

        return $next($request);
    }
}
