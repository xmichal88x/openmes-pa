# PA Baseline — OpenMES (r23)

- **Data:** 2026-11-05
- **Obraz produkcyjny:** `ghcr.io/mes-open/openmes:port-20261105-r23`
- **Digest obrazu:** `sha256:afc6c1f842771e1725b101fa21746da391cefb4871bece0a18f473cda8cfa8bf`
- **Źródło overlayu:** LXC 122, `/root/build/openmes-r23` — drzewo, z którego
  zbudowano i wdrożono obraz r23.
- **Branch:** `pa/r23-baseline`
- **Tag baseline:** `pa-r23-20261105` (annotated)

## Co odtwarza baseline (mixed)

Baseline jest **mieszany** — to nie jest ani czysty `v0.27.1`, ani czysty
`v0.28.0`. Obraz r23 powstał z backendu 0.27.1 z patchami PA nałożonymi na
warstwę root/packages z 0.28.0.

| Obszar | Pochodzenie | Weryfikacja |
|---|---|---|
| `backend/` | upstream **v0.27.1** (`25fe932c9cf9440a8b06afb2856291c732fd3277`) + patche PA **R05–R22** | `git diff v0.27.1..HEAD -- backend`: 183 pliki, +23755/−710 |
| `backend/Dockerfile` | baza v0.27.1/v0.28.0 + PA R21 (`npm run lint && npm test`) oraz `php:8.3-fpm-alpine` | diff wobec obu tagów ograniczony do tych zmian |
| pliki root: `.github`, `docs`, `desktop`, `mobile`, `docker-compose*.yml`, `Caddyfile`, `README.md`, `package.json` itd. | upstream **v0.28.0** (tag `v0.28.0` → `eb184a2984b74d685e65ee24df9b8c7b34c765f6`) | brak różnic poza `PA-BASELINE.md` |
| `packages/` | upstream **v0.28.0** | 0 różnic |
| `modules/` (root) | upstream **v0.28.0** | 0 różnic |
| `backend/modules/` | przykłady upstream + moduły PA | patrz sekcja „Moduły" |

> **Uwaga:** backend w r23 **nie jest wersją 0.28**. Root, `packages/` i pliki
> wdrożeniowe są z 0.28.0, ale kod PHP backendu to 0.27.1 + patche PA. Nie należy
> opisywać tego baseline jako „OpenMES 0.28".

Baseline jest **stanem końcowym** (jeden commit importu). Historia commitów PA
(R05–R22) nie jest rekonstruowana w tym repo — mapowanie poniżej wskazuje tylko,
gdzie w drzewie znajduje się wynik danej zmiany.

### Placeholdery Laravela (storage / bootstrap cache)

Z `v0.27.1` przywrócono 11 plików-placeholderów, aby świeży klon miał katalogi
wymagane przez `backend/Dockerfile` (`chown -R ... /var/www/html/storage
/var/www/html/bootstrap/cache`):

- `backend/bootstrap/cache/.gitignore`
- `backend/storage/app/.gitignore`
- `backend/storage/app/private/.gitignore`
- `backend/storage/app/public/.gitignore`
- `backend/storage/framework/.gitignore`
- `backend/storage/framework/cache/.gitignore`
- `backend/storage/framework/cache/data/.gitignore`
- `backend/storage/framework/sessions/.gitignore`
- `backend/storage/framework/testing/.gitignore`
- `backend/storage/framework/views/.gitignore`
- `backend/storage/logs/.gitignore`

Wszystkie są **tracked** (trafiają do `git archive` i świeżego klonu). Poza nimi
nie ma żadnych innych deletions względem `v0.27.1`.

## Moduły

| Moduł | Lokalizacja | Typ | Opis (z `module.json`) |
|---|---|---|---|
| `PipelineDashboard` | `backend/modules/PipelineDashboard` | PA | Widoczność pipeline'u produkcji end-to-end: Woo → Dolibarr → MES → Nesting → Produkcja |
| `PlPackagingExtras` | `backend/modules/PlPackagingExtras` | PA | API pakowania P1 EAN: oczekiwane kody, atomowe PACK WO, guardy skanowania, etykiety PL |
| `ExampleHooks` | `backend/modules/`, `modules/` | upstream | Przykład systemu hooks/events OpenMES |
| `ExampleShowcase` | `backend/modules/`, `modules/` | upstream | Moduł referencyjny wszystkich punktów rozszerzeń OpenMES |
| `OrderPinger` | `backend/modules/`, `modules/` | upstream | Moduł tutorialowy (logi zamówień + KPI na dashboardzie) |

## Mapowanie R15–R22 → baseline

R05–R14 wchodzą w skład różnicy `backend/` wobec `v0.27.1`; poniżej
uszczegółowiono wyłącznie zmiany R15–R22.

| ID | Zakres | Gdzie w baseline |
|---|---|---|
| R15 | Preflight startu kroku CNC przed `BatchService::startStep` (blokady `rebuilding`, `merged/source`; confirm dla `merged/target`; opcja B: kolejka down → warning + start) | `backend/app/Services/WorkOrder/StartPreflightService.php`, `backend/app/Services/WorkOrder/BatchService.php`, `backend/config/start_preflight.php`, `backend/tests/Feature/StartPreflight/` |
| R16 | Lease startowy — domknięcie TOCTOU między preflight a startem (TTL 15 s, marker cancelu 600 s, fail-closed 503) | `StartPreflightService`, `BatchService`, `config/start_preflight.php`; strona runtime kolejki (`start_lease.py`, `preflight_api.py`) żyje w repo PA `queue/`, nie w OpenMES |
| R17 | DEF-1: autoryzacja `system_fanout` (permission `start system fanout`, `SystemFanoutForbiddenException`, audit `start_preflight.denied`, API 403) | `backend/database/seeders/RolesAndPermissionsSeeder.php`, `backend/app/Exceptions/SystemFanoutForbiddenException.php`, `backend/app/Http/Requests/Api/V1/StartBatchStepRequest.php`, `BatchService` |
| R18 | Guard `load_nc` na source po fan-out (409 `shared_sheet_source`) — **wdrożony, następnie wycofany w R18b** | stan końcowy: brak guardu (`grep shared_sheet_source` = 0) |
| R18b | Wspólny „Load NC" source/target — MES-only, bez zmian gatewaya; resolver gatewaya prowadzi source do live target entry | `backend/app/Services/Machine/MachineCommandService.php`, `backend/lang/{pl,en}.json` |
| R19 | Numer obrazu dla R18b (`port-20261103-r19`); brak odrębnej zmiany kodu | — |
| R20 | UX komunikatów błędów machine commands (PL/EN): `cycle_active_unconfirmed`, `cycle_active_other_sheet`, `state_read_failed` | `backend/resources/js/components/machine/machineCommandsLogic.js`, `MachineCommandPanel.jsx`, `backend/lang/{pl,en}.json` |
| R21 | Fix `preflightWarning is not defined` — banner w `BatchStepList`; bramki ESLint `no-undef`/`jsx-no-undef`, test bannera, lint+test+build w Dockerfile | `backend/resources/js/components/PreflightWarningBanner.jsx`, `WorkOrderDetail.jsx`, `backend/eslint.config.js`, `backend/vitest.config.js`, `backend/Dockerfile` |
| R22 | Repair wave: `useRef` guard double-click w `confirmSharedSheetStart`, mapowanie `Step is already IN_PROGRESS`, hoist `usePage`, `react-hooks/rules-of-hooks: error` | `WorkOrderDetail.jsx`, `backend/resources/js/Pages/admin/logs/System.jsx`, `machine/preflightLogic.js`, `backend/lang/{pl,en}.json`, `backend/eslint.config.js` |

Uwaga: numeracja obrazów nie jest tożsama z numeracją zmian (np. R18b →
obraz `r19`). Obraz `r23` jest finalnym buildem z tego drzewa.

## Następny krok (poza zakresem baseline)

**Reconciliacja backendu do 0.28** — osobne zadanie, nie część tego baseline:

1. Przenieść `backend/` na upstream `v0.28.0`.
2. Re-aplikować patche PA R05–R22 na backend 0.28 (konflikty, testy, migracje).
3. Zweryfikować moduły PA (`PipelineDashboard`, `PlPackagingExtras`) na 0.28.
4. Zbudować nowy obraz CI i wdrożyć przez zmianę `OPENMES_VERSION`.

Do czasu wykonania tego zadania baseline pozostaje backendem 0.27.1 + patche PA.

## Zasady baseline

1. **Brak `.env` i sekretów.** W repo są wyłącznie `backend/.env.example` oraz
   `backend/.env.testing` (klucz testowy upstream). Nie kopiujemy `.env`, `vendor/`,
   `node_modules/`, `public/build/`.
2. **Deploy wyłącznie z obrazów CI.** Produkcja jest wdrażana przez zmianę
   `OPENMES_VERSION` na obraz z rejestru; baseline służy jako referencja źródła,
   audyt i punkt wyjścia do re-aplikacji patchy — nie jest budowany na maszynie
   produkcyjnej.
3. **Higiena repo.** Zmiany wprowadzamy przez PR do tego repo; sekrety i artefakty
   builda pozostają poza gitem.

## AGPL — obowiązek udostępnienia źródła

Rdzeń OpenMES jest na licencji **AGPL-3.0** (`LICENSE`); moduły w `modules/` mogą
być na AFL-3.0, ale moduły PA (`backend/modules/PipelineDashboard`,
`PlPackagingExtras`) są częścią rdzenia, więc objęte są AGPL. Prowadzenie
zmodyfikowanej wersji jako usługi sieciowej (MES dla użytkowników) wymaga
udostępnienia odpowiadającego źródła na tej samej licencji (AGPL §13) —
**publiczne repo z tym baseline albo formalna oferta udostępnienia źródła**
(np. na żądanie, w formie pisemnej). Sam obraz Docker nie spełnia tego obowiązku.
