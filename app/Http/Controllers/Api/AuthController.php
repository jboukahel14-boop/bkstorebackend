<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Check if an admin account already exists.
     */
    public function checkAdminStatus()
    {
        $hasAdmin = UserRole::where('role', 'admin')->exists() || User::where('is_admin', true)->exists();
        return response()->json([
            'has_admin' => $hasAdmin,
        ]);
    }

    /**
     * Register the very first admin account.
     */
    public function registerFirstAdmin(Request $request)
    {
        $hasAdmin = UserRole::where('role', 'admin')->exists() || User::where('is_admin', true)->exists();
        if ($hasAdmin) {
            return response()->json([
                'message' => 'Un administrateur existe déjà. Veuillez vous connecter.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_admin' => true,
        ]);

        UserRole::create([
            'user_id' => $user->id,
            'role' => 'admin',
            'created_at' => now(),
        ]);

        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'message' => 'Compte administrateur créé avec succès',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    /**
     * Admin login.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        // Verify admin role
        $isAdmin = $user->hasRole('admin') || $user->is_admin;
        if (!$isAdmin) {
            return response()->json([
                'message' => 'Accès refusé. Rôle administrateur requis.',
            ], 403);
        }

        // Ensure user_roles has admin record if not yet synced
        if (!$user->hasRole('admin')) {
            UserRole::create([
                'user_id' => $user->id,
                'role' => 'admin',
                'created_at' => now(),
            ]);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Admin logout.
     */
    public function logout(Request $request)
    {
        if ($request->user()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Déconnexion réussie',
        ]);
    }

    /**
     * Current authenticated user profile.
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin') || $user->is_admin;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $isAdmin,
            ],
        ]);
    }
}
