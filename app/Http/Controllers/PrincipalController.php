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
    public function viewAllRepositories(Request $request)
    {
        try {
            $search = $request->query('search');
            $perPage = $request->query('per_page', 10);
            $cursor = $request->query('cursor');

            // Folders query
            $foldersQuery = Folder::with(['children.files', 'files', 'user'])
                ->whereNull('parent_id');

            // Files query (root files only)
            $filesQuery = File::with('user')->whereNull('folder_id');

            if ($search) {
                // Search folders by name, user full_name, or role
                $foldersQuery->where(function ($q) use ($search) {
                    $q->where('folder_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn($u) => $u->where('full_name', 'like', "%{$search}%")
                            ->orWhere('role', 'like', "%{$search}%"));
                });

                // Search root files by name, user full_name, or role
                $filesQuery->where(function ($q) use ($search) {
                    $q->where('file_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn($u) => $u->where('full_name', 'like', "%{$search}%")
                            ->orWhere('role', 'like', "%{$search}%"));
                });
            }

            // Cursor pagination
            $folders = $foldersQuery->orderBy('id')->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
            $files = $filesQuery->orderBy('id')->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

            // Attach URLs
            $folders->getCollection()->each(function ($folder) {
                $folder->folder_url = asset($folder->path ?? '');
                $folder->files->each(fn($f) => $f->file_url = asset($f->file_path));
                $folder->children->each(function ($child) {
                    $child->folder_url = asset($child->path ?? '');
                    $child->files->each(fn($f) => $f->file_url = asset($f->file_path));
                });
            });

            $files->getCollection()->each(fn($f) => $f->file_url = asset($f->file_path));

            return response()->json([
                'isSuccess' => true,
                'message' => 'All repositories loaded successfully.',
                'data' => [
                    'folders' => $folders,
                    'files' => $files,
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
