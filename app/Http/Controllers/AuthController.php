<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    /**
     * Tampilkan halaman login.
     */
    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login', [
            'appName'      => config('app.app_name', 'PINTU MADRASAH'),
            'madrasahName' => env('MADRASAH_NAME', 'MTs Negeri'),
        ]);
    }

    /**
     * Proses login pengguna.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $attempt = Auth::attempt(
            ['username' => $credentials['username'], 'password' => $credentials['password'], 'is_active' => true],
            $request->boolean('remember')
        );

        if (! $attempt) {
            return back()
                ->withErrors(['username' => 'Username atau password salah, atau akun tidak aktif.'])
                ->withInput($request->only('username'));
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    /**
     * Logout pengguna.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
