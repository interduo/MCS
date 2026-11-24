# MCS — Moja Czysta Sieć

Panel i zbieracz zgłoszeń o reputacji sieci dla małego ISP. MCS zbiera zgłoszenia nadużyć i listingi z wielu źródeł, łączy je z adresami IP własnej sieci (AS) i pokazuje jeden obraz: co jest zgłoszone, przez kogo i co z tym zrobić.

![PHP](https://img.shields.io/badge/PHP-%5E8.2-777bb4) ![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.7-ee4623) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-required-336791) ![License](https://img.shields.io/badge/license-MIT-green)

![Panel MCS: filtry, liczniki incydentów i trendy dzienne źródeł zgłoszeń](docs/dashboard.jpg)

## Co robi

- Importuje zgłoszenia nadużyć z wielu źródeł przez wtyczki (IMAP, API, pliki CSV) — pełna lista w sekcji [Wtyczki źródeł](#wtyczki-źródeł).
- Skanuje własne prefiksy IPv4 na listach DNSBL (lokalny resolver `unbound`, limity zapytań, test kontrolny strefy) — `/dashboard/dnsbl`.
- Dopasowuje lokalnie darmowe listy Spamhaus DROP, DROPv6 i ASN-DROP do prefiksów AS — `/dashboard/spamhaus`.
- Importuje IOC abuse.ch ThreatFox (`ip` i `ip:port`); trafienie w adresie własnej sieci tworzy incydenty — `/dashboard/threatfox`.
- Agreguje raporty speedtest Ookla Servers reporting per /24 (IPv4) i /48 (IPv6) — `/dashboard/speedtest`.
- Łączy dane w incydenty z wagą i deduplikacją, z widokiem per IP (`/ip/{adres}`) i enrichmentem (AbuseIPDB, DNSBL, GreyNoise, Ismalicious, SpamRats, LMS).
- Udostępnia API badge dla LMS+ (token), API enrichment (`api/enrichment/{zrodlo}?ip=`) oraz panel z logowaniem i rolami.

## Wtyczki źródeł

Każde źródło to katalog `plugins/{zrodlo}/` (kontrakt: [plugins/README.md](plugins/README.md)). Nowe źródła startują jako wstrzymane.

| Wtyczka | Co pobiera | Incydenty |
|---|---|---|
| `shadowserver` | raporty Shadowserver (IMAP) | tak |
| `play` | zgłoszenia abuse Play | tak |
| `snds` | Microsoft SNDS (token) | tak |
| `mojecert` | MojeCert (API) | tak |
| `cert_n6` | CERT N6 (API: bearer / apiKey / cert klienta) | tak |
| `wanguard` | Wanguard (API) | tak |
| `wazuh` | Wazuh (API, alerty) | tak |
| `cloudflare_botnet` | Cloudflare Botnet Intelligence (API) | tak |
| `spoofer` | CAIDA Spoofer — BCP38, spoofowalne prefiksy | tak |
| `blocklist_de` | blocklist.de — ataki z ostatnich 48 h (ssh, mail, bruteforce, bots, strongips) | tak |
| `urlhaus` | URLhaus abuse.ch — recent CSV (adresy malware) | tylko gdy host = adres IP |
| `threatfox` | ThreatFox abuse.ch — IOC `ip` / `ip:port`, wymaga Auth-Key | tak (trafienia) |
| `spamhaus` | lokalne dopasowanie DROP / DROPv6 / ASN-DROP do prefiksów AS | tak |
| `dnsbl` | skaner DNSBL własnych adresów IP (z panelu lub cron) | tak |
| `greynoise` | GreyNoise Community API — enrichment IP | tylko enrichment |
| `ismalicious` | isMalicious — enrichment IP | tylko enrichment |
| `spamrats` | SpamRats RATS-All — enrichment IP | tylko enrichment |
| `lms_mapowanie` | LMS — IP → komputer / abonent | tylko enrichment |
| `speedtest` | Ookla Servers reporting (portal lub CSV) — agregaty /24, /48 | tylko enrichment |

Enrichment `abuseipdb` nie jest wtyczką — działa jako serwis: `php spark mcs:abuseipdb <ip>`.

## Enrichment

- Panel per IP: `GET /ip/{ip}/enrichment/{abuseipdb,dnsbl,greynoise,ismalicious,lms,spamrats}` czyta cache; `POST` tego samego adresu (z tokenem CSRF) wymusza odpytanie API.
- API: `GET api/enrichment/{zrodlo}?ip={ip}&force=1` — te same źródła, JSON: `400` nieznane źródło, `429` rate limit z nagłówkiem `Retry-After`, `503` brak klucza / konfiguracji.
- Klucze API ustawiasz w `.env` albo przyciskiem „Klucz” na wierszu wtyczki w `/admin/pluginy` (np. GreyNoise, ThreatFox, URLhaus, SNDS).
- Enrichment nie trafia do indeksu badge — osobne tabele `mcs_enrichment_*` i widoki `/dashboard/{dnsbl,spamhaus,threatfox,speedtest}`.

## Wymagania

- PHP 8.2 lub nowszy, rozszerzenia wymagane przez CodeIgniter 4.7
- PostgreSQL
- Composer
- Dla skanera DNSBL: lokalny resolver rekurencyjny (np. `unbound` na 127.0.0.1)

## Instalacja

```bash
composer install
cp .env.example .env     # uzupełnij dane bazy i mcs.badgeToken
php spark migrate
php spark mcs:seed-pluginy
php spark mcs:create-user admin@example.local 'hasło' superadmin
php spark mcs:pobierz all   # import; uruchamiaj z crona co 15 min
```

Docroot serwera WWW: `public/`. Sekrety trzymaj wyłącznie w `.env`. Nie commituj tego pliku.

## Upgrade

```bash
git pull
composer install
php spark migrate
```

`php spark mcs:seed-pluginy` uruchom dodatkowo, gdy CHANGELOG dorzuca nowe wtyczki źródeł.

## Testy

```bash
vendor/bin/phpunit
```

## Architektura

- Rdzeń: CodeIgniter 4 (`app/`), baza PostgreSQL, migracje w `app/Database/Migrations`.
- Źródła: wtyczki w `plugins/{zrodlo}/` (interfejs `SourcePluginInterface`). Opis kontraktu: [plugins/README.md](plugins/README.md).
- Nowe źródła startują jako wstrzymane (`import_wstrzymany=true`). Włączasz je świadomie w panelu.
- Błąd pobrania nigdy nie oznacza "czysto". Brak danych jest osobnym statusem.
- Integracje na hoście LMS+ siedzą w `scaffold/`: [lms-mcs-badge](scaffold/lms-mcs-badge/README.md) (badge API) i [lms-mcs-mapowanie](scaffold/lms-mcs-mapowanie/README.md) (IP → komputer/abonent). Każdy katalog ma własną instrukcję instalacji.

## Bezpieczeństwo

Zasady zgłaszania luk: [SECURITY.md](SECURITY.md). Nie umieszczaj kluczy API, haseł ani tokenów w repozytorium.

## Wdrożenie i bezpieczeństwo

**TLS na reverse proxy.** Wystaw panel tylko przez HTTPS, np. Caddy albo nginx z certyfikatem. Aplikacja za proxy działa po HTTP.
- Wpisz adres proxy w `.env`: `app.proxyIPs = '192.0.2.247=X-Forwarded-For'`. Bez tego MCS widzi IP proxy zamiast IP klienta. Limit logowania liczy wtedy wszystkich jako jednego klienta.
- Proxy musi wysyłać `X-Forwarded-Proto: https`. Caddy robi to domyślnie.
- Dodatkowe nazwy hosta wpisz w `app.allowedHostnames`. `app.httpHostnames` zostaw puste, chyba że świadomie wchodzisz bez TLS w LAN.

**Ciasteczka.** `Config\Cookie::$secure` jest domyślnie `true` i działa jak tryb „auto”:
- żądanie przez HTTPS albo przez zaufane proxy z `X-Forwarded-Proto: https`: ciasteczka sesji i CSRF mają flagę `Secure`,
- żądanie po czystym HTTP (np. adres LAN, healthcheck): ciasteczka bez flagi `Secure`.
- `cookie.secure = false` w `.env` wyłącza flagę zawsze. Nie zalecamy tego.

Logowanie po czystym HTTP wysyła hasło i sesję otwartym tekstem. Używaj go tylko w zaufanej sieci testowej.

**Nagłówki.** Filtr `secureheaders` (`App\Filters\McsSecureHeaders`) działa dla każdej odpowiedzi:
- `X-Frame-Options: DENY` i `Content-Security-Policy: frame-ancestors 'none'` (ochrona przed clickjackingiem),
- `X-Content-Type-Options: nosniff`,
- `Referrer-Policy: same-origin`.

Pełne CSP nie jest włączone, bo widoki mają skrypty inline. HSTS ustaw na reverse proxy.

**Ograniczenie dostępu po IP.** Panel nie jest przeznaczony dla Internetu. Na reverse proxy zezwól tylko na adresy biura i VPN, np. w Caddy:

```caddy
mcs.example.net {
    @blocked not remote_ip 192.0.2.0/24 2001:db8::/48
    respond @blocked "Not allowed." 403
    reverse_proxy 192.0.2.10
}
```

**Role.** `operator` czyta dane i zleca skan DNSBL. `admin` zmienia wtyczki i ustawienia. `superadmin` zarządza kontami. Dawaj najniższą rolę, która wystarcza.

**Sekrety w `.env`.** Hasło bazy, `mcs.badgeToken`, klucze API i hasła IMAP trzymaj tylko w `.env`.
- Ustaw prawa pliku: właściciel `www-data`, tryb `0640` albo `0600`.
- Plik jest w `.gitignore`. Nie kopiuj go do zgłoszeń ani czatów.

**Rotacja tokenów.** Zmień sekret od razu, gdy mógł wyciec, oraz po odejściu osoby z dostępem.
- `mcs.badgeToken`: wygeneruj nowy (`openssl rand -hex 32`), wpisz w `.env` MCS i w `lms.ini` na LMS w tym samym oknie.
- Klucze API dostawców: wygeneruj nowe w panelu dostawcy, potem usuń stare.
- Tokeny zapisane w panelu (SNDS) są w tabeli `mcs_ustawienia`. Kopia bazy zawiera je otwartym tekstem.

**Backup.** Rób kopię bazy (`pg_dump -Fc mcs`) i pliku `.env`. Kopia `.env` i bazy zawiera sekrety, więc szyfruj ją i ogranicz dostęp.

**Limit logowania.** Po 5 próbach na jeden e-mail albo 20 próbach z jednego IP w ciągu 10 minut panel odrzuca kolejne próby. Komunikat nie mówi, czy konto istnieje. Liczniki są w cache aplikacji (`writable/cache`). Odblokowanie ręczne: usuń pliki `writable/cache/throttler_mcslogin_*`.

**Skaner DNSBL z WWW.** Jeden skan ma limit IP (`mcs.dnsbl.skanerMaksIpWww`, domyślnie 4096) i jest dzienny limit skanów (`mcs.dnsbl.skanerLimitDobaWww`, domyślnie 20 na 24 h). Naraz działa jeden skan. Zmienisz to w `/admin/ustawienia/opcje`.

## Dane zewnętrzne i atrybucja

- Spamhaus DROP / DROPv6 / ASN-DROP: dane © The Spamhaus Project SLU, używane zgodnie z DROP Fair Use Policy. Panel pokazuje źródło i datę pliku. MCS nie odpytuje `zen.spamhaus.org` ani `check.spamhaus.org` automatem.
- Pozostałe źródła: obowiązują ich własne regulaminy i limity. Sprawdź je przed włączeniem wtyczki.
- Używane biblioteki: zobacz `composer.lock` (CodeIgniter 4, licencja MIT).

## Licencja

MIT. Tekst w pliku [LICENSE](LICENSE).

## Konfiguracja

Wszystkie opcje są opisane w komentarzach pliku [.env.example](.env.example). Kontrakt wtyczek źródeł: [plugins/README.md](plugins/README.md).

Przykład crona (co 15 minut):

```cron
*/15 * * * * www-data cd /var/www/mcs && php spark mcs:pobierz all
```
