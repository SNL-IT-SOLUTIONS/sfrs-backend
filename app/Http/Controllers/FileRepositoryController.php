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
     * 🗑 Delete a file
     */
    public function deleteFile($fileId)
    {
        try {
            $user = auth()->user();

            $file = File::where('id', $fileId)
                ->where('user_id', $user->id)
                ->first();

            if (!$file) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'File not found or you do not have permission.',
                ], 404);
            }

            $filePath = public_path($file->file_path);

            // Delete the file from public folder
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete the file record from DB
            $file->delete();

            return response()->json([
                'isSuccess' => true,
                'message' => 'File deleted successfully.',
            ]);
        } catch (Exception $e) {
            Log::error('Error deleting file: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to delete file.',
            ], 500);
        }
    }
    /**
     * 🗑 Delete a folder (and all its children + files)
     */
    public function deleteFolder($folderId)
    {
        try {
            $user = auth()->user();

            $folder = Folder::where('id', $folderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$folder) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Folder not found or you do not have permission.',
                ], 404);
            }

            // Recursive function to delete folder contents
            $deleteFolderRecursively = function ($folder) use (&$deleteFolderRecursively) {
                // Delete child folders
                foreach ($folder->children as $child) {
                    $deleteFolderRecursively($child);
                }

                // Delete files in this folder
                foreach ($folder->files as $file) {
                    $filePath = public_path($file->file_path);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    $file->delete();
                }

                // Delete folder from public folder
                if ($folder->path && file_exists(public_path($folder->path))) {
                    rmdir($folder->path);
                }

                // Delete folder from DB
                $folder->delete();
            };

            // Load children and files before deleting
            $folder->load(['children', 'files']);
            $deleteFolderRecursively($folder);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Folder deleted successfully.',
            ]);
        } catch (Exception $e) {
            Log::error('Error deleting folder: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to delete folder.',
            ], 500);
        }
    }

    /**
     * ✏️ Update file name
     */
    public function updateFile(Request $request, $fileId)
    {
        try {
            $user = auth()->user();

            $file = File::where('id', $fileId)
                ->where('user_id', $user->id)
                ->first();

            if (!$file) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'File not found or you do not have permission.',
                ], 404);
            }

            $validated = $request->validate([
                'file_name' => 'required|string|max:255',
            ]);

            $oldFilePath = storage_path('app/public/' . $file->file_path);
            $directory = dirname($oldFilePath);
            $extension = pathinfo($oldFilePath, PATHINFO_EXTENSION);

            // Generate safe new file name
            $safeFileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $validated['file_name']);
            if ($extension) {
                $safeFileName .= '.' . $extension;
            }

            $newFilePath = $directory . '/' . $safeFileName;

            // Rename the file
            if (file_exists($oldFilePath)) {
                rename($oldFilePath, $newFilePath);
            }

            // Save relative path (from storage/app/public)
            $relativePath = str_replace(storage_path('app/public/') . '', '', $newFilePath);

            $file->update([
                'file_name' => $validated['file_name'],
                'file_path' => $relativePath,
            ]);

            // Add file URL for frontend
            $file->file_url = asset('storage/' . $file->file_path);

            return response()->json([
                'isSuccess' => true,
                'message' => 'File updated successfully.',
                'data' => $file,
            ]);
        } catch (Exception $e) {
            Log::error('Error updating file: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to update file.',
            ], 500);
        }
    }

    /**
     * ✏️ Update an existing folder
     */
    public function updateFolder(Request $request, $folderId)
    {
        try {
            $user = auth()->user();

            $folder = Folder::where('id', $folderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$folder) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Folder not found or you do not have permission.',
                ], 404);
            }

            $validated = $request->validate([
                'folder_name' => 'required|string|max:255',
                'parent_id' => 'nullable|exists:folders,id',
            ]);

            // Determine prefix path
            $safeUserName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user->full_name);
            $userFolderPrefix = 'user_' . $safeUserName;

            if (!empty($validated['parent_id'])) {
                $parentFolder = Folder::find($validated['parent_id']);
                if ($parentFolder) {
                    $userFolderPrefix = $parentFolder->path; // relative path
                }
            }

            $safeFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $validated['folder_name']);
            $newFolderPath = $userFolderPrefix . '/' . $safeFolderName;

            $oldFullPath = $folder->path
                ? storage_path('app/public/' . $folder->path)
                : storage_path('app/public/user_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user->full_name) . '/' . $safeFolderName);

            $newFullPath = storage_path('app/public/' . $newFolderPath);

            // Only rename if the old folder exists
            if ($folder->path && $folder->path !== $newFolderPath && file_exists($oldFullPath)) {
                if (!rename($oldFullPath, $newFullPath)) {
                    throw new \Exception("Failed to rename folder. Check folder permissions.");
                }
            } else {
                // Ensure folder exists
                if (!file_exists($newFullPath)) {
                    mkdir($newFullPath, 0755, true);
                }
            }


            // Save relative path
            $folder->update([
                'folder_name' => $validated['folder_name'],
                'parent_id' => $validated['parent_id'] ?? null,
                'path' => $newFolderPath,
            ]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Folder updated successfully.',
                'data' => $folder,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating folder: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to update folder. ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * 👀 View file in browser (inline)
     */
    public function viewFile($fileId)
    {
        try {
            $user = auth()->user();

            $file = File::where('id', $fileId)
                ->where('user_id', $user->id)
                ->first();

            if (!$file) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'File not found or you do not have permission.',
                ], 404);
            }

            $filePath = storage_path('app/public/' . $file->file_path);

            if (!file_exists($filePath)) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'File not found.',
                ], 404);
            }

            // Return the file inline (not download)
            return response()->file($filePath, [
                'Content-Type' => $file->file_type,
                'Content-Disposition' => 'inline; filename="' . $file->file_name . '"'
            ]);
        } catch (Exception $e) {
            Log::error('Error viewing file: ' . $e->getMessage());

            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to view file.',
            ], 500);
        }
    }






    /**
     * ⬇ Download a file
     */
    public function downloadFile($fileId)
    {
        try {
            $file = File::findOrFail($fileId);

            // Use Storage path correctly
            $filePath = storage_path('app/public/' . $file->file_path);

            if (!file_exists($filePath)) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'File not found.',
                ], 404);
            }

            // Serve file
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
