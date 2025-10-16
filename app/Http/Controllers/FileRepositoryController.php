<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\DB;


class FileRepositoryController extends Controller
{
    /**
     * Get all folders and files for a user
     */
    public function getRepository($userId)
    {
        try {
            $folders = Folder::where('user_id', $userId)
                ->whereNull('parent_id')
                ->with('children', 'files')
                ->get();

            return response()->json([
                'isSuccess' => true,
                'data' => $folders,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching repository: ' . $e->getMessage());
            return response()->json(['isSuccess' => false, 'message' => 'Failed to load repository.'], 500);
        }
    }

    public function getMyRepository(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Unauthorized access.',
                ], 401);
            }

            // Fetch all folders that belong to the user (root level)
            $folders = Folder::where('user_id', $user->id)
                ->whereNull('parent_id')
                ->with([
                    'children' => function ($query) {
                        $query->with([
                            'files',
                            'children' => function ($subQuery) {
                                $subQuery->with('files'); // recursion level 2
                            },
                        ]);
                    },
                    'files'
                ])
                ->get();

            // ✅ Fetch root files (those not inside any folder)
            $rootFiles = File::where('user_id', $user->id)
                ->whereNull('folder_id')
                ->get();

            $userFolderPrefix = 'user_' . str_replace(' ', '_', $user->first_name . '_' . $user->last_name) . '/';

            // Attach folder URLs and file URLs
            $folders->each(function ($folder) use ($userFolderPrefix) {
                // Base folder URL (without user_ prefix)
                $cleanFolderPath = str_replace($userFolderPrefix, '', $folder->path ?? '');
                $folder->folder_url = asset('storage/' . $cleanFolderPath);

                // Add URLs for files inside the folder
                $folder->files->each(function ($file) use ($userFolderPrefix) {
                    $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                    $file->file_url = asset('storage/' . $cleanPath);
                });

                // For subfolders
                $folder->children->each(function ($child) use ($userFolderPrefix) {
                    $cleanChildPath = str_replace($userFolderPrefix, '', $child->path ?? '');
                    $child->folder_url = asset('storage/' . $cleanChildPath);

                    $child->files->each(function ($file) use ($userFolderPrefix) {
                        $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                        $file->file_url = asset('storage/' . $cleanPath);
                    });
                });
            });

            // ✅ Add public URLs for root files
            $rootFiles->each(function ($file) use ($userFolderPrefix) {
                $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                $file->file_url = asset('storage/' . $cleanPath);
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Repository loaded successfully.',
                'data' => [
                    'root_files' => $rootFiles,
                    'folders' => $folders,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user repository: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to load repository.',
            ], 500);
        }
    }




    /**
     *  Create a new folder
     */
    public function createFolder(Request $request)
    {
        try {
            $user = auth()->user();

            $validated = $request->validate([
                'folder_name' => 'required|string|max:255',
                'parent_id' => 'nullable|exists:folders,id',
            ]);

            // Create folder in database
            $folder = Folder::create([
                'user_id' => $user->id,
                'folder_name' => $validated['folder_name'],
                'parent_id' => $validated['parent_id'] ?? null,
                'is_archived' => false,
            ]);

            // Make user name and folder name URL-safe
            $safeUserName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user->full_name);
            $safeFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $validated['folder_name']);
            $path = 'user_' . $safeUserName . '/' . $safeFolderName;



            Storage::disk('public')->makeDirectory($path);

            $folder->update(['path' => $path]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Folder created successfully.',
                'data' => $folder,
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating folder: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to create folder.',
            ], 500);
        }
    }



    /**
     * ⬆ Upload a file
     */
    public function uploadFile(Request $request)
    {
        try {
            $user = auth()->user();

            $validated = $request->validate([
                'folder_id' => 'nullable|exists:folders,id',
                'file' => 'required|file|max:10240', // 10MB limit
            ]);

            $file = $request->file('file');

            // Always sanitize user name
            $safeUserName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user->full_name);
            $folderPath = 'user_' . $safeUserName; // default: root directory

            // ✅ If uploading into a subfolder
            if (!empty($validated['folder_id'])) {
                $folder = Folder::where('id', $validated['folder_id'])
                    ->where('user_id', $user->id)
                    ->first();

                if ($folder) {
                    // Use folder path directly from DB (already user-based)
                    $folderPath = $folder->path;
                }
            }

            // Ensure directory exists
            Storage::disk('public')->makeDirectory($folderPath);

            // Store file
            $filePath = $file->store($folderPath, 'public');

            // Save file in DB
            $newFile = File::create([
                'user_id' => $user->id,
                'folder_id' => $validated['folder_id'] ?? null,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'is_archived' => false,
            ]);

            // Public file URL (frontend ready)
            $newFile->file_url = asset('storage/' . $filePath);

            return response()->json([
                'isSuccess' => true,
                'message' => 'File uploaded successfully.',
                'data' => $newFile,
            ]);
        } catch (Exception $e) {
            Log::error('Error uploading file: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'File upload failed.',
            ], 500);
        }
    }


    /**
     * 🗑️ Delete a file or folder
     */
    public function deleteItem(Request $request)
    {
        try {
            $validated = $request->validate([
                'type' => 'required|in:file,folder',
                'id' => 'required|integer',
            ]);

            if ($validated['type'] === 'file') {
                $file = File::find($validated['id']);
                if ($file) {
                    $file->update(['is_archived' => true]);
                }
            } else {
                $folder = Folder::find($validated['id']);
                if ($folder) {
                    $folder->update(['is_archived' => true]);
                }
            }

            return response()->json([
                'isSuccess' => true,
                'message' => ucfirst($validated['type']) . ' archived successfully.',
            ]);
        } catch (Exception $e) {
            Log::error('Error archiving item: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to archive item.',
            ], 500);
        }
    }


    /**
     * ⬇ Download a file
     */
    public function downloadFile($fileId)
    {
        try {
            $user = auth()->user();

            $file = File::where('id', $fileId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $filePath = storage_path('app/public/' . $file->file_path);

            if (!file_exists($filePath)) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'File not found.',
                ], 404);
            }

            return response()->download($filePath, $file->file_name);
        } catch (Exception $e) {
            Log::error('Error downloading file: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to download file.',
            ], 500);
        }
    }
}
