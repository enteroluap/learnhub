<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\StudentProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ProgressController extends Controller
{
    /**
     * Criar uma nova instância de controller.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Obter o progresso do usuário em um curso específico.
     *
     * @param  int  $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function courseProgress($courseId)
    {
        $user = auth()->user();

        // Verificar se o usuário está inscrito no curso
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        if (!$enrollment) {
            return response()->json(['error' => 'Você não está inscrito neste curso'], 403);
        }

        // Obter o curso com seus módulos e aulas
        $course = Course::with(['modules' => function ($query) {
            $query->orderBy('order');
        }, 'modules.lessons' => function ($query) {
            $query->orderBy('order');
        }])->findOrFail($courseId);

        // Inicializar contadores
        $totalLessons = 0;
        $completedLessons = 0;

        // Estrutura para armazenar o progresso
        $progress = [
            'course_id' => $courseId,
            'course_title' => $course->title,
            'progress_percentage' => $enrollment->progress_percentage,
            'total_lessons' => 0,
            'completed_lessons' => 0,
            'modules' => []
        ];

        // Para cada módulo, verificar o progresso das aulas
        foreach ($course->modules as $module) {
            $moduleProgress = [
                'module_id' => $module->id,
                'module_title' => $module->title,
                'total_lessons' => count($module->lessons),
                'completed_lessons' => 0,
                'progress_percentage' => 0,
                'lessons' => []
            ];

            foreach ($module->lessons as $lesson) {
                // Verificar se a aula foi concluída
                $studentProgress = StudentProgress::where('user_id', $user->id)
                    ->where('lesson_id', $lesson->id)
                    ->first();

                $isCompleted = $studentProgress ? $studentProgress->is_completed : false;
                $watchedSeconds = $studentProgress ? $studentProgress->watched_seconds : 0;
                $lastWatchedAt = $studentProgress ? $studentProgress->last_watched_at : null;

                $totalLessons++;

                if ($isCompleted) {
                    $completedLessons++;
                    $moduleProgress['completed_lessons']++;
                }

                $moduleProgress['lessons'][] = [
                    'lesson_id' => $lesson->id,
                    'lesson_title' => $lesson->title,
                    'is_completed' => $isCompleted,
                    'watched_seconds' => $watchedSeconds,
                    'last_watched_at' => $lastWatchedAt ? $lastWatchedAt->format('Y-m-d H:i:s') : null,
                    'type' => $lesson->type,
                    'duration_in_minutes' => $lesson->duration_in_minutes
                ];
            }

            // Calcular percentual de progresso do módulo
            if ($moduleProgress['total_lessons'] > 0) {
                $moduleProgress['progress_percentage'] = round(
                    ($moduleProgress['completed_lessons'] / $moduleProgress['total_lessons']) * 100
                );
            }

            $progress['modules'][] = $moduleProgress;
        }

        // Atualizar contadores gerais
        $progress['total_lessons'] = $totalLessons;
        $progress['completed_lessons'] = $completedLessons;

        // Verificar se o percentual de progresso está atualizado
        $calculatedPercentage = $totalLessons > 0
            ? round(($completedLessons / $totalLessons) * 100)
            : 0;

        // Se o percentual calculado é diferente do armazenado, atualizar
        if ($calculatedPercentage != $enrollment->progress_percentage) {
            $enrollment->progress_percentage = $calculatedPercentage;

            // Se completou 100%, marcar como concluído
            if ($calculatedPercentage == 100 && $enrollment->status != 'completed') {
                $enrollment->status = 'completed';
                $enrollment->completed_at = now();

                // Aqui você pode disparar eventos ou jobs relacionados à conclusão do curso
                // Por exemplo: event(new CourseCompleted($user->id, $courseId));
            }

            $enrollment->save();
            $progress['progress_percentage'] = $calculatedPercentage;
        }

        return response()->json($progress);
    }

    /**
     * Atualizar o progresso do aluno em uma aula.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $lessonId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateLessonProgress(Request $request, $lessonId)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'watched_seconds' => 'required|integer|min:0',
            'is_completed' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $lesson = Lesson::with('module.course')->findOrFail($lessonId);
        $courseId = $lesson->module->course->id;

        // Verificar se o usuário está inscrito no curso
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->first();

        if (!$enrollment && !$lesson->is_free) {
            return response()->json(['error' => 'Você precisa estar inscrito neste curso'], 403);
        }

        // Buscar ou criar registro de progresso
        $progress = StudentProgress::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lessonId,
        ]);

        // Atualizar progresso
        $progress->watched_seconds = $request->watched_seconds;
        $progress->last_watched_at = Carbon::now();

        // Se explicitamente marcado como concluído ou se assistiu o suficiente do vídeo
        if ($request->has('is_completed') && $request->is_completed) {
            $progress->is_completed = true;
            $progress->completed_at = Carbon::now();
        } else {
            // Para vídeos, verificar se o aluno assistiu pelo menos 90% da duração
            if ($lesson->type === 'video' && $lesson->duration_in_minutes > 0) {
                $durationInSeconds = $lesson->duration_in_minutes * 60;
                $completionThreshold = $durationInSeconds * 0.9;

                if ($request->watched_seconds >= $completionThreshold) {
                    $progress->is_completed = true;
                    $progress->completed_at = Carbon::now();
                }
            }
        }

        $progress->save();

        // Registrar evento de engajamento para processamento assíncrono
        // Aqui você poderia publicar uma mensagem no Kafka, por exemplo:
        /*
        $producer = new RdKafka\Producer();
        $producer->addBrokers(config('kafka.brokers', 'localhost:9092'));
        
        $topic = $producer->newTopic('student-engagement');
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode([
            'event_type' => 'lesson_viewed',
            'user_id' => $user->id,
            'lesson_id' => $lessonId,
            'course_id' => $courseId,
            'viewed_seconds' => $request->watched_seconds,
            'lesson_duration' => $lesson->duration_in_minutes * 60,
            'timestamp' => time()
        ]));
        */

        // Recalcular progresso geral do curso
        if ($enrollment) {
            $enrollment->calculateProgress();
        }

        return response()->json([
            'message' => 'Progresso atualizado com sucesso',
            'progress' => $progress
        ]);
    }

    /**
     * Marcar uma aula como concluída.
     *
     * @param  int  $lessonId
     * @return \Illuminate\Http\JsonResponse
     */
    public function completeLesson($lessonId)
    {
        $user = auth()->user();
        $lesson = Lesson::with('module.course')->findOrFail($lessonId);
        $courseId = $lesson->module->course->id;

        // Verificar se o usuário está inscrito no curso
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->first();

        if (!$enrollment && !$lesson->is_free) {
            return response()->json(['error' => 'Você precisa estar inscrito neste curso'], 403);
        }

        // Buscar ou criar registro de progresso
        $progress = StudentProgress::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lessonId,
        ]);

        // Marcar como concluído
        $progress->is_completed = true;
        $progress->completed_at = Carbon::now();
        $progress->last_watched_at = Carbon::now();
        $progress->save();

        // Registrar evento de engajamento para processamento assíncrono
        // Similar ao método anterior

        // Recalcular progresso geral do curso
        if ($enrollment) {
            $enrollment->calculateProgress();
        }

        return response()->json([
            'message' => 'Aula marcada como concluída',
            'progress' => $progress
        ]);
    }

    /**
     * Obter o progresso para todas as inscrições ativas do usuário.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function allProgress()
    {
        $user = auth()->user();

        // Buscar todas as inscrições ativas
        $enrollments = Enrollment::where('user_id', $user->id)
            ->where('status', 'active')
            ->with('course:id,title,thumbnail,duration_in_minutes,instructor_id')
            ->get();

        $result = [];

        foreach ($enrollments as $enrollment) {
            // Buscar dados básicos de progresso
            $totalLessons = Lesson::whereHas('module', function ($query) use ($enrollment) {
                $query->where('course_id', $enrollment->course_id);
            })->count();

            $completedLessons = StudentProgress::where('user_id', $user->id)
                ->whereHas('lesson.module', function ($query) use ($enrollment) {
                    $query->where('course_id', $enrollment->course_id);
                })
                ->where('is_completed', true)
                ->count();

            $result[] = [
                'enrollment_id' => $enrollment->id,
                'course_id' => $enrollment->course_id,
                'course_title' => $enrollment->course->title,
                'course_thumbnail' => $enrollment->course->thumbnail,
                'total_lessons' => $totalLessons,
                'completed_lessons' => $completedLessons,
                'progress_percentage' => $enrollment->progress_percentage,
                'last_activity' => $this->getLastActivity($user->id, $enrollment->course_id)
            ];
        }

        return response()->json($result);
    }

    /**
     * Obter a última atividade do usuário em um curso.
     *
     * @param  int  $userId
     * @param  int  $courseId
     * @return string|null
     */
    private function getLastActivity($userId, $courseId)
    {
        $lastProgress = StudentProgress::where('user_id', $userId)
            ->whereHas('lesson.module', function ($query) use ($courseId) {
                $query->where('course_id', $courseId);
            })
            ->orderBy('last_watched_at', 'desc')
            ->first();

        return $lastProgress ? $lastProgress->last_watched_at->format('Y-m-d H:i:s') : null;
    }
}
