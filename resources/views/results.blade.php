<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark:bg-neutral-900">
    <head>
        @include('partials.head', ['title' => 'Results'])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-neutral-900">
        <main class="mx-auto max-w-4xl space-y-8 p-6 lg:p-10">
            <header>
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                    Laravel Test Pattern Analyzer — results
                </h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                    Read-only view over the emitted dataset ({{ number_format(\App\Models\TestObservation::count()) }}
                    observations across {{ \App\Models\Repository::count() }} repositories).
                </p>
            </header>

            <livewire:results.version-trends />
            <livewire:results.ai-comparison />
        </main>
        @fluxScripts
    </body>
</html>
