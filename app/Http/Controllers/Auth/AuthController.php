<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private AccessService $access)
    {
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            app(\App\Services\AuditLogger::class)->record('login_failed', 'User', ['username' => ['old' => null, 'new' => $credentials['username']]], $credentials['username'], $user?->id, false);
            throw ValidationException::withMessages([
                'username' => ['ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],
            ]);
        }

        $token = $user->createToken('madd-frontend')->plainTextToken;
        app(\App\Services\AuditLogger::class)->record('login', $user, [], $user->username, null, $user);

        return response()->json([
            'user' => $this->withAccess($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        app(\App\Services\AuditLogger::class)->record('logout', $request->user(), [], $request->user()->username);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'ออกจากระบบเรียบร้อย']);
    }

    /** Re-read by the frontend on every app load, so Role changes apply without re-login. */
    public function me(Request $request)
    {
        return response()->json($this->withAccess($request->user()));
    }

    /**
     * The user plus their resolved `access` (permissions / field levels / data scopes, see
     * AccessService) — the frontend only uses this to shape the UI; the API enforces it itself.
     */
    private function withAccess(User $user): array
    {
        return $user->load('branches:id,name,code')->toArray() + ['access' => $this->access->resolve($user)];
    }
}
