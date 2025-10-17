<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Folder;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PrincipalController extends Controller
{
    /**
     * View all uploaded files in the repository (across all users)
     */
    public function viewAllRepositories(Request $request)
    {
        try {
            $search = $request->query('search', '');

            // Get all root folders with their relationships
            $folders = Folder::with(['user', 'files.user', 'children.files.user'])
                ->whereNull('parent_id')
                ->get();

            // Get all root files (files without folders)
            $files = File::with(['user', 'folder'])
                ->whereNull('folder_id')
                ->get();

            // Apply search filter if provided
            if (!empty($search)) {
                $folders = $folders->filter(function ($folder) use ($search) {
                    return stripos($folder->folder_name, $search) !== false ||
                        ($folder->user && stripos($folder->user->full_name, $search) !== false);
                });

                $files = $files->filter(function ($file) use ($search) {
                    return stripos($file->file_name, $search) !== false ||
                        ($file->user && stripos($file->user->full_name, $search) !== false);
                });
            }

            // Format folders data
            $formattedFolders = $folders->map(function ($folder) {
                return [
                    'id' => $folder->id,
                    'user_id' => $folder->user_id,
                    'folder_name' => $folder->folder_name,
                    'parent_id' => $folder->parent_id,
                    'is_archived' => $folder->is_archived,
                    'created_at' => $folder->created_at,
                    'updated_at' => $folder->updated_at,
                    'folder_url' => $folder->path ? asset($folder->path) : null,
                    'user' => $folder->user ? [
                        'id' => $folder->user->id,
                        'full_name' => $folder->user->full_name,
                        'email' => $folder->user->email,
                        'role' => $folder->user->role,
                        'is_active' => $folder->user->is_active,
                        'is_archived' => $folder->user->is_archived,
                        'is_approved' => $folder->user->is_approved,
                        'created_at' => $folder->user->created_at,
                        'updated_at' => $folder->user->updated_at,
                    ] : null,
                    'children' => $folder->children->map(function ($child) {
                        return [
                            'id' => $child->id,
                            'user_id' => $child->user_id,
                            'folder_name' => $child->folder_name,
                            'parent_id' => $child->parent_id,
                            'is_archived' => $child->is_archived,
                            'created_at' => $child->created_at,
                            'updated_at' => $child->updated_at,
                            'folder_url' => $child->path ? asset($child->path) : null,
                            'files' => $child->files->map(function ($file) {
                                return $this->formatFile($file);
                            }),
                            'user' => $child->user ? [
                                'id' => $child->user->id,
                                'full_name' => $child->user->full_name,
                                'email' => $child->user->email,
                            ] : null,
                        ];
                    }),
                    'files' => $folder->files->map(function ($file) {
                        return $this->formatFile($file);
                    }),
                ];
            });

            // Format files data
            $formattedFiles = $files->map(function ($file) {
                return $this->formatFile($file);
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'All repositories loaded successfully.',
                'data' => [
                    'folders' => $formattedFolders,
                    'files' => $formattedFiles,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching all repositories: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to load repositories.',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Format file data for consistent response
     */
    private function formatFile($file)
    {
        return [
            'id' => $file->id,
            'user_id' => $file->user_id,
            'folder_id' => $file->folder_id,
            'file_name' => $file->file_name,
            'file_path' => $file->file_path,
            'file_type' => $file->file_type,
            'file_size' => $file->file_size,
            'is_archived' => $file->is_archived,
            'created_at' => $file->created_at,
            'updated_at' => $file->updated_at,
            'file_url' => $file->file_path ? asset($file->file_path) : null,
            'user' => $file->user ? [
                'id' => $file->user->id,
                'full_name' => $file->user->full_name,
                'email' => $file->user->email,
                'role' => $file->user->role,
                'is_active' => $file->user->is_active,
                'is_archived' => $file->user->is_archived,
                'is_approved' => $file->user->is_approved,
            ] : null,
        ];
    }

    /**
     * View all users pending approval
     */
    public function getPendingUsers()
    {
        try {
            $pendingUsers = User::where('is_approved', false)
                ->select('id', 'full_name', 'email', 'role', 'created_at')
                ->get();

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
            $admin = auth()->user();

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $user->is_approved = true;
            $user->approved_at = now();
            $user->save();

            // Log the approval
            DB::table('user_approvals')->insert([
                'user_id' => $user->id,
                'action' => 'approved',
                'performed_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
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

    /**
     * Reject a user registration
     */
    public function rejectUser($id)
    {
        try {
            $user = User::find($id);
            $admin = auth()->user();

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            // Log the rejection before potentially deleting the user
            DB::table('user_approvals')->insert([
                'user_id' => $user->id,
                'action' => 'rejected',
                'performed_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Optionally delete the user or just mark as rejected
            $user->delete();

            return response()->json([
                'isSuccess' => true,
                'message' => 'User rejected successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error rejecting user: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to reject user.',
            ], 500);
        }
    }

    /**
     * Get approval history
     */
    public function getApprovalHistory()
    {
        try {
            $history = DB::table('user_approvals')
                ->join('users as u', 'user_approvals.user_id', '=', 'u.id')
                ->join('users as admin', 'user_approvals.performed_by', '=', 'admin.id')
                ->select(
                    'user_approvals.id',
                    'u.full_name as user_name',
                    'u.email as user_email',
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
