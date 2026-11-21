{{--
  <x-pwa::push-toggle/> — turns web push on and off for this device (pwa.push.enabled).
  pwa.js sets data-pwa-push-state (subscribed, unsubscribed, denied, install-required) and shows the
  matching label; the component stays hidden where push is not available.

  <x-pwa::push-toggle class="btn" />
  <x-pwa::push-toggle>
      <x-slot:enable>Notify me about orders</x-slot:enable>
  </x-pwa::push-toggle>
--}}
@props(['styles' => true])

@if ($styles)
    @include('pwa::partials.ui-styles')
@endif

<div data-pwa-push hidden class="pwa-ui pwa-push">
    <button type="button" data-pwa-push-toggle aria-pressed="false" {{ $attributes->merge(['class' => 'pwa-push__toggle']) }}>
        <span data-pwa-push-label="unsubscribed">{{ $enable ?? __('pwa::messages.push_enable') }}</span>
        <span data-pwa-push-label="subscribed" hidden>{{ $disable ?? __('pwa::messages.push_disable') }}</span>
        <span data-pwa-push-label="denied" hidden>{{ $enable ?? __('pwa::messages.push_enable') }}</span>
        <span data-pwa-push-label="install-required" hidden>{{ $enable ?? __('pwa::messages.push_enable') }}</span>
    </button>
    <small class="pwa-push__hint" data-pwa-push-label="denied" hidden>{{ $denied ?? __('pwa::messages.push_denied') }}</small>
    <small class="pwa-push__hint" data-pwa-push-label="install-required" hidden>{{ $installRequired ?? __('pwa::messages.push_install_required') }}</small>
</div>
