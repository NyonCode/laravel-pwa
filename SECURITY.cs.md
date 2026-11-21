# Bezpečnostní politika

## Podporované verze

| Verze | Bezpečnostní opravy |
| --- | --- |
| 1.x | poslední minor verze |

## Nahlášení zranitelnosti

Zranitelnost prosím nehlaste přes veřejné issue. Napište na **ondrej@nyoncode.cz** popis, dotčenou
verzi a pokud to jde, postup, jak ji vyvolat. Odpověď dostanete do 72 hodin.

Jakmile je oprava hotová, vyjde jako patch verze a jako bezpečnostní advisory na GitHubu
s poděkováním autorovi hlášení, pokud nechcete zůstat v anonymitě.

## Rozsah

Balíček ukládá stránky a API odpovědi v prohlížeči zvlášť pro každého uživatele, udržuje přihlášení,
ukládá push odběry a spouští service worker na vašem originu. Do rozsahu patří chyby, kdy se data
jednoho uživatele dostanou k jinému, požadavek dorazí na host, kam nemá, nebo lze worker přimět
servírovat obsah, který nemá. Co balíček dělá a co záměrně ne, popisuje
[bezpečnostní dokumentace](https://nyoncode.github.io/laravel-pwa/cs/security.html).
