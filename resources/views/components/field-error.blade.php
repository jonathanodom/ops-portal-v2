@props(['field', 'message' => null])

@php($fieldMessage = $errors->first($field) ?: $message)

@if($fieldMessage)
    <p id="{{ $field }}-error" class="mt-2 flex items-start gap-2 text-sm font-semibold text-red-700" role="alert">
        <span aria-hidden="true">!</span>
        <span>{{ $fieldMessage }}</span>
    </p>
@endif
