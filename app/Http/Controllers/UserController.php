<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::orderBy('name')->paginate(20);

        return Inertia::render('Users/Index', ['users' => $users]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
        ]);

        return redirect()->route('users.index')->with('success', 'User created');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Users/Edit', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->rules($user));

        $update = [
            'name' => $data['name'],
            'email' => $data['email'],
        ];

        if (! empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
        }

        $user->update($update);

        return redirect()->route('users.index')->with('success', 'User updated');
    }

    public function destroy(User $user): RedirectResponse
    {
        if (User::count() <= 1) {
            return back()->with('error', 'Cannot delete the last admin user');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted');
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => $user ? 'nullable|string|min:8|max:255' : 'required|string|min:8|max:255',
        ];
    }
}
