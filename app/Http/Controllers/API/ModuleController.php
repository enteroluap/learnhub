<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuleController extends Controller
{
    /**
     * Criar uma nova instância de controller.
     * Aplica middleware de autenticação para todas as rotas.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Listar todos os módulos de um curso específico.
     * 
     * @param  int  $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($courseId)
    {
        $course = Course::findOrFail($courseId);

        // Verificar permissão - apenas usuários com acesso ao curso podem listar módulos
        $user = auth()->user();
        if (!$course->is_published && $user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Curso não encontrado'], 404);
        }

        // Carregar módulos ordenados por ordem
        $modules = Module::where('course_id', $courseId)
            ->orderBy('order')
            ->with(['lessons' => function ($query) {
                $query->orderBy('order');
            }])
            ->get();

        return response()->json($modules);
    }

    /**
     * Armazenar um novo módulo.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'order' => 'nullable|integer|min:0',
            'is_free' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verificar permissão
        $user = auth()->user();
        $course = Course::findOrFail($request->course_id);

        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Definir a ordem automaticamente se não for fornecida
        if (!$request->has('order') || $request->order === null) {
            $maxOrder = Module::where('course_id', $request->course_id)->max('order') ?? 0;
            $order = $maxOrder + 1;
        } else {
            $order = $request->order;
        }

        // Criar o módulo
        $module = Module::create([
            'course_id' => $request->course_id,
            'title' => $request->title,
            'description' => $request->description,
            'order' => $order,
            'is_free' => $request->is_free ?? false,
            'duration_in_minutes' => 0, // Inicialmente zero, será atualizado à medida que aulas forem adicionadas
        ]);

        return response()->json([
            'message' => 'Módulo criado com sucesso',
            'module' => $module
        ], 201);
    }

    /**
     * Exibir um módulo específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $module = Module::with(['course', 'lessons' => function ($query) {
            $query->orderBy('order');
        }])->findOrFail($id);

        // Verificar permissão
        $user = auth()->user();
        $course = $module->course;

        if (!$course->is_published && $user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Módulo não encontrado'], 404);
        }

        return response()->json($module);
    }

    /**
     * Atualizar um módulo específico.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $module = Module::findOrFail($id);
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
            'order' => 'integer|min:0',
            'is_free' => 'boolean',
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
        if ($request->has('order')) {
            $updateData['order'] = $request->order;
        }
        if ($request->has('is_free')) {
            $updateData['is_free'] = $request->is_free;
        }

        // Atualizar o módulo
        Module::where('id', $id)->update($updateData);

        // Recarregar o módulo para obter os dados atualizados
        $module = Module::findOrFail($id);

        return response()->json([
            'message' => 'Módulo atualizado com sucesso',
            'module' => $module
        ]);
    }

    /**
     * Remover um módulo específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $module = Module::findOrFail($id);
        $course = Course::findOrFail($module->course_id);

        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Verificar se possui aulas
        $lessonsCount = $module->lessons()->count();
        if ($lessonsCount > 0) {
            return response()->json([
                'error' => 'Este módulo não pode ser excluído porque possui aulas',
                'lessons_count' => $lessonsCount
            ], 422);
        }

        // Remover o módulo
        $module->delete();

        // Atualizar a duração total do curso
        $courseTotalDuration = Module::where('course_id', $course->id)->sum('duration_in_minutes');
        Course::where('id', $course->id)->update(['duration_in_minutes' => $courseTotalDuration]);

        return response()->json(['message' => 'Módulo removido com sucesso']);
    }

    /**
     * Reordenar os módulos de um curso.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorder(Request $request, $courseId)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'modules' => 'required|array',
            'modules.*.id' => 'required|exists:modules,id',
            'modules.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $course = Course::findOrFail($courseId);

        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Verificar se todos os módulos pertencem ao curso
        $moduleIds = [];
        foreach ($request->modules as $module) {
            $moduleIds[] = $module['id'];
        }

        $courseModulesCount = Module::where('course_id', $courseId)
            ->whereIn('id', $moduleIds)
            ->count();

        if (count($moduleIds) !== $courseModulesCount) {
            return response()->json(['error' => 'Um ou mais módulos não pertencem a este curso'], 422);
        }

        // Atualizar a ordem dos módulos
        foreach ($request->modules as $moduleData) {
            Module::where('id', $moduleData['id'])->update(['order' => $moduleData['order']]);
        }

        // Recarregar os módulos ordenados
        $modules = Module::where('course_id', $courseId)->orderBy('order')->get();

        return response()->json([
            'message' => 'Ordem dos módulos atualizada com sucesso',
            'modules' => $modules
        ]);
    }
}
