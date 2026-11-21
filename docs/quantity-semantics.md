# Quantity semantics

Several numbers in OpenMES are called "produced". They are not interchangeable.
This page says what each one means, where it comes from, and which reports use
which — so no screen can show two different "produced" values as if they were
the same thing.

Rule of thumb: **one screen, one basis — unless the payload says otherwise.**
Every report that returns a produced quantity states its basis next to it
(`produced_basis`), and a figure that cannot be stated is `null` plus a count of
the records it could not read (`unknown_source_batches`), never a quiet `0`.

## What each number means

| Number | Meaning | Written by | Additive? |
|---|---|---|---|
| `work_orders.produced_qty` | **Good pieces** of the order: packed units under the unit flow, or the declared figure a human/machine stated. One implementation: `App\Services\WorkOrder\WorkOrderProducedQty` (per batch, from `produced_qty_source`). Two declared policies override it, visibly: counting source `machine`/`both` ⇒ the PLC counter owns the number; transfer flow ⇒ the number is what left each batch's last step (the ledger). | production confirmations, packing, corrections, counters | yes, per order |
| `batches.produced_qty` | **Step confirmation** of the batch: `passed_qty` of the batch's last effective step at close (R40/P2.12). It is what the batch's last operation confirmed, frozen per batch. | `BatchService` roll-up, packing correction, machine/simulation | **no** — a re-cut replacement batch confirms the same physical piece again |
| `batch_steps.passed_qty` | Ledger: pieces that passed *through this step*. Each step of a batch sees the same pieces, so summing across steps double counts. | operator/machine quantity logging | no, per step |
| `scrap_entries.quantity` | Reported scrap for the work order (optionally attached to a batch step), with a reason code. Reporting evidence: Pareto, trends, scrap rate numerator. | operator scrap entry, step logging | yes |
| `batches.scrap_qty` | Batch-level scrap as declared on the batch screen. The ledger write path records the same loss as a `ScrapEntry`, so the two must not be added together. | batch release/edit | yes, per batch |
| `serial_units.packed_at` | The physical act of packing. A **good unit** is `status IN (completed, shipped)` **and** `packed_at` set (`App\Services\Packaging\PackedUnitQty`). | `UnitPackingService::packUnit()` | yes, counted once per unit |
| `serial_units.status = scrapped` | A unit that was scrapped. Writes no `ScrapEntry`, so it is separate evidence. | `SerialTraceService::scrapUnit()` | yes |
| `work_order_shift_entries.quantity` | What an operator entered for a shift. Its own flow: not packed units, not batch confirmations. | shift entry screen | yes, per shift |
| `pallets.qty` | Pieces on a pallet — **material/backflush** quantity, not a production count. | packing station | yes, per pallet |

## Provenance of a batch's quantity

`batches.produced_qty_source` (stored in `extra_data.produced_qty_source`, also
exposed as an appended attribute) says where the figure came from:

| Source | Meaning |
|---|---|
| `units` | Derived from packed serial units (unit flow). |
| `manual` | A human stated it (operator entry, packing correction). |
| `machine` | A machine counter / collector supplied it. |
| `steps` | Rolled up from the step ledger. |
| `unknown` | **Never declared.** The figure cannot be trusted as either attempts or good pieces. |

`unknown` is not a default value and never throws: consumers must present the
gap (`null` + `unknown_source_batches`), not a number.

Two policies are *decisions*, not fallbacks, and both are visible in the data:
`counting_source = machine|both` (the PLC counter is the order's quantity) and
the transfer production flow (the step ledger is). See `WorkOrderProducedQty`
for the exact rules; reports that need "the order's good pieces" read
`work_orders.produced_qty` and inherit whichever policy is active.

## Hold / unblock and the quantity

A hold is a physical fact ("this piece may not go on"), so `block` always lands.
What follows for the numbers depends on where the batch's figure comes from —
and a figure that is a declaration is never rewritten by a unit event.

| Step | What it does | Where |
|---|---|---|
| `POST /api/v1/serial-units/{id}/block` (`SerialTraceService::blockUnit()`) | Sets `status = blocked`, writes `extra_data.hold` (reason code/source), records a `blocked` event in the unit's history, recomputes the pallet's quality gate, then re-derives the quantities as `blocked`. | `backend/app/Services/Traceability/SerialTraceService.php:557-584` |
| `POST /api/v1/serial-units/{id}/unblock` (`SerialTraceService::unblockUnit()`) | Requires `status = blocked` (otherwise `422` *is not blocked*), restores `packed_at ? completed : in_production`, clears `extra_data.hold`, records an `unblocked` event, recomputes the pallet, then re-derives the quantities as `unblocked`. | `backend/app/Services/Traceability/SerialTraceService.php:592-613` |

The release stores no "restore": the quantity is recounted from the unit's new
status by the same single path (`UnitProducedQtySync::afterStatusChange()` →
`recomputeBatch()`, `backend/app/Services/WorkOrder/UnitProducedQtySync.php:64-163`).

When the quantity comes back, and when it stays:

| Batch source / policy | `block` | `unblock` | Why |
|---|---|---|---|
| `units` | Recounted **down** — the held unit is no longer a good unit (`PackedUnitQty`: `packed_at` + `completed\|shipped`) | Recounted **up** — the released unit counts again | The number is the packed units the MES counts now, in both directions |
| `units`, unit never packed | No change (it was never in the count) | No change: status returns as `in_production`, not `completed` | A good unit is a **packed** unit (`serial_units.packed_at`, P2.12) — a release must not conjure a piece that never left the line |
| `manual` / `machine` | **Unchanged** + `produced_qty_declared_unchanged` | **Unchanged** + `produced_qty_declared_unchanged` | A human or a collector stated the figure; a unit event does not rewrite a declaration. A correction goes through the packing quantity |
| `counting_source = machine\|both` | **Unchanged** + `produced_qty_machine_counter` | **Unchanged** + `produced_qty_machine_counter` | The PLC counter owns the order's number |
| transfer flow | **Unchanged** + `produced_qty_transfer_ledger` | **Unchanged** + `produced_qty_transfer_ledger` | The step ledger (`passed_qty`) owns the batch; corrections belong to `correctGoodQuantity` |
| `unknown` in a finished batch | Batch untouched + `produced_qty_source_unknown` (rollup blocked, audit row `produced_qty_rollup_blocked`) | same | Nothing records where the figure came from, so it is not re-derived |

The notices are part of the answer, not of the logs: both endpoints return
`warnings[]` next to `data`
(`backend/app/Http/Controllers/Api/V1/SerialUnitController.php:127-154`, fed by
`SerialTraceService::takeProducedQtyWarnings()`, `SerialTraceService.php:626-632`).
An empty array means the figure *was* re-derived; a non-empty one names what
could not be. The station screen also appends those messages to its `message`
field (`UnitLabelStationController::unitAnswer()`), because the toast renders
`message`, not `warnings[]`.

## Bases a report can state

| `produced_basis` | Meaning |
|---|---|
| `units` | The quantity is packed (good) units; scrap is *added* to it, never subtracted again. |
| `declared` | The quantity is a declared total; good = declared − scrap. |
| `mixed` | The row adds up batches of both evidences; no single basis applies. |
| `batch_confirmation` | The number is `batches.produced_qty` — a step confirmation, **not** good pieces. |
| `machine_counter` | The number comes from machine counter events (line card rate on a collector line). |
| `shift_entries` | The number is operator shift-entry quantity. |
| `null` | No basis can be stated (unknown source, or no evidence at all). See `unknown_source_batches`. |

The decision of `units` vs `declared` lives in exactly one place:
`App\Services\Quality\QualityFacts` (`forWorkOrder`, `forBatches`, `perBatch`).

## Which report uses which

| Surface | Produced figure | Basis | Notes |
|---|---|---|---|
| `GET /api/v1/erp/*` (`produced_qty`, `shortage_qty`, `short_closed_at`) | `work_orders.produced_qty` | good pieces | ERP contract — unchanged by P1.2 |
| Scrap rate per line (`ScrapReportService::ratePerLine`, admin scrap report + `GET /api/v1/reports/scrap-rate`, shift KPI tile via `ShiftKpiService`) | Σ good of the line's completed batches | `units` / `declared` / `mixed` | Scrap = reported entries + scrapped units. Denominator (`total_qty`): for `units` rows good + scrap (a packed unit is good output, the loss was never in it); for `declared` rows the declared total, which already holds the loss. Unknown batch ⇒ `produced_qty`, `total_qty`, `scrap_rate_pct` null + `unknown_source_batches` (the KPI tile then says why). |
| Operator production rate (`OperatorProductionRateService`) | Σ good of the pair's batches | `units` / `declared` / `mixed` | A replacement original contributes 0 good, its replacement 1 — the same physical piece |
| Line cards, rate fallback (`LineProgressService`, no collector) | Σ good of the order's batches completed in the last hour | `units` / `declared` / `mixed` | Same evidence as the card's progress (`work_orders.produced_qty`) |
| Line cards, collector line | Machine counter events | `machine_counter` | max over the line's stations |
| Batch completion report (`ReportController::batchCompletion`) | Σ `batches.produced_qty` | `batch_confirmation` | About batch cycle times, not good output |
| Cycle time analytics (`AnalyticsController::cycleTime`) | `batches.produced_qty` per batch | `batch_confirmation` | same |
| Shift handover (`ShiftHandoverCalculator`) | Operator shift entries | `shift_entries` | its own flow, numbers unchanged |
| OEE quality ratio (`OeeCalculationService`) | `QualityFacts::forBatches()` | `units` / `declared` | quality null (not 0 %) when the window has an unknown batch |
| Backflush / material consumption (`PalletBackflushService`) | Pallet quantities, `target_qty` fallback | n/a | material booking, not a production count — open item, see below |

## Reading a figure that is not there

- `produced_qty: null` with `unknown_source_batches > 0` — the window holds
  batches that never declared their quantity source. Nothing is summed, because
  a partial sum would silently shrink the denominator.
- `produced_qty: null` with `unknown_source_batches = 0` — there is no evidence
  at all (e.g. no completed batch in the period). Not the same as "0 pieces".
- `scrap_rate_pct: null` — no denominator. Never `0 %` and never `100 %`.

Open item (not part of P1.2): `PalletBackflushService` still falls back to
`target_qty` for material booking and carries no scrap quantity. That is a
level-3 (material/accounting) decision and is tracked separately.
