<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Folder;
use Illuminate\Support\Facades\DB;


class PrincipalController extends Controller
{
    /**
     * View all uploaded files in the repository (across all users)
     */
    public function viewAllRepositories()
    {
        try {
            // Fetch all folders from all users (root level)
            $folders = Folder::whereNull('parent_id')
                ->with([
                    'children' => function ($query) {
                        $query->with([
                            'files',
                            'children' => function ($subQuery) {
                                $subQuery->with('files'); // recursion level 2
                            },
                            'user'
                        ]);
                    },
                    'files',
                    'user' // include user info
                ])
                ->get();

            // Fetch all root files (not inside any folder)
            $rootFiles = File::whereNull('folder_id')
                ->with('user')
                ->get();

            // Attach URLs and user full names
            $folders->each(function ($folder) {
                $user = $folder->user;
                $folder->user_full_name = $user ? $user->full_name : 'Unknown User';

                $userFolderPrefix = $user
                    ? 'user_' . str_replace(' ', '_', $user->first_name . '_' . $user->last_name) . '/'
                    : '';

                $cleanFolderPath = str_replace($userFolderPrefix, '', $folder->path ?? '');
                $folder->folder_url = asset('storage/' . $cleanFolderPath);

                // Folder files
                $folder->files->each(function ($file) use ($userFolderPrefix, $user) {
                    $file->user_full_name = $user ? $user->full_name : 'Unknown User';
                    $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                    $file->file_url = asset('storage/' . $cleanPath);
                });

                // Child folders
                $folder->children->each(function ($child) use ($userFolderPrefix) {
                    $childUser = $child->user;
                    $child->user_full_name = $childUser ? $childUser->full_name : 'Unknown User';

                    $cleanChildPath = str_replace($userFolderPrefix, '', $child->path ?? '');
                    $child->folder_url = asset('storage/' . $cleanChildPath);

                    $child->files->each(function ($file) use ($userFolderPrefix, $childUser) {
                        $file->user_full_name = $childUser ? $childUser->full_name : 'Unknown User';
                        $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                        $file->file_url = asset('storage/' . $cleanPath);
                    });
                });
            });

            // Attach URLs and user names for root files
            $rootFiles->each(function ($file) {
                $user = $file->user;
                $file->user_full_name = $user ? $user->full_name : 'Unknown User';

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
                    'folders' => $folders,
                    'files' => $rootFiles,
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
            $admin = auth()->user(); // The one performing the action

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $user->is_approved = true;
            $user->save();

            // Log the approval
            DB::table('user_approvals')->insert([
                'user_id' => $user->id,
                'action' => 'approved',
                'performed_by' => $admin->id,
                'created_at' => now()
            ]);

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

    public function rejectUser($id)
    {
        try {
            $user = User::find($id);
            $admin = auth()->user(); // The one performing the action

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $user->is_approved = false;
            $user->save();

            // Log the rejection
            DB::table('user_approvals')->insert([
                'user_id' => $user->id,
                'action' => 'rejected',
                'performed_by' => $admin->id,
                'created_at' => now()
            ]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'User rejected successfully.',
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Error rejecting user: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to reject user.',
            ], 500);
        }
    }


    public function getApprovalHistory()
    {
        try {
            $history = DB::table('user_approvals')
                ->join('users as u', 'user_approvals.user_id', '=', 'u.id')
                ->join('users as admin', 'user_approvals.performed_by', '=', 'admin.id')
                ->select(
                    'user_approvals.id',
                    'u.full_name as user_name',
                    'action',
                    'admin.full_name as performed_by',
                    'user_approvals.created_at'
                )
                ->orderBy('user_approvals.created_at', 'desc')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'message' => 'Approval history retrieved successfully.',
                'data' => $history,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching approval history: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch approval history.',
            ], 500);
        }
    }
}
