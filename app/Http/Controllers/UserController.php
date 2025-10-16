<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * ✅ Create a new user account
     */
    public function createUser(Request $request)
    {
        try {
            $validated = $request->validate([
                'full_name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'role' => 'required|string|max:100',
                'password' => 'required|string|min:8|confirmed',
            ]);

            // Hash the password before saving
            $validated['password'] = Hash::make($validated['password']);

            $user = User::create($validated);

            return response()->json([
                'isSuccess' => true,
                'message' => 'User created successfully.',
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to create user.',
                'error' => $e->getMessage(), // optional, helpful for debugging
            ], 500);
        }
    }


    /**
     * ✅ Retrieve all active users
     */
    public function getUsers()
    {
        $users = User::where('is_archived', false)->get();

        return response()->json($users);
    }

    /**
     * ✅ Retrieve a specific user by ID
     */
    public function getUserById($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'User not found.',
            ], 404);
        }

        return response()->json($user);
    }

    /**
     * ✅ Update a user account
     */
    public function updateUser(Request $request, $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $validated = $request->validate([
                'full_name' => 'nullable|string|max:255',
                'email' => 'nullable|email|unique:users,email,' . $id,
                'role' => 'nullable|string|max:100',
                'password' => 'nullable|string|min:8|confirmed',
            ]);

            if (!empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            $user->update($validated);

            return response()->json([
                'isSuccess' => true,
                'message' => 'User updated successfully.',
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to update user.',
            ], 500);
        }
    }

    /**
     * ✅ Soft delete (archive) a user
     */
    public function archiveUser($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $user->is_archived = true;
        $user->save();

        return response()->json([
            'isSuccess' => true,
            'message' => 'User archived successfully.',
        ]);
    }
}
