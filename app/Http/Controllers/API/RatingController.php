<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RatingController extends Controller
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
     * Listar as avaliações de um curso específico.
     *
     * @param  int  $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function courseRatings($courseId)
    {
        // Verificar se o curso existe
        $course = Course::findOrFail($courseId);

        // Buscar avaliações aprovadas
        $ratings = Rating::with('user:id,name,profile_image')
            ->where('course_id', $courseId)
            ->where('is_approved', true)
            ->orderBy('created_at', 'desc')
            ->get();

        // Calcular estatísticas de avaliação
        $stats = [
            'average' => $course->average_rating,
            'count' => $course->ratings_count,
            'distribution' => $this->calculateRatingDistribution($courseId)
        ];

        return response()->json([
            'ratings' => $ratings,
            'stats' => $stats
        ]);
    }

    /**
     * Armazenar uma nova avaliação.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, $courseId)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        // Verificar se o usuário está inscrito e completou pelo menos 20% do curso
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'error' => 'Você precisa estar inscrito neste curso para avaliá-lo'
            ], 403);
        }

        if ($enrollment->progress_percentage < 20) {
            return response()->json([
                'error' => 'Você precisa completar pelo menos 20% do curso para avaliá-lo',
                'current_progress' => $enrollment->progress_percentage . '%'
            ], 403);
        }

        // Verificar se o usuário já avaliou este curso
        $existingRating = Rating::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        if ($existingRating) {
            // Atualizar avaliação existente
            $existingRating->rating = $request->rating;
            $existingRating->review = $request->review;
            $existingRating->save();

            // Recalcular a média de avaliações do curso
            $this->updateCourseRating($courseId);

            return response()->json([
                'message' => 'Avaliação atualizada com sucesso',
                'rating' => $existingRating
            ]);
        }

        // Criar nova avaliação
        $rating = Rating::create([
            'user_id' => $user->id,
            'course_id' => $courseId,
            'rating' => $request->rating,
            'review' => $request->review,
            'is_approved' => true, // Auto-aprovação (pode ser alterada em um fluxo real)
        ]);

        // Carregar o usuário para a resposta
        $rating->load('user:id,name,profile_image');

        // Recalcular a média de avaliações do curso
        $this->updateCourseRating($courseId);

        // Notificar o instrutor sobre a nova avaliação (em um sistema real)
        // $course = Course::find($courseId);
        // Notification::send($course->instructor, new NewRatingNotification($rating));

        return response()->json([
            'message' => 'Avaliação enviada com sucesso',
            'rating' => $rating
        ], 201);
    }

    /**
     * Exibir uma avaliação específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $rating = Rating::with(['user:id,name,profile_image', 'course:id,title,instructor_id'])
            ->findOrFail($id);

        $user = auth()->user();

        // Verificar permissões
        $isAdmin = $user->is_admin;
        $isInstructor = $rating->course && $user->id === $rating->course->instructor_id;
        $isOwner = $user->id === $rating->user_id;

        // Se a avaliação não está aprovada e o usuário não tem permissão
        if (!$rating->is_approved && !$isAdmin && !$isInstructor && !$isOwner) {
            return response()->json(['error' => 'Avaliação não encontrada'], 404);
        }

        return response()->json($rating);
    }

    /**
     * Atualizar uma avaliação específica.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'rating' => 'integer|min:1|max:5',
            'review' => 'nullable|string|max:1000',
            'is_approved' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();
        $rating = Rating::with('course')->findOrFail($id);

        // Verificar permissões
        $isAdmin = $user->is_admin;
        $isInstructor = $rating->course && $user->id === $rating->course->instructor_id;
        $isOwner = $user->id === $rating->user_id;

        // Apenas o dono pode editar a avaliação
        if ($request->has('rating') || $request->has('review')) {
            if (!$isOwner) {
                return response()->json([
                    'error' => 'Apenas o autor pode editar a avaliação'
                ], 403);
            }
        }

        // Apenas admin ou instrutor podem aprovar/rejeitar
        if ($request->has('is_approved') && !$isAdmin && !$isInstructor) {
            return response()->json([
                'error' => 'Apenas administradores ou instrutores podem aprovar avaliações'
            ], 403);
        }

        // Preparar dados para atualização
        $updateData = [];

        if ($request->has('rating') && $isOwner) {
            $updateData['rating'] = $request->rating;
        }

        if ($request->has('review')) {
            $updateData['review'] = $request->review;
        }

        if ($request->has('is_approved') && ($isAdmin || $isInstructor)) {
            $updateData['is_approved'] = $request->is_approved;
        }

        // Atualizar a avaliação
        Rating::where('id', $id)->update($updateData);

        // Recalcular a média de avaliações do curso
        $this->updateCourseRating($rating->course_id);

        // Recarregar a avaliação para obter os dados atualizados
        $rating = Rating::with(['user:id,name,profile_image', 'course:id,title,instructor_id'])
            ->findOrFail($id);

        return response()->json([
            'message' => 'Avaliação atualizada com sucesso',
            'rating' => $rating
        ]);
    }

    /**
     * Remover uma avaliação específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $user = auth()->user();
        $rating = Rating::findOrFail($id);

        // Verificar permissões
        $isAdmin = $user->is_admin;
        $isOwner = $user->id === $rating->user_id;

        if (!$isOwner && !$isAdmin) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Armazenar o course_id antes de remover
        $courseId = $rating->course_id;

        // Remover a avaliação
        $rating->delete();

        // Recalcular a média de avaliações do curso
        $this->updateCourseRating($courseId);

        return response()->json(['message' => 'Avaliação removida com sucesso']);
    }

    /**
     * Listar avaliações pendentes de aprovação (para admins e instrutores).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function pendingRatings(Request $request)
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
        $query = Rating::where('is_approved', false)
            ->with(['user:id,name,profile_image', 'course:id,title,instructor_id']);

        // Filtrar por cursos do instrutor
        if (!$user->is_admin) {
            $query->whereHas('course', function ($q) use ($user) {
                $q->where('instructor_id', $user->id);
            });
        }

        // Filtrar por curso específico
        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        // Ordenar e paginar
        $ratings = $query->orderBy('created_at', 'asc')->paginate($perPage);

        return response()->json($ratings);
    }

    /**
     * Listar avaliações feitas pelo usuário autenticado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function myRatings()
    {
        $user = auth()->user();

        $ratings = Rating::with('course:id,title,thumbnail')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($ratings);
    }

    /**
     * Calcular a distribuição de avaliações por nota (1-5).
     *
     * @param  int  $courseId
     * @return array
     */
    private function calculateRatingDistribution($courseId)
    {
        $distribution = [];

        for ($i = 5; $i >= 1; $i--) {
            $count = Rating::where('course_id', $courseId)
                ->where('rating', $i)
                ->where('is_approved', true)
                ->count();

            $distribution[$i] = $count;
        }

        return $distribution;
    }

    /**
     * Atualizar a média de avaliações do curso.
     *
     * @param  int  $courseId
     * @return void
     */
    private function updateCourseRating($courseId)
    {
        $ratings = Rating::where('course_id', $courseId)
            ->where('is_approved', true)
            ->get();

        $course = Course::find($courseId);

        if ($ratings->isEmpty()) {
            $course->average_rating = 0;
            $course->ratings_count = 0;
        } else {
            $course->average_rating = round($ratings->avg('rating'), 2);
            $course->ratings_count = $ratings->count();
        }

        $course->save();
    }
}
