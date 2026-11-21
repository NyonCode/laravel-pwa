{{--
  <x-pwa::update-toast/> — "A new version is available" with Update and Later.
  Hidden until a new service worker waits; Update activates it and reloads the page.
  With this component on the page, the built-in update bar is not shown.

  <x-pwa::update-toast class="my-toast">The app was updated.</x-pwa::update-toast>
  <x-pwa::update-toast :styles="false" />
--}}
@props(['dismissible' => true, 'styles' => true])

@if ($styles)
    @include('pwa::partials.ui-styles')
@endif

<div data-pwa-update hidden role="status" aria-live="polite" {{ $attributes->merge(['class' => 'pwa-ui pwa-toast']) }}>
    <span class="pwa-toast__text">{{ $slot->isEmpty() ? __('pwa::messages.update_available') : $slot }}</span>
    <span class="pwa-toast__actions">
        @if ($dismissible)
            <button type="button" data-pwa-update-dismiss class="pwa-toast__dismiss">{{ __('pwa::messages.later') }}</button>
        @endif
        <button type="button" data-pwa-update-apply class="pwa-toast__apply">{{ $apply ?? __('pwa::messages.update_apply') }}</button>
    </span>
</div>
