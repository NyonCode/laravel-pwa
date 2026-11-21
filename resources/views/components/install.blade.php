{{--
  <x-pwa::install/> — install button and instructions for iPhone / iPad (Share → Add to Home Screen).
  Both stay hidden until installing makes sense (see config pwa.install); pwa.js shows them.

  <x-pwa::install class="btn">Install the app</x-pwa::install>
  <x-pwa::install ios="hint" />         one-line hint instead of the step list
  <x-pwa::install ios="none" />         no iOS instructions
  <x-pwa::install :styles="false" />    no base styles (style the pwa-* classes yourself)
--}}
@props(['dismissible' => true, 'ios' => 'sheet', 'styles' => true])

@if ($styles)
    @include('pwa::partials.ui-styles')
@endif

<button type="button" data-pwa-install hidden {{ $attributes->merge(['class' => 'pwa-install']) }}>
    {{ $slot->isEmpty() ? __('pwa::messages.install') : $slot }}
</button>

@if ($ios !== 'none')
<div data-pwa-ios-hint hidden class="pwa-ui pwa-ios-hint pwa-ios-hint--{{ $ios === 'hint' ? 'hint' : 'sheet' }}" role="note">
    @isset($iosHint)
        {{ $iosHint }}
    @elseif ($ios === 'hint')
        <p>{{ __('pwa::messages.ios_hint') }}</p>
    @else
        <p class="pwa-ios-hint__title">{{ __('pwa::messages.install_title') }}</p>
        <ol>
            <li>
                {{ __('pwa::messages.ios_step_share') }}
                <svg class="pwa-ios-hint__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4M6 11H5v10h14V11h-1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </li>
            <li>{{ __('pwa::messages.ios_step_add') }}</li>
        </ol>
    @endisset
    @if ($dismissible)
        <button type="button" data-pwa-install-dismiss class="pwa-ios-hint__close">{{ __('pwa::messages.dismiss') }}</button>
    @endif
</div>
@endif
