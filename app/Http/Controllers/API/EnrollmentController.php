<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends Controller
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
     * Listar todas as inscrições do usuário autenticado.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Obtém o usuário autenticado
        $user = auth()->user();

        // Parâmetros de paginação e filtragem
        $perPage = $request->get('per_page', 10);
        $status = $request->get('status'); // active, completed, expired, canceled
        $search = $request->get('search', '');
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        // Consulta base
        $query = Enrollment::with(['course' => function ($query) {
            $query->select('id', 'title', 'thumbnail', 'instructor_id', 'level', 'duration_in_minutes');
            $query->with(['instructor:id,name']);
        }])
            ->where('user_id', $user->id);

        // Filtrar por status
        if ($status) {
            $query->where('status', $status);
        }

        // Filtrar por termo de busca (no título do curso)
        if (!empty($search)) {
            $query->whereHas('course', function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%");
            });
        }

        // Ordenação
        if ($sortBy === 'title') {
            $query->join('courses', 'enrollments.course_id', '=', 'courses.id')
                ->orderBy('courses.title', $sortOrder)
                ->select('enrollments.*');
        } elseif ($sortBy === 'progress') {
            $query->orderBy('progress_percentage', $sortOrder);
        } elseif ($sortBy === 'completed_at') {
            $query->orderBy('completed_at', $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        // Obter resultados paginados
        $enrollments = $query->paginate($perPage);

        return response()->json($enrollments);
    }

    /**
     * Inscrever o usuário em um curso.
     * 
     * Nesta implementação, assumimos que o pagamento foi processado previamente
     * e temos um transaction_id válido.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function enroll(Request $request, $courseId)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'nullable|string',
            'payment_method' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Encontrar o curso
        $course = Course::findOrFail($courseId);

        // Verificar se o curso está publicado
        if (!$course->is_published) {
            return response()->json(['error' => 'Este curso não está disponível para inscrição'], 400);
        }

        // Obter usuário autenticado
        $user = auth()->user();

        // Verificar se o usuário já está inscrito neste curso
        $existingEnrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        if ($existingEnrollment) {
            // Se já existe uma inscrição e está ativa ou concluída, retornar erro
            if (in_array($existingEnrollment->status, ['active', 'completed'])) {
                return response()->json(['error' => 'Você já está inscrito neste curso'], 400);
            }

            // Se a inscrição existe mas está cancelada ou expirada, podemos atualizá-la
            $enrollment = $existingEnrollment;
        } else {
            // Criar uma nova inscrição
            $enrollment = new Enrollment();
            $enrollment->user_id = $user->id;
            $enrollment->course_id = $courseId;
        }

        // Determinar o preço a ser pago (considerando promoções)
        $paidAmount = $course->getCurrentPrice();

        // Iniciar transação de banco de dados para garantir consistência
        DB::beginTransaction();
        try {
            // Configurar a inscrição
            $enrollment->status = 'active';
            $enrollment->paid_amount = $paidAmount;
            $enrollment->transaction_id = $request->transaction_id;
            $enrollment->payment_method = $request->payment_method;

            // Definir data de expiração, se aplicável (por exemplo, para cursos com acesso limitado)
            // Neste exemplo, definimos como 1 ano a partir de agora
            $enrollment->expires_at = now()->addYear();

            // Resetar progresso se for uma reinscrição
            if ($existingEnrollment) {
                $enrollment->progress_percentage = 0;
                $enrollment->completed_at = null;
            }

            $enrollment->save();

            // Incrementar contador de alunos no curso
            Course::where('id', $courseId)->increment('students_count');

            // Registrar transação financeira, se houver pagamento
            if ($paidAmount > 0 && $request->transaction_id) {
                Transaction::create([
                    'user_id' => $user->id,
                    'course_id' => $courseId,
                    'transaction_id' => $request->transaction_id,
                    'amount' => $paidAmount,
                    'status' => 'completed',
                    'payment_method' => $request->payment_method ?? 'credit_card',
                    'payment_gateway' => $request->payment_gateway ?? 'vindi',
                    'paid_at' => now(),
                ]);
            }

            DB::commit();

            // Carregar relacionamentos para resposta
            $enrollment->load(['course' => function ($query) {
                $query->select('id', 'title', 'thumbnail', 'instructor_id', 'level', 'duration_in_minutes');
                $query->with(['instructor:id,name']);
            }]);

            return response()->json([
                'message' => 'Inscrição realizada com sucesso',
                'enrollment' => $enrollment
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'Erro ao processar a inscrição: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Exibir detalhes de uma inscrição específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        // Encontrar a inscrição com relacionamentos
        $enrollment = Enrollment::with([
            'course' => function ($query) {
                $query->with(['instructor:id,name,profile_image', 'modules.lessons']);
            }
        ])->findOrFail($id);

        // Verificar permissão
        $user = auth()->user();
        if ($user->id !== $enrollment->user_id && $user->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Para cada aula, verificar se já foi concluída pelo usuário
        if ($enrollment->course && $enrollment->course->modules) {
            foreach ($enrollment->course->modules as $module) {
                foreach ($module->lessons as $lesson) {
                    $lesson->is_completed = $lesson->isCompletedByUser($user->id);
                }
            }
        }

        // Verificar se o certificado está disponível
        $enrollment->certificate_available = false;
        $enrollment->certificate_id = null;

        if ($enrollment->status === 'completed') {
            $certificate = Certificate::where('user_id', $user->id)
                ->where('course_id', $enrollment->course_id)
                ->first();

            if ($certificate) {
                $enrollment->certificate_available = true;
                $enrollment->certificate_id = $certificate->id;
            }
        }

        return response()->json($enrollment);
    }

    /**
     * Atualizar uma inscrição específica (apenas admins podem alterar status).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Encontrar a inscrição
        $enrollment = Enrollment::findOrFail($id);

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,completed,expired,canceled',
            'expires_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
            'progress_percentage' => 'nullable|integer|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Atualizar campos
        $enrollment->status = $request->status;

        if ($request->has('expires_at')) {
            $enrollment->expires_at = $request->expires_at;
        }

        if ($request->has('completed_at')) {
            $enrollment->completed_at = $request->completed_at;
        }

        if ($request->has('progress_percentage')) {
            $enrollment->progress_percentage = $request->progress_percentage;
        }

        // Se status é completed e não há data de conclusão, definir como agora
        if ($enrollment->status === 'completed' && !$enrollment->completed_at) {
            $enrollment->completed_at = now();
        }

        $enrollment->save();

        // Se marcado como concluído, verificar/gerar certificado
        if ($enrollment->status === 'completed') {
            $certificateExists = Certificate::where('user_id', $enrollment->user_id)
                ->where('course_id', $enrollment->course_id)
                ->exists();

            if (!$certificateExists) {
                // Gerar certificado
                $certificateNumber = Certificate::generateCertificateNumber(
                    $enrollment->user_id,
                    $enrollment->course_id
                );

                Certificate::create([
                    'user_id' => $enrollment->user_id,
                    'course_id' => $enrollment->course_id,
                    'certificate_number' => $certificateNumber,
                    'issued_at' => now(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Inscrição atualizada com sucesso',
            'enrollment' => $enrollment
        ]);
    }

    /**
     * Cancelar uma inscrição.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel($id)
    {
        // Encontrar a inscrição
        $enrollment = Enrollment::findOrFail($id);

        // Verificar permissão
        $user = auth()->user();
        $isAdmin = $user->is_admin;

        // Apenas o próprio usuário ou admin pode cancelar
        if ($user->id !== $enrollment->user_id && !$isAdmin) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Verificar se a inscrição já está cancelada ou expirada
        if (in_array($enrollment->status, ['canceled', 'expired'])) {
            return response()->json(['error' => 'Esta inscrição já está ' . $enrollment->status], 400);
        }

        // Verificar período de reembolso (por exemplo, 7 dias após a inscrição)
        $refundPeriod = now()->subDays(7);
        $isEligibleForRefund = $enrollment->created_at->gt($refundPeriod);

        // Atualizar status da inscrição
        $enrollment->status = 'canceled';
        $enrollment->save();

        // Verificar se há transação associada para possível reembolso
        $transaction = Transaction::where('user_id', $enrollment->user_id)
            ->where('course_id', $enrollment->course_id)
            ->where('transaction_id', $enrollment->transaction_id)
            ->where('status', 'completed')
            ->first();

        $refundProcessed = false;
        $refundAmount = 0;

        // Processar reembolso, se elegível
        if ($transaction && $isEligibleForRefund && $isAdmin) {
            // Aqui entraria a lógica de integração com o gateway de pagamento
            // para processar o reembolso automaticamente

            // Registrar o reembolso
            $refundId = 'REF-' . Str::random(10);

            $transaction->status = 'refunded';
            $transaction->refund_id = $refundId;
            $transaction->refund_reason = 'Solicitação do usuário';
            $transaction->save();

            $refundProcessed = true;
            $refundAmount = $transaction->amount;
        }

        return response()->json([
            'message' => 'Inscrição cancelada com sucesso',
            'refund_processed' => $refundProcessed,
            'refund_amount' => $refundAmount,
            'enrollment' => $enrollment
        ]);
    }

    /**
     * Listar cursos concluídos pelo usuário.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function completed()
    {
        $user = auth()->user();

        $completedCourses = Enrollment::with(['course' => function ($query) {
            $query->select('id', 'title', 'thumbnail', 'instructor_id');
            $query->with(['instructor:id,name']);
        }])
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->orderBy('completed_at', 'desc')
            ->get();

        // Adicionar informações do certificado para cada curso
        foreach ($completedCourses as $enrollment) {
            $certificate = Certificate::where('user_id', $user->id)
                ->where('course_id', $enrollment->course_id)
                ->first();

            $enrollment->certificate = $certificate;
        }

        return response()->json($completedCourses);
    }

    /**
     * Listar cursos em andamento pelo usuário.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function inProgress()
    {
        $user = auth()->user();

        $inProgressCourses = Enrollment::with(['course' => function ($query) {
            $query->select('id', 'title', 'thumbnail', 'instructor_id', 'duration_in_minutes');
            $query->with(['instructor:id,name']);
        }])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json($inProgressCourses);
    }

    /**
     * Verificar se o usuário está inscrito em um curso específico.
     *
     * @param  int  $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkEnrollment($courseId)
    {
        $user = auth()->user();

        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where(function ($query) {
                $query->where('status', 'active')
                    ->orWhere('status', 'completed');
            })
            ->first();

        $isEnrolled = (bool) $enrollment;

        $response = [
            'is_enrolled' => $isEnrolled,
            'enrollment' => $isEnrolled ? $enrollment : null,
        ];

        // Verificar se o curso é do próprio usuário
        if (!$isEnrolled) {
            $course = Course::find($courseId);
            $response['is_owner'] = $course && $course->instructor_id === $user->id;
        }

        return response()->json($response);
    }

    /**
     * Renovar uma inscrição expirada.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function renew(Request $request, $id)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
            'payment_method' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Encontrar a inscrição
        $enrollment = Enrollment::with('course')->findOrFail($id);

        // Verificar permissão
        $user = auth()->user();
        if ($user->id !== $enrollment->user_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Verificar se a inscrição está expirada ou cancelada
        if (!in_array($enrollment->status, ['expired', 'canceled'])) {
            return response()->json(['error' => 'Esta inscrição ainda está ativa ou já foi concluída'], 400);
        }

        // Obter o preço atual do curso
        $paidAmount = $enrollment->course->getCurrentPrice();

        // Atualizar a inscrição
        $enrollment->status = 'active';
        $enrollment->paid_amount = $paidAmount;
        $enrollment->transaction_id = $request->transaction_id;
        $enrollment->payment_method = $request->payment_method;
        $enrollment->expires_at = now()->addYear(); // Renova por mais um ano
        $enrollment->save();

        // Registrar transação financeira
        Transaction::create([
            'user_id' => $user->id,
            'course_id' => $enrollment->course_id,
            'transaction_id' => $request->transaction_id,
            'amount' => $paidAmount,
            'status' => 'completed',
            'payment_method' => $request->payment_method,
            'payment_gateway' => $request->payment_gateway ?? 'vindi',
            'paid_at' => now(),
        ]);

        return response()->json([
            'message' => 'Inscrição renovada com sucesso',
            'enrollment' => $enrollment
        ]);
    }

    /**
     * Obter relatório de progresso do usuário no curso.
     *
     * @param  int  $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function progress($courseId)
    {
        $user = auth()->user();

        // Verificar se o usuário está inscrito no curso
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        if (!$enrollment) {
            return response()->json(['error' => 'Você não está inscrito neste curso'], 403);
        }

        // Obter detalhes do curso com módulos e aulas
        $course = Course::with(['modules' => function ($query) {
            $query->orderBy('order');
        }, 'modules.lessons' => function ($query) {
            $query->orderBy('order');
        }])->findOrFail($courseId);

        // Contar total de aulas
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
                $isCompleted = $lesson->isCompletedByUser($user->id);
                $totalLessons++;

                if ($isCompleted) {
                    $completedLessons++;
                    $moduleProgress['completed_lessons']++;
                }

                $moduleProgress['lessons'][] = [
                    'lesson_id' => $lesson->id,
                    'lesson_title' => $lesson->title,
                    'is_completed' => $isCompleted
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
            }

            $enrollment->save();
            $progress['progress_percentage'] = $calculatedPercentage;
        }

        return response()->json($progress);
    }

    /**
     * Remover uma inscrição (apenas para administradores).
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        // Verificar permissão
        $user = auth()->user();
        if ($user->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Encontrar a inscrição
        $enrollment = Enrollment::findOrFail($id);

        // Remover a inscrição (soft delete)
        $enrollment->delete();

        return response()->json(['message' => 'Inscrição removida com sucesso']);
    }
}
