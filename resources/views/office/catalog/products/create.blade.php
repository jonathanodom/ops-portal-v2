<x-layouts.office title="Add product" width="form">
    <x-office.page-header title="Add product" description="Define a physical product and its base consumption and sales units." eyebrow="Catalog" />
    <x-office.catalog-tabs />
    <x-form-errors />
    <form class="office-form-shell p-4" method="POST" action="{{ route('office.catalog.products.store') }}">
        @csrf
        @include('office.catalog.products._form')
        <x-office.form-actions><a class="button-secondary" href="{{ route('office.catalog.products.index') }}">Cancel</a><button class="button-primary">Create product</button></x-office.form-actions>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const code = document.querySelector('[data-product-code-autofill]');
            const sku = document.getElementById('sku');
            const manufacturer = document.getElementById('manufacturer');
            const model = document.getElementById('model');
            if (!code || !sku || !manufacturer || !model) return;

            let manuallyEdited = code.value.trim() !== '';
            const fallback = () => [manufacturer.value, model.value]
                .map(value => value.trim())
                .filter(Boolean)
                .join('-')
                .toUpperCase()
                .replace(/[^A-Z0-9._-]+/g, '-')
                .replace(/^[._-]+|[._-]+$/g, '');
            const synchronize = () => {
                if (manuallyEdited) return;
                code.value = sku.value.trim() !== '' ? sku.value.trim().toUpperCase() : fallback();
            };

            code.addEventListener('input', () => { manuallyEdited = true; });
            sku.addEventListener('input', synchronize);
            manufacturer.addEventListener('input', synchronize);
            model.addEventListener('input', synchronize);
        });
    </script>
</x-layouts.office>
