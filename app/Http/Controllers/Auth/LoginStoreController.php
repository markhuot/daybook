<?php

namespace App\Http\Controllers\Auth;

use App\Data\LoginData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginStoreController extends Controller
{
    public function __invoke(LoginData $data, Request $request): RedirectResponse|JsonResponse
    {
        $credentials = [
            'email' => $data->email,
            'password' => $data->password,
        ];

        if (Auth::attempt($credentials, $data->remember)) {
            $request->session()->regenerate();

            if ($request->wantsJson()) {
                return response()->json(['authenticated' => true]);
            }

            return redirect()->intended('/');
        }

        if ($request->wantsJson()) {
            return response()->json([
                'errors' => ['email' => ['These credentials do not match our records.']],
            ], 422);
        }

        return back()->withErrors([
            'email' => 'These credentials do not match our records.',
        ])->onlyInput('email');
    }
}
