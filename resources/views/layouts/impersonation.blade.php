@if(auth()->check() && session()->has('impersonator_id'))
    <div role="status" class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950">
        <div class="min-w-0">
            <p class="font-semibold">Impersonation aktiv</p>
            <p class="break-words text-sm">Sie sind als {{ auth()->user()->name }} ({{ auth()->user()->email }}) angemeldet.</p>
        </div>
        <form method="POST" action="{{ route('impersonation.destroy') }}">
            @csrf
            <x-button submit color="amber" text="Zum eigenen Konto zurück" />
        </form>
    </div>
@endif
