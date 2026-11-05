# PA Baseline — OpenMES (r23)

- **Data:** 2026-11-05
- **Upstream:** [Mes-Open/OpenMes](https://github.com/Mes-Open/OpenMes), tag `v0.28.0`, commit `eb184a2984b74d685e65ee24df9b8c7b34c765f6`
- **Obraz produkcyjny:** `ghcr.io/mes-open/openmes:port-20261105-r23`
- **Digest obrazu:** `sha256:afc6c1f842771e1725b101fa21746da391cefb4871bece0a18f473cda8cfa8bf`
- **Źródło overlayu:** LXC 122, `/root/build/openmes-r23` — dokładnie to drzewo, z którego zbudowano i wdrożono obraz r23.
- **Branch:** `pa/0.28`

## Skład baseline

| Obszar | Pochodzenie |
|---|---|
| `.github`, `docs`, `desktop`, `mobile`, `docker-compose.yml`, `Caddyfile`, pliki root | upstream `v0.28.0` (`eb184a2`) |
| `backend/`, `modules/`, `packages/` | źródło r23 z LXC 122 (`/root/build/openmes-r23`) |

Baseline jest **stanem końcowym** (jeden commit importu). Historia commitów PA
(R05–R22) nie jest rekonstruowana w tym repo — mapowanie poniżej wskazuje tylko,
gdzie w drzewie znajduje się wynik danej zmiany.

### Fakty o drzewie r23 (zweryfikowane diffem)

- `backend/` z r23 odpowiada bazie **0.27.x + patche PA**: 149 plików różnicy
  wobec `v0.27.0` i 348 wobec `v0.28.0`. Obraz r23 nie zawiera backendu 0.28.
- `packages/` = `v0.28.0` (0 różnic).
- `modules/` (root) = 3 przykładowe moduły OpenMES; `backend/modules/` = przykłady
  + moduły PA.

## Moduły

| Moduł | Lokalizacja | Opis (z `module.json`) |
|---|---|---|
| `PipelineDashboard` | `backend/modules/PipelineDashboard` | Widoczność pipeline'u produkcji end-to-end: Woo → Dolibarr → MES → Nesting → Produkcja |
| `PlPackagingExtras` | `backend/modules/PlPackagingExtras` | API pakowania P1 EAN: oczekiwane kody, atomowe PACK WO, guardy skanowania, etykiety PL |
| `ExampleHooks` | `backend/modules/`, `modules/` | Przykład systemu hooks/events OpenMES |
| `ExampleShowcase` | `backend/modules/`, `modules/` | Moduł referencyjny wszystkich punktów rozszerzeń OpenMES |
| `OrderPinger` | `backend/modules/`, `modules/` | Moduł tutorialowy (logi zamówień + KPI na dashboardzie) |

## Mapowanie R15–R22 → baseline

| ID | Zakres | Gdzie w baseline |
|---|---|---|
| R15 | Preflight startu kroku CNC przed `BatchService::startStep` (blokady `rebuilding`, `merged/source`; confirm dla `merged/target`; opcja B: kolejka down → warning + start) | `backend/app/Services/WorkOrder/StartPreflightService.php`, `backend/app/Services/WorkOrder/BatchService.php`, `backend/config/start_preflight.php`, `backend/tests/Feature/StartPreflight/` |
| R16 | Lease startowy — domknięcie TOCTOU między preflight a startem (TTL 15 s, marker cancelu 600 s, fail-closed 503) | `StartPreflightService`, `BatchService`, `config/start_preflight.php`; strona runtime kolejki (`start_lease.py`, `preflight_api.py`) żyje w repo PA `queue/`, nie w OpenMES |
| R17 | DEF-1: autoryzacja `system_fanout` (permission `start system fanout`, `SystemFanoutForbiddenException`, audit `start_preflight.denied`, API 403) | `backend/database/seeders/RolesAndPermissionsSeeder.php`, `backend/app/Exceptions/SystemFanoutForbiddenException.php`, `backend/app/Http/Requests/Api/V1/StartBatchStepRequest.php`, `BatchService` |
| R18 | Guard `load_nc` na source po fan-out (409 `shared_sheet_source`) — **wdrożony, następnie wycofany w R18b** | stan końcowy: brak guardu (`grep shared_sheet_source` = 0) |
| R18b | Wspólny „Load NC” source/target — MES-only, bez zmian gatewaya; resolver gatewaya prowadzi source do live target entry | `backend/app/Services/Machine/MachineCommandService.php`, `backend/lang/{pl,en}.json` |
| R19 | Numer obrazu dla R18b (`port-20261103-r19`); brak odrębnej zmiany kodu | — |
| R20 | UX komunikatów błędów machine commands (PL/EN): `cycle_active_unconfirmed`, `cycle_active_other_sheet`, `state_read_failed` | `backend/resources/js/components/machine/machineCommandsLogic.js`, `MachineCommandPanel.jsx`, `backend/lang/{pl,en}.json` |
| R21 | Fix `preflightWarning is not defined` — banner w `BatchStepList`; bramki ESLint `no-undef`/`jsx-no-undef`, test bannera, lint+test+build w Dockerfile | `backend/resources/js/components/PreflightWarningBanner.jsx`, `WorkOrderDetail.jsx`, `backend/eslint.config.js`, `backend/vitest.config.js`, `backend/Dockerfile` |
| R22 | Repair wave: `useRef` guard double-click w `confirmSharedSheetStart`, mapowanie `Step is already IN_PROGRESS`, hoist `usePage`, `react-hooks/rules-of-hooks: error` | `WorkOrderDetail.jsx`, `backend/resources/js/Pages/admin/logs/System.jsx`, `machine/preflightLogic.js`, `backend/lang/{pl,en}.json`, `backend/eslint.config.js` |

Uwaga: numeracja obrazów nie jest tożsama z numeracją zmian (np. R18b →
obraz `r19`). Obraz `r23` jest finalnym buildem z tego drzewa.

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
