<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CompanyInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * 🔐 User Login
     */
    public function login(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string|min:8',
            ]);

            $user = User::where('email', $validated['email'])->first();

            if (!$user || !Hash::check($validated['password'], $user->password)) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Invalid credentials.',
                ], 401);
            }

            if ($user->is_archived) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Account has been archived. Please contact admin.',
                ], 403);
            }

            if (!$user->is_active) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Account is inactive. Please contact admin.',
                ], 403);
            }

            // Generate a new Sanctum token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'isSuccess' => true,
                'message' => 'Login successful.',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role,

                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred during login.',
            ], 500);
        }
    }

    /**
     * 🚪 Logout User (Revoke Token)
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if ($user) {
                $user->currentAccessToken()->delete();

                return response()->json([
                    'isSuccess' => true,
                    'message' => 'Logged out successfully.',
                ]);
            }

            return response()->json([
                'isSuccess' => false,
                'message' => 'User not authenticated.',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred during logout.',
            ], 500);
        }
    }

    /**
     * 👤 Get Authenticated User Info
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'No authenticated user found.',
                ], 401);
            }

            return response()->json([
                'isSuccess' => true,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Auth check error: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve authenticated user.',
            ], 500);
        }
    }
}
