<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 1 — masa berlaku sesi login (idle timeout).
 *
 * Sebelum ini token Sanctum di proyek ini TIDAK PERNAH kedaluwarsa
 * (config/sanctum.php 'expiration' => null), sehingga sekali login sebuah
 * perangkat bisa masuk selamanya — termasuk HP bersama di gate atau laptop
 * kantor yang ditinggal pemiliknya. Middleware ini menutup celah itu:
 *
 *   1. IDLE   — token dicabut kalau tidak ada aktivitas selama N menit.
 *               Batasnya per peran (config/nontix.php 'session.idle_minutes').
 *   2. ABSOLUT — token mati sendiri N hari setelah dibuat, diatur lewat
 *               config('sanctum.expiration'). Dikerjakan otomatis oleh
 *               Sanctum, bukan di sini.
 *
 * ⚠️ PENTING — jangan matikan pelacakan di config/sanctum.php:
 * Sanctum secara bawaan menulis ulang `last_used_at` = sekarang pada SETIAP
 * request (Laravel\Sanctum\Guard::updateLastUsedAt), dan itu terjadi SEBELUM
 * middleware ini jalan. Akibatnya selisih waktu menganggur akan selalu 0 menit
 * dan sesi tidak akan pernah berakhir. Karena itu
 * config('sanctum.last_used_at') diset false, dan middleware inilah yang
 * mencatat `last_used_at` — setelah pengecekan, bukan sebelumnya.
 */
class EnsureSessionIdle
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Hanya token yang benar-benar tersimpan di database yang dihitung.
        // Selain itu dilewati:
        //   - TransientToken → sesi berbasis cookie, bukan token;
        //   - mock           → Sanctum::actingAs() memakai Mockery mock dari
        //                      PersonalAccessToken (Sanctum.php), sehingga lolos
        //                      instanceof padahal tidak punya baris di database:
        //                      `exists` = false dan setiap atribut tak di-stub
        //                      bernilai false (bukan null).
        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            return $next($request);
        }

        $batasMenit = $this->batasMenganggurMenit($user->peran?->value);

        // Token baru (last_used_at null) belum pernah dipakai → selalu lolos.
        if ($batasMenit > 0 && $token->last_used_at?->lt(now()->subMinutes($batasMenit))) {
            $token->delete();

            return response()->json([
                'message' => 'Sesi Anda berakhir karena tidak ada aktivitas. Silakan masuk kembali.',
                'code' => 'SESSION_IDLE_EXPIRED',
            ], 401);
        }

        // Catat aktivitas terakhir; jadi patokan pengecekan pada request berikutnya.
        $token->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }

    /** Batas menganggur (menit) untuk sebuah peran. 0 = tanpa batas. */
    private function batasMenganggurMenit(?string $peran): int
    {
        $perPeran = (array) config('nontix.session.idle_minutes', []);

        return (int) ($perPeran[$peran] ?? config('nontix.session.idle_default_minutes', 120));
    }
}
