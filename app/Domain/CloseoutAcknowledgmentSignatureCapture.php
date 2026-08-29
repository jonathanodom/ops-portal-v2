<?php

namespace App\Domain;

use App\Models\Closeout;
use App\Models\CloseoutAcknowledgmentSignature;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CloseoutAcknowledgmentSignatureCapture
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array{bytes:string,width:int,height:int,sha256:string} */
    public function decode(string $payload, ?int $maximumBytes = null, string $blankMessage = 'Draw a signature before submitting the closeout.'): array
    {
        $max = $maximumBytes ?? (int) config('field_execution.ack_signature_max_bytes', 1048576);
        if (strlen($payload) > (int) ceil($max * 4 / 3) + 128 || ! str_starts_with($payload, 'data:image/png;base64,')) {
            $this->invalid('Signature must be a bounded PNG captured by the signature pad.');
        }
        $bytes = base64_decode(substr($payload, 22), true);
        if ($bytes === false || strlen($bytes) > $max || strlen($bytes) < 100) {
            $this->invalid('The signature image is invalid or too large.');
        }
        $image = @getimagesizefromstring($bytes);
        if (! $image || ($image['mime'] ?? null) !== 'image/png' || $image[0] < 200 || $image[0] > 2000 || $image[1] < 80 || $image[1] > 1000) {
            $this->invalid('The signature must be a valid PNG with supported dimensions.');
        }
        if (! $this->hasInk($bytes)) {
            $this->invalid($blankMessage);
        }

        return ['bytes' => $bytes, 'width' => $image[0], 'height' => $image[1], 'sha256' => hash('sha256', $bytes)];
    }

    /** @param array{bytes:string,width:int,height:int,sha256:string} $decoded */
    public function store(Closeout $closeout, User $actor, array $decoded): CloseoutAcknowledgmentSignature
    {
        if ($closeout->acknowledgmentSignature()->exists()) {
            throw ValidationException::withMessages(['signature_data' => 'This Closeout version already has immutable signature evidence.']);
        }
        $disk = (string) config('field_execution.ack_signature_disk', 'local');
        $key = 'field-acknowledgments/'.now()->format('Y/m').'/'.Str::uuid().'.png';
        if (! Storage::disk($disk)->put($key, $decoded['bytes'])) {
            throw ValidationException::withMessages(['signature_data' => 'The signature was not stored. Please retry.']);
        }
        try {
            $signature = CloseoutAcknowledgmentSignature::query()->create([
                'organization_id' => $closeout->organization_id,
                'closeout_id' => $closeout->id,
                'signer_name' => $closeout->representative_name,
                'signer_role' => $closeout->representative_role,
                'statement_version' => config('field_execution.ack_statement_version'),
                'statement_snapshot' => config('field_execution.ack_statement'),
                'storage_disk' => $disk,
                'storage_key' => $key,
                'mime_type' => 'image/png',
                'size_bytes' => strlen($decoded['bytes']),
                'sha256' => $decoded['sha256'],
                'signed_at' => now(),
                'captured_by_id' => $actor->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($key);
            throw $exception;
        }
        $this->audit->record($closeout->visit->serviceTicket->organization, $actor, 'closeout.customer_acknowledgment_signed', $closeout, [
            'visit_id' => $closeout->visit_id,
            'closeout_id' => $closeout->id,
            'signature_id' => $signature->id,
            'statement_version' => $signature->statement_version,
            'signer_name_present' => true,
            'role_present' => filled($signature->signer_role),
        ]);

        return $signature;
    }

    public function deleteObject(CloseoutAcknowledgmentSignature $signature): void
    {
        Storage::disk($signature->storage_disk)->delete($signature->storage_key);
    }

    private function hasInk(string $png): bool
    {
        if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return false;
        }
        $offset = 8;
        $idat = '';
        $width = $height = $bitDepth = $colorType = $interlace = null;
        while ($offset + 12 <= strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            if ($offset + 12 + $length > strlen($png)) {
                return false;
            }
            $data = substr($png, $offset + 8, $length);
            if ($type === 'IHDR' && $length === 13) {
                [$width, $height] = array_values(unpack('Nwidth/Nheight', substr($data, 0, 8)));
                $bitDepth = ord($data[8]);
                $colorType = ord($data[9]);
                $interlace = ord($data[12]);
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
            $offset += 12 + $length;
        }
        if ($bitDepth !== 8 || ! in_array($colorType, [2, 6], true) || $interlace !== 0 || ! $width || ! $height) {
            return false;
        }
        $raw = @gzuncompress($idat);
        $bytesPerPixel = $colorType === 6 ? 4 : 3;
        $rowBytes = $width * $bytesPerPixel;
        if ($raw === false || strlen($raw) !== ($rowBytes + 1) * $height) {
            return false;
        }
        $prior = array_fill(0, $rowBytes, 0);
        $cursor = 0;
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$cursor++]);
            $row = [];
            for ($x = 0; $x < $rowBytes; $x++) {
                $value = ord($raw[$cursor++]);
                $left = $x >= $bytesPerPixel ? $row[$x - $bytesPerPixel] : 0;
                $above = $prior[$x] ?? 0;
                $upperLeft = $x >= $bytesPerPixel ? ($prior[$x - $bytesPerPixel] ?? 0) : 0;
                $row[$x] = match ($filter) {
                    0 => $value,
                    1 => ($value + $left) & 255,
                    2 => ($value + $above) & 255,
                    3 => ($value + intdiv($left + $above, 2)) & 255,
                    4 => ($value + $this->paeth($left, $above, $upperLeft)) & 255,
                    default => -1,
                };
                if ($row[$x] < 0) {
                    return false;
                }
            }
            for ($x = 0; $x < $rowBytes; $x += $bytesPerPixel) {
                $alpha = $bytesPerPixel === 4 ? $row[$x + 3] : 255;
                if ($alpha > 24 && min($row[$x], $row[$x + 1], $row[$x + 2]) < 235) {
                    return true;
                }
            }
            $prior = $row;
        }

        return false;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['signature_data' => $message]);
    }
}
