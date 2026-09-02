<?php

namespace App\Domain;

use App\Models\Closeout;
use App\Models\ServiceTicketWorkItem;
use App\Models\VisitMedia;

class CloseoutReadiness
{
    public function __construct(private readonly CloseoutRequirements $requirements) {}

    /** @return array<string, string> */
    public function errors(Closeout $closeout, bool $signatureProvided = false, bool $requireSignature = false): array
    {
        $errors = [];
        if (ServiceTicketWorkItem::query()
            ->where('organization_id', $closeout->organization_id)
            ->where('service_ticket_id', $closeout->visit->service_ticket_id)
            ->where('status', 'open')
            ->whereHas('visits', fn ($query) => $query->whereKey($closeout->visit_id))
            ->exists()) {
            $errors['work_items'] = 'Choose Completed or Needs follow-up for every Work Item handled during this Visit.';
        }
        if (! $closeout->outcome) {
            $errors['outcome'] = 'Choose an outcome.';
        }
        $purpose = $closeout->visit->serviceTicket->purpose;
        foreach ($this->requirements->narrativeFields($purpose, $closeout->outcome) as $field) {
            if (blank($closeout->$field)) {
                $errors[$field] = $field === 'diagnosis'
                    ? 'Diagnosis is required for a Service Visit.'
                    : 'Work performed is required.';
            }
        }

        foreach ($this->requirements->returnTripFields($closeout->outcome) as $field) {
            if (blank($closeout->$field)) {
                $errors[$field] = 'Return reason is required when a return Visit is needed.';
            }
        }
        if ($closeout->outcome === 'on_hold') {
            if (blank($closeout->hold_reason)) {
                $errors['hold_reason'] = 'Hold reason is required.';
            }
            if (blank($closeout->recommendations)) {
                $errors['recommendations'] = 'Recommendations are required when work is placed on hold.';
            }
        }
        if ($closeout->outcome === 'customer_unavailable') {
            if (blank($closeout->unavailable_category)) {
                $errors['unavailable_category'] = 'Choose a customer unavailable reason.';
            }
            if (blank($closeout->unavailable_detail)) {
                $errors['unavailable_detail'] = 'Customer unavailable details are required.';
            }
        }
        if (in_array($closeout->outcome, ['resolved', 'needs_return_trip', 'on_hold'], true)) {
            if (filled($closeout->ack_unavailable_category)) {
                if (blank($closeout->ack_unavailable_detail)) {
                    $errors['ack_unavailable_detail'] = 'Acknowledgment fallback details are required.';
                }
            } elseif (blank($closeout->representative_name)) {
                $errors['representative_name'] = 'Enter the POC or customer name for on-site signature, or choose an acknowledgment fallback.';
            } elseif ($requireSignature && ! $signatureProvided && ! $closeout->acknowledgmentSignature()->exists()) {
                $errors['signature_data'] = 'Capture the on-site POC signature before submitting.';
            }
            if (blank($closeout->ack_unavailable_category) && filled($closeout->ack_unavailable_detail)) {
                $errors['ack_unavailable_category'] = 'Choose the acknowledgment fallback category.';
            }
        }
        if ($closeout->outcome === 'resolved' && ! VisitMedia::query()->whereIn('closeout_id', $this->versionIds($closeout))->where('state', 'stored')->exists()) {
            if (blank($closeout->no_photo_category) && blank($closeout->no_photo_detail)) {
                $errors['no_photo_category'] = 'Add a photo or complete the no-photo fallback.';
            } elseif (blank($closeout->no_photo_category)) {
                $errors['no_photo_category'] = 'Choose why photo evidence could not be provided.';
            } elseif (blank($closeout->no_photo_detail)) {
                $errors['no_photo_detail'] = 'No-photo fallback details are required.';
            }
        }

        return $errors;
    }

    /** @return array<int, int> */
    private function versionIds(Closeout $closeout): array
    {
        $ids = [];
        do {
            $ids[] = $closeout->id;
            $closeout = $closeout->parent;
        } while ($closeout);

        return $ids;
    }
}
