<?php

namespace App\Http\Controllers\Auth;

use App\Data\RegisterData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class RegisterStoreController extends Controller
{
    public function __invoke(RegisterData $data): RedirectResponse
    {
        $user = User::create($data->toArray());

        Auth::login($user);

        return redirect('/');
    }
}
