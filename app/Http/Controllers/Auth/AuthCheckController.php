<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => $request->user() !== null,
        ]);
    }
}
