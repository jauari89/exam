<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthLoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(AuthLoginRequest $request)
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user = $request->user()->load('roles.permissions');

        if ($user->status !== 'active') {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'This account is not active.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'user' => $user,
            'token' => $user->createToken($request->input('device_name', 'secure-exam-spa'))->plainTextToken,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load('roles.permissions'),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
