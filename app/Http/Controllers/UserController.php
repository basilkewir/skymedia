<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
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
        $users = User::withCount('channels')
            ->orderBy('name')
            ->paginate(20);

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
            'is_admin' => $data['is_admin'] ?? false,
        ]);

        return redirect()->route('users.index')->with('success', 'User created');
    }

    public function show(User $user): Response
    {
        $channels = $user->channels()
            ->withCount('dvrSegments')
            ->withSum('dvrSegments', 'filesize')
            ->latest()
            ->paginate(15);

        return Inertia::render('Users/Show', [
            'user' => $user,
            'channels' => $channels,
        ]);
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
            'is_admin' => $data['is_admin'] ?? false,
        ];

        if (!empty($data['password'])) {
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

        // Optionally reassign channels or delete them
        $user->channels()->delete();
        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted');
    }

    // Channel management for a user
    public function channels(User $user): Response
    {
        $channels = $user->channels()
            ->withCount('dvrSegments')
            ->withSum('dvrSegments', 'filesize')
            ->latest()
            ->paginate(20);

        // Get unassigned channels for the assign modal
        $unassignedChannels = Channel::whereNull('user_id')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'push_url', 'push_stream_key']);

        return Inertia::render('Users/Channels', [
            'user' => $user,
            'channels' => $channels,
            'unassignedChannels' => $unassignedChannels,
        ]);
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
            'is_admin' => 'boolean',
        ];
    }

    // Channel assignment
    public function attachChannel(Request $request, User $user, Channel $channel): RedirectResponse
    {
        $request->validate([
            'storage_quota_bytes' => 'nullable|integer|min:1',
        ]);

        $channel->update([
            'user_id' => $user->id,
            'storage_quota_bytes' => $request->input('storage_quota_bytes'),
        ]);

        return back()->with('success', "Channel assigned to {$user->name}");
    }

    public function detachChannel(User $user, Channel $channel): RedirectResponse
    {
        if ($channel->user_id !== $user->id) {
            return back()->with('error', 'Channel not assigned to this user');
        }

        $channel->update(['user_id' => null]);

        return back()->with('success', 'Channel unassigned');
    }
}
