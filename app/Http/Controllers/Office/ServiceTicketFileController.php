<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Jobs\DeleteRemovedServiceTicketFile;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketFile;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceTicketFileController extends Controller
{
    public function store(Request $request, string $serviceTicket, AuditRecorder $audit): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.config('service_ticket_files.max_file_kb'),
                'mimetypes:'.implode(',', config('service_ticket_files.mimetypes')),
            ],
            'caption' => ['nullable', 'string', 'max:500'],
        ]);
        $mimeType = (string) $data['file']->getMimeType();
        $disk = (string) config('service_ticket_files.disk');
        $key = 'service-ticket-files/'.now()->format('Y/m').'/'.Str::uuid().'.'.$this->extension($mimeType);
        $stored = Storage::disk($disk)->putFileAs(dirname($key), $data['file'], basename($key));
        if (! $stored) {
            return back()->withErrors(['file' => 'The Ticket file could not be stored. Please retry.']);
        }

        try {
            DB::transaction(function () use ($request, $ticket, $data, $mimeType, $disk, $key, $audit): void {
                $file = ServiceTicketFile::query()->create([
                    'organization_id' => $ticket->organization_id,
                    'service_ticket_id' => $ticket->id,
                    'uploaded_by_id' => $request->user()->id,
                    'storage_disk' => $disk,
                    'storage_key' => $key,
                    'original_name' => $this->safeOriginalName((string) $data['file']->getClientOriginalName()),
                    'mime_type' => $mimeType,
                    'byte_size' => $data['file']->getSize(),
                    'caption' => $data['caption'] ?? null,
                ]);
                $audit->record($request->attributes->get('organization'), $request->user(), 'service_ticket_file.uploaded', $file, [
                    'ticket_id' => $ticket->id,
                    'file_id' => $file->id,
                    'mime_type' => $file->mime_type,
                    'byte_size' => $file->byte_size,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($key);
            throw $exception;
        }

        return back()->with('status', 'Ticket file uploaded.');
    }

    public function show(Request $request, string $file): StreamedResponse
    {
        $file = ServiceTicketFile::query()
            ->where('organization_id', $request->attributes->get('organization')->id)
            ->where('state', 'stored')
            ->with('serviceTicket')
            ->findOrFail($file);
        Gate::authorize('view', $file->serviceTicket);

        return Storage::disk($file->storage_disk)->response($file->storage_key, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $serviceTicket, string $file, AuditRecorder $audit): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);

        DB::transaction(function () use ($request, $ticket, $file, $audit): void {
            $file = ServiceTicketFile::query()
                ->where('organization_id', $ticket->organization_id)
                ->where('service_ticket_id', $ticket->id)
                ->where('state', 'stored')
                ->lockForUpdate()
                ->findOrFail($file);
            $file->update([
                'state' => 'removed',
                'removed_at' => now(),
                'removed_by_id' => $request->user()->id,
            ]);
            $audit->record($request->attributes->get('organization'), $request->user(), 'service_ticket_file.removed', $file, [
                'ticket_id' => $ticket->id,
                'file_id' => $file->id,
                'mime_type' => $file->mime_type,
                'byte_size' => $file->byte_size,
            ]);
            DeleteRemovedServiceTicketFile::dispatch($file->id)->afterCommit();
        });

        return back()->with('status', 'Ticket file removed.');
    }

    private function ticket(Request $request, string $id): ServiceTicket
    {
        return ServiceTicket::query()
            ->where('organization_id', $request->attributes->get('organization')->id)
            ->findOrFail($id);
    }

    private function extension(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            default => throw new \InvalidArgumentException('Unsupported Ticket file type.'),
        };
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'file';

        return Str::limit($name, 255, '');
    }
}
