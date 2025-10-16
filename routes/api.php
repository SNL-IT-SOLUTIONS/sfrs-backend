<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BaseController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileRepositoryController;

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


//File Repository
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('repository/{userId}', [FileRepositoryController::class, 'getRepository']);
    Route::get('my-repository', [FileRepositoryController::class, 'getMyRepository']);
    Route::post('/create/folders', [FileRepositoryController::class, 'createFolder']);
    Route::post('/file/upload', [FileRepositoryController::class, 'uploadFile']);
    Route::get('download/{fileId}', [FileRepositoryController::class, 'downloadFile']);
    Route::delete('files/{fileId}', [FileRepositoryController::class, 'deleteFile']);
});
