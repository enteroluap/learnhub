<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class AiAssistantController extends Controller
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
     * Enviar uma pergunta ao assistente de IA e receber uma resposta.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ask(Request $request)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:3|max:1000',
            'lesson_id' => 'nullable|exists:lessons,id',
            'context' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Obter usuário autenticado
        $user = auth()->user();

        // Verificar se está relacionado a uma aula específica
        $lessonId = $request->lesson_id;
        $courseContext = null;

        if ($lessonId) {
            $lesson = Lesson::with('module.course')->findOrFail($lessonId);
            $course = $lesson->module->course;

            // Verificar se o usuário tem acesso a esta aula
            $isAdmin = $user->is_admin;
            $isInstructor = $user->id === $course->instructor_id;
            $isEnrolled = Enrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('status', 'active')
                ->exists();

            if (!$isAdmin && !$isInstructor && !$isEnrolled && !$lesson->is_free) {
                return response()->json([
                    'error' => 'Acesso não autorizado',
                    'message' => 'Você precisa estar inscrito no curso para usar o assistente de IA'
                ], 403);
            }

            // Construir contexto do curso e da aula para enviar para o modelo
            $courseContext = "Esta pergunta está relacionada ao curso '{$course->title}', " .
                "no módulo '{$lesson->module->title}', " .
                "aula '{$lesson->title}'. ";

            if ($lesson->description) {
                $courseContext .= "\nDescrição da aula: {$lesson->description}";
            }
        }

        // Obter contexto adicional, se fornecido
        $additionalContext = $request->context;

        // Construir o prompt para o assistente
        $prompt = "Você é um assistente educacional para uma plataforma de cursos online. " .
            "Sua tarefa é ajudar os alunos com dúvidas sobre o conteúdo do curso. " .
            "Seja claro, conciso e educativo na sua resposta.\n\n";

        // Adicionar contexto do curso, se disponível
        if ($courseContext) {
            $prompt .= "CONTEXTO DO CURSO:\n" . $courseContext . "\n\n";
        }

        // Adicionar contexto adicional, se fornecido
        if ($additionalContext) {
            $prompt .= "CONTEXTO ADICIONAL:\n" . $additionalContext . "\n\n";
        }

        // Adicionar a pergunta do usuário
        $prompt .= "PERGUNTA DO ALUNO:\n" . $request->query;

        // Modelo a ser usado
        $model = 'gpt-3.5-turbo';

        try {
            // Chamar a API da OpenAI (usando Http Facade)
            $openaiKey = config('services.openai.api_key', 'sua_chave_da_openai');

            $result = Http::withHeaders([
                'Authorization' => 'Bearer ' . $openaiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $prompt],
                    ['role' => 'user', 'content' => $request->query]
                ],
                'temperature' => 0.7,
                'max_tokens' => 1000,
            ]);

            // Verificar se a resposta foi bem-sucedida
            if (!$result->successful()) {
                throw new \Exception('Erro na API da OpenAI: ' . $result->body());
            }

            $responseData = $result->json();

            // Extrair a resposta
            $response = $responseData['choices'][0]['message']['content'];

            // Obter informações sobre o uso de tokens
            $tokensUsed = $responseData['usage']['total_tokens'];

            // Salvar a interação no banco de dados
            $interaction = AiInteraction::create([
                'user_id' => $user->id,
                'lesson_id' => $lessonId,
                'query' => $request->query,
                'response' => $response,
                'model_used' => $model,
                'tokens_used' => $tokensUsed,
            ]);

            return response()->json([
                'response' => $response,
                'interaction_id' => $interaction->id,
                'tokens_used' => $tokensUsed
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao processar a solicitação',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marcar uma interação como útil ou não útil.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function feedback(Request $request, $id)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'was_helpful' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Obter usuário autenticado
        $user = auth()->user();

        // Encontrar a interação
        $interaction = AiInteraction::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Atualizar o feedback
        $interaction->was_helpful = $request->was_helpful;
        $interaction->save();

        return response()->json([
            'message' => 'Feedback registrado com sucesso',
            'interaction' => $interaction
        ]);
    }

    /**
     * Obter o histórico de interações do usuário com o assistente de IA.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request)
    {
        // Obter usuário autenticado
        $user = auth()->user();

        // Parâmetros de paginação e filtragem
        $perPage = $request->get('per_page', 15);
        $lessonId = $request->get('lesson_id');
        $courseId = $request->get('course_id');

        // Consulta base
        $query = AiInteraction::where('user_id', $user->id);

        // Filtrar por aula, se especificado
        if ($lessonId) {
            $query->where('lesson_id', $lessonId);
        }

        // Filtrar por curso, se especificado
        if ($courseId) {
            $query->whereHas('lesson.module', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            });
        }

        // Ordenar e paginar
        $interactions = $query->with('lesson:id,title,module_id')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($interactions);
    }

    /**
     * Obter detalhes de uma interação específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        // Obter usuário autenticado
        $user = auth()->user();

        // Encontrar a interação
        $interaction = AiInteraction::where('id', $id)
            ->where('user_id', $user->id)
            ->with('lesson:id,title,module_id')
            ->firstOrFail();

        return response()->json($interaction);
    }

    /**
     * Remover uma interação.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        // Obter usuário autenticado
        $user = auth()->user();

        // Encontrar a interação
        $interaction = AiInteraction::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Remover a interação
        $interaction->delete();

        return response()->json(['message' => 'Interação removida com sucesso']);
    }

    /**
     * Obter métricas do uso do assistente de IA (para administradores).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function metrics()
    {
        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Obter estatísticas de uso
        $totalInteractions = AiInteraction::count();
        $totalTokensUsed = AiInteraction::sum('tokens_used');
        $totalUsers = AiInteraction::distinct('user_id')->count('user_id');

        // Percentual de interações úteis (quando há feedback)
        $withFeedback = AiInteraction::whereNotNull('was_helpful')->count();
        $helpfulCount = AiInteraction::where('was_helpful', true)->count();
        $helpfulPercentage = $withFeedback > 0 ? round(($helpfulCount / $withFeedback) * 100, 2) : 0;

        // Top 5 lições com mais perguntas
        $topLessons = AiInteraction::whereNotNull('lesson_id')
            ->selectRaw('lesson_id, count(*) as total')
            ->groupBy('lesson_id')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->with('lesson:id,title,module_id')
            ->get();

        return response()->json([
            'total_interactions' => $totalInteractions,
            'total_tokens_used' => $totalTokensUsed,
            'total_users' => $totalUsers,
            'helpful_percentage' => $helpfulPercentage,
            'feedback_rate' => $withFeedback > 0 ? round(($withFeedback / $totalInteractions) * 100, 2) : 0,
            'top_lessons' => $topLessons
        ]);
    }
}
