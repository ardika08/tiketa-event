<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function registerPartner(Request $request)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'nama_penyelenggara' => ['required', 'string', 'max:150'],
            'telepon' => ['nullable', 'string', 'max:30'],
        ]);

        [$user] = DB::transaction(function () use ($data) {
            $organizer = Organizer::create([
                'nama_penyelenggara' => $data['nama_penyelenggara'],
                'email' => $data['email'],
                'telepon' => $data['telepon'] ?? null,
                'status_verifikasi' => 'pending',
            ]);

            $user = User::create([
                'nama' => $data['nama'],
                'email' => $data['email'],
                'password' => $data['password'],
                'peran' => Role::PARTNER,
                'organizer_id' => $organizer->id,
                'status' => UserStatus::ACTIVE,
            ]);

            $organizer->update(['user_id' => $user->id]);

            return [$user];
        });

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $user->createToken('partner')->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'peran' => ['nullable', 'in:admin_platform,partner,staff'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'Akun tidak aktif.']);
        }

        if (! empty($data['peran']) && $user->peran->value !== $data['peran']) {
            throw ValidationException::withMessages(['email' => 'Peran akun tidak sesuai.']);
        }

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $user->createToken($user->peran->value)->plainTextToken,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('organizer');

        return [
            'id' => $user->id,
            'nama' => $user->nama,
            'email' => $user->email,
            'peran' => $user->peran->value,
            'status' => $user->status->value,
            'organizer' => $user->organizer ? [
                'id' => $user->organizer->id,
                'nama_penyelenggara' => $user->organizer->nama_penyelenggara,
                'status_verifikasi' => $user->organizer->status_verifikasi->value,
            ] : null,
        ];
    }
}
