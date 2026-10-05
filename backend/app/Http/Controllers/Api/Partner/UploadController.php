<?php

namespace App\Http\Controllers\Api\Partner;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends PartnerController
{
    private const FOLDERS = ['tickets', 'events', 'logos', 'seat-plans', 'proofs'];

    public function store(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'folder' => ['nullable', 'string', 'in:'.implode(',', self::FOLDERS)],
        ]);

        $folder = $data['folder'] ?? 'tickets';
        $file = $data['file'];

        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'gambar';
        $filename = $name.'-'.Str::random(8).'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs($folder, $filename, 'public');

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ],
        ], 201);
    }
}