<?php

namespace App\Http\Controllers\Api\Partner;

use Illuminate\Http\Request;

class ProfileController extends PartnerController
{
    public function show(Request $request)
    {
        $organizer = $this->organizer($request)->load('legal');

        return response()->json(['data' => $organizer]);
    }

    public function update(Request $request)
    {
        $organizer = $this->organizer($request);

        $data = $request->validate([
            'nama_penyelenggara' => ['sometimes', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'deskripsi' => ['nullable', 'string'],
            'nama_bank' => ['nullable', 'string', 'max:100'],
            'nomor_rekening' => ['nullable', 'string', 'max:50'],
            'nama_rekening' => ['nullable', 'string', 'max:150'],
        ]);

        $organizer->update($data);

        return response()->json(['data' => $organizer->fresh('legal')]);
    }

    public function updateLegal(Request $request)
    {
        $organizer = $this->organizer($request);

        $data = $request->validate([
            'nama_penanggung_jawab' => ['required', 'string', 'max:150'],
            'no_identitas' => ['nullable', 'string', 'max:80'],
            'dokumen_url' => ['nullable', 'string', 'max:500'],
            'tipe_dokumen' => ['nullable', 'string', 'max:80'],
        ]);

        $legal = $organizer->legal()->updateOrCreate(
            ['organizer_id' => $organizer->id],
            $data + ['status' => 'pending'],
        );

        return response()->json(['data' => $legal]);
    }
}
