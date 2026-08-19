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

    public function getUsers()
    {
        return response()->json(User::with('division')->get());
    }

    public function createUser(Request $request)
    {
        $request->validate([
            'username' => 'required|unique:users,username',
            'password' => 'required',
            'division_id' => 'nullable|exists:divisions,id'
        ]);

        $user = User::create([
            'username' => $request->username,
            'password_hash' => Hash::make($request->password),
            'role' => 'teller',
            'division_id' => $request->division_id
        ]);

        return response()->json(['success' => true, 'id' => $user->id]);
    }

    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        if ($user->role === 'admin') {
            return response()->json(['error' => 'Cannot delete admin account'], 400);
        }
        $user->delete();
        return response()->json(['success' => true]);
    }

    public function resetPassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required'
        ]);

        $user = User::findOrFail($id);
        $user->update([
            'password_hash' => Hash::make($request->password)
        ]);

        return response()->json(['success' => true]);
    }
}
