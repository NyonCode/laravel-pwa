<x-demo::layout :title="'Orders'" :base="$base">
    <h1 id="title">Orders</h1>
    <p class="muted">Open this page once while online; it then opens offline too.</p>
    @foreach ([['1001', 'Shipped'], ['1002', 'Packing'], ['1003', 'Waiting for payment']] as [$number, $state])
        <div class="card"><strong>Order {{ $number }}</strong> · <span class="muted">{{ $state }}</span></div>
    @endforeach
</x-demo::layout>
