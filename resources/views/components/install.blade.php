{{--
  <x-pwa::install/> — install button and a hint for iPhone / iPad.
  Both stay hidden until installing makes sense (see config pwa.install).

  <x-pwa::install class="btn">Nainstalovat aplikaci</x-pwa::install>
--}}
@props(['dismissible' => true])

<button type="button" data-pwa-install hidden {{ $attributes->merge(['class' => 'pwa-install']) }}>
    {{ $slot->isEmpty() ? __('pwa::messages.install') : $slot }}
</button>

<div data-pwa-ios-hint hidden class="pwa-ios-hint" role="note">
    <p>{{ __('pwa::messages.ios_hint') }}</p>
    @if ($dismissible)
        <button type="button" data-pwa-install-dismiss class="pwa-ios-hint__close">{{ __('pwa::messages.dismiss') }}</button>
    @endif
</div>
