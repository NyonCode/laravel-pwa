{{--
  <x-pwa::outbox/> — changes made offline that the server refused when they were sent (offline.outbox):
  each with the reason and the validation messages, and Try again / Discard. Hidden while empty.

  <x-pwa::outbox class="my-outbox"><x-slot:title>Unsaved orders</x-slot:title></x-pwa::outbox>
  <x-pwa::outbox :styles="false" />
--}}
@props(['styles' => true])

@if ($styles)
    @include('pwa::partials.ui-styles')
@endif

<section data-pwa-outbox hidden aria-live="polite"
    data-pwa-outbox-refused="{{ __('pwa::messages.outbox_refused') }}"
    data-pwa-outbox-expired="{{ __('pwa::messages.outbox_expired') }}"
    {{ $attributes->merge(['class' => 'pwa-ui pwa-outbox']) }}>
    <p class="pwa-outbox__title">{{ $title ?? __('pwa::messages.outbox_title') }}</p>
    <p class="pwa-outbox__lead">{{ $slot->isEmpty() ? __('pwa::messages.outbox_lead') : $slot }}</p>
    <ul data-pwa-outbox-list class="pwa-outbox__list"></ul>
    <template data-pwa-outbox-item>
        <li class="pwa-outbox__item">
            <span class="pwa-outbox__what"><span data-pwa-outbox-field="method"></span> <span data-pwa-outbox-field="path"></span></span>
            <span class="pwa-outbox__why" data-pwa-outbox-field="reason"></span>
            <ul class="pwa-outbox__errors" data-pwa-outbox-errors hidden></ul>
            <span class="pwa-outbox__actions">
                <button type="button" data-pwa-outbox-retry>{{ __('pwa::messages.outbox_retry') }}</button>
                <button type="button" data-pwa-outbox-discard>{{ __('pwa::messages.outbox_discard') }}</button>
            </span>
        </li>
    </template>
</section>
