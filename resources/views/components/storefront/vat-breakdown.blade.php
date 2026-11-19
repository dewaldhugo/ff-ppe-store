@props(['rows', 'suffix' => 'ex VAT'])

{{-- The VAT by rate for a cart or an order: what is charged at each rate (ex VAT) and the VAT on it. Zero-rated goods are named. --}}
<div {{ $attributes->class('rounded border border-border bg-white p-3 text-xs') }} data-vat-breakdown>
    <p class="font-semibold text-ink">VAT breakdown</p>

    <table class="mt-2 w-full">
        <thead class="text-ink-muted">
            <tr>
                <th scope="col" class="pb-1 text-left font-normal">Rate</th>
                <th scope="col" class="pb-1 text-right font-normal">Goods and delivery {{ $suffix }}</th>
                <th scope="col" class="pb-1 text-right font-normal">VAT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr data-vat-row @if ($row['zeroRated']) data-zero-rated-row @endif>
                    <th scope="row" class="py-0.5 text-left font-semibold text-ink">{{ $row['label'] }}</th>
                    <td class="py-0.5 text-right">{{ $row['net'] }}</td>
                    <td class="py-0.5 text-right font-semibold text-ink">{{ $row['vat'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
