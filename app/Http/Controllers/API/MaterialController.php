<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MaterialController extends Controller
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
     * Listar os materiais de uma aula específica.
     *
     * @param  int  $lessonId
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($lessonId)
    {
        // Encontrar a aula e verificar se existe
        $lesson = Lesson::with('module.course')->findOrFail($lessonId);
        $course = $lesson->module->course;

        // Verificar permissão - alunos inscritos, admin ou instrutor podem ver materiais
        $user = auth()->user();
        $isInstructor = $user->id === $course->instructor_id;
        $isAdmin = $user->is_admin;

        // Verificar se o usuário está inscrito no curso
        $isEnrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();

        // Se não é admin, instrutor ou aluno inscrito, verificar se a aula é gratuita
        if (!$isAdmin && !$isInstructor && !$isEnrolled && !$lesson->is_free) {
            return response()->json([
                'error' => 'Acesso não autorizado',
                'message' => 'Você precisa se inscrever no curso para acessar estes materiais'
            ], 403);
        }

        // Obter materiais da aula
        $materials = Material::where('lesson_id', $lessonId)
            ->orderBy('created_at', 'desc')
            ->get();

        // Adicionar URLs completas para os arquivos
        foreach ($materials as $material) {
            $material->file_url = asset('storage/' . $material->file_path);
        }

        return response()->json($materials);
    }

    /**
     * Armazenar um novo material.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'lesson_id' => 'required|exists:lessons,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file' => 'required|file|max:51200', // Máximo de 50MB
            'is_downloadable' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Encontrar a aula e verificar permissões
        $lesson = Lesson::with('module.course')->findOrFail($request->lesson_id);
        $course = $lesson->module->course;

        // Verificar se o usuário é admin ou instrutor do curso
        $user = auth()->user();
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Processar o arquivo
        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $fileSize = $file->getSize();
            $fileType = $file->getMimeType();

            // Gerar um nome único para o arquivo
            $fileName = 'material_' . time() . '_' . Str::random(10) . '.' . $extension;

            // Definir o caminho para salvar o arquivo
            $path = 'materials/' . $course->id . '/' . $lesson->id;

            // Salvar o arquivo no storage
            $filePath = $file->storeAs($path, $fileName, 'public');

            // Criar o registro do material
            $material = Material::create([
                'lesson_id' => $request->lesson_id,
                'title' => $request->title,
                'description' => $request->description,
                'file_path' => $filePath,
                'file_type' => $fileType,
                'file_size' => $fileSize,
                'is_downloadable' => $request->is_downloadable ?? true,
            ]);

            // Adicionar URL completa para o arquivo
            $material->file_url = asset('storage/' . $filePath);

            return response()->json([
                'message' => 'Material adicionado com sucesso',
                'material' => $material
            ], 201);
        }

        return response()->json(['error' => 'Falha ao processar o arquivo'], 422);
    }

    /**
     * Exibir um material específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $material = Material::with('lesson.module.course')->findOrFail($id);
        $lesson = $material->lesson;
        $course = $lesson->module->course;

        // Verificar permissão
        $user = auth()->user();
        $isInstructor = $user->id === $course->instructor_id;
        $isAdmin = $user->is_admin;

        // Verificar se o usuário está inscrito no curso
        $isEnrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();

        // Se não é admin, instrutor ou aluno inscrito, verificar se a aula é gratuita
        if (!$isAdmin && !$isInstructor && !$isEnrolled && !$lesson->is_free) {
            return response()->json([
                'error' => 'Acesso não autorizado',
                'message' => 'Você precisa se inscrever no curso para acessar este material'
            ], 403);
        }

        // Adicionar URL completa para o arquivo
        $material->file_url = asset('storage/' . $material->file_path);

        return response()->json($material);
    }

    /**
     * Atualizar um material específico.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $material = Material::with('lesson.module.course')->findOrFail($id);
        $lesson = $material->lesson;
        $course = $lesson->module->course;

        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'title' => 'string|max:255',
            'description' => 'nullable|string',
            'file' => 'nullable|file|max:51200', // Máximo de 50MB
            'is_downloadable' => 'boolean',
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
        if ($request->has('is_downloadable')) {
            $updateData['is_downloadable'] = $request->is_downloadable;
        }

        // Processar atualização de arquivo, se fornecido
        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            // Remover o arquivo antigo
            Storage::disk('public')->delete($material->file_path);

            // Processar o novo arquivo
            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();
            $fileSize = $file->getSize();
            $fileType = $file->getMimeType();

            // Gerar um nome único para o arquivo
            $fileName = 'material_' . time() . '_' . Str::random(10) . '.' . $extension;

            // Definir o caminho para salvar o arquivo
            $path = 'materials/' . $course->id . '/' . $lesson->id;

            // Salvar o arquivo no storage
            $filePath = $file->storeAs($path, $fileName, 'public');

            // Atualizar informações do arquivo
            $updateData['file_path'] = $filePath;
            $updateData['file_type'] = $fileType;
            $updateData['file_size'] = $fileSize;
        }

        // Atualizar o material
        Material::where('id', $id)->update($updateData);

        // Recarregar o material para obter os dados atualizados
        $material = Material::findOrFail($id);

        // Adicionar URL completa para o arquivo
        $material->file_url = asset('storage/' . $material->file_path);

        return response()->json([
            'message' => 'Material atualizado com sucesso',
            'material' => $material
        ]);
    }

    /**
     * Remover um material específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $material = Material::with('lesson.module.course')->findOrFail($id);
        $lesson = $material->lesson;
        $course = $lesson->module->course;

        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Remover o arquivo do storage
        if ($material->file_path) {
            Storage::disk('public')->delete($material->file_path);
        }

        // Remover o registro do material
        $material->delete();

        return response()->json(['message' => 'Material removido com sucesso']);
    }

    /**
     * Incrementar o contador de downloads e retornar URL para download.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function download($id)
    {
        $material = Material::with('lesson.module.course')->findOrFail($id);
        $lesson = $material->lesson;
        $course = $lesson->module->course;

        // Verificar permissão
        $user = auth()->user();
        $isInstructor = $user->id === $course->instructor_id;
        $isAdmin = $user->is_admin;

        // Verificar se o usuário está inscrito no curso
        $isEnrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();

        // Se não é admin, instrutor ou aluno inscrito, verificar se a aula é gratuita
        if (!$isAdmin && !$isInstructor && !$isEnrolled && !$lesson->is_free) {
            return response()->json([
                'error' => 'Acesso não autorizado',
                'message' => 'Você precisa se inscrever no curso para baixar este material'
            ], 403);
        }

        // Verificar se o material permite download
        if (!$material->is_downloadable) {
            return response()->json([
                'error' => 'Download não permitido',
                'message' => 'Este material não está disponível para download'
            ], 403);
        }

        // Incrementar o contador de downloads
        $material->increment('download_count');

        // Verificar se o arquivo existe
        if (!Storage::disk('public')->exists($material->file_path)) {
            return response()->json(['error' => 'Arquivo não encontrado'], 404);
        }

        // Gerar URL para download
        $url = asset('storage/' . $material->file_path);

        return response()->json([
            'download_url' => $url,
            'file_name' => $material->title . '.' . pathinfo($material->file_path, PATHINFO_EXTENSION),
            'file_type' => $material->file_type,
            'file_size' => $material->file_size
        ]);
    }
}
