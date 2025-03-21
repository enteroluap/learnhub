<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\CourseController;
use App\Http\Controllers\API\ModuleController;
use App\Http\Controllers\API\LessonController;
use App\Http\Controllers\API\MaterialController;
use App\Http\Controllers\API\EnrollmentController;
use App\Http\Controllers\API\ProgressController;
use App\Http\Controllers\API\QuestionController;
use App\Http\Controllers\API\RatingController;
use App\Http\Controllers\API\CertificateController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\AiAssistantController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Rotas públicas
Route::post('login', [AuthController::class, 'login']);
Route::post('register', [AuthController::class, 'register']);

// Rotas para cursos (listagem pública)
Route::get('courses', [CourseController::class, 'index']);
Route::get('courses/{id}', [CourseController::class, 'show']);
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/{id}/courses', [CategoryController::class, 'courses']);

// Rotas protegidas por autenticação
Route::middleware('auth:api')->group(function () {
    // Rotas de autenticação
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::get('me', [AuthController::class, 'me']);

    // Usuários
    Route::get('users/profile', [UserController::class, 'profile']);
    Route::put('users/profile', [UserController::class, 'updateProfile']);
    Route::put('users/password', [UserController::class, 'updatePassword']);

    // Inscrições em cursos
    Route::get('enrollments', [EnrollmentController::class, 'index']);
    Route::post('courses/{id}/enroll', [EnrollmentController::class, 'enroll']);
    Route::get('enrollments/{id}', [EnrollmentController::class, 'show']);

    // Progresso do aluno
    Route::get('courses/{id}/progress', [ProgressController::class, 'courseProgress']);
    Route::post('lessons/{id}/progress', [ProgressController::class, 'updateLessonProgress']);
    Route::post('lessons/{id}/complete', [ProgressController::class, 'completeLesson']);

    // Perguntas e respostas
    Route::get('lessons/{id}/questions', [QuestionController::class, 'lessonQuestions']);
    Route::post('lessons/{id}/questions', [QuestionController::class, 'storeQuestion']);
    Route::get('questions/{id}', [QuestionController::class, 'show']);
    Route::put('questions/{id}', [QuestionController::class, 'update']);

    // Avaliações
    Route::post('courses/{id}/ratings', [RatingController::class, 'store']);
    Route::get('courses/{id}/ratings', [RatingController::class, 'courseRatings']);

    // Rotas para certificados
    Route::get('certificates', [CertificateController::class, 'index']);
    Route::get('certificates/{id}', [CertificateController::class, 'show']);
    Route::get('certificates/{id}/download', [CertificateController::class, 'download'])->name('certificates.download');
    Route::post('certificates/{id}/regenerate', [CertificateController::class, 'regenerate']);
    Route::post('courses/{id}/certificate', [CertificateController::class, 'generate']);

    // Rota pública para verificação de certificados (não requer autenticação)
    Route::post('certificates/verify', [CertificateController::class, 'verify'])->withoutMiddleware('auth:api');

    // Rotas para administradores (dentro do middleware para administradores)
    Route::middleware('can:manage-courses')->group(function () {
        Route::get('admin/certificates', [CertificateController::class, 'adminList']);
    });

    // Pagamentos
    Route::post('payments/process', [PaymentController::class, 'process']);
    Route::get('payments/history', [PaymentController::class, 'history']);

    // Assistente IA
    Route::post('ai/ask', [AiAssistantController::class, 'ask']);

    // Rotas para administradores e instrutores
    Route::middleware('can:manage-courses')->group(function () {
        // Dashboard
        Route::get('dashboard/stats', [DashboardController::class, 'stats']);
        Route::get('dashboard/recent-activity', [DashboardController::class, 'recentActivity']);

        // Gerenciamento de cursos
        Route::post('courses', [CourseController::class, 'store']);
        Route::put('courses/{id}', [CourseController::class, 'update']);
        Route::delete('courses/{id}', [CourseController::class, 'destroy']);

        // Gerenciamento de módulos
        Route::get('courses/{id}/modules', [ModuleController::class, 'index']);
        Route::post('modules', [ModuleController::class, 'store']);
        Route::put('modules/{id}', [ModuleController::class, 'update']);
        Route::delete('modules/{id}', [ModuleController::class, 'destroy']);

        // Gerenciamento de aulas
        Route::get('modules/{id}/lessons', [LessonController::class, 'index']);
        Route::post('lessons', [LessonController::class, 'store']);
        Route::put('lessons/{id}', [LessonController::class, 'update']);
        Route::delete('lessons/{id}', [LessonController::class, 'destroy']);

        // Materiais de aula
        Route::get('lessons/{id}/materials', [MaterialController::class, 'index']);
        Route::post('materials', [MaterialController::class, 'store']);
        Route::put('materials/{id}', [MaterialController::class, 'update']);
        Route::delete('materials/{id}', [MaterialController::class, 'destroy']);

        // Gerenciamento de categorias (somente admin)
        Route::middleware('can:manage-categories')->group(function () {
            Route::post('categories', [CategoryController::class, 'store']);
            Route::put('categories/{id}', [CategoryController::class, 'update']);
            Route::delete('categories/{id}', [CategoryController::class, 'destroy']);
        });

        // Gerenciamento de usuários (somente admin)
        Route::middleware('can:manage-users')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{id}', [UserController::class, 'show']);
            Route::put('users/{id}', [UserController::class, 'update']);
            Route::delete('users/{id}', [UserController::class, 'destroy']);
            Route::post('users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
        });
    });
});
