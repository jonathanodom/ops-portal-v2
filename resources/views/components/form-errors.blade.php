@if ($errors->any())
    <div class="mb-6 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900" role="alert" tabindex="-1">
        <p class="font-bold">Please correct the highlighted information.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
