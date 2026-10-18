<?php

namespace App\Http\Controllers\Api\Partner;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffController extends PartnerController
{
    public function index(Request $request)
    {
        $staffs = Staff::where('organizer_id', $this->organizerId($request))
            ->with('gate:id,nama_gate')
            ->orderByRaw("CASE WHEN status = 'aktif' THEN 0 ELSE 1 END")
            ->orderBy('nama')
            ->get();

        return response()->json(['data' => $staffs]);
    }

    public function store(Request $request)
    {
        $organizerId = $this->organizerId($request);

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'peran' => ['nullable', 'string', 'max:60'],
            // Gate WAJIB milik organizer ini. Sebelumnya cukup exists:gates,id
            // sehingga partner bisa menugaskan staff ke gate milik partner lain.
            'gate_id' => ['nullable', Rule::exists('gates', 'id')->where('organizer_id', $organizerId)],
            'buat_akun' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'required_if:buat_akun,true'],
        ], [
            'gate_id.exists' => 'Gate itu bukan milikmu.',
            'password.required_if' => 'Password wajib diisi kalau membuat akun login.',
        ]);

        $email = $data['email'] ?? null;

        if ($email) {
            $sudahAda = Staff::where('organizer_id', $organizerId)
                ->where('email', $email)
                ->exists();

            if ($sudahAda) {
                throw ValidationException::withMessages([
                    'email' => 'Email ini sudah dipakai staff lain di daftar Anda.',
                ]);
            }
        }

        $staff = DB::transaction(function () use ($data, $email, $organizerId) {
            $userId = null;

            if (($data['buat_akun'] ?? false) && $email) {
                $userId = $this->userIdUntukStaffBaru($email, $data, $organizerId);
            }

            return Staff::create([
                'organizer_id' => $organizerId,
                'user_id' => $userId,
                'gate_id' => $data['gate_id'] ?? null,
                'nama' => $data['nama'],
                'email' => $email,
                'peran' => $data['peran'] ?? 'Scanner',
            ]);
        });

        return response()->json(['data' => $staff->load('gate:id,nama_gate')], 201);
    }

    public function update(Request $request, Staff $staff)
    {
        $organizerId = $this->organizerId($request);

        abort_if($staff->organizer_id !== $organizerId, 403, 'Staff ini bukan milikmu.');

        $data = $request->validate([
            'nama' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'peran' => ['nullable', 'string', 'max:60'],
            'gate_id' => ['nullable', Rule::exists('gates', 'id')->where('organizer_id', $organizerId)],
            'status' => ['nullable', 'in:aktif,nonaktif'],
        ], [
            'gate_id.exists' => 'Gate itu bukan milikmu.',
        ]);

        $email = $data['email'] ?? null;

        if ($email) {
            $dipakaiStaffLain = Staff::where('organizer_id', $organizerId)
                ->where('email', $email)
                ->whereKeyNot($staff->id)
                ->exists();

            if ($dipakaiStaffLain) {
                throw ValidationException::withMessages([
                    'email' => 'Email ini sudah dipakai staff lain di daftar Anda.',
                ]);
            }

            // Tabel users punya email unik sendiri: dicek di luar transaksi
            // supaya pesannya rapi, bukan error 500 dari constraint database.
            if ($staff->user) {
                $dipakaiAkunLain = User::where('email', $email)
                    ->whereKeyNot($staff->user_id)
                    ->exists();

                if ($dipakaiAkunLain) {
                    throw ValidationException::withMessages([
                        'email' => 'Email ini sudah dipakai akun login lain.',
                    ]);
                }
            }
        }

        DB::transaction(function () use ($staff, $data, $email) {
            $staff->update($data);

            // Perubahan email & status harus ikut ke akun login-nya. Tanpa ini
            // email di daftar staff berubah tetapi orangnya tetap login dengan
            // email lama, dan staff "nonaktif" masih bisa masuk.
            if (! $staff->user) {
                return;
            }

            $ubah = [];

            if ($email) {
                $ubah['email'] = $email;
            }

            if (! empty($data['status'])) {
                $ubah['status'] = $data['status'] === 'aktif'
                    ? UserStatus::ACTIVE
                    : UserStatus::INACTIVE;
            }

            if ($ubah) {
                $staff->user->update($ubah);
            }
        });

        return response()->json(['data' => $staff->fresh('gate:id,nama_gate')]);
    }

    public function destroy(Request $request, Staff $staff)
    {
        abort_if($staff->organizer_id !== $this->organizerId($request), 403, 'Staff ini bukan milikmu.');

        DB::transaction(function () use ($staff) {
            // Akun login dinonaktifkan, bukan dihapus: riwayat check-in tetap
            // menyimpan siapa petugasnya, tapi orangnya tidak bisa masuk lagi.
            if ($staff->user && $staff->user->peran === Role::STAFF) {
                $staff->user->update(['status' => UserStatus::INACTIVE]);
            }

            $staff->delete();
        });

        return response()->json(['message' => 'Staff dihapus.']);
    }

    /**
     * Akun login untuk staff baru.
     *
     * JANGAN memakai email milik akun yang sudah ada kecuali memang staff
     * organizer yang sama. Tanpa pengecekan ini, partner bisa menempelkan akun
     * admin platform (atau staff organizer lain) ke daftar staffnya.
     */
    private function userIdUntukStaffBaru(string $email, array $data, int $organizerId): int
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'nama' => $data['nama'],
                'email' => $email,
                'password' => $data['password'],
                'peran' => Role::STAFF,
                'organizer_id' => $organizerId,
                'status' => UserStatus::ACTIVE,
            ]);

            return $user->id;
        }

        $bolehDipakai = $user->peran === Role::STAFF
            && (int) $user->organizer_id === $organizerId;

        if (! $bolehDipakai) {
            throw ValidationException::withMessages([
                'email' => 'Email ini sudah terdaftar sebagai akun lain. Pakai email berbeda untuk staff ini.',
            ]);
        }

        return $user->id;
    }
}
