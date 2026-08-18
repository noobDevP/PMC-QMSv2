<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request )
    {
        ->validate([
            'username' => 'required',
            'password' => 'required'
        ]);

         = User::with('division')->where('username', ->username)->first();

        if (! || !Hash::check(->password, ->password_hash)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

         = ->createToken('auth_token')->plainTextToken;

        return response()->json([
            'id' => ->id,
            'username' => ->username,
            'role' => ->role,
            'division_id' => ->division_id,
            'division_name' => ->division ? ->division->name : null,
            'token' => ,
            'success' => true
        ]);
    }
}
