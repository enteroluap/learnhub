<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Category;
use App\Models\Module;
use App\Models\Lesson;
use App\Models\Enrollment;
use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class CourseController extends Controller
{
    /**
     * Criar uma nova instância de controller.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api')->except(['index', 'show']);
    }

    /**
     * Listar todos os cursos disponíveis.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Parâmetros de paginação e filtragem
        $perPage = $request->get('per_page', 12);
        $search = $request->get('search', '');
        $categoryId = $request->get('category_id');
        $level = $request->get('level');
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $featured = $request->has('featured') ? $request->get('featured') : null;
        $priceMin = $request->get('price_min');
        $priceMax = $request->get('price_max');
        $includeUnpublished = $request->get('include_unpublished', false) && auth()->check() && auth()->user()->is_admin == true;

        // Consulta base
        $query = Course::query();

        // Incluir relacionamentos
        $query->with(['instructor:id,name,profile_image', 'category:id,name,slug']);

        // Aplicar filtros de busca pelo título ou descrição
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        // Filtrar por categoria
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        // Filtrar por nível
        if ($level) {
            $query->where('level', $level);
        }

        // Filtrar por destaque
        if ($featured !== null) {
            $query->where('is_featured', (bool) $featured);
        }

        // Filtrar por faixa de preço
        if ($priceMin !== null) {
            $query->where('price', '>=', (float) $priceMin);
        }
        if ($priceMax !== null) {
            $query->where('price', '<=', (float) $priceMax);
        }

        // Filtrar cursos publicados (a menos que explicitamente solicitado)
        if (!$includeUnpublished) {
            $query->where('is_published', true);
        }

        // Ordenação
        if ($sortBy === 'price') {
            $query->orderBy('price', $sortOrder);
        } elseif ($sortBy === 'rating') {
            $query->orderBy('average_rating', $sortOrder);
        } elseif ($sortBy === 'popularity') {
            $query->orderBy('students_count', $sortOrder);
        } elseif ($sortBy === 'title') {
            $query->orderBy('title', $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        // Obter resultados paginados
        $courses = $query->paginate($perPage);

        // Para usuários autenticados, adicionar campo indicando se estão inscritos
        if (auth()->check()) {
            $userId = auth()->id();

            // Extrair IDs dos cursos para verificar inscrições
            $courseIds = [];
            foreach ($courses as $course) {
                $courseIds[] = $course->id;
            }

            // Obter IDs dos cursos em que o usuário está inscrito
            $enrolledCourseIds = [];
            $enrollments = Enrollment::where('user_id', $userId)
                ->whereIn('course_id', $courseIds)
                ->get();

            foreach ($enrollments as $enrollment) {
                $enrolledCourseIds[] = $enrollment->course_id;
            }

            // Adicionar campo is_enrolled para cada curso
            foreach ($courses as $course) {
                $course->is_enrolled = in_array($course->id, $enrolledCourseIds);
            }
        }

        return response()->json($courses);
    }

    /**
     * Armazenar um novo curso.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Verificar permissão
        $user = auth()->user();

        // ALTERAÇÃO: Verificação direta dos atributos is_admin e is_instructor em vez de chamar métodos
        if ($user->is_admin != true && $user->is_instructor != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'short_description' => 'nullable|string|max:500',
            'requirements' => 'nullable|string',
            'what_will_learn' => 'nullable|string',
            'category_id' => 'required|exists:categories,id',
            'thumbnail' => 'nullable|image|max:2048', // Max 2MB
            'cover_image' => 'nullable|image|max:5120', // Max 5MB
            'promotional_video_url' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0|lt:price',
            'discount_ends_at' => 'nullable|date|after:now',
            'level' => 'required|in:beginner,intermediate,advanced,all-levels',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Gerar slug único
        $slug = Str::slug($request->title);
        $count = 1;

        while (Course::where('slug', $slug)->exists()) {
            $slug = Str::slug($request->title) . '-' . $count;
            $count++;
        }

        // Configurar o curso
        $courseData = [
            'title' => $request->title,
            'slug' => $slug,
            'description' => $request->description,
            'short_description' => $request->short_description,
            'requirements' => $request->requirements,
            'what_will_learn' => $request->what_will_learn,
            'instructor_id' => $user->id,
            'category_id' => $request->category_id,
            'promotional_video_url' => $request->promotional_video_url,
            'price' => $request->price,
            'discount_price' => $request->discount_price,
            'discount_ends_at' => $request->discount_ends_at,
            'level' => $request->level,
            // ALTERAÇÃO: Verificação direta do atributo is_admin em vez de chamar isAdmin()
            'is_featured' => $user->is_admin == true ? ($request->is_featured ?? false) : false, // Somente admins podem destacar cursos
            'is_published' => $request->is_published ?? false,
        ];

        // Processar upload de imagem de thumbnail, se houver
        if ($request->hasFile('thumbnail') && $request->file('thumbnail')->isValid()) {
            $thumbnail = $request->file('thumbnail');
            $thumbnailName = 'thumbnail_' . time() . '_' . Str::random(10) . '.' . $thumbnail->getClientOriginalExtension();
            $thumbPath = 'courses/thumbnails';

            // Redimensionar e salvar a imagem
            $manager = new ImageManager(new Driver());
            $image = $manager->read($thumbnail);
            $image->cover(640, 360); // 16:9 aspect ratio

            Storage::disk('public')->put($thumbPath . '/' . $thumbnailName, $image->toJpeg());
            $courseData['thumbnail'] = $thumbPath . '/' . $thumbnailName;
        }

        // Processar upload de imagem de capa, se houver
        if ($request->hasFile('cover_image') && $request->file('cover_image')->isValid()) {
            $coverImage = $request->file('cover_image');
            $coverName = 'cover_' . time() . '_' . Str::random(10) . '.' . $coverImage->getClientOriginalExtension();
            $coverPath = 'courses/covers';

            // Redimensionar e salvar a imagem
            $manager = new ImageManager(new Driver());
            $image = $manager->read($coverImage);
            $image->cover(1280, 720); // 16:9 aspect ratio

            Storage::disk('public')->put($coverPath . '/' . $coverName, $image->toJpeg());
            $courseData['cover_image'] = $coverPath . '/' . $coverName;
        }

        // Criar o curso
        $course = Course::create($courseData);

        return response()->json([
            'message' => 'Curso criado com sucesso',
            'course' => $course
        ], 201);
    }

    /**
     * Exibir um curso específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        // Carregar curso com relacionamentos
        $course = Course::with([
            'instructor:id,name,profile_image,bio',
            'category:id,name,slug',
            'modules' => function ($query) {
                $query->orderBy('order');
            },
            'modules.lessons' => function ($query) {
                $query->orderBy('order');
            }
        ])->findOrFail($id);

        // Verificar se curso está publicado para usuários não autorizados
        if (!$course->is_published) {
            $user = auth()->user();

            // ALTERAÇÃO: Verificação direta do atributo is_admin em vez de chamar isAdmin()
            if (!$user || ($user->is_admin != true && $user->id !== $course->instructor_id)) {
                return response()->json(['error' => 'Curso não encontrado'], 404);
            }
        }

        // Para usuários autenticados, adicionar informações de progresso
        if (auth()->check()) {
            $userId = auth()->id();

            // Verificar se o usuário está inscrito no curso
            $enrollment = Enrollment::where('user_id', $userId)
                ->where('course_id', $id)
                ->first();

            $course->is_enrolled = $enrollment ? true : false;
            $course->progress_percentage = $enrollment ? $enrollment->progress_percentage : 0;

            // Para cada aula, verificar se já foi concluída pelo usuário
            foreach ($course->modules as $module) {
                foreach ($module->lessons as $lesson) {
                    $lesson->is_completed = $lesson->isCompletedByUser($userId);
                }
            }

            // Verificar se o usuário já avaliou o curso
            $rating = Rating::where('user_id', $userId)
                ->where('course_id', $id)
                ->first();

            $course->user_rating = $rating ? $rating->rating : null;
            $course->user_review = $rating ? $rating->review : null;
        }

        // ALTERAÇÃO: Usar Query Builder para incrementar visualizações em vez de chamar save()
        Course::where('id', $course->id)->increment('views_count');

        return response()->json($course);
    }

    /**
     * Atualizar um curso específico.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $course = Course::findOrFail($id);

        // Verificar permissão
        $user = auth()->user();

        // ALTERAÇÃO: Verificação direta do atributo is_admin em vez de chamar isAdmin()
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'title' => 'string|max:255',
            'description' => 'string',
            'short_description' => 'nullable|string|max:500',
            'requirements' => 'nullable|string',
            'what_will_learn' => 'nullable|string',
            'category_id' => 'exists:categories,id',
            'thumbnail' => 'nullable|image|max:2048', // Max 2MB
            'cover_image' => 'nullable|image|max:5120', // Max 5MB
            'promotional_video_url' => 'nullable|string|max:255',
            'price' => 'numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0|lt:price',
            'discount_ends_at' => 'nullable|date|after:now',
            'level' => 'in:beginner,intermediate,advanced,all-levels',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Atualizar slug se o título for alterado
        if ($request->has('title') && $request->title !== $course->title) {
            $slug = Str::slug($request->title);
            $count = 1;

            while (Course::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = Str::slug($request->title) . '-' . $count;
                $count++;
            }

            $course->slug = $slug;
        }

        // ALTERAÇÃO: Usar array de dados para atualização em vez de chamar save()
        $updateData = [];

        if ($request->has('title')) {
            $updateData['title'] = $request->title;
        }
        if ($request->has('description')) {
            $updateData['description'] = $request->description;
        }
        if ($request->has('short_description')) {
            $updateData['short_description'] = $request->short_description;
        }
        if ($request->has('requirements')) {
            $updateData['requirements'] = $request->requirements;
        }
        if ($request->has('what_will_learn')) {
            $updateData['what_will_learn'] = $request->what_will_learn;
        }
        if ($request->has('category_id')) {
            $updateData['category_id'] = $request->category_id;
        }
        if ($request->has('promotional_video_url')) {
            $updateData['promotional_video_url'] = $request->promotional_video_url;
        }
        if ($request->has('price')) {
            $updateData['price'] = $request->price;
        }
        if ($request->has('discount_price')) {
            $updateData['discount_price'] = $request->discount_price;
        }
        if ($request->has('discount_ends_at')) {
            $updateData['discount_ends_at'] = $request->discount_ends_at;
        }
        if ($request->has('level')) {
            $updateData['level'] = $request->level;
        }

        // ALTERAÇÃO: Verificação direta do atributo is_admin em vez de chamar isAdmin()
        // Somente admins podem alterar o destaque
        if ($user->is_admin == true && $request->has('is_featured')) {
            $updateData['is_featured'] = $request->is_featured;
        }

        // O instrutor ou admin pode alterar o status de publicação
        if ($request->has('is_published')) {
            $updateData['is_published'] = $request->is_published;
        }

        // Processar upload de imagem de thumbnail, se houver
        if ($request->hasFile('thumbnail') && $request->file('thumbnail')->isValid()) {
            // Remover a imagem antiga, se existir
            if ($course->thumbnail) {
                Storage::disk('public')->delete($course->thumbnail);
            }

            $thumbnail = $request->file('thumbnail');
            $thumbnailName = 'thumbnail_' . time() . '_' . Str::random(10) . '.' . $thumbnail->getClientOriginalExtension();
            $thumbPath = 'courses/thumbnails';

            // Redimensionar e salvar a imagem
            $manager = new ImageManager(new Driver());
            $image = $manager->read($thumbnail);
            $image->cover(640, 360); // 16:9 aspect ratio

            Storage::disk('public')->put($thumbPath . '/' . $thumbnailName, $image->toJpeg());
            $updateData['thumbnail'] = $thumbPath . '/' . $thumbnailName;
        }

        // Processar upload de imagem de capa, se houver
        if ($request->hasFile('cover_image') && $request->file('cover_image')->isValid()) {
            // Remover a imagem antiga, se existir
            if ($course->cover_image) {
                Storage::disk('public')->delete($course->cover_image);
            }

            $coverImage = $request->file('cover_image');
            $coverName = 'cover_' . time() . '_' . Str::random(10) . '.' . $coverImage->getClientOriginalExtension();
            $coverPath = 'courses/covers';

            // Redimensionar e salvar a imagem
            $manager = new ImageManager(new Driver());
            $image = $manager->read($coverImage);
            $image->cover(1280, 720); // 16:9 aspect ratio

            Storage::disk('public')->put($coverPath . '/' . $coverName, $image->toJpeg());
            $updateData['cover_image'] = $coverPath . '/' . $coverName;
        }

        // ALTERAÇÃO: Usar Query Builder para atualizar em vez de chamar save()
        Course::where('id', $id)->update($updateData);

        // Recarregar o curso para obter os dados atualizados
        $course = Course::findOrFail($id);

        return response()->json([
            'message' => 'Curso atualizado com sucesso',
            'course' => $course
        ]);
    }

    /**
     * Remover um curso específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $course = Course::findOrFail($id);

        // Verificar permissão
        $user = auth()->user();

        // ALTERAÇÃO: Verificação direta dos atributos is_admin em vez de chamar isAdmin()
        if ($user->is_admin != true && $user->id !== $course->instructor_id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Verificar se existem alunos inscritos
        $enrollmentsCount = Enrollment::where('course_id', $id)->count();

        // ALTERAÇÃO: Verificação direta do atributo is_admin em vez de chamar isAdmin()
        if ($enrollmentsCount > 0 && $user->is_admin != true) {
            return response()->json([
                'error' => 'Não é possível excluir o curso porque existem alunos inscritos',
                'enrollments_count' => $enrollmentsCount
            ], 422);
        }

        // Remover as imagens associadas ao curso
        if ($course->thumbnail) {
            Storage::disk('public')->delete($course->thumbnail);
        }
        if ($course->cover_image) {
            Storage::disk('public')->delete($course->cover_image);
        }

        // Remover o curso (soft delete)
        $course->delete();

        return response()->json(['message' => 'Curso removido com sucesso']);
    }
}
