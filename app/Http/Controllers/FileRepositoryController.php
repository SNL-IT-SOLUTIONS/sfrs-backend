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


    public function getMyRepository()
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Unauthorized access.',
                ], 401);
            }

            $folders = Folder::where('user_id', $user->id)
                ->whereNull('parent_id')
                ->with([
                    'children.files',          // files of child folders
                    'children.children.files', // deeper level if needed
                    'files'                    // files of the root folder
                ])
                ->get();


            return response()->json([
                'isSuccess' => true,
                'data' => $folders,
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

            // Generate a safe folder name
            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $validated['folder_name']);
            $path = 'user_' . $user->full_name . '/' . $safeName;


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

            // Determine target directory
            $folder = null;
            $folderPath = 'user_' . $user->full_name; // Default user root

            if (!empty($validated['folder_id'])) {
                $folder = Folder::where('id', $validated['folder_id'])
                    ->where('user_id', $user->id)
                    ->first();

                if ($folder) {
                    // Use folder's path from DB if exists
                    $folderPath = $folder->path ?? $folderPath;
                }
            }

            // Ensure the folder exists in storage
            Storage::disk('public')->makeDirectory($folderPath);

            // Store the file inside the correct folder
            $storedFilePath = $file->store($folderPath, 'public');

            // Save file info in DB
            $newFile = File::create([
                'user_id' => $user->id,
                'folder_id' => $validated['folder_id'] ?? null,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $storedFilePath,
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'is_archived' => false,
            ]);

            // Add public asset URL for easy frontend access
            $newFile->file_url = asset('storage/' . $storedFilePath);

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
