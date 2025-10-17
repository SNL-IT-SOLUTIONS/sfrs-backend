<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class FileRepositoryController extends Controller
{
    /**
     * 🗂 Get user repository (folders + files)
     */
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

            // Fetch root folders with children and files
            $folders = Folder::where('user_id', $user->id)
                ->whereNull('parent_id')
                ->with([
                    'children' => function ($query) {
                        $query->with(['files', 'children.files']);
                    },
                    'files'
                ])
                ->get();

            // Fetch root files (not in any folder)
            $rootFiles = File::where('user_id', $user->id)
                ->whereNull('folder_id')
                ->get();

            $userFolderPrefix = 'user_' . str_replace(' ', '_', $user->full_name) . '/';

            // Add URLs for folders and files recursively
            $folders->each(function ($folder) use ($userFolderPrefix) {
                $folder->folder_url = asset($folder->path ?? '');
                $folder->files->each(fn($file) => $file->file_url = asset($file->file_path));
                $folder->children->each(function ($child) {
                    $child->folder_url = asset($child->path ?? '');
                    $child->files->each(fn($file) => $file->file_url = asset($file->file_path));
                });
            });

            // Add URLs for root files
            $rootFiles->each(fn($file) => $file->file_url = asset($file->file_path));

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
     * 🗂 Create a new folder in public directory
     */
    public function createFolder(Request $request)
    {
        try {
            $user = auth()->user();

            $validated = $request->validate([
                'folder_name' => 'required|string|max:255',
                'parent_id' => 'nullable|exists:folders,id',
            ]);

            // Create DB record first
            $folder = Folder::create([
                'user_id' => $user->id,
                'folder_name' => $validated['folder_name'],
                'parent_id' => $validated['parent_id'] ?? null,
                'is_archived' => false,
            ]);

            // Build folder path dynamically
            $userFolderPrefix = 'user_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user->full_name);

            if (!empty($validated['parent_id'])) {
                $parentFolder = Folder::find($validated['parent_id']);
                if ($parentFolder) {
                    $userFolderPrefix = $parentFolder->path;
                }
            }

            $safeFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $validated['folder_name']);
            $folderPath = $userFolderPrefix . '/' . $safeFolderName;

            // Create folder in public
            $this->makePublicFolder($folderPath);

            $folder->update(['path' => $folderPath]);

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

            // Build new folder path
            $userFolderPrefix = 'user_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user->full_name);

            if (!empty($validated['parent_id'])) {
                $parentFolder = Folder::find($validated['parent_id']);
                if ($parentFolder) {
                    $userFolderPrefix = $parentFolder->path;
                }
            }

            $safeFolderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $validated['folder_name']);
            $newFolderPath = $userFolderPrefix . '/' . $safeFolderName;

            // Rename folder in public if path changed
            $oldPath = public_path($folder->path);
            $newPath = public_path($newFolderPath);

            if ($folder->path !== $newFolderPath && file_exists($oldPath)) {
                rename($oldPath, $newPath);
            } else {
                // Ensure folder exists if it didn't previously
                if (!file_exists($newPath)) {
                    mkdir($newPath, 0755, true);
                }
            }

            // Update DB record
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
        } catch (Exception $e) {
            Log::error('Error updating folder: ' . $e->getMessage());
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to update folder.',
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

            $oldFilePath = public_path($file->file_path);
            $directory = dirname($oldFilePath);
            $extension = pathinfo($oldFilePath, PATHINFO_EXTENSION);

            // Generate new safe file name
            $safeFileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $validated['file_name']);
            if ($extension) {
                $safeFileName .= '.' . $extension;
            }

            $newFilePath = $directory . '/' . $safeFileName;

            // Rename the file in the public folder
            if (file_exists($oldFilePath)) {
                rename($oldFilePath, $newFilePath);
            }

            // Update DB record
            $file->update([
                'file_name' => $validated['file_name'],
                'file_path' => str_replace(public_path() . '/', '', $newFilePath),
            ]);

            // Add file URL for frontend
            $file->file_url = asset($file->file_path);

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
     * ⬆ Upload a file to public folder
     */
    public function uploadFile(Request $request)
    {
        try {
            $user = auth()->user();

            $validated = $request->validate([
                'folder_id' => 'nullable|exists:folders,id',
                'file' => 'required|file|max:10240', // 10MB
            ]);

            $folderPath = 'user_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user->full_name);

            if (!empty($validated['folder_id'])) {
                $folder = Folder::where('id', $validated['folder_id'])
                    ->where('user_id', $user->id)
                    ->first();
                if ($folder) {
                    $folderPath = $folder->path;
                }
            }

            // Save file using helper
            $filePath = $this->saveFileToPublic($request, 'file', $folderPath);

            if (!$filePath) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'No file uploaded.',
                ], 400);
            }

            $file = $request->file('file');

            // Save DB record
            $newFile = File::create([
                'user_id' => $user->id,
                'folder_id' => $validated['folder_id'] ?? null,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'is_archived' => false,
            ]);

            $newFile->file_url = asset($filePath);

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
     * ⬇ Download a file
     */
    public function downloadFile($fileId)
    {
        try {
            $file = File::findOrFail($fileId);
            $filePath = public_path($file->file_path);

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
     * Helper: save uploaded file to public dynamically
     */
    private function saveFileToPublic(Request $request, $field, $folderPath)
    {
        if ($request->hasFile($field)) {
            $file = $request->file($field);

            $directory = public_path($folderPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $file->getClientOriginalName());
            $file->move($directory, $filename);

            return $folderPath . '/' . $filename;
        }

        return null;
    }

    /**
     * Helper: create a folder in public dynamically
     */
    private function makePublicFolder($folderPath)
    {
        $directory = public_path($folderPath);
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
