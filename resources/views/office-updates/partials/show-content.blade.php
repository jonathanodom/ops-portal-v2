<div class="space-y-5">
    @if(session('status'))<x-office.alert>{{ session('status') }}</x-office.alert>@endif
    <nav class="text-sm" aria-label="Breadcrumb"><a class="font-bold text-brand-blue" href="{{ route('office-updates.index') }}">← Office Updates</a></nav>
    <article class="border border-slate-300 bg-white p-5 sm:p-7">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Office Update</p>
        <h1 class="mt-2 break-words text-2xl font-bold text-slate-950 sm:text-3xl">{{ $update->title }}</h1>
        <div class="mt-6 whitespace-pre-wrap break-words text-base leading-7 text-slate-800">{{ $update->body }}</div>
        <dl class="mt-8 grid gap-4 border-t border-slate-200 pt-5 text-sm sm:grid-cols-2">
            <div><dt class="font-bold text-slate-500">Published by</dt><dd class="mt-1 text-slate-900">{{ $update->publishedBy->name }}</dd></div>
            <div><dt class="font-bold text-slate-500">Published</dt><dd class="mt-1 text-slate-900"><x-local-time :value="$update->published_at" :timezone="$activeOrganization->timezone" format="F j, Y \a\t g:i A" /></dd></div>
            <div><dt class="font-bold text-slate-500">Audience</dt><dd class="mt-1 text-slate-900">{{ str($update->audience_type)->replace('_', ' ')->title() }}</dd></div>
        </dl>
    </article>
</div>
