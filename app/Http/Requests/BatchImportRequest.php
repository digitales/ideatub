<?php

namespace App\Http\Requests;

use App\Services\Import\MicrositeImportDetector;
use Illuminate\Foundation\Http\FormRequest;

class BatchImportRequest extends FormRequest
{
    private const MAX_FILES = 200;

    private const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_title' => ['required', 'string', 'max:255'],
            'dedupe_mode' => ['required', 'string', 'in:new,existing'],
            'no_chunking' => ['nullable', 'boolean'],
            'skip_ai_metadata' => ['nullable', 'boolean'],
            'relative_paths' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'relative_paths.*' => ['required', 'string', 'max:1024'],
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:1024', 'mimes:txt,md,markdown'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            $paths = $this->input('relative_paths', []);
            $files = $this->file('files', []);

            if (count($paths) !== count($files)) {
                $v->errors()->add('files', 'relative_paths count must match files count.');

                return;
            }

            $total = 0;
            foreach ($files as $f) {
                $total += $f->getSize();
            }
            if ($total > self::MAX_TOTAL_BYTES) {
                $v->errors()->add('files', 'Total upload size exceeds 20 MB.');
            }

            foreach ($paths as $i => $p) {
                if (! is_string($p) || ! $this->pathIsSafe($p)) {
                    $v->errors()->add("relative_paths.{$i}", 'Illegal path segment.');
                }
            }

            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if (MicrositeImportDetector::hasDuplicatePagePathSegments($paths, $files)) {
                $v->errors()->add('files', 'This folder uses the same page name twice (same numbered .md name). Remove or rename a file.');
            }
        });
    }

    private function pathIsSafe(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, ':') || str_contains($path, "\0")) {
            return false;
        }
        if (mb_strlen($path) > 1024) {
            return false;
        }
        $segments = explode('/', $path);
        if (count($segments) > 10) {
            return false;
        }
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..' || str_starts_with($seg, '.') || mb_strlen($seg) > 255) {
                return false;
            }
            if (preg_match('/[\x00-\x1F\x7F]/u', $seg) === 1) {
                return false;
            }
        }

        return true;
    }
}
