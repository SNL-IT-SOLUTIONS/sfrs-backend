<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PrincipalController extends Controller
{
    /**
     * View all uploaded files in the repository (across all users)
     */
    public function viewAllFiles()
    {
        try {
            $files = File::with('folder.user')->get();

            // Add full URL for file access
            $files->each(function ($file) {
                $file->file_url = asset('storage/' . $file->file_path);
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'All uploaded files retrieved successfully.',
                'data' => $files,
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving files: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to load files.',
            ], 500);
        }
    }

    /**
     * View all users pending approval
     */
    public function getPendingUsers()
    {
        try {
            $pendingUsers = User::where('is_approved', false)->get();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Pending user accounts retrieved successfully.',
                'data' => $pendingUsers,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching pending users: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch pending users.',
            ], 500);
        }
    }

    /**
     * Approve a user registration
     */
    public function approveUser($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $user->is_approved = true;
            $user->save();

            return response()->json([
                'isSuccess' => true,
                'message' => 'User approved successfully.',
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Error approving user: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to approve user.',
            ], 500);
        }
    }
}
