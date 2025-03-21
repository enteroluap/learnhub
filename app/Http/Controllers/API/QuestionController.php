<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class QuestionController extends Controller
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
     * Listar perguntas de uma aula específica.
     *
     * @param  int  $lessonId
     * @return \Illuminate\Http\JsonResponse
     */
    public function lessonQuestions($lessonId)
    {
        $user = auth()->user();
        $lesson = Lesson::with('module.course')->findOrFail($lessonId);
        $course = $lesson->module->course;

        // Verificar se o usuário tem acesso à aula
        $isAdmin = $user->is_admin;
        $isInstructor = $user->id === $course->instructor_id;

        // Verificar se o usuário está inscrito no curso
        $isEnrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();

        // Se não tem acesso e a aula não é gratuita
        if (!$isAdmin && !$isInstructor && !$isEnrolled && !$lesson->is_free) {
            return response()->json([
                'error' => 'Acesso não autorizado',
                'message' => 'Você precisa estar inscrito no curso para ver as perguntas'
            ], 403);
        }

        // Determinar quais perguntas exibir com base no perfil do usuário
        $query = Question::with(['user:id,name,profile_image'])
            ->where('lesson_id', $lessonId);

        // Alunos normais só veem perguntas públicas e suas próprias
        if (!$isAdmin && !$isInstructor) {
            $query->where(function ($q) use ($user) {
                $q->where('is_public', true)
                    ->orWhere('user_id', $user->id);
            });
        }

        $questions = $query->orderBy('created_at', 'desc')->get();

        // Para cada pergunta, verificar se o usuário pode editar/excluir
        foreach ($questions as $question) {
            $question->can_edit = $user->id === $question->user_id || $isAdmin || $isInstructor;
            $question->can_delete = $user->id === $question->user_id || $isAdmin;
        }

        return response()->json($questions);
    }

    /**
     * Armazenar uma nova pergunta.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $lessonId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeQuestion(Request $request, $lessonId)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'question' => 'required|string|min:10|max:1000',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $lesson = Lesson::with('module.course')->findOrFail($lessonId);
        $course = $lesson->module->course;

        // Verificar se o usuário tem acesso à aula
        $isEnrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();

        if (!$user->is_admin && $user->id !== $course->instructor_id && !$isEnrolled && !$lesson->is_free) {
            return response()->json([
                'error' => 'Acesso não autorizado',
                'message' => 'Você precisa estar inscrito no curso para fazer perguntas'
            ], 403);
        }

        // Criar a pergunta
        $question = Question::create([
            'user_id' => $user->id,
            'lesson_id' => $lessonId,
            'question' => $request->question,
            'is_public' => $request->is_public ?? true,
        ]);

        // Carregar relacionamentos para a resposta
        $question->load('user:id,name,profile_image');

        // Atributos adicionais para o frontend
        $question->can_edit = true;
        $question->can_delete = true;

        // Notificar o instrutor sobre a nova pergunta (em um sistema real)
        // Notification::send($course->instructor, new NewQuestionNotification($question));

        return response()->json([
            'message' => 'Pergunta enviada com sucesso',
            'question' => $question
        ], 201);
    }

    /**
     * Exibir detalhes de uma pergunta específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = auth()->user();
        $question = Question::with(['user:id,name,profile_image', 'lesson.module.course'])
            ->findOrFail($id);

        $lesson = $question->lesson;
        $course = $lesson->module->course;

        // Verificar se o usuário pode ver esta pergunta
        $isAdmin = $user->is_admin;
        $isInstructor = $user->id === $course->instructor_id;
        $isOwner = $user->id === $question->user_id;

        if (!$isAdmin && !$isInstructor && !$isOwner && !$question->is_public) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Verificar permissões
        $question->can_edit = $isOwner || $isAdmin || $isInstructor;
        $question->can_delete = $isOwner || $isAdmin;
        $question->can_answer = $isAdmin || $isInstructor;

        return response()->json($question);
    }

    /**
     * Atualizar uma pergunta específica.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'question' => 'string|min:10|max:1000',
            'answer' => 'nullable|string',
            'is_public' => 'boolean',
            'is_resolved' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $question = Question::with('lesson.module.course')->findOrFail($id);
        $course = $question->lesson->module->course;

        // Verificar permissões
        $isAdmin = $user->is_admin;
        $isInstructor = $user->id === $course->instructor_id;
        $isOwner = $user->id === $question->user_id;

        // O usuário pode editar a pergunta se for o criador, admin ou instrutor
        if (!$isOwner && !$isAdmin && !$isInstructor) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Apenas o dono pode editar o conteúdo da pergunta
        if ($request->has('question') && !$isOwner) {
            return response()->json([
                'error' => 'Apenas o autor pode editar o conteúdo da pergunta'
            ], 403);
        }

        // Apenas admin ou instrutor podem adicionar/editar resposta
        if ($request->has('answer') && !$isAdmin && !$isInstructor) {
            return response()->json([
                'error' => 'Apenas administradores ou instrutores podem responder'
            ], 403);
        }

        // Preparar dados para atualização
        $updateData = [];

        if ($request->has('question') && $isOwner) {
            $updateData['question'] = $request->question;
        }

        if ($request->has('is_public')) {
            $updateData['is_public'] = $request->is_public;
        }

        if ($request->has('is_resolved')) {
            $updateData['is_resolved'] = $request->is_resolved;
        }

        // Responder à pergunta
        if ($request->has('answer') && ($isAdmin || $isInstructor)) {
            $updateData['answer'] = $request->answer;
            $updateData['answered_by'] = $user->id;
            $updateData['answered_at'] = now();

            // Marcar como resolvida automaticamente
            $updateData['is_resolved'] = true;
        }

        // Atualizar a pergunta
        Question::where('id', $id)->update($updateData);

        // Recarregar a pergunta para obter os dados atualizados
        $question = Question::with(['user:id,name,profile_image', 'lesson.module.course'])
            ->findOrFail($id);

        // Atributos adicionais para o frontend
        $question->can_edit = $isOwner || $isAdmin || $isInstructor;
        $question->can_delete = $isOwner || $isAdmin;
        $question->can_answer = $isAdmin || $isInstructor;

        // Notificar o aluno sobre a resposta (em um sistema real)
        if ($request->has('answer') && ($isAdmin || $isInstructor)) {
            // Notification::send($question->user, new QuestionAnsweredNotification($question));
        }

        return response()->json([
            'message' => 'Pergunta atualizada com sucesso',
            'question' => $question
        ]);
    }

    /**
     * Remover uma pergunta específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = auth()->user();
        $question = Question::with('lesson.module.course')->findOrFail($id);

        // Verificar permissões
        $isAdmin = $user->is_admin;
        $isOwner = $user->id === $question->user_id;

        if (!$isOwner && !$isAdmin) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Remover a pergunta
        $question->delete();

        return response()->json(['message' => 'Pergunta removida com sucesso']);
    }

    /**
     * Listar perguntas feitas pelo usuário autenticado.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function myQuestions(Request $request)
    {
        $user = auth()->user();

        // Parâmetros de paginação e filtragem
        $perPage = $request->get('per_page', 10);
        $status = $request->get('status'); // resolved, unanswered
        $courseId = $request->get('course_id');

        // Consulta base
        $query = Question::where('user_id', $user->id)
            ->with(['lesson:id,title,module_id', 'lesson.module:id,title,course_id', 'lesson.module.course:id,title']);

        // Filtrar por status
        if ($status === 'resolved') {
            $query->where('is_resolved', true);
        } elseif ($status === 'unanswered') {
            $query->whereNull('answer');
        }

        // Filtrar por curso
        if ($courseId) {
            $query->whereHas('lesson.module', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            });
        }

        // Ordenar e paginar
        $questions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($questions);
    }

    /**
     * Listar perguntas para responder (para instrutores e admins).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function questionsToAnswer(Request $request)
    {
        $user = auth()->user();

        // Verificar permissões
        if (!$user->is_admin && !$user->is_instructor) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Parâmetros de paginação e filtragem
        $perPage = $request->get('per_page', 15);
        $courseId = $request->get('course_id');

        // Consulta base
        $query = Question::whereNull('answer')
            ->with(['user:id,name,profile_image', 'lesson:id,title,module_id', 'lesson.module:id,title,course_id']);

        // Filtrar por cursos do instrutor
        if (!$user->is_admin) {
            $query->whereHas('lesson.module.course', function ($q) use ($user) {
                $q->where('instructor_id', $user->id);
            });
        }

        // Filtrar por curso específico
        if ($courseId) {
            $query->whereHas('lesson.module', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            });
        }

        // Ordenar e paginar
        $questions = $query->orderBy('created_at', 'asc')->paginate($perPage);

        return response()->json($questions);
    }
}
