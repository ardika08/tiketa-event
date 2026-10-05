<?php

namespace App\Http\Controllers\Api\Partner;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffController extends PartnerController
{
    public function index(Request $request)
    {
        $staffs = Staff::where('organizer_id', $this->organizerId($request))
            ->with('gate:id,nama_gate')
            ->latest()
            ->get();

        return response()->json(['data' => $staffs]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'peran' => ['nullable', 'string', 'max:60'],
            'gate_id' => ['nullable', 'exists:gates,id'],
            'buat_akun' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        $organizerId = $this->organizerId($request);

        $staff = DB::transaction(function () use ($data, $organizerId) {
            $userId = null;

            if (($data['buat_akun'] ?? false) && ! empty($data['email'])) {
                $user = User::firstOrCreate(
                    ['email' => $data['email']],
                    [
                        'nama' => $data['nama'],
                        'password' => $data['password'] ?? 'staff123',
                        'peran' => Role::STAFF,
                        'organizer_id' => $organizerId,
                        'status' => UserStatus::ACTIVE,
                    ],
                );
                $userId = $user->id;
            }

            return Staff::create([
                'organizer_id' => $organizerId,
                'user_id' => $userId,
                'gate_id' => $data['gate_id'] ?? null,
                'nama' => $data['nama'],
                'email' => $data['email'] ?? null,
                'peran' => $data['peran'] ?? 'Scanner',
            ]);
        });

        return response()->json(['data' => $staff->load('gate:id,nama_gate')], 201);
    }

    public function update(Request $request, Staff $staff)
    {
        abort_if($staff->organizer_id !== $this->organizerId($request), 403, 'Staff ini bukan milikmu.');

        $staff->update($request->validate([
            'nama' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'peran' => ['nullable', 'string', 'max:60'],
            'gate_id' => ['nullable', 'exists:gates,id'],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ]));

        return response()->json(['data' => $staff->fresh('gate:id,nama_gate')]);
    }

    public function destroy(Request $request, Staff $staff)
    {
        abort_if($staff->organizer_id !== $this->organizerId($request), 403, 'Staff ini bukan milikmu.');
        $staff->delete();

        return response()->json(['message' => 'Staff dihapus.']);
    }
}
