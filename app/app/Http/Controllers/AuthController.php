<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function create(Request $request)
    {
        if ($request->header('X-Inertia')) {
            return \Inertia\Inertia::location(route('login'));
        }

        return response()->view('auth.login')->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $credentials['email'] = strtolower(trim($credentials['email']));
        $key = 'owner-login:'.hash('sha256', $credentials['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many login attempts. Try again later.');
        }
        if (! Auth::guard('web')->attempt($credentials)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate(true);
        $request->session()->forget([
            'chat_session_id', 'chat_user_id', 'identity_source', 'authenticated_owner_context',
        ]);

        return redirect()->route('history');
    }

    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
