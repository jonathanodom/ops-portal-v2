<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Organization;
use App\Support\Api\ApiResponse;
use App\Support\Api\V1\ContactSummary;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /** GET /api/v1/contacts/search — plan §8.2. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $organization = $this->organization($request);
        $term = trim($data['q']);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = Phone::normalize($term);

        $contacts = Contact::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->where(function ($query) use ($like, $digits): void {
                $query->where('name', 'like', $like)->orWhere('email', 'like', $like);
                if ($digits !== null) {
                    $query->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            })
            ->orderBy('name')
            ->limit((int) ($data['limit'] ?? 20))
            ->get();

        return ApiResponse::success($request, $contacts->map(ContactSummary::make(...))->all());
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
