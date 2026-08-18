<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required'
        ]);

        $user = User::with('division')->where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'division_id' => $user->division_id,
            'division_name' => $user->division ? $user->division->name : null,
            'token' => $token,
            'success' => true
        ]);
    }
}
