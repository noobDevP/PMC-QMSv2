<?php
namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string|max:50',
            'password' => 'required|string|max:128',
        ]);

        $user = User::with('division')->where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $expiration = (int) config('sanctum.expiration', 1440);
        if ($expiration > 0) {
            $user->tokens()->where('created_at', '<', now()->subMinutes($expiration))->delete();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'division_id' => $user->division_id,
            'division_name' => $user->division ? $user->division->name : null,
            'token' => $token,
            'success' => true,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['success' => true]);
    }

    public function getUsers()
    {
        return response()->json(User::with('division')->get());
    }

    public function createUser(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:50|unique:users,username',
            'password' => 'required|string|min:6|max:128',
            'division_id' => 'required|array|min:1',
            'division_id.*' => 'integer|exists:divisions,id',
        ]);

        $div_ids = $validated['division_id'];
        $primary_div = count($div_ids) > 0 ? $div_ids[0] : null;

        $user = User::create([
            'username' => $validated['username'],
            'password_hash' => Hash::make($validated['password']),
            'role' => 'teller',
            'division_id' => $primary_div,
        ]);

        // Assign teller_id to all selected divisions
        Division::whereIn('id', $div_ids)->update(['teller_id' => $user->id]);

        return response()->json(['success' => true, 'id' => $user->id]);
    }

    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        if ($user->role === 'admin') {
            return response()->json(['error' => 'Cannot delete admin account'], 400);
        }

        $user->tokens()->delete();
        Division::where('teller_id', $user->id)->update(['teller_id' => null]);
        $user->delete();

        return response()->json(['success' => true]);
    }

    public function resetPassword(Request $request, $id)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:6|max:128',
        ]);

        $user = User::findOrFail($id);
        $user->update([
            'password_hash' => Hash::make($validated['password']),
        ]);
        $user->tokens()->delete();

        return response()->json(['success' => true]);
    }
}
