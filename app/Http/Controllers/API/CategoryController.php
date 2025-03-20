<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Criar uma nova instância de controller.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api')->except(['index', 'show', 'courses']);
    }

    /**
     * Listar todas as categorias.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Parâmetros de filtragem
        $includeInactive = $request->get('include_inactive', false) && auth()->check() && auth()->user()->is_admin == true;
        $parentId = $request->get('parent_id');

        // Consulta base
        $query = Category::query();

        // Filtrar categorias ativas (a menos que explicitamente solicitado)
        if (!$includeInactive) {
            $query->where('is_active', true);
        }

        // Filtrar por categoria pai
        if ($parentId !== null) {
            $query->where('parent_id', $parentId === 'null' ? null : (int)$parentId);
        }

        // Ordenar por ordem e nome
        $categories = $query->orderBy('order')->orderBy('name')->get();

        // Estruturar em formato de árvore, se solicitado
        if ($request->get('tree', false)) {
            $categories = $this->buildCategoryTree($categories);
        }

        return response()->json($categories);
    }

    /**
     * Armazenar uma nova categoria.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Verificar permissão
        if (auth()->user()->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'parent_id' => 'nullable|exists:categories,id',
            'order' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Gerar slug único
        $slug = Str::slug($request->name);
        $count = 1;

        // Verificar se o slug já existe
        while (Category::where('slug', $slug)->exists()) {
            $slug = Str::slug($request->name) . '-' . $count;
            $count++;
        }

        // Criar a categoria
        $category = Category::create([
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
            'icon' => $request->icon,
            'is_active' => $request->is_active ?? true,
            'parent_id' => $request->parent_id,
            'order' => $request->order ?? 0,
        ]);

        return response()->json([
            'message' => 'Categoria criada com sucesso',
            'category' => $category
        ], 201);
    }

    /**
     * Exibir uma categoria específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $category = Category::findOrFail($id);

        // Verificar se categoria está ativa para usuários não-admin
        if (!$category->is_active && (!auth()->check() || auth()->user()->is_admin != true)) {
            return response()->json(['error' => 'Categoria não encontrada'], 404);
        }

        return response()->json($category);
    }

    /**
     * Atualizar uma categoria específica.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // Verificar permissão
        if (auth()->user()->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        $category = Category::findOrFail($id);

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'parent_id' => 'nullable|exists:categories,id',
            'order' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Preparar dados para atualização
        $updateData = [];

        // Atualizar slug se o nome for alterado
        if ($request->has('name') && $request->name !== $category->name) {
            $slug = Str::slug($request->name);
            $count = 1;

            // Verificar se o slug já existe (excluindo a categoria atual)
            while (Category::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = Str::slug($request->name) . '-' . $count;
                $count++;
            }

            $updateData['slug'] = $slug;
            $updateData['name'] = $request->name;
        }

        // Não permitir definir a própria categoria como pai
        if ($request->has('parent_id') && $request->parent_id == $id) {
            return response()->json(['error' => 'Uma categoria não pode ser pai dela mesma'], 422);
        }

        // Não permitir definir como pai uma categoria que é filha desta
        if ($request->has('parent_id') && $request->parent_id) {
            $parentIds = $this->getAllParentIds($request->parent_id);
            if (in_array($id, $parentIds)) {
                return response()->json(['error' => 'Criaria uma referência circular'], 422);
            }
            $updateData['parent_id'] = $request->parent_id;
        }

        // Atualizar outros campos
        if ($request->has('description')) {
            $updateData['description'] = $request->description;
        }
        if ($request->has('icon')) {
            $updateData['icon'] = $request->icon;
        }
        if ($request->has('is_active')) {
            $updateData['is_active'] = $request->is_active;
        }
        if ($request->has('order')) {
            $updateData['order'] = $request->order;
        }

        // Atualizar a categoria
        Category::where('id', $id)->update($updateData);

        // Recarregar a categoria para obter os dados atualizados
        $category = Category::findOrFail($id);

        return response()->json([
            'message' => 'Categoria atualizada com sucesso',
            'category' => $category
        ]);
    }

    /**
     * Remover uma categoria específica.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        // Verificar permissão
        if (auth()->user()->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        $category = Category::findOrFail($id);

        // Verificar se existem cursos vinculados a esta categoria
        $coursesCount = Course::where('category_id', $id)->count();
        if ($coursesCount > 0) {
            return response()->json([
                'error' => 'Não é possível excluir a categoria porque existem cursos vinculados a ela',
                'courses_count' => $coursesCount
            ], 422);
        }

        // Verificar se existem subcategorias
        $subcategoriesCount = Category::where('parent_id', $id)->count();
        if ($subcategoriesCount > 0) {
            return response()->json([
                'error' => 'Não é possível excluir a categoria porque existem subcategorias vinculadas a ela',
                'subcategories_count' => $subcategoriesCount
            ], 422);
        }

        // Remover a categoria
        $category->delete();

        return response()->json(['message' => 'Categoria removida com sucesso']);
    }

    /**
     * Listar cursos de uma categoria específica.
     *
     * @param  int  $id
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function courses($id, Request $request)
    {
        $category = Category::findOrFail($id);

        // Verificar se categoria está ativa para usuários não-admin
        if (!$category->is_active && (!auth()->check() || auth()->user()->is_admin != true)) {
            return response()->json(['error' => 'Categoria não encontrada'], 404);
        }

        // Parâmetros de paginação e filtragem
        $perPage = $request->get('per_page', 12);
        $includeUnpublished = $request->get('include_unpublished', false) && auth()->check() && auth()->user()->is_admin == true;

        // Consulta base
        $query = Course::where('category_id', $id);

        // Filtrar cursos publicados (a menos que explicitamente solicitado)
        if (!$includeUnpublished) {
            $query->where('is_published', true);
        }

        // Ordenar e paginar
        $courses = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($courses);
    }

    /**
     * Constrói uma árvore de categorias a partir de uma coleção plana.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $categories
     * @param  int|null  $parentId
     * @return array
     */
    private function buildCategoryTree($categories, $parentId = null)
    {
        $branch = [];

        foreach ($categories as $category) {
            if ($category->parent_id === $parentId) {
                $children = $this->buildCategoryTree($categories, $category->id);

                if ($children) {
                    $category->children = $children;
                }

                $branch[] = $category;
            }
        }

        return $branch;
    }

    /**
     * Obtém todos os IDs das categorias pai recursivamente.
     *
     * @param  int  $categoryId
     * @param  array  $parentIds
     * @return array
     */
    private function getAllParentIds($categoryId, $parentIds = [])
    {
        $category = Category::find($categoryId);

        if (!$category || !$category->parent_id) {
            return $parentIds;
        }

        $parentIds[] = $category->parent_id;
        return $this->getAllParentIds($category->parent_id, $parentIds);
    }
}
