<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class UserController extends Controller
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
     * Exibir uma lista de usuários (somente para administradores).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Validar se o usuário tem permissão
        if (auth()->user()->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Parâmetros de paginação e filtragem
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search', '');
        $role = $request->get('role', '');

        // Consulta base
        $query = User::query();

        // Aplicar filtros de busca
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filtrar por perfil (admin, instrutor, aluno)
        if ($role === 'admin') {
            $query->where('is_admin', true);
        } elseif ($role === 'instructor') {
            $query->where('is_instructor', true)->where('is_admin', false);
        } elseif ($role === 'student') {
            $query->where('is_instructor', false)->where('is_admin', false);
        }

        // Obter resultados paginados
        $users = $query->latest()->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Armazenar um novo usuário (somente para administradores).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validar se o usuário tem permissão
        if (auth()->user()->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'is_admin' => 'boolean',
            'is_instructor' => 'boolean',
            'phone' => 'nullable|string|max:20',
            'document_number' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Criar o usuário
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_admin' => $request->is_admin ?? false,
            'is_instructor' => $request->is_instructor ?? false,
            'phone' => $request->phone,
            'document_number' => $request->document_number,
            'bio' => $request->bio,
            'address' => $request->address,
            'city' => $request->city,
            'state' => $request->state,
            'zip_code' => $request->zip_code,
            'country' => $request->country,
        ]);

        return response()->json([
            'message' => 'Usuário criado com sucesso',
            'user' => $user
        ], 201);
    }

    /**
     * Exibir informações de um usuário específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        // Verificar permissões
        if (auth()->user()->is_admin != true && auth()->id() != $id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        $user = User::findOrFail($id);

        return response()->json($user);
    }

    /**
     * Atualizar informações de um usuário.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // Verificar permissões
        if (auth()->user()->is_admin != true && auth()->id() != $id) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        $user = User::findOrFail($id);

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'string|email|max:255|unique:users,email,' . $user->id,
            'is_admin' => 'boolean',
            'is_instructor' => 'boolean',
            'phone' => 'nullable|string|max:20',
            'document_number' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Preparar dados para atualização
        $updateData = [];

        if ($request->has('name')) {
            $updateData['name'] = $request->name;
        }
        if ($request->has('email')) {
            $updateData['email'] = $request->email;
        }
        if ($request->has('phone')) {
            $updateData['phone'] = $request->phone;
        }
        if ($request->has('document_number')) {
            $updateData['document_number'] = $request->document_number;
        }
        if ($request->has('bio')) {
            $updateData['bio'] = $request->bio;
        }
        if ($request->has('address')) {
            $updateData['address'] = $request->address;
        }
        if ($request->has('city')) {
            $updateData['city'] = $request->city;
        }
        if ($request->has('state')) {
            $updateData['state'] = $request->state;
        }
        if ($request->has('zip_code')) {
            $updateData['zip_code'] = $request->zip_code;
        }
        if ($request->has('country')) {
            $updateData['country'] = $request->country;
        }

        // Apenas administradores podem alterar perfis de usuário
        if (auth()->user()->is_admin == true) {
            if ($request->has('is_admin')) {
                $updateData['is_admin'] = $request->is_admin;
            }
            if ($request->has('is_instructor')) {
                $updateData['is_instructor'] = $request->is_instructor;
            }
        }

        // Atualizar o usuário
        User::where('id', $id)->update($updateData);

        // Recarregar o usuário para obter os dados atualizados
        $user = User::findOrFail($id);

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'user' => $user
        ]);
    }

    /**
     * Remover um usuário (somente para administradores).
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        // Verificar permissões
        if (auth()->user()->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        $user = User::findOrFail($id);

        // Não permitir remover o próprio usuário
        if (auth()->id() == $id) {
            return response()->json(['error' => 'Não é possível remover o próprio usuário'], 400);
        }

        $user->delete();

        return response()->json(['message' => 'Usuário removido com sucesso']);
    }

    /**
     * Obter informações do perfil do usuário atual.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile()
    {
        $user = auth()->user();

        return response()->json($user);
    }

    /**
     * Atualizar o perfil do usuário atual.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'string|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'document_number' => 'nullable|string|max:20',
            'bio' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'profile_image' => 'nullable|image|max:2048', // Max 2MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Preparar dados para atualização
        $updateData = [];

        if ($request->has('name')) {
            $updateData['name'] = $request->name;
        }
        if ($request->has('email')) {
            $updateData['email'] = $request->email;
        }
        if ($request->has('phone')) {
            $updateData['phone'] = $request->phone;
        }
        if ($request->has('document_number')) {
            $updateData['document_number'] = $request->document_number;
        }
        if ($request->has('bio')) {
            $updateData['bio'] = $request->bio;
        }
        if ($request->has('address')) {
            $updateData['address'] = $request->address;
        }
        if ($request->has('city')) {
            $updateData['city'] = $request->city;
        }
        if ($request->has('state')) {
            $updateData['state'] = $request->state;
        }
        if ($request->has('zip_code')) {
            $updateData['zip_code'] = $request->zip_code;
        }
        if ($request->has('country')) {
            $updateData['country'] = $request->country;
        }

        // Processar upload de imagem de perfil, se houver
        if ($request->hasFile('profile_image') && $request->file('profile_image')->isValid()) {
            // Remover imagem antiga, se existir
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }

            $file = $request->file('profile_image');
            $fileName = 'profile_' . $user->id . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $path = 'users/profile';

            // Redimensionar e salvar a imagem
            $manager = new ImageManager(new Driver());
            $image = $manager->read($file);
            $image->cover(300, 300);

            // Salvar a imagem no storage
            Storage::disk('public')->put($path . '/' . $fileName, $image->toJpeg());
            $updateData['profile_image'] = $path . '/' . $fileName;
        }

        // Atualizar o usuário
        User::where('id', $user->id)->update($updateData);

        // Recarregar o usuário para obter os dados atualizados
        $user = User::findOrFail($user->id);

        return response()->json([
            'message' => 'Perfil atualizado com sucesso',
            'user' => $user
        ]);
    }

    /**
     * Atualizar a senha do usuário atual.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePassword(Request $request)
    {
        $user = auth()->user();

        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verificar se a senha atual está correta
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'A senha atual está incorreta'], 422);
        }

        // Atualizar a senha
        User::where('id', $user->id)->update([
            'password' => Hash::make($request->password)
        ]);

        return response()->json(['message' => 'Senha atualizada com sucesso']);
    }

    /**
     * Alternar o status de um usuário (ativar/desativar).
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleStatus($id)
    {
        // Verificar permissões
        if (auth()->user()->is_admin != true) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        $user = User::findOrFail($id);

        // Não permitir desativar o próprio usuário
        if (auth()->id() == $id) {
            return response()->json(['error' => 'Não é possível desativar o próprio usuário'], 400);
        }

        // Alternar o status (usando soft delete)
        if ($user->trashed()) {
            $user->restore();
            $message = 'Usuário ativado com sucesso';
        } else {
            $user->delete();
            $message = 'Usuário desativado com sucesso';
        }

        return response()->json(['message' => $message]);
    }
}
