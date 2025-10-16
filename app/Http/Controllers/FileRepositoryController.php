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

            // Attach URLs for folders and files
            $folders->each(function ($folder) use ($userFolderPrefix) {
                $cleanFolderPath = str_replace($userFolderPrefix, '', $folder->path ?? '');
                $folder->folder_url = asset('storage/' . $cleanFolderPath);

                // Folder files
                $folder->files->each(function ($file) use ($userFolderPrefix) {
                    $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                    $file->file_url = asset('storage/' . $cleanPath);
                });

                // Child folders
                $folder->children->each(function ($child) use ($userFolderPrefix) {
                    $cleanChildPath = str_replace($userFolderPrefix, '', $child->path ?? '');
                    $child->folder_url = asset('storage/' . $cleanChildPath);

                    $child->files->each(function ($file) use ($userFolderPrefix) {
                        $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                        $file->file_url = asset('storage/' . $cleanPath);
                    });
                });
            });

            // ✅ Add URLs for root files
            $rootFiles->each(function ($file) use ($userFolderPrefix) {
                $cleanPath = str_replace($userFolderPrefix, '', $file->file_path);
                $file->file_url = asset('storage/' . $cleanPath);
            });

            // ✅ Combine them cleanly (no "root" folder)
            return response()->json([
                'isSuccess' => true,
                'message' => 'Repository loaded successfully.',
                'data' => [
                    'folders' => $folders,
                    'files' => $rootFiles,
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
            $folderPath = 'user_' . $safeUserName; // default to user's root folder

            if (!empty($validated['folder_id'])) {
                $folder = Folder::where('id', $validated['folder_id'])
                    ->where('user_id', $user->id)
                    ->first();

                if ($folder) {
                    // Make sure we include the subfolder name
                    $safeFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $folder->folder_name);
                    $folderPath = ($folder->path ?? $folderPath) . '/' . $safeFolderName;
                }
            }

            // Ensure the folder exists in storage
            Storage::disk('public')->makeDirectory($folderPath);

            // Store the file inside the correct folder
            $filePath = $file->store($folderPath, 'public');

            // Save file info in DB
            $newFile = File::create([
                'user_id' => $user->id,
                'folder_id' => $validated['folder_id'] ?? null,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'is_archived' => false,
            ]);

            // Add public asset URL for frontend
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
    // public function deleteItem(Request $request)
    // {
    //     try {
    //         $validated = $request->validate([
    //             'type' => 'required|in:file,folder',
    //             'id' => 'required|integer',
    //         ]);

    //         if ($validated['type'] === 'file') {
    //             $file = File::find($validated['id']);
    //             if ($file) {
    //                 $file->update(['is_archived' => true]);
    //             }
    //         } else {
    //             $folder = Folder::find($validated['id']);
    //             if ($folder) {
    //                 $folder->update(['is_archived' => true]);
    //             }
    //         }

    //         return response()->json([
    //             'isSuccess' => true,
    //             'message' => ucfirst($validated['type']) . ' archived successfully.',
    //         ]);
    //     } catch (Exception $e) {
    //         Log::error('Error archiving item: ' . $e->getMessage());
    //         return response()->json([
    //             'isSuccess' => false,
    //             'message' => 'Failed to archive item.',
    //         ], 500);
    //     }
    // }


    /**
     * ⬇ Download a file
     */
    public function downloadFile($fileId)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Unauthorized access.'
                ], 401);
            }

            // Get the file record that belongs to the authenticated user
            $file = File::where('id', $fileId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Construct full path including subfolders
            $filePath = storage_path('app/public/' . $file->file_path);

            if (!file_exists($filePath)) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'File not found on server.'
                ], 404);
            }

            // Proper download response
            return response()->download($filePath, $file->original_name ?? basename($filePath));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'File not found.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while downloading the file.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
