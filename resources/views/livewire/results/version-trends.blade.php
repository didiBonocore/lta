<section class="space-y-4 rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Per-version state</h2>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Instrument A — version-boundary snapshots, grouped by integer Laravel major.
            </p>
        </div>

        <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
            Metric
            <select wire:model.live="metric"
                    class="rounded-lg border border-neutral-300 bg-white px-2 py-1 text-sm dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-100">
                @foreach ($metrics as $option)
                    <option value="{{ $option }}">{{ str_replace('_', ' ', $option) }}</option>
                @endforeach
            </select>
        </label>
    </div>

    @if ($rows->isEmpty())
        <p class="text-sm text-neutral-500 dark:text-neutral-400">
            No version-boundary observations yet — run <code>analyse:snapshot</code> and <code>analyse:extract</code>.
        </p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                    <th class="py-2 pr-4 font-medium">Laravel major</th>
                    <th class="py-2 pr-4 font-medium">n</th>
                    <th class="py-2 pr-4 font-medium">mean</th>
                    <th class="py-2 pr-4 font-medium">median</th>
                    <th class="py-2 font-medium">mean, scaled</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr wire:key="major-{{ $row['major'] }}" class="border-b border-neutral-100 dark:border-neutral-800">
                        <td class="py-2 pr-4 font-medium text-neutral-900 dark:text-neutral-100">{{ $row['major'] }}</td>
                        <td class="py-2 pr-4 tabular-nums text-neutral-700 dark:text-neutral-300">{{ $row['n'] }}</td>
                        <td class="py-2 pr-4 tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format($row['mean'], 2) }}</td>
                        <td class="py-2 pr-4 tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format($row['median'], 2) }}</td>
                        <td class="py-2">
                            <div class="h-3 w-full rounded bg-neutral-100 dark:bg-neutral-800">
                                <div class="h-3 rounded bg-neutral-900 dark:bg-neutral-200"
                                     style="width: {{ number_format(min(100, $row['mean'] / $maxMean * 100), 1) }}%"></div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="text-xs text-neutral-500 dark:text-neutral-400">
            trend: {{ str_replace('_', ' ', $metric) }} =
            {{ number_format($fit['slope'], 3) }} × major {{ $fit['intercept'] < 0 ? '−' : '+' }}{{ number_format(abs($fit['intercept']), 3) }}
            (r² = {{ number_format($fit['r2'], 3) }}, n = {{ $fit['n'] }})
        </p>
    @endif
</section>
