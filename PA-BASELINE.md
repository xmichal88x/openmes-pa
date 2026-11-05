# PA Baseline — OpenMES (0.28)

- **Data:** 2026-11-05
- **Branch:** `pa/0.28`
- **Backend:** upstream **v0.28.0** (`eb184a2984b74d685e65ee24df9b8c7b34c765f6`) + patche PA **R05–R22**
- **Root / `packages/` / `modules/`:** upstream **v0.28.0**
- **Poprzedni baseline (historyczny):** `pa/r23-baseline`, obraz `ghcr.io/mes-open/openmes:port-20261105-r23`
  (backend 0.27.1 + PA) — zachowany jako referencja wdrożonego obrazu, nie jako stan docelowy.

## Co odtwarza baseline

Baseline jest **spójny wersyjnie**: backend, root, `packages/` i `modules/` pochodzą
z upstream `v0.28.0`, a na backend nałożone są patche PA R05–R22.

| Obszar | Pochodzenie | Weryfikacja |
|---|---|---|
| `backend/` | upstream **v0.28.0** (`eb184a2984b74d685e65ee24df9b8c7b34c765f6`) + patche PA **R05–R22** | merge `v0.28.0` na gałąź `pa/0.28`; 5 konfliktów rozwiązanych ręcznie (poniżej) |
| `backend/Dockerfile` | baza v0.28.0 + PA R21 (`npm run lint && npm test`) oraz `php:8.3-fpm-alpine` | diff wobec tagu ograniczony do zmian PA |
| pliki root: `.github`, `docs`, `desktop`, `mobile`, `docker-compose*.yml`, `Caddyfile`, `README.md`, `package.json` itd. | upstream **v0.28.0** | brak różnic poza `PA-BASELINE.md` i workflowami PA |
| `packages/` | upstream **v0.28.0** | 0 różnic |
| `modules/` (root) | upstream **v0.28.0** | 0 różnic |
| `backend/modules/` | przykłady upstream + moduły PA | patrz sekcja „Moduły" |

Wersjonowanie backendu pilnuje bramka CI `Version guard`
(`backend/config/version.php` musi zawierać `'current' => 'v0.28.0'`).

## Merge 0.28 — przebieg i konflikty

Kolejność prac:

1. `git checkout -b pa/0.28 v0.27.1^{}`.
2. Replay całego katalogu `backend/` z `pa/r23-baseline`
   (`chore(pa): replay PA backend on upstream v0.27.1`).
3. `git merge v0.28.0^{}` — konflikty tylko w 5 plikach:

| Plik | Rozwiązanie |
|---|---|
| `backend/config/version.php` | wersja z upstream: `'current' => 'v0.28.0'` |
| `backend/database/seeders/RolesAndPermissionsSeeder.php` | unia: role/uprawnienia PA (PackingWorker, PackingBridge, MachineBridge, `create batches`, `start system fanout`, `request machine *`, `process machine commands`) **oraz** nowe 0.28 (`InspectionPermissions`, `$firstInspectionSeed`). Wszystkie `$keepTabs` → `$keepGranted` (upstream przemianował zmienną); bez tego `db:seed` kończy się fatalem na rolach PA. |
| `backend/lang/pl.json` | unia kluczy (JSON): 7040 wspólnych, 114 tylko PA, 251 tylko 0.28; wspólne klucze mają identyczne wartości, więc konflikt wartości nie wystąpił. Wynik: 7405 kluczy, walidacja `python3 -m json.tool`. |
| `backend/lang/en.json` | jak wyżej: 7022 wspólnych, 40 tylko PA, 251 tylko 0.28; wynik 7313 kluczy. |
| `backend/resources/js/Pages/admin/logs/System.jsx` | zachowany hoist `usePage`/bloku `missing` z PA R22, treść komunikatu przepisana na i18n `__()` z 0.28; żaden hook nie został zgubiony. |

Dodatkowe bramki trwałe:

- `backend/tests/Feature/Seeders/RolesAndPermissionsSeederTest.php` — uruchamia
  seeder i sprawdza role/uprawnienia PA oraz `InspectionPermissions`.
- `.github/workflows/tests.yml` — krok `Version guard`; przywrócone PA workflow
  (`tests.yml`, `docker-publish.yml`, triggery `pa/**` i tagi `pa-*`), usunięte
  upstreamowe `cla.yml`, `deploy.yml`, `release.yml`.

## Placeholdery Laravela (storage / bootstrap cache)

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

Wszystkie są **tracked** (trafiają do `git archive` i świeżego klonu).

## Moduły

| Moduł | Lokalizacja | Typ | Opis (z `module.json`) |
|---|---|---|---|
| `PipelineDashboard` | `backend/modules/PipelineDashboard` | PA | Widoczność pipeline'u produkcji end-to-end: Woo → Dolibarr → MES → Nesting → Produkcja |
| `PlPackagingExtras` | `backend/modules/PlPackagingExtras` | PA | API pakowania P1 EAN: oczekiwane kody, atomowe PACK WO, guardy skanowania, etykiety PL |
| `ExampleHooks` | `backend/modules/`, `modules/` | upstream | Przykład systemu hooks/events OpenMES |
| `ExampleShowcase` | `backend/modules/`, `modules/` | upstream | Moduł referencyjny wszystkich punktów rozszerzeń OpenMES |
| `OrderPinger` | `backend/modules/`, `modules/` | upstream | Moduł tutorialowy (logi zamówień + KPI na dashboardzie) |

## Mapowanie R15–R22 → baseline

R05–R14 wchodzą w skład różnicy `backend/` wobec upstreamu; poniżej
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
obraz `r19`).

## Zasady baseline

1. **Brak `.env` i sekretów.** W repo są wyłącznie `backend/.env.example` oraz
   `backend/.env.testing` (klucz testowy upstream). Nie kopiujemy `.env`, `vendor/`,
   `node_modules/`, `public/build/`.
2. **Deploy wyłącznie z obrazów CI, pinowanych digestem.** Produkcja jest
   wdrażana przez `OPENMES_IMAGE=repo@sha256:<64 hex>` i
   `scripts/deploy-openmes.sh`; baseline służy jako referencja źródła, audyt i
   punkt wyjścia do re-aplikacji patchy — nie jest budowany na maszynie
   produkcyjnej.
3. **Higiena repo.** Zmiany wprowadzamy przez PR do tego repo; sekrety i artefakty
   builda pozostają poza gitem.

## Permanentny model obrazu

Produkcja nie używa już mutable tagów (`latest`, `OPENMES_VERSION`) ani
lokalnego aliasu builda. Model jest **fail-closed i odtwarzalny**:

| Element | Stan |
|---|---|
| `docker-compose.yml` (5 usług OpenMES: `backend`, `reverb`, `mqtt-listener`, `modbus-poller`, `queue-worker`) | `image: ${OPENMES_IMAGE:?OPENMES_IMAGE is required (repo@sha256:...)}`; brak wartości = compose nie wystartuje |
| `pull_policy` | `${OPENMES_PULL_POLICY:-never}` — zwykłe `docker compose up` nie pobiera ani nie buduje innego obrazu |
| `scripts/deploy-openmes.sh` | wymaga `^.+@sha256:[0-9a-f]{64}$`; `docker manifest inspect` gdy obrazu nie ma lokalnie; `docker compose config -q`; pull (jawny lub compose); `up -d` **wszystkich** usług OpenMES; czeka na `/api/health` = 200; sprawdza, że wszystkie kontenery mają ten sam image ID równy pinowanemu; przy błędzie `exit 1`, a po nieudanym `up` próbuje przywrócić poprzedni wspólny obraz |
| `docker-compose.dev.yml` | bez zmian — dev może dalej budować lokalnie |
| CI publish (`docker-publish.yml`) | etykieta `org.opencontainers.image.licenses=AGPL-3.0-only`; tagi immutable per commit/tag |

Weryfikacja compose (bez sekretów):

```bash
OPENMES_IMAGE=ghcr.io/xmichal88x/openmes-pa@sha256:<64 hex> docker compose config -q
```

## AGPL — obowiązek udostępnienia źródła

Rdzeń OpenMES jest na licencji **AGPL-3.0-only** (`LICENSE`). Wszystkie modyfikacje
PA (R05–R22) oraz moduły PA (`backend/modules/PipelineDashboard`,
`backend/modules/PlPackagingExtras`) są **AGPL-3.0-only** — nie AFL. Warstwa
`modules/` z upstreamu zachowuje swoje licencje (AFL-3.0 tylko tam, gdzie plik
niesie odpowiednią notę); żaden moduł PA nie jest AFL.

Prowadzenie zmodyfikowanej wersji jako usługi sieciowej (MES dla użytkowników)
wymaga udostępnienia odpowiadającego źródła na tej samej licencji (AGPL §13).
Baseline spełnia to przez:

- publiczne repo `https://github.com/xmichal88x/openmes-pa` jako corresponding source;
- `NOTICE` (modyfikacja, upstream v0.28.0 `eb184a2`, zakres PA R05–R22, brak gwarancji);
- `TRADEMARK.md` (zasady użycia nazwy/logo przy forkach);
- `LICENSE`, `LICENSING.md`, `NOTICE`, `TRADEMARK.md` **kopiowane do obrazu**;
- `"license": "AGPL-3.0-only"` w `module.json` modułów PA i w `package.json` root;
- link **„Source (AGPL-3.0)”** w UI (layout auth — widoczny przed logowaniem —
  oraz layouty aplikacji/operatora), kierujący do `APP_SOURCE_URL` z domyślną
  wartością `.../tree/pa/0.28`; build może wskazać dokładny commit;
- bramkę CI `image-build`: obecność czterech plików licencyjnych i 5 modułów
  w `/var/www/html/modules`.

Sam obraz Docker nie spełnia tego obowiązku — dowodem jest publiczne źródło i
widoczna w UI oferta.
