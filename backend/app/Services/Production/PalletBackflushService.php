<?php

namespace App\Services\Production;

use App\Exceptions\PalletBackflushDisabledException;
use App\Models\Pallet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Legacy pallet milestone backflush - RETIRED, forced OFF.
 *
 * Raw material is settled exactly once, by the production operation that
 * consumes it (see RQ-PA-265). A pallet never consumes raw material: creating,
 * closing, retrying or deleting one must not write any stock movement, so both
 * booking entry points raise PalletBackflushDisabledException unconditionally -
 * before any batch read, lock, transaction or movement.
 *
 * The historical `backflush_on_pallet_creation` setting is still readable via
 * configuredEnabled() for diagnostics only; it can no longer switch the
 * behaviour back on. isEnabled() is a named, enforced OFF policy - not a silent
 * `catch -> false` fallback.
 *
 * Packaged goods and finished-goods documents are unaffected by this policy.
 */
class PalletBackflushService
{
    /** System-settings key of the retired, diagnostics-only trigger. */
    public const SETTING_KEY = 'backflush_on_pallet_creation';

    /** Named policy: pallet material booking is disabled by force. */
    public const POLICY_MATERIAL_BOOKING_FORCED_OFF = true;

    /**
     * Kept empty on purpose: the forced-OFF policy needs no collaborators, but
     * the class stays container-resolvable for existing injection sites.
     */
    public function __construct() {}

    /**
     * The stored value of the retired setting, for diagnostics only.
     *
     * Null when the row is missing, the read fails or the value is not valid
     * JSON - never throws. This does NOT drive behaviour: see isEnabled().
     */
    public function configuredEnabled(): ?bool
    {
        try {
            $raw = DB::table('system_settings')->where('key', self::SETTING_KEY)->value('value');
        } catch (\Throwable) {
            return null;
        }

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_bool($decoded) ? $decoded : null;
    }

    /**
     * Forced OFF policy: pallet material booking is always disabled.
     *
     * Raw material is settled once by the consuming operation, so this returns
     * false regardless of the stored setting (whose historical `true` value is
     * only reported by configuredEnabled() for diagnostics). This is an
     * intentional gate for every caller, not an error-handling fallback.
     */
    public function isEnabled(): bool
    {
        return ! self::POLICY_MATERIAL_BOOKING_FORCED_OFF;
    }

    /**
     * Entry point from pallet creation - always refuses.
     *
     * @throws PalletBackflushDisabledException always; no stock movement is recorded.
     */
    public function backflushForPallet(Pallet $pallet, ?float $explicitQty = null, ?User $user = null): Collection
    {
        throw new PalletBackflushDisabledException;
    }

    /**
     * The quantity to backflush against: the explicit produced quantity supplied
     * at pallet creation, otherwise the pallet's batch produced (then target)
     * quantity. Zero when nothing is resolvable. Kept unchanged by the forced-OFF
     * policy so reconciliation can still describe historical pallets.
     */
    public function resolveQuantity(Pallet $pallet, ?float $explicit = null): float
    {
        if ($explicit !== null && $explicit > 0) {
            return $explicit;
        }

        $pallet->loadMissing('batch');
        $batch = $pallet->batch;
        if ($batch) {
            if ((float) $batch->produced_qty > 0) {
                return (float) $batch->produced_qty;
            }
            if ((float) $batch->target_qty > 0) {
                return (float) $batch->target_qty;
            }
        }

        return 0.0;
    }

    /**
     * Book the BOM consumption for $pallet - always refuses.
     *
     * Kept as the single forced-OFF gate: nothing is read, locked or written, so
     * a zero or negative quantity cannot bypass it either.
     *
     * @throws PalletBackflushDisabledException always; no stock movement is recorded.
     */
    public function backflush(Pallet $pallet, float $quantity, ?User $user = null): Collection
    {
        throw new PalletBackflushDisabledException;
    }
}
