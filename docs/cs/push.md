---
title: Push notifikace
description: "Volitelný modul web push: klíče VAPID, odběr pro každé zařízení, kanál pro Laravel notifikace, obsluha ve workeru, Pwa.push v prohlížeči, Declarative Web Push a WebPush::fake(); nebo vlastní push systém přes sw.import."
---

# Push notifikace

Balíček má volitelný modul web push, ve výchozím stavu vypnutý. Pokrývá celou cestu: klíče VAPID,
jeden odběr na zařízení, kanál pro Laravel notifikace, worker, který je zobrazí, a API v prohlížeči
pro přihlášení k odběru. Pokud už push systém máte, nechte si ho a použijte
[vlastní push systém](#vlastní-push-systém).

## Nastavení

```bash
composer require minishlink/web-push
php artisan pwa:push:vapid
php artisan vendor:publish --tag="pwa::migrations"
php artisan migrate
```

```dotenv
PWA_PUSH_ENABLED=true
PWA_VAPID_SUBJECT=mailto:ops@example.com
# zapíše pwa:push:vapid; na produkci je zkopírujte do prostředí serveru
PWA_VAPID_PUBLIC_KEY=…
PWA_VAPID_PRIVATE_KEY=…
```

```php
use NyonCode\LaravelPwa\Push\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasPushSubscriptions, Notifiable;
}
```

Pak spusťte `php artisan pwa:build` (worker nese veřejný klíč) a `php artisan pwa:doctor`, který
zkontroluje knihovnu, klíče, subject a tabulku.

| Požadavek | Proč |
| --- | --- |
| `minishlink/web-push` ^10 nebo ^11 | šifrování (RFC 8291) a podpisy VAPID (RFC 8292) |
| HTTPS | prohlížeče povolí push jen na zabezpečeném originu |
| přihlášený uživatel | odběry patří Eloquent modelu (`subscribable`, morph relace) |

Vlastníci s klíči UUID nebo ULID: v publikované migraci změňte `morphs()` na `uuidMorphs()` nebo
`ulidMorphs()`.

## Přihlášení k odběru v prohlížeči

Ptejte se po kliknutí, nikdy při načtení stránky: prohlížeče žádosti, o které nikdo nestál, blokují
nebo schovávají.

```js
button.addEventListener('click', async () => {
  const state = await Pwa.push.state()
  if (state === 'install-required') return showInstallHelp() // iPhone, iPad: jen aplikace na ploše
  if (state === 'denied') return showBlockedHelp()
  await Pwa.push.subscribe()
})

Pwa.on('push', ({ state }) => (button.hidden = state === 'subscribed'))
```

Nebo vložte [`<x-pwa::push-toggle/>`](components.md#x-pwapush-toggle): jedno tlačítko, které dělá totéž,
včetně textů pro zablokovaná oznámení a iPhone.

| `Pwa.push` | Vrací | Popis |
| --- | --- | --- |
| `supported()` | bool | push je zapnutý na serveru i v tomto prohlížeči |
| `permission()` | string | `default`, `granted`, `denied`, `unsupported` |
| `state()` | `Promise<string>` | `subscribed`, `unsubscribed`, `denied`, `install-required`, `unsupported` |
| `subscribe()` | `Promise<PushSubscription\|null>` | požádá o oprávnění, přihlásí odběr a uloží ho na server |
| `unsubscribe()` | `Promise<bool>` | odebere odběr na serveru i v prohlížeči |
| `toggle()` | Promise | `unsubscribe()`, když odběr existuje, jinak `subscribe()` |
| `subscription()` | `Promise<PushSubscription\|null>` | aktuální odběr prohlížeče |

Klient drží odběr v souladu sám:

- jednou denně (při keep-alive) ho potvrdí serveru; změněný endpoint nebo nový klíč VAPID uloží
  znovu;
- když prohlížeč odběr zahodil (iOS to dělá), vyšle `pwa:push` s `{state: 'unsubscribed', lost: true}`,
  takže můžete tlačítko nabídnout znovu;
- při odhlášení nebo přihlášení jiného uživatele odběr v prohlížeči zruší.

## Odesílání

```php
use Illuminate\Notifications\Notification;
use NyonCode\LaravelPwa\Push\WebPushChannel;
use NyonCode\LaravelPwa\Push\WebPushMessage;

class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class]; // nebo 'webpush'
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return WebPushMessage::create('Objednávka odeslána')
            ->body("Objednávka {$this->order->number} je na cestě.")
            ->url(route('orders.show', $this->order))
            ->tag("order-{$this->order->id}")
            ->action('track', 'Sledovat', route('orders.track', $this->order));
    }
}

$user->notify(new OrderShipped($order));
```

Bez třídy notifikace:

```php
use NyonCode\LaravelPwa\Facades\WebPush;

WebPush::send($user, WebPushMessage::create('Export je hotový')->url('/exports'));
WebPush::send($user->pushSubscriptions()->latest()->first(), $message); // jedno zařízení
```

| `WebPushMessage` | Účinek |
| --- | --- |
| `title()`, `body()` | text (titulek je povinný) |
| `icon()`, `badge()`, `image()` | výchozí: `pwa.push.icon` (nebo `icon-192.png`), `pwa.push.badge` (nebo `monochrome-512.png`) |
| `url()` | stránka otevřená po kliknutí; jen URL tohoto originu, cokoli jiného otevře scope |
| `action($id, $title, $url = null)` | tlačítko; vlastní URL, nebo URL zprávy |
| `tag($tag, renotify: false)` | nahradí dřívější notifikaci se stejným tagem |
| `requireInteraction()`, `silent()`, `lang()`, `dir()`, `timestamp()` | jako v `showNotification()` |
| `appBadge($n)` | číslo na ikoně aplikace, `0` ho smaže |
| `data([...])` | další data pro váš kód v `sw.extend` |
| `ttl()`, `urgency()`, `topic()` | doručení: výchozí `pwa.push.ttl` (28 dní), `pwa.push.urgency` |
| `declarative()` | [Declarative Web Push](#declarative-web-push) |
| `public()` | smí se ukázat komukoli na zařízení (viz [Sdílená zařízení](#sdílená-zařízení)) |

Šifrovaný obsah má limit 4078 bajtů; delší zpráva vyhodí výjimku dřív, než se cokoli odešle.
Podrobnosti nechte na stránce, kterou notifikace otevře.

## Výsledky doručení

| Push služba odpoví | Balíček |
| --- | --- |
| 2xx | nastaví `last_used_at` |
| 404, 410 | smaže odběr, vyšle `PushSubscriptionExpired` |
| cokoli jiného (413, 429, 5xx, síť) | odběr ponechá, vyšle `PushFailed` |

`WebPush::send()` i kanál vracejí seznam `Push\PushReport` (`endpoint`, `success`, `status`,
`reason`). Odběry, u kterých prohlížeč ohlásil `expirationTime`, odstraní
`php artisan model:prune --model="NyonCode\LaravelPwa\Push\PushSubscription"`.

## Sdílená zařízení

Push se řídí stejným pravidlem jako cache: data jednoho uživatele se nikdy nedostanou k dalšímu
člověku na zařízení.

- Odhlášení odebere odběry z této session na serveru i v prohlížeči.
- Každá zpráva nese hash příjemce. Když je na zařízení přihlášený někdo jiný (nebo nikdo), worker
  místo obsahu ukáže neutrální notifikaci („Máte nové oznámení. Otevřete aplikaci a podívejte se.“,
  `pwa::messages.push_fallback`). Safari vyžaduje notifikaci ke každému push, takže se nikdy
  nevynechá.
- Odběr přejde na uživatele, který se na zařízení přihlásí k odběru; automatickou obnovu workerem
  (`pushsubscriptionchange`) server přijme jen místo endpointu, který přihlášený uživatel vlastní.
- Zprávy pro příjemce, který není uživatel (tým, kanál), a zprávy `public()` se ukážou komukoli.

## Bezpečnost na serveru

| Riziko | Co balíček dělá |
| --- | --- |
| uživatel zaregistruje jako endpoint interní URL a server na ni pošle POST | přijímá a obsluhuje jen `https` endpointy na hostech z `pwa.push.endpoints` (FCM, Mozilla, Windows, Apple) |
| cizí stránka přihlásí účet oběti ke svému endpointu | routy jsou ve skupině `web` s CSRF a `auth`, omezené na 20 za minutu |
| jeden účet zaplní tabulku | `pwa.push.max_subscriptions` (10) na vlastníka; nejdéle nepoužitý jde první |
| uniklé klíče odběru | `public_key` a `auth_token` jsou `$hidden`; bez soukromého klíče VAPID nikdo nic neodešle |

Výměna klíčů VAPID (`pwa:push:vapid --force`) zneplatní všechny odběry. Prohlížeče se při příští
návštěvě přihlásí znovu s novým klíčem; do té doby nedostanou nic.

## Declarative Web Push

Safari 18.4+ (iOS, iPadOS) a macOS 15.5+ ukazují deklarativní zprávy bez spuštění workeru, což je na
iOS spolehlivější. Ostatní prohlížeče je předají workeru, který je zobrazí stejně.

```php
WebPushMessage::create('Objednávka odeslána')->url('/orders/7')->appBadge(1)->declarative();
```

Deklarativní zpráva potřebuje `url()`. Prohlížeč ji ukáže tak, jak přišla, takže kontrola uživatele
ve workeru se na ni nevztahuje: používejte ji pro zprávy, které jsou na sdíleném zařízení v pořádku.

## Ve workeru

S `pwa.push.handle_in_worker = true` (výchozí) worker:

- zobrazí každý push (`push`), i prostý text a prázdný;
- po kliknutí přepne na okno s URL, přesměruje okno aplikace, nebo otevře nové
  (`notificationclick`); stránka dostane `pwa:notification-click` s `{url, action}`;
- obnoví vyměněný odběr a uloží ho pro stejného uživatele (`pushsubscriptionchange`,
  `pwa:push-renewed`);
- dá otevřeným stránkám vědět o každém push (`pwa:push-received` s `{tag}`).

Nastavte `handle_in_worker` na `false`, když notifikace zobrazuje váš vlastní skript v `sw.import`;
routy pro odběry, kanál i `Pwa.push` fungují dál.

## Testování

```php
use NyonCode\LaravelPwa\Facades\WebPush;

WebPush::fake();

$user->notify(new OrderShipped($order));

WebPush::assertSentTo($user, fn (WebPushMessage $message) => $message->getTitle() === 'Objednávka odeslána');
WebPush::assertNotSentTo($otherUser);
WebPush::assertSentCount(1);
WebPush::assertNothingSent();
```

Fake sestaví každou zprávu (titulek, velikost obsahu), ale nic neodešle. `Notification::fake()`
funguje jako obvykle, když vás zajímá jen to, že se notifikace vyslala.

## Vlastní push systém

Používáte `laravel-notification-channels/webpush`, Firebase nebo SaaS? Nechte `push.enabled`
vypnuté a zapojte svůj kód do workeru balíčku. Na jednom scope smí být jen jeden service worker a ten
vlastní tento balíček: druhý worker registrovaný na `/` by ho nahradil.

### 1. Místo v service workeru

```php
// config/pwa.php
'sw' => ['import' => ['/push-sw.js']],
```

```js
// public/push-sw.js – dodá push systém
self.addEventListener('push', (event) => {
  const data = event.data?.json() ?? {}
  event.waitUntil(self.registration.showNotification(data.title ?? 'Oznámení', {
    body: data.body,
    data: { url: data.url },
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  event.waitUntil(clients.openWindow(event.notification.data?.url ?? '/'))
})

self.addEventListener('pushsubscriptionchange', (event) => { /* pošlete nový odběr na server */ })
```

Soubor se načte před runtime balíčku. Jeho obsah je součástí verze service workeru, takže se každá
změna k uživatelům dostane sama (po `pwa:build`).

### 2. Stav instalace v prohlížeči

```js
if (Pwa.isIos() && !Pwa.isInstalled()) {
  // na iPhonu/iPadu jde push zapnout jen v aplikaci na ploše
  showInstallHelp()
} else {
  button.onclick = () => pushSystem.subscribe() // jen po gestu uživatele
}
```

### 3. Události

| Událost | Co s ní push systém udělá |
| --- | --- |
| `pwa:installed` | nabídne zapnutí notifikací |
| `pwa:resume` | zavolá `pushManager.getSubscription()` a porovná se serverem (na iOS odběry mizí) |
| `pwa:logout` | smaže odběr na serveru a případně zavolá `subscription.unsubscribe()` |

### 4. Odznak

`Pwa.badge.set(n)` / `Pwa.badge.clear()` z okna; `self.navigator.setAppBadge(n)` ze service workeru.

## Co balíček garantuje

- Aktualizace service workeru ani režim údržby push odběr nezruší.
- Odhlášení posílá `Clear-Site-Data: "cache"`, ne `"storage"`, takže service worker přežije
  (vestavěný modul odběry daného uživatele odebere sám).
- **Výjimka**: `pwa:disable` odregistruje service worker, a tím zruší i odběry (příkaz na to upozorní).

## Pravidla platforem

| Platforma | Pravidlo |
| --- | --- |
| iPhone, iPad | jen aplikace přidané na plochu (iOS 16.4+), oprávnění jen po klepnutí; `Pwa.push.state()` v Safari vrací `install-required` |
| Safari (iOS 18.4+, macOS 15.5+) | Declarative Web Push: zobrazí se i bez spuštění workeru |
| Safari | každý push musí ukázat notifikaci, jinak Safari po několika vynecháních odběr zruší |
| Chrome, Edge, Firefox | standardní Web Push přes service worker |
| odběry | mohou vypršet bez zásahu uživatele; balíček je při 404/410 smaže |
