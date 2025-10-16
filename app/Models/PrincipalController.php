<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Folder;


class PrincipalController extends Controller
{
    /**
     * View all uploaded files in the repository (across all users)
     */
    public function viewAllRepositories()
    {
        try {
            // Fetch ALL folders from all users (root level)
            $folders = Folder::whereNull('parent_id')
                ->with([
                    'children' => function ($query) {
                        $query->with([
                            'files',
                            'children' => function ($subQuery) {
                                $subQuery->with('files');
                            },
                        ]);
                    },
                    'files',
                    'user' // ✅ include user info for identification
                ])
                ->get();

            // ✅ Fetch ALL root files (not inside any folder)
            $rootFiles = File::whereNull('folder_id')
                ->with('user')
                ->get();

            // Attach folder and file URLs
            $folders->each(function ($folder) {
                $user = $folder->user;
                $userFolderPrefix = $user
                    ? 'user_' . str_replace(' ', '_', $user->first_name . '_' . $user->last_name) . '/'
                    : '';

                $cleanFolderPath = str_replace($userFolderPrefix, '', $folder->path ?? '');
                $folder->folder_url = asset('storage/' . $cleanFolderPath);

                // Attach file URLs in folder
                $folder->files->each(function ($file) use ($userFolderPrefix) {
                    $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                    $file->file_url = asset('storage/' . $cleanPath);
                });

                // Attach file URLs in subfolders
                $folder->children->each(function ($child) use ($userFolderPrefix) {
                    $cleanChildPath = str_replace($userFolderPrefix, '', $child->path ?? '');
                    $child->folder_url = asset('storage/' . $cleanChildPath);

                    $child->files->each(function ($file) use ($userFolderPrefix) {
                        $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                        $file->file_url = asset('storage/' . $cleanPath);
                    });
                });
            });

            // ✅ Attach URLs for root files (those not inside any folder)
            $rootFiles->each(function ($file) {
                $user = $file->user;
                $userFolderPrefix = $user
                    ? 'user_' . str_replace(' ', '_', $user->first_name . '_' . $user->last_name) . '/'
                    : '';

                $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                $file->file_url = asset('storage/' . $cleanPath);
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'All repositories loaded successfully.',
                'data' => [
                    'root_files' => $rootFiles,
                    'folders' => $folders,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching all repositories: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to load repositories.',
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
