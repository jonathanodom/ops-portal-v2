@php($formatQuantity = fn (int $millis) => rtrim(rtrim(number_format($millis / 1000, 3, '.', ''), '0'), '.'))
<x-layouts.office :title="$package->name" width="detail">
    @if(session('status'))
        <div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>
    @endif

    <x-form-errors />

    <x-office.record-header
        :title="$package->name"
        :back-href="route('office.catalog.packages.index')"
        back-label="Packages"
        :description="$package->customer_description ?: 'No customer description.'"
    >
        <x-slot:badges>
            <span class="{{ $package->active ? 'status-success' : 'status-muted' }}">{{ $package->active ? 'Active' : 'Inactive' }}</span>
            <span class="status-active">{{ Str::headline($package->pricing_model) }}</span>
        </x-slot:badges>

        @if($activeMembership->hasCapability('catalog.manage'))
            <x-slot:actions>
                <a class="button-primary" href="{{ route('office.catalog.packages.edit', $package) }}">Edit package</a>
            </x-slot:actions>
        @endif
    </x-office.record-header>

    <x-office.catalog-tabs />
    <x-office.detail-nav :items="['overview' => 'Overview', 'recipe' => 'Standard recipe', 'demand' => 'Demand preview']" />

    <div class="office-detail-grid">
        <div class="office-detail-main">
            <section id="overview" class="surface scroll-mt-6 p-5">
                <h2 class="text-xl font-bold">Customer-facing Package</h2>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-semibold text-slate-500">Package code</dt>
                        <dd class="mt-1 font-bold">{{ $package->package_code }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-slate-500">Category</dt>
                        <dd class="mt-1">{{ $package->category?->name ?? 'Uncategorized' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-slate-500">Sales unit</dt>
                        <dd class="mt-1">Per {{ strtolower($package->salesUom->name) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-semibold text-slate-500">Recipe components</dt>
                        <dd class="mt-1">{{ $package->components->where('active', true)->count() }} active</dd>
                    </div>
                </dl>

                @if($package->internal_description)
                    <div class="mt-5 border-t border-slate-200 pt-4">
                        <h3 class="font-bold">Internal definition</h3>
                        <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $package->internal_description }}</p>
                    </div>
                @endif
            </section>

            <section id="recipe" class="surface scroll-mt-6 p-5">
                <h2 class="text-xl font-bold">Standard recipe</h2>
                <p class="mt-1 text-sm text-slate-600">Expected quantities per one {{ strtolower($package->salesUom->name) }}. Actual field consumption will be stored separately in a later phase.</p>

                <div class="mt-5 space-y-4">
                    @forelse($package->components as $component)
                        @php($item = $component->component_type === 'product' ? $component->product : $component->service)
                        @if($item)
                            <article class="rounded-lg border border-slate-200 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ ucfirst($component->component_type) }}</p>
                                        <h3 class="mt-1 font-bold">{{ $item->name }}</h3>
                                        <p class="mt-1 text-sm text-slate-600">
                                            @if($component->quantity_basis === 'pull_allowance')
                                                {{ $formatQuantity($component->basis_count_millis) }} pulls ×
                                                {{ $formatQuantity($component->basis_quantity_millis) }} {{ Str::plural($component->componentUom->name, (int) ceil($component->basis_quantity_millis / 1000)) }} =
                                            @endif
                                            {{ $formatQuantity($component->quantity_millis) }} {{ Str::plural($component->componentUom->name, (int) ceil($component->quantity_millis / 1000)) }} per {{ strtolower($package->salesUom->name) }}
                                            @if($component->waste_basis_points)
                                                · {{ number_format($component->waste_basis_points / 100, 2) }}% planning waste
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @if($component->customer_visible)<span class="status-active">Customer visible</span>@endif
                                        <span class="{{ $component->active ? 'status-success' : 'status-muted' }}">{{ $component->active ? 'Active' : 'Inactive' }}</span>
                                    </div>
                                </div>

                                @if($component->internal_notes)
                                    <p class="mt-3 border-t border-slate-200 pt-3 text-sm text-slate-700">{{ $component->internal_notes }}</p>
                                @endif

                                @if($activeMembership->hasCapability('catalog.manage'))
                                    <details class="mt-3">
                                        <summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Edit recipe component</summary>
                                        <form method="POST" action="{{ route('office.catalog.packages.components.update', [$package, $component]) }}" class="grid gap-3 sm:grid-cols-2">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="component_type" value="{{ $component->component_type }}">

                                            <div>
                                                <label class="form-label" for="component_{{ $component->id }}">{{ ucfirst($component->component_type) }}</label>
                                                <select class="form-input" id="component_{{ $component->id }}" name="component_id" required>
                                                    @foreach($component->component_type === 'product' ? $products : $services as $candidate)
                                                        <option value="{{ $candidate->id }}" @selected($item->id === $candidate->id)>{{ $candidate->name }} ({{ $component->component_type === 'product' ? $candidate->product_code : $candidate->service_code }})</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            @if($component->component_type === 'product')
                                                <div>
                                                    <label class="form-label" for="basis_{{ $component->id }}">Quantity basis</label>
                                                    <select class="form-input" id="basis_{{ $component->id }}" name="quantity_basis">
                                                        <option value="direct" @selected($component->quantity_basis === 'direct')>Direct quantity</option>
                                                        <option value="pull_allowance" @selected($component->quantity_basis === 'pull_allowance')>Pull count × allowance</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="form-label" for="quantity_{{ $component->id }}">Direct standard quantity</label>
                                                    <input class="form-input" id="quantity_{{ $component->id }}" name="quantity" inputmode="decimal" value="{{ $component->quantity_basis === 'direct' ? $formatQuantity($component->quantity_millis) : '' }}">
                                                </div>
                                                <div>
                                                    <label class="form-label" for="basis_count_{{ $component->id }}">Pull count</label>
                                                    <input class="form-input" id="basis_count_{{ $component->id }}" name="basis_count" inputmode="decimal" value="{{ $component->basis_count_millis ? $formatQuantity($component->basis_count_millis) : '' }}">
                                                </div>
                                                <div>
                                                    <label class="form-label" for="basis_quantity_{{ $component->id }}">Standard quantity per pull</label>
                                                    <input class="form-input" id="basis_quantity_{{ $component->id }}" name="basis_quantity" inputmode="decimal" value="{{ $component->basis_quantity_millis ? $formatQuantity($component->basis_quantity_millis) : '' }}">
                                                </div>
                                                <div>
                                                    <label class="form-label" for="waste_{{ $component->id }}">Waste percent</label>
                                                    <input class="form-input" id="waste_{{ $component->id }}" name="waste_percent" inputmode="decimal" value="{{ $component->waste_basis_points ? rtrim(rtrim(number_format($component->waste_basis_points / 100, 2, '.', ''), '0'), '.') : '' }}">
                                                </div>
                                            @else
                                                <input type="hidden" name="quantity_basis" value="direct">
                                                <input type="hidden" name="waste_percent" value="0">
                                                <div>
                                                    <label class="form-label" for="quantity_{{ $component->id }}">Standard quantity</label>
                                                    <input class="form-input" id="quantity_{{ $component->id }}" name="quantity" inputmode="decimal" value="{{ $formatQuantity($component->quantity_millis) }}" required>
                                                </div>
                                            @endif

                                            <div>
                                                <label class="form-label" for="sort_{{ $component->id }}">Sort order</label>
                                                <input class="form-input" id="sort_{{ $component->id }}" name="sort_order" type="number" min="0" value="{{ $component->sort_order }}" required>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="form-label" for="notes_{{ $component->id }}">Internal notes</label>
                                                <textarea class="form-textarea" id="notes_{{ $component->id }}" name="internal_notes" rows="2">{{ $component->internal_notes }}</textarea>
                                            </div>
                                            <div class="grid gap-2">
                                                <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="customer_visible" value="1" @checked($component->customer_visible)> Customer-visible component</label>
                                                <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="active" value="1" @checked($component->active)> Active</label>
                                            </div>
                                            <button class="button-secondary">Save component</button>
                                        </form>
                                    </details>
                                @endif
                            </article>
                        @endif
                    @empty
                        <p class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">No recipe components configured.</p>
                    @endforelse
                </div>

                @if($activeMembership->hasCapability('catalog.manage'))
                    <div class="mt-6 grid gap-5 border-t border-slate-200 pt-5 xl:grid-cols-2">
                        <form method="POST" action="{{ route('office.catalog.packages.components.store', $package) }}" class="rounded-lg border border-slate-200 p-4">
                            @csrf
                            <input type="hidden" name="component_type" value="product">
                            <h3 class="font-bold">Add Product</h3>
                            <div class="mt-3 grid gap-3">
                                <div>
                                    <label class="form-label" for="new_product_id">Product</label>
                                    <select class="form-input" id="new_product_id" name="component_id" required>
                                        <option value="">Select Product</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->product_code }}) · {{ $product->baseUom->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="new_product_basis">Quantity basis</label>
                                    <select class="form-input" id="new_product_basis" name="quantity_basis">
                                        <option value="direct">Direct quantity</option>
                                        <option value="pull_allowance">Pull count × allowance</option>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">Use a pull allowance when the recipe must retain both the number of pulls and the standard length of each pull.</p>
                                </div>
                                <div>
                                    <label class="form-label" for="new_product_quantity">Direct standard quantity</label>
                                    <input class="form-input" id="new_product_quantity" name="quantity" inputmode="decimal" placeholder="350">
                                </div>
                                <div>
                                    <label class="form-label" for="new_product_basis_count">Pull count</label>
                                    <input class="form-input" id="new_product_basis_count" name="basis_count" inputmode="decimal" placeholder="2">
                                </div>
                                <div>
                                    <label class="form-label" for="new_product_basis_quantity">Standard quantity per pull</label>
                                    <input class="form-input" id="new_product_basis_quantity" name="basis_quantity" inputmode="decimal" placeholder="175">
                                </div>
                                <div>
                                    <label class="form-label" for="new_product_waste">Waste percent</label>
                                    <input class="form-input" id="new_product_waste" name="waste_percent" inputmode="decimal" placeholder="Optional, such as 5">
                                </div>
                                <div>
                                    <label class="form-label" for="new_product_notes">Internal notes</label>
                                    <textarea class="form-textarea" id="new_product_notes" name="internal_notes" rows="2"></textarea>
                                </div>
                                <input type="hidden" name="sort_order" value="0">
                                <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="customer_visible" value="1"> Customer-visible component</label>
                                <input type="hidden" name="active" value="1">
                                <button class="button-primary">Add Product</button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('office.catalog.packages.components.store', $package) }}" class="rounded-lg border border-slate-200 p-4">
                            @csrf
                            <input type="hidden" name="component_type" value="service">
                            <input type="hidden" name="quantity_basis" value="direct">
                            <input type="hidden" name="waste_percent" value="0">
                            <h3 class="font-bold">Add Service</h3>
                            <div class="mt-3 grid gap-3">
                                <div>
                                    <label class="form-label" for="new_service_id">Service</label>
                                    <select class="form-input" id="new_service_id" name="component_id" required>
                                        <option value="">Select Service</option>
                                        @foreach($services as $service)
                                            <option value="{{ $service->id }}">{{ $service->name }} ({{ $service->service_code }}) · {{ $service->salesUom->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="new_service_quantity">Standard quantity</label>
                                    <input class="form-input" id="new_service_quantity" name="quantity" inputmode="decimal" placeholder="1" required>
                                </div>
                                <div>
                                    <label class="form-label" for="new_service_notes">Internal notes</label>
                                    <textarea class="form-textarea" id="new_service_notes" name="internal_notes" rows="2"></textarea>
                                </div>
                                <input type="hidden" name="sort_order" value="0">
                                <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="customer_visible" value="1"> Customer-visible component</label>
                                <input type="hidden" name="active" value="1">
                                <button class="button-primary">Add Service</button>
                            </div>
                        </form>
                    </div>
                @endif
            </section>

            <section id="demand" class="surface scroll-mt-6 p-5">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold">Expected demand preview</h2>
                        <p class="mt-1 text-sm text-slate-600">Standard recipe demand is forecasting information, not actual consumption.</p>
                    </div>
                    <form method="GET" class="flex flex-wrap items-end gap-2">
                        <div>
                            <label class="form-label" for="quantity">Package quantity</label>
                            <input class="form-input max-w-40" id="quantity" name="quantity" inputmode="decimal" value="{{ $quantity }}" required>
                        </div>
                        <button class="button-secondary">Calculate</button>
                    </form>
                </div>
                <div class="mt-5 rounded-lg border border-brand-blue/30 bg-blue-50 p-4">
                    <p class="text-sm font-semibold text-slate-600">Customer-facing transaction</p>
                    <p class="mt-1 text-lg font-bold text-slate-950">{{ $formatQuantity($quantityMillis) }} × {{ $package->name }}</p>
                </div>

                <div class="mt-5">
                    <h3 class="font-bold">Product demand</h3>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <thead class="border-b border-slate-200 text-slate-600">
                                <tr><th class="px-3 py-3">Product</th><th class="px-3 py-3">Standard</th><th class="px-3 py-3">Planning with waste</th></tr>
                            </thead>
                            <tbody>
                                @forelse($demand['products'] as $row)
                                    <tr class="border-b border-slate-100">
                                        <td class="px-3 py-3"><strong>{{ $row['product_name'] }}</strong><span class="block text-xs text-slate-500">{{ $row['product_code'] }}</span></td>
                                        <td class="px-3 py-3">{{ $formatQuantity($row['standard_quantity_millis']) }} {{ Str::plural($row['uom_name'], (int) ceil($row['standard_quantity_millis'] / 1000)) }}</td>
                                        <td class="px-3 py-3">{{ $formatQuantity($row['planning_quantity_millis']) }} {{ Str::plural($row['uom_name'], (int) ceil($row['planning_quantity_millis'] / 1000)) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="px-3 py-6 text-center text-slate-500">No active Product demand.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($demand['services']->isNotEmpty())
                    <div class="mt-5">
                        <h3 class="font-bold">Service demand</h3>
                        <ul class="mt-3 space-y-2">
                            @foreach($demand['services'] as $row)
                                <li class="rounded-lg border border-slate-200 p-3 text-sm"><strong>{{ $row['service_name'] }}</strong> · {{ $formatQuantity($row['standard_quantity_millis']) }} {{ Str::plural($row['uom_name'], (int) ceil($row['standard_quantity_millis'] / 1000)) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        </div>

        <aside class="office-detail-rail">
            <section class="surface p-5">
                <h2 class="font-bold">Package pricing</h2>
                <p class="mt-3 text-2xl font-bold">{{ $package->default_price_cents === null ? 'Quote required' : '$'.number_format($package->default_price_cents / 100, 2) }}</p>
                <p class="mt-1 text-sm text-slate-600">Per {{ strtolower($package->salesUom->name) }}</p>
                <dl class="mt-5 space-y-3 border-t border-slate-200 pt-4 text-sm">
                    <div><dt class="font-semibold text-slate-500">Tax default</dt><dd>{{ $package->taxable ? 'Taxable' : 'Non-taxable' }}</dd></div>
                    <div><dt class="font-semibold text-slate-500">Standard versus actual</dt><dd>Recipe quantities remain the estimating standard. Actual usage is not recorded here.</dd></div>
                </dl>
            </section>
        </aside>
    </div>
</x-layouts.office>
