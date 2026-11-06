@if(session('success'))
    <div role="status" class="mb-6"><x-alert color="indigo" light icon="check-circle" :text="session('success')" /></div>
@endif
@if($errors->any())
    <div role="alert" class="mb-6">
        <x-alert color="red" light icon="exclamation-circle" title="Bitte prüfen Sie die Angaben.">
            <ul class="list-inside list-disc space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-alert>
    </div>
@endif
