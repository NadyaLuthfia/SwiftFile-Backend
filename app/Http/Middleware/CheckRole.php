<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // 1. Cek apakah user sudah login/terautentikasi
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Silakan login terlebih dahulu.'
            ], 401); // 401 Unauthorized
        }

        // 2. Ambil role user yang sedang login (pastikan nama kolom di tabel 'users' kamu adalah 'role')
        $userRole = auth()->user()->role;

        // 3. Cek apakah role user ada di dalam daftar role yang diizinkan oleh route
        if (!in_array($userRole, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak! Anda tidak memiliki hak akses untuk fitur ini.'
            ], 403); // 403 Forbidden
        }

        return $next($request);
    }
}