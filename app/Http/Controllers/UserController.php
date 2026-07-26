<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {
    }

    /**
     * Lista os usuários da empresa com papel e técnico vinculado, além dos
     * dados que alimentam o formulário de criação/edição: papéis disponíveis
     * e técnicos ainda sem usuário.
     */
    public function index(): Response
    {
        $usuarios = User::query()
            ->with(['roles:id,name', 'technician:id,user_id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $usuario) => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'email' => $usuario->email,
                'role' => $usuario->roles->first()?->name,
                'is_active' => $usuario->is_active,
                'technician' => $usuario->technician ? [
                    'id' => $usuario->technician->id,
                    'name' => $usuario->technician->name,
                ] : null,
                'created_at' => $usuario->created_at,
            ]);

        return Inertia::render('Settings/Users/Index', [
            'users' => $usuarios,
            'roles' => Role::orderBy('name')->pluck('name'),
            'techniciansWithoutUser' => Technician::whereNull('user_id')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(UserRequest $request)
    {
        try {
            $this->userService->criar($request->validated());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('settings.users.index')
            ->with('success', 'Usuário criado com sucesso.');
    }

    public function update(UserRequest $request, User $user)
    {
        try {
            $this->userService->atualizar($user, $request->validated());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('settings.users.index')
            ->with('success', 'Usuário atualizado com sucesso.');
    }

    public function alterarStatus(Request $request, User $user)
    {
        $dados = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        try {
            $this->userService->alterarStatus($user, $dados['is_active'], Auth::id());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('settings.users.index')
            ->with('success', 'Status do usuário atualizado com sucesso.');
    }
}
