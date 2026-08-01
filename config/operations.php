<?php

return [
    'ready_handoff_warning_hours' => (int) env('OPS_READY_HANDOFF_WARNING_HOURS', 24),
    'active_visit_warning_hours' => (int) env('OPS_ACTIVE_VISIT_WARNING_HOURS', 24),
    'closeout_warning_hours' => (int) env('OPS_CLOSEOUT_WARNING_HOURS', 48),
];
