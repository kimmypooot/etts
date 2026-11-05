<?php

namespace App\Services\Transactions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Applicant attachments on the private `attachments` disk.
 *
 * A file is accepted only if its extension is allowed AND its real content type
 * (detected from the bytes, not from what the browser claims) matches that
 * extension, and it is within the size cap. It is stored under a random name,
 * so the original name never reaches the disk, and it is never executed or
 * served by the web server directly; {@see response()} streams it with a fixed
 * content type and `nosniff`.
 */
final class AttachmentStore
{
    private const DISK = 'attachments';

    /**
     * Stores an upload and returns the stored file name, or null when none was sent.
     */
    public function store(?UploadedFile $file): ?string
    {
        if ($file === null) {
            return null;
        }

        if (! $file->isValid()) {
            throw new WorkflowException('The attachment could not be uploaded. Please try again.');
        }

        $types = config('etts.attachments.types');
        $extension = strtolower($file->getClientOriginalExtension());

        if (! isset($types[$extension])) {
            throw new WorkflowException('Attachments must be a PDF, JPG or PNG file.');
        }

        if (! in_array($file->getMimeType(), $types[$extension], true)) {
            throw new WorkflowException('The attachment is not really a '.strtoupper($extension).' file.');
        }

        if ($file->getSize() > config('etts.attachments.max_kb') * 1024) {
            throw new WorkflowException('An attachment may not be larger than '.round(config('etts.attachments.max_kb') / 1024, 1).' MB.');
        }

        $name = bin2hex(random_bytes(16)).'.'.($extension === 'jpeg' ? 'jpg' : $extension);

        Storage::disk(self::DISK)->putFileAs('', $file, $name);

        return $name;
    }

    /** Removes a file this app stored (used when the record it belonged to was rolled back). */
    public function forget(string $name): void
    {
        if ($name === basename($name) && ! str_contains($name, '..')) {
            Storage::disk(self::DISK)->delete($name);
        }
    }

    public function exists(string $name): bool
    {
        return Storage::disk(self::DISK)->exists($name);
    }

    /**
     * The file, shown in the browser. The content type comes from the stored
     * extension (which {@see store()} verified), never from the file.
     */
    public function response(string $name): StreamedResponse
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $type = config('etts.attachments.types')[$extension][0] ?? 'application/octet-stream';

        return Storage::disk(self::DISK)->response($name, $name, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
