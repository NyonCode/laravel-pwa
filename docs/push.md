---
title: Push notifikace
description: Rozhraní mezi PWA balíčkem a samostatným push systémem – sw.import, události, stav instalace a pravidla platforem.
---

# Push notifikace

Balíček push notifikace **neimplementuje** – nemá VAPID klíče, tabulku odběrů, UI ani odesílání.
Staví je samostatný push systém. Vazba je jen technická: na jednom scope smí být jeden service
worker a ten vlastní tento balíček. Kdyby push systém registroval vlastní SW na `/`, jeden by
přepsal druhý.

## Rozhraní

### 1. Místo v service workeru

```php
// config/pwa.php
'sw' => ['import' => ['/push-sw.js']],
```

```js
// public/push-sw.js – dodá push systém
self.addEventListener('push', (event) => {
  const data = event.data?.json() ?? {}
  event.waitUntil(self.registration.showNotification(data.title ?? 'Upozornění', {
    body: data.body,
    data: { url: data.url },
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  event.waitUntil(clients.openWindow(event.notification.data?.url ?? '/'))
})

self.addEventListener('pushsubscriptionchange', (event) => { /* poslat nový odběr na server */ })
```

Soubor se načte před runtime balíčku; jeho obsah mění verzi SW, takže úprava se k uživatelům
dostane sama (po `pwa:build`).

### 2. Stav instalace v prohlížeči

```js
if (Pwa.isIos() && !Pwa.isInstalled()) {
  // na iPhonu/iPadu jde push povolit jen v aplikaci na ploše
  showInstallHelp()
} else {
  button.onclick = () => pushSystem.subscribe() // jen po gestu uživatele
}
```

### 3. Události

| Událost | Co s ní push systém udělá |
| --- | --- |
| `pwa:installed` | nabídnout povolení notifikací |
| `pwa:resume` | `pushManager.getSubscription()` a porovnat se serverem (odběry na iOS mizí) |
| `pwa:logout` | smazat odběr na serveru, případně `subscription.unsubscribe()` |

### 4. Badge

`Pwa.badge.set(n)` / `Pwa.badge.clear()` z okna; ze SW `self.navigator.setAppBadge(n)`.

## Co balíček zaručuje

- Aktualizace SW ani údržba push odběr neruší.
- Odhlášení posílá `Clear-Site-Data: "cache"`, ne `"storage"`, takže SW i odběr zůstanou.
- **Výjimka**: `pwa:disable` SW odregistruje, tím zaniknou i odběry (příkaz varuje).

## Pravidla platforem pro push systém

| Platforma | Pravidlo |
| --- | --- |
| iPhone, iPad | jen aplikace přidaná na plochu (iOS 16.4+), povolení jen po klepnutí |
| Safari (iOS 18.4+, macOS 15.5+) | umí Declarative Web Push: JSON s `web_push: 8030` se zobrazí i bez SW; odběr přes `window.pushManager` |
| Safari | každý push musí zobrazit notifikaci, jinak po několika případech odběr zruší |
| Chrome, Edge, Firefox | standardní Web Push přes SW |
| odběry | mohou zaniknout bez zásahu uživatele; serverová strana maže při 404/410 |

Odesílání ze serveru: např. `minishlink/web-push` nebo `laravel-notification-channels/webpush`.
