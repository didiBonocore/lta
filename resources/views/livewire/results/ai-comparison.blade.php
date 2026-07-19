<section class="space-y-4 rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Pre/post-AI authored flow</h2>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Instrument B — one observation per authored test method, split on its introducing
                commit's author-date vs {{ $cutoff->toDateString() }} (pre n={{ $nPre }}, post n={{ $nPost }}).
            </p>
        </div>

        <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
            Cutoff
            <select wire:model.live="anchor"
                    class="rounded-lg border border-neutral-300 bg-white px-2 py-1 text-sm dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-100">
                <option value="primary">Copilot GA ({{ config('analyser.ai_cutoff') }})</option>
                <option value="sensitivity">ChatGPT launch ({{ config('analyser.ai_cutoff_sensitivity') }})</option>
            </select>
        </label>
    </div>

    @if ($rows === [])
        <p class="text-sm text-neutral-500 dark:text-neutral-400">
            Insufficient data for a comparison — run <code>analyse:blame</code>, or both windows are empty at this cutoff.
        </p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                    <th class="py-2 pr-4 font-medium">metric</th>
                    <th class="py-2 pr-4 font-medium">median pre</th>
                    <th class="py-2 pr-4 font-medium">median post</th>
                    <th class="py-2 pr-4 font-medium">p (Mann-Whitney)</th>
                    <th class="py-2 pr-4 font-medium">Cliff's δ</th>
                    <th class="py-2 font-medium">magnitude</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr wire:key="metric-{{ $row['metric'] }}" class="border-b border-neutral-100 dark:border-neutral-800">
                        <td class="py-2 pr-4 font-medium text-neutral-900 dark:text-neutral-100">{{ str_replace('_', ' ', $row['metric']) }}</td>
                        <td class="py-2 pr-4 tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format($row['medianPre'], 2) }}</td>
                        <td class="py-2 pr-4 tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format($row['medianPost'], 2) }}</td>
                        <td class="py-2 pr-4 tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format($row['p'], 4) }}</td>
                        <td class="py-2 pr-4 tabular-nums text-neutral-700 dark:text-neutral-300">{{ number_format($row['delta'], 3) }}</td>
                        <td class="py-2 text-neutral-700 dark:text-neutral-300">{{ $row['magnitude'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
