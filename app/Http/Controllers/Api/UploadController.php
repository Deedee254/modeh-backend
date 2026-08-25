<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UploadController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Generic upload endpoint used by frontend for question and quiz media.
     * Accepts a single file field named 'file' and optional 'type' (image/audio/video).
     * Returns JSON { url: '...' }
     */
    public function store(Request $request)
    {
        $type = $request->get('type') ?: 'uploads';

        // Set validation rules by declared type - enforces strict MIME type/extension checks for security
        $rules = ['file' => 'required|file', 'type' => 'nullable|string'];
        switch (strtolower($type)) {
            case 'image':
                $rules['file'] = 'required|file|image|mimes:jpeg,png,jpg,gif,webp|max:5120'; // 5 MB
                break;
            case 'audio':
                $rules['file'] = 'required|file|mimes:mp3,wav,ogg,m4a|max:15360'; // 15 MB
                break;
            case 'video':
                $rules['file'] = 'required|file|mimes:mp4,webm,mov,ogg|max:51200'; // 50 MB
                break;
            case 'document':
            default:
                // Security: restrict default file uploads to safe document extensions to prevent execution of arbitrary uploaded scripts (e.g., .php, .html, .exe)
                $rules['file'] = 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip|max:10240'; // 10 MB default
                break;
        }

        $v = Validator::make($request->all(), $rules);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $file = $request->file('file');
        $type = $request->get('type') ?: 'uploads';

        // sanitize type into folder name
        $folder = preg_replace('/[^a-z0-9_\-]/i', '_', $type);
        if (empty($folder)) {
            $folder = 'uploads';
        }

        $path = Storage::disk('public')->putFile($folder, $file);
        $url = url(Storage::url($path));

        return response()->json([
            'url' => $url,
            'path' => $path,
        ], 201);
    }
}
