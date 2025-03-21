<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Question;
use App\Models\Rating;
use App\Models\StudentProgress;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
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
     * Obter estatísticas gerais para o painel de controle.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats(Request $request)
    {
        $user = auth()->user();

        // Verificar permissões
        if (!$user->is_admin && !$user->is_instructor) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Determinar período (último mês por padrão)
        $period = $request->get('period', 'month');
        $courseId = $request->get('course_id'); // Opcional, para instrutores

        $startDate = $this->getStartDate($period);

        // Estatísticas gerais
        $generalStats = [];

        // Para administradores, mostrar estatísticas globais
        if ($user->is_admin) {
            $generalStats = [
                'total_users' => User::count(),
                'total_courses' => Course::count(),
                'total_enrollments' => Enrollment::count(),
                'total_revenue' => Transaction::where('status', 'completed')->sum('amount'),

                'new_users' => User::where('created_at', '>=', $startDate)->count(),
                'new_enrollments' => Enrollment::where('created_at', '>=', $startDate)->count(),
                'revenue_period' => Transaction::where('status', 'completed')
                    ->where('paid_at', '>=', $startDate)
                    ->sum('amount'),

                'completed_courses' => Enrollment::where('status', 'completed')->count(),
                'avg_course_completion_rate' => $this->calculateCompletionRate(),
                'avg_rating' => Course::avg('average_rating'),
            ];
        }
        // Para instrutores, mostrar estatísticas dos seus cursos
        else {
            $courses = Course::where('instructor_id', $user->id);

            if ($courseId) {
                $courses->where('id', $courseId);
            }

            $courseIds = $courses->pluck('id')->toArray();

            $generalStats = [
                'total_courses' => $courses->count(),
                'total_enrollments' => Enrollment::whereIn('course_id', $courseIds)->count(),
                'total_revenue' => Transaction::whereIn('course_id', $courseIds)
                    ->where('status', 'completed')
                    ->sum('amount'),

                'new_enrollments' => Enrollment::whereIn('course_id', $courseIds)
                    ->where('created_at', '>=', $startDate)
                    ->count(),
                'revenue_period' => Transaction::whereIn('course_id', $courseIds)
                    ->where('status', 'completed')
                    ->where('paid_at', '>=', $startDate)
                    ->sum('amount'),

                'completed_courses' => Enrollment::whereIn('course_id', $courseIds)
                    ->where('status', 'completed')
                    ->count(),
                'avg_course_completion_rate' => $this->calculateCompletionRate($courseIds),
                'avg_rating' => Course::whereIn('id', $courseIds)->avg('average_rating'),
                'unanswered_questions' => Question::whereHas('lesson.module', function ($query) use ($courseIds) {
                    $query->whereIn('course_id', $courseIds);
                })
                    ->whereNull('answer')
                    ->count()
            ];
        }

        // Gráficos e tendências
        $charts = [
            'enrollments_trend' => $this->getEnrollmentsTrend($period, $courseId, $user),
            'revenue_trend' => $this->getRevenueTrend($period, $courseId, $user),
            'completion_rate_trend' => $this->getCompletionRateTrend($period, $courseId, $user),
        ];

        // Adicionar análise de engajamento para cursos específicos
        if ($courseId) {
            $charts['student_engagement'] = $this->getStudentEngagement($courseId);
        }

        return response()->json([
            'general' => $generalStats,
            'charts' => $charts
        ]);
    }

    /**
     * Obter atividades recentes para o painel de controle.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function recentActivity(Request $request)
    {
        $user = auth()->user();

        // Verificar permissões
        if (!$user->is_admin && !$user->is_instructor) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        $limit = $request->get('limit', 10);
        $courseId = $request->get('course_id'); // Opcional, para instrutores

        // Para administradores, mostrar atividades globais
        if ($user->is_admin) {
            $enrollmentsQuery = Enrollment::with(['user:id,name,profile_image', 'course:id,title'])
                ->orderBy('created_at', 'desc');

            $questionsQuery = Question::with(['user:id,name,profile_image', 'lesson:id,title,module_id'])
                ->whereNull('answer')
                ->orderBy('created_at', 'desc');

            $ratingsQuery = Rating::with(['user:id,name,profile_image', 'course:id,title'])
                ->orderBy('created_at', 'desc');

            // Filtrar por curso, se especificado
            if ($courseId) {
                $enrollmentsQuery->where('course_id', $courseId);
                $questionsQuery->whereHas('lesson.module', function ($query) use ($courseId) {
                    $query->where('course_id', $courseId);
                });
                $ratingsQuery->where('course_id', $courseId);
            }
        }
        // Para instrutores, mostrar atividades dos seus cursos
        else {
            $courses = Course::where('instructor_id', $user->id);

            if ($courseId) {
                $courses->where('id', $courseId);
            }

            $courseIds = $courses->pluck('id')->toArray();

            $enrollmentsQuery = Enrollment::with(['user:id,name,profile_image', 'course:id,title'])
                ->whereIn('course_id', $courseIds)
                ->orderBy('created_at', 'desc');

            $questionsQuery = Question::with(['user:id,name,profile_image', 'lesson:id,title,module_id'])
                ->whereHas('lesson.module', function ($query) use ($courseIds) {
                    $query->whereIn('course_id', $courseIds);
                })
                ->whereNull('answer')
                ->orderBy('created_at', 'desc');

            $ratingsQuery = Rating::with(['user:id,name,profile_image', 'course:id,title'])
                ->whereIn('course_id', $courseIds)
                ->orderBy('created_at', 'desc');
        }

        // Limitar as consultas
        $enrollments = $enrollmentsQuery->limit($limit)->get();
        $questions = $questionsQuery->limit($limit)->get();
        $ratings = $ratingsQuery->limit($limit)->get();

        // Formatar as atividades
        $activities = [];

        foreach ($enrollments as $enrollment) {
            $activities[] = [
                'type' => 'enrollment',
                'id' => $enrollment->id,
                'user' => [
                    'id' => $enrollment->user->id,
                    'name' => $enrollment->user->name,
                    'profile_image' => $enrollment->user->profile_image
                ],
                'course' => [
                    'id' => $enrollment->course->id,
                    'title' => $enrollment->course->title
                ],
                'message' => 'inscreveu-se no curso',
                'date' => $enrollment->created_at->format('Y-m-d H:i:s')
            ];
        }

        foreach ($questions as $question) {
            $activities[] = [
                'type' => 'question',
                'id' => $question->id,
                'user' => [
                    'id' => $question->user->id,
                    'name' => $question->user->name,
                    'profile_image' => $question->user->profile_image
                ],
                'lesson' => [
                    'id' => $question->lesson->id,
                    'title' => $question->lesson->title
                ],
                'message' => 'fez uma pergunta',
                'date' => $question->created_at->format('Y-m-d H:i:s')
            ];
        }

        foreach ($ratings as $rating) {
            $activities[] = [
                'type' => 'rating',
                'id' => $rating->id,
                'user' => [
                    'id' => $rating->user->id,
                    'name' => $rating->user->name,
                    'profile_image' => $rating->user->profile_image
                ],
                'course' => [
                    'id' => $rating->course->id,
                    'title' => $rating->course->title
                ],
                'rating' => $rating->rating,
                'message' => 'avaliou o curso',
                'date' => $rating->created_at->format('Y-m-d H:i:s')
            ];
        }

        // Ordenar por data decrescente
        usort($activities, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // Limitar o total de atividades
        $activities = array_slice($activities, 0, $limit);

        return response()->json($activities);
    }

    /**
     * Obter estatísticas de uso da IA.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function aiMetrics(Request $request)
    {
        $user = auth()->user();

        // Verificar permissões
        if (!$user->is_admin) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Estatísticas gerais
        $totalInteractions = AiInteraction::count();
        $totalUsers = AiInteraction::distinct('user_id')->count('user_id');
        $totalTokensUsed = AiInteraction::sum('tokens_used');

        // Feedback
        $withFeedback = AiInteraction::whereNotNull('was_helpful')->count();
        $helpfulCount = AiInteraction::where('was_helpful', true)->count();
        $helpfulPercentage = $withFeedback > 0 ? round(($helpfulCount / $withFeedback) * 100, 2) : 0;

        // Top perguntas e tópicos
        $topQueries = AiInteraction::select('query', DB::raw('count(*) as count'))
            ->groupBy('query')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();

        // Uso por curso
        $courseUsage = AiInteraction::select('lesson_id', DB::raw('count(*) as count'))
            ->whereNotNull('lesson_id')
            ->groupBy('lesson_id')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->with('lesson:id,title,module_id')
            ->with('lesson.module:id,title,course_id')
            ->with('lesson.module.course:id,title')
            ->get();

        // Tendência de uso diário
        $usageTrend = AiInteraction::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as count'),
            DB::raw('sum(tokens_used) as tokens_used')
        )
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'total_interactions' => $totalInteractions,
            'total_users' => $totalUsers,
            'total_tokens_used' => $totalTokensUsed,
            'helpful_percentage' => $helpfulPercentage,
            'feedback_rate' => $withFeedback > 0 ? round(($withFeedback / $totalInteractions) * 100, 2) : 0,
            'top_queries' => $topQueries,
            'course_usage' => $courseUsage,
            'usage_trend' => $usageTrend
        ]);
    }

    /**
     * Obter relatório de receitas.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function revenueReport(Request $request)
    {
        $user = auth()->user();

        // Verificar permissões
        if (!$user->is_admin && !$user->is_instructor) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Determinar período
        $period = $request->get('period', 'year');
        $courseId = $request->get('course_id'); // Opcional

        // Limitar a visualização de instrutores aos seus próprios cursos
        if (!$user->is_admin && $courseId) {
            $course = Course::find($courseId);
            if (!$course || $course->instructor_id !== $user->id) {
                return response()->json(['error' => 'Acesso não autorizado a este curso'], 403);
            }
        }

        // Data inicial com base no período
        $startDate = $this->getStartDate($period);

        // Consulta base
        $query = Transaction::where('status', 'completed')
            ->where('paid_at', '>=', $startDate);

        // Filtrar por curso
        if ($courseId) {
            $query->where('course_id', $courseId);
        }
        // Filtrar por instrutor, se aplicável
        elseif (!$user->is_admin) {
            $courseIds = Course::where('instructor_id', $user->id)->pluck('id');
            $query->whereIn('course_id', $courseIds);
        }

        // Agrupar por data
        $groupBy = '';
        switch ($period) {
            case 'week':
                $groupBy = 'DATE(paid_at)';
                break;
            case 'month':
                $groupBy = 'DATE(paid_at)';
                break;
            case 'year':
                $groupBy = 'MONTH(paid_at)';
                break;
            default:
                $groupBy = 'DATE(paid_at)';
        }

        // Obter dados de receita
        $revenueData = $query->select(
            DB::raw($groupBy . ' as date'),
            DB::raw('SUM(amount) as revenue'),
            DB::raw('COUNT(*) as transactions')
        )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Formatar saída com base no período
        $formattedData = [];

        if ($period === 'year') {
            // Converter números de mês para nomes
            foreach ($revenueData as $item) {
                $monthName = Carbon::createFromDate(null, $item->date, 1)->format('F');
                $formattedData[] = [
                    'date' => $monthName,
                    'revenue' => $item->revenue,
                    'transactions' => $item->transactions
                ];
            }
        } else {
            // Usar o formato de data normal
            foreach ($revenueData as $item) {
                $formattedData[] = [
                    'date' => $item->date,
                    'revenue' => $item->revenue,
                    'transactions' => $item->transactions
                ];
            }
        }

        // Estatísticas de resumo
        $totalRevenue = $query->sum('amount');
        $totalTransactions = $query->count();
        $averageValue = $totalTransactions > 0 ? $totalRevenue / $totalTransactions : 0;

        // Métodos de pagamento
        $paymentMethods = $query->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as revenue'))
            ->groupBy('payment_method')
            ->get();

        return response()->json([
            'total_revenue' => $totalRevenue,
            'total_transactions' => $totalTransactions,
            'average_transaction_value' => round($averageValue, 2),
            'payment_methods' => $paymentMethods,
            'revenue_data' => $formattedData
        ]);
    }

    /**
     * Obter data inicial com base no período.
     *
     * @param  string  $period
     * @return \Carbon\Carbon
     */
    private function getStartDate($period)
    {
        switch ($period) {
            case 'week':
                return Carbon::now()->subWeek();
            case 'month':
                return Carbon::now()->subMonth();
            case 'year':
                return Carbon::now()->subYear();
            case 'quarter':
                return Carbon::now()->subMonths(3);
            default:
                return Carbon::now()->subMonth();
        }
    }

    /**
     * Calcular a taxa média de conclusão de cursos.
     *
     * @param  array|null  $courseIds
     * @return float
     */
    private function calculateCompletionRate($courseIds = null)
    {
        $query = Enrollment::where('status', 'active')
            ->orWhere('status', 'completed');

        if ($courseIds) {
            $query->whereIn('course_id', $courseIds);
        }

        $totalEnrollments = $query->count();

        if ($totalEnrollments === 0) {
            return 0;
        }

        $totalProgress = $query->sum('progress_percentage');

        return round($totalProgress / $totalEnrollments, 2);
    }

    /**
     * Obter tendência de inscrições por período.
     *
     * @param  string  $period
     * @param  int|null  $courseId
     * @param  \App\Models\User  $user
     * @return array
     */
    private function getEnrollmentsTrend($period, $courseId = null, $user)
    {
        $startDate = $this->getStartDate($period);

        // Consulta base
        $query = Enrollment::where('created_at', '>=', $startDate);

        // Filtrar por curso específico
        if ($courseId) {
            $query->where('course_id', $courseId);
        }
        // Limitar a cursos do instrutor
        elseif (!$user->is_admin) {
            $courseIds = Course::where('instructor_id', $user->id)->pluck('id');
            $query->whereIn('course_id', $courseIds);
        }

        // Agrupar por data
        $groupBy = '';
        $dateFormat = '';

        switch ($period) {
            case 'week':
                $groupBy = 'DATE(created_at)';
                $dateFormat = 'Y-m-d';
                break;
            case 'month':
                $groupBy = 'DATE(created_at)';
                $dateFormat = 'Y-m-d';
                break;
            case 'year':
                $groupBy = 'MONTH(created_at)';
                $dateFormat = 'F'; // Nome do mês
                break;
            default:
                $groupBy = 'DATE(created_at)';
                $dateFormat = 'Y-m-d';
        }

        $enrollmentData = $query->select(
            DB::raw($groupBy . ' as date'),
            DB::raw('COUNT(*) as count')
        )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Formatar saída
        $result = [];

        if ($period === 'year') {
            // Converter números de mês para nomes
            foreach ($enrollmentData as $item) {
                $monthName = Carbon::createFromDate(null, $item->date, 1)->format('F');
                $result[] = [
                    'date' => $monthName,
                    'count' => $item->count
                ];
            }
        } else {
            foreach ($enrollmentData as $item) {
                $result[] = [
                    'date' => $item->date,
                    'count' => $item->count
                ];
            }
        }

        return $result;
    }

    /**
     * Obter tendência de receita por período.
     *
     * @param  string  $period
     * @param  int|null  $courseId
     * @param  \App\Models\User  $user
     * @return array
     */
    private function getRevenueTrend($period, $courseId = null, $user)
    {
        $startDate = $this->getStartDate($period);

        // Consulta base
        $query = Transaction::where('status', 'completed')
            ->where('paid_at', '>=', $startDate);

        // Filtrar por curso específico
        if ($courseId) {
            $query->where('course_id', $courseId);
        }
        // Limitar a cursos do instrutor
        elseif (!$user->is_admin) {
            $courseIds = Course::where('instructor_id', $user->id)->pluck('id');
            $query->whereIn('course_id', $courseIds);
        }

        // Agrupar por data
        $groupBy = '';

        switch ($period) {
            case 'week':
                $groupBy = 'DATE(paid_at)';
                break;
            case 'month':
                $groupBy = 'DATE(paid_at)';
                break;
            case 'year':
                $groupBy = 'MONTH(paid_at)';
                break;
            default:
                $groupBy = 'DATE(paid_at)';
        }

        $revenueData = $query->select(
            DB::raw($groupBy . ' as date'),
            DB::raw('SUM(amount) as revenue')
        )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Formatar saída
        $result = [];

        if ($period === 'year') {
            // Converter números de mês para nomes
            foreach ($revenueData as $item) {
                $monthName = Carbon::createFromDate(null, $item->date, 1)->format('F');
                $result[] = [
                    'date' => $monthName,
                    'revenue' => $item->revenue
                ];
            }
        } else {
            foreach ($revenueData as $item) {
                $result[] = [
                    'date' => $item->date,
                    'revenue' => $item->revenue
                ];
            }
        }

        return $result;
    }

    /**
     * Obter tendência de taxa de conclusão por período.
     *
     * @param  string  $period
     * @param  int|null  $courseId
     * @param  \App\Models\User  $user
     * @return array
     */
    private function getCompletionRateTrend($period, $courseId = null, $user)
    {
        $startDate = $this->getStartDate($period);

        // Consulta base para inscrições completadas
        $query = Enrollment::where('status', 'completed')
            ->where('completed_at', '>=', $startDate);

        // Filtrar por curso específico
        if ($courseId) {
            $query->where('course_id', $courseId);
        }
        // Limitar a cursos do instrutor
        elseif (!$user->is_admin) {
            $courseIds = Course::where('instructor_id', $user->id)->pluck('id');
            $query->whereIn('course_id', $courseIds);
        }

        // Agrupar por data
        $groupBy = '';

        switch ($period) {
            case 'week':
                $groupBy = 'DATE(completed_at)';
                break;
            case 'month':
                $groupBy = 'DATE(completed_at)';
                break;
            case 'year':
                $groupBy = 'MONTH(completed_at)';
                break;
            default:
                $groupBy = 'DATE(completed_at)';
        }

        $completionData = $query->select(
            DB::raw($groupBy . ' as date'),
            DB::raw('COUNT(*) as count')
        )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Formatar saída
        $result = [];

        if ($period === 'year') {
            // Converter números de mês para nomes
            foreach ($completionData as $item) {
                $monthName = Carbon::createFromDate(null, $item->date, 1)->format('F');
                $result[] = [
                    'date' => $monthName,
                    'completed' => $item->count
                ];
            }
        } else {
            foreach ($completionData as $item) {
                $result[] = [
                    'date' => $item->date,
                    'completed' => $item->count
                ];
            }
        }

        return $result;
    }

    /**
     * Obter análise de engajamento dos alunos para um curso específico.
     *
     * @param  int  $courseId
     * @return array
     */
    private function getStudentEngagement($courseId)
    {
        // Verificar se o curso existe
        $course = Course::findOrFail($courseId);

        // Obter todos os IDs de aulas deste curso
        $lessonIds = DB::table('lessons')
            ->join('modules', 'lessons.module_id', '=', 'modules.id')
            ->where('modules.course_id', $courseId)
            ->pluck('lessons.id');

        // Engajamento por aula (quais aulas são mais assistidas)
        $lessonEngagement = StudentProgress::whereIn('lesson_id', $lessonIds)
            ->select('lesson_id', DB::raw('COUNT(*) as views'), DB::raw('SUM(is_completed) as completions'))
            ->groupBy('lesson_id')
            ->orderBy('views', 'desc')
            ->with('lesson:id,title,module_id')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'lesson_id' => $item->lesson_id,
                    'lesson_title' => $item->lesson->title,
                    'views' => $item->views,
                    'completions' => $item->completions,
                    'completion_rate' => $item->views > 0 ? round(($item->completions / $item->views) * 100, 2) : 0
                ];
            });

        // Tempo médio para conclusão do curso
        $averageCompletionDays = Enrollment::where('course_id', $courseId)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->select(DB::raw('AVG(DATEDIFF(completed_at, created_at)) as avg_days'))
            ->first()
            ->avg_days ?? 0;

        // Taxas de desistência
        $totalEnrollments = Enrollment::where('course_id', $courseId)->count();
        $canceledEnrollments = Enrollment::where('course_id', $courseId)
            ->where('status', 'canceled')
            ->count();
        $dropoutRate = $totalEnrollments > 0 ? round(($canceledEnrollments / $totalEnrollments) * 100, 2) : 0;

        // Análise de progresso
        $progressDistribution = Enrollment::where('course_id', $courseId)
            ->where('status', 'active')
            ->select(
                DB::raw('CASE
                                                WHEN progress_percentage < 10 THEN "0-10%"
                                                WHEN progress_percentage < 25 THEN "10-25%"
                                                WHEN progress_percentage < 50 THEN "25-50%"
                                                WHEN progress_percentage < 75 THEN "50-75%"
                                                WHEN progress_percentage < 100 THEN "75-100%"
                                                ELSE "100%"
                                            END as progress_range'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('progress_range')
            ->get();

        return [
            'lesson_engagement' => $lessonEngagement,
            'average_completion_days' => round($averageCompletionDays, 1),
            'dropout_rate' => $dropoutRate,
            'progress_distribution' => $progressDistribution
        ];
    }
}
