<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Module;
use App\Models\Lesson;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LessonController extends Controller
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
     * Listar as aulas de um módulo específico.
     *
     * @param  int  $moduleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($moduleId)
    {
        $module = Module::findOrFail($moduleId);
        $course = Course::findOrFail($module->course_id);

        // Verificar permissão
        $user = auth()->user();
        if (!$course->is_published && $user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Módulo não encontrado'], 404);
        }

        // Verificar se o usuário está inscrito no curso
        $isEnrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();

        // Carregar aulas
        $query = Lesson::where('module_id', $moduleId)->orderBy('order');

        // Filtrar aulas não gratuitas para usuários não inscritos
        if (!$isEnrolled && $user->is_admin != true && $user->id !== $course->instructor_id) {
            $query->where(function ($q) use ($module) {
                $q->where('is_free', true)->orWhereIn('id', function ($subquery) use ($module) {
                    $subquery->select('id')
                        ->from('lessons')
                        ->where('module_id', $module->id)
                        ->orderBy('order')
                        ->limit(1); // Primeira aula é sempre acessível
                });
            });
        }

        $lessons = $query->get();

        // Para cada aula, verificar se já foi concluída pelo usuário
        $userId = auth()->id();
        foreach ($lessons as $lesson) {
            $completed = Lesson::where('id', $lesson->id)
                ->whereHas('progress', function ($query) use ($userId) {
                    $query->where('user_id', $userId)
                        ->where('is_completed', true);
                })
                ->exists();

            $lesson->is_completed = $completed;
        }

        return response()->json($lessons);
    }

    /**
     * Armazenar uma nova aula.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'module_id' => 'required|exists:modules,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:video,text,quiz,assignment',
            'video_url' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'duration_in_minutes' => 'nullable|integer|min:0',
            'order' => 'nullable|integer|min:0',
            'is_free' => 'boolean',
            'is_published' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verificar permissão
        $user = auth()->user();
        $module = Module::findOrFail($request->module_id);
        $course = Course::findOrFail($module->course_id);

        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Definir a ordem automaticamente se não for fornecida
        if (!$request->has('order') || $request->order === null) {
            $maxOrder = Lesson::where('module_id', $request->module_id)->max('order') ?? 0;
            $order = $maxOrder + 1;
        } else {
            $order = $request->order;
        }

        // Criar a aula
        $lesson = Lesson::create([
            'module_id' => $request->module_id,
            'title' => $request->title,
            'description' => $request->description,
            'type' => $request->type,
            'video_url' => $request->video_url,
            'content' => $request->content,
            'duration_in_minutes' => $request->duration_in_minutes ?? 0,
            'order' => $order,
            'is_free' => $request->is_free ?? false,
            'is_published' => $request->is_published ?? true,
        ]);

        // Atualizar a duração do módulo
        $this->updateModuleDuration($module);

        return response()->json([
            'message' => 'Aula criada com sucesso',
            'lesson' => $lesson
        ], 201);
    }

    /**
     * Exibir uma aula específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $lesson = Lesson::with(['module.course', 'materials'])->findOrFail($id);
        $module = $lesson->module;
        $course = $module->course;

        // Verificar permissão
        $user = auth()->user();

        // Aulas não publicadas são visíveis apenas para administradores e instrutores do curso
        if (!$lesson->is_published && $user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Aula não encontrada'], 404);
        }

        // Verificar se o usuário está inscrito no curso ou se é admin/instrutor
        $isEnrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();

        $isAdminOrInstructor = $user->is_admin == true || $user->id === $course->instructor_id;

        // Apenas permitir acesso a aulas não gratuitas para usuários inscritos, admin ou instrutor
        if (!$lesson->is_free && !$isAdminOrInstructor && !$isEnrolled) {
            // Verificar se é a primeira aula
            $firstLesson = Lesson::where('module_id', $module->id)
                ->orderBy('order')
                ->first();

            if ($lesson->id !== $firstLesson->id) {
                return response()->json([
                    'error' => 'Acesso não autorizado',
                    'message' => 'Você precisa se inscrever neste curso para acessar esta aula'
                ], 403);
            }
        }

        // Verificar se o usuário já concluiu esta aula
        $completed = Lesson::where('id', $lesson->id)
            ->whereHas('progress', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('is_completed', true);
            })
            ->exists();

        $lesson->is_completed = $completed;

        return response()->json($lesson);
    }

    /**
     * Atualizar uma aula específica.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $lesson = Lesson::findOrFail($id);
        $module = Module::findOrFail($lesson->module_id);
        $course = Course::findOrFail($module->course_id);

        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'title' => 'string|max:255',
            'description' => 'nullable|string',
            'type' => 'in:video,text,quiz,assignment',
            'video_url' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'duration_in_minutes' => 'integer|min:0',
            'order' => 'integer|min:0',
            'is_free' => 'boolean',
            'is_published' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Preparar dados para atualização
        $updateData = [];

        if ($request->has('title')) {
            $updateData['title'] = $request->title;
        }
        if ($request->has('description')) {
            $updateData['description'] = $request->description;
        }
        if ($request->has('type')) {
            $updateData['type'] = $request->type;
        }
        if ($request->has('video_url')) {
            $updateData['video_url'] = $request->video_url;
        }
        if ($request->has('content')) {
            $updateData['content'] = $request->content;
        }
        if ($request->has('duration_in_minutes')) {
            $updateData['duration_in_minutes'] = $request->duration_in_minutes;
        }
        if ($request->has('order')) {
            $updateData['order'] = $request->order;
        }
        if ($request->has('is_free')) {
            $updateData['is_free'] = $request->is_free;
        }
        if ($request->has('is_published')) {
            $updateData['is_published'] = $request->is_published;
        }

        // Atualizar a aula
        Lesson::where('id', $id)->update($updateData);

        // Atualizar a duração do módulo, se necessário
        if ($request->has('duration_in_minutes')) {
            $this->updateModuleDuration($module);
        }

        // Recarregar a aula para obter os dados atualizados
        $lesson = Lesson::findOrFail($id);

        return response()->json([
            'message' => 'Aula atualizada com sucesso',
            'lesson' => $lesson
        ]);
    }

    /**
     * Remover uma aula específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $lesson = Lesson::findOrFail($id);
        $module = Module::findOrFail($lesson->module_id);
        $course = Course::findOrFail($module->course_id);

        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Remover materiais associados à aula
        $materials = $lesson->materials;
        foreach ($materials as $material) {
            if ($material->file_path) {
                Storage::disk('public')->delete($material->file_path);
            }
            $material->delete();
        }

        // Remover a aula
        $lesson->delete();

        // Atualizar a duração do módulo
        $this->updateModuleDuration($module);

        return response()->json(['message' => 'Aula removida com sucesso']);
    }

    /**
     * Reordenar as aulas de um módulo.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $moduleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorder(Request $request, $moduleId)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'lessons' => 'required|array',
            'lessons.*.id' => 'required|exists:lessons,id',
            'lessons.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $module = Module::findOrFail($moduleId);
        $course = Course::findOrFail($module->course_id);

        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Verificar se todas as aulas pertencem ao módulo
        $lessonIds = [];
        foreach ($request->lessons as $lesson) {
            $lessonIds[] = $lesson['id'];
        }

        $moduleLessonsCount = Lesson::where('module_id', $moduleId)
            ->whereIn('id', $lessonIds)
            ->count();

        if (count($lessonIds) !== $moduleLessonsCount) {
            return response()->json(['error' => 'Uma ou mais aulas não pertencem a este módulo'], 422);
        }

        // Atualizar a ordem das aulas
        foreach ($request->lessons as $lessonData) {
            Lesson::where('id', $lessonData['id'])->update(['order' => $lessonData['order']]);
        }

        // Recarregar as aulas ordenadas
        $lessons = Lesson::where('module_id', $moduleId)->orderBy('order')->get();

        return response()->json([
            'message' => 'Ordem das aulas atualizada com sucesso',
            'lessons' => $lessons
        ]);
    }

    /**
     * Atualizar a duração total do módulo com base nas aulas.
     *
     * @param  \App\Models\Module  $module
     * @return void
     */
    private function updateModuleDuration($module)
    {
        $totalDuration = Lesson::where('module_id', $module->id)->sum('duration_in_minutes');

        Module::where('id', $module->id)->update(['duration_in_minutes' => $totalDuration]);

        // Atualizar a duração total do curso
        $courseId = $module->course_id;
        $courseTotalDuration = Module::where('course_id', $courseId)->sum('duration_in_minutes');

        Course::where('id', $courseId)->update(['duration_in_minutes' => $courseTotalDuration]);
    }
}
