# PlPackagingExtras (P1)

Module port of the P1 packaging extensions to OpenMES 0.27. This first step
contains the module skeleton, the PACKING/PACK-1 seed and the public health
route. The packaging API (EAN CRUD, scan, expected codes, R06 work orders) is
added in a later step.

## What it does

- `Installer::install()` idempotently seeds the `PACKING` line and its `PACK-1`
  workstation. Existing active rows are reused and never overwritten.
- Exposes `GET /api/v1/pl-packaging/health` returning
  `{"status":"ok","module":"PlPackagingExtras"}`.
- Ships the six P1 Polish UI strings under `lang/pl.json`; the core i18n loader
  merges module strings below core ones.

## Requirements

Requires OpenMES core `>=0.27.0` **with the P1 core patch**:

- `work_order_eans` metadata migration (`code_type`, `label_batch_id`,
  `part_seq`, `state`),
- `App\Models\WorkOrderEan` constants, fillable fields and `expected` scope,
- the core packaging scan write-path that reads `state`.

The module alone does not add scan guards or API routes.

## Enabling

Enable it in **Admin → Modules**, or:

```bash
php artisan tinker --execute="
  app(\App\Services\ModuleManager::class)->enable('PlPackagingExtras');
  app(\App\Services\ModuleManager::class)->runInstaller('PlPackagingExtras');
"
php artisan route:clear
```

`uninstall()` is intentionally a no-op: the seeded master data is not removed.
