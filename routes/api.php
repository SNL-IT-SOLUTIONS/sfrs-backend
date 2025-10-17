<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BaseController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileRepositoryController;
use App\Http\Controllers\PrincipalController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

//Auth
Route::controller(AuthController::class)->group(function () {
    Route::post('login', 'login');
});

Route::controller(AuthController::class)->middleware(['auth:sanctum'])->group(function () {
    Route::post('logout', 'logout');
});


//User Management
Route::controller(UserController::class)->group(function () {
    Route::post('create/users', 'createUser');
    Route::get('users', 'getUsers');
    Route::get('users/{id}', 'getUserById');
    Route::post('updat/users/{id}', 'updateUser');
    Route::post('users/{id}/archive', 'archiveUser');
});
//TEST

//File Repository
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('repository/{userId}', [FileRepositoryController::class, 'getRepository']);
    Route::get('my-repository', [FileRepositoryController::class, 'getMyRepository']);
    Route::post('/create/folders', [FileRepositoryController::class, 'createFolder']);
    Route::post('/file/upload', [FileRepositoryController::class, 'uploadFile']);
    Route::post('update/folders/{folderId}', [FileRepositoryController::class, 'updateFolder']);
    Route::post('update/files/{fileId}', [FileRepositoryController::class, 'updateFile']);
    Route::delete('folders/{folderId}', [FileRepositoryController::class, 'deleteFolder']);
    Route::delete('files/{fileId}', [FileRepositoryController::class, 'deleteFile']);
});
Route::get('download/{fileId}', [FileRepositoryController::class, 'downloadFile']);
Route::get('preview/files/{fileId}', [FileRepositoryController::class, 'viewFile']);

//Principal
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('all-repositories', [PrincipalController::class, 'viewAllRepositories']);
    Route::get('pending-users', [PrincipalController::class, 'getPendingUsers']);
    Route::post('approve-user/{id}', [PrincipalController::class, 'approveUser']);
    Route::post('reject-user/{id}', [PrincipalController::class, 'rejectUser']);
    Route::get('history', [PrincipalController::class, 'getApprovalHistory']);
});
