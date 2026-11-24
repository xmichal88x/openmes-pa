<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasTenant;
use App\Models\Concerns\SoftDeletesWithAudit;
use App\Services\Material\BomExplosionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Material extends Model
{
    use HasCustomFields, HasFactory, HasTenant;
    use SoftDeletesWithAudit;

    /** Which system's balance for this SKU is authoritative (F1-1). */
    public const STOCK_OWNER_ERP = 'erp';

    public const STOCK_OWNER_MES = 'mes';

    public const STOCK_OWNER_UNKNOWN = 'unknown';

    /** Where the declared balance number came from. */
    public const BALANCE_SOURCE_ERP_PROJECTION = 'erp_projection';

    public const BALANCE_SOURCE_OPENING_DECLARED = 'opening_declared';

    public const BALANCE_SOURCE_MES_LEDGER = 'mes_ledger';

    public const BALANCE_SOURCE_DEMO_SEED = 'demo_seed';

    public const BALANCE_SOURCE_UNKNOWN = 'unknown';

    public const BALANCE_SOURCE_STALE = 'stale';

    /** Sources that make a balance usable when the ERP owns the SKU. */
    public const ERP_DECLARED_BALANCE_SOURCES = [
        self::BALANCE_SOURCE_ERP_PROJECTION,
        self::BALANCE_SOURCE_OPENING_DECLARED,
    ];

    /** Sources that make a balance usable when the MES owns the SKU (WIP). */
    public const MES_DECLARED_BALANCE_SOURCES = [
        self::BALANCE_SOURCE_MES_LEDGER,
        self::BALANCE_SOURCE_OPENING_DECLARED,
    ];

    protected $fillable = [
        'code',
        'name',
        'description',
        'material_type_id',
        'unit_of_measure',
        'tracking_type',
        'is_manufactured',
        'producing_process_template_id',
        'default_scrap_percentage',
        'stock_quantity',
        'reserved_quantity',
        'min_stock_level',
        'extra_data',
        'external_code',
        'external_system',
        'supplier_name',
        'supplier_code',
        'unit_price',
        'price_currency',
        'ean',
        'last_stock_sync_at',
        'stock_owner',
        'balance_source',
        'balance_as_of',
        'is_active',
        'tenant_id',
    ];

    /**
     * The column default lives in the DB, but a freshly built model wouldn't
     * reflect it until reloaded — leaving `is_manufactured` null in memory and
     * make-or-buy checks reading a null instead of false.
     */
    protected $attributes = [
        'is_manufactured' => false,
        // Nothing is known about the balance until someone declares it. A null
        // here would read as "not set" rather than "unknown", which is a
        // different and more dangerous statement.
        'stock_owner' => self::STOCK_OWNER_UNKNOWN,
        'balance_source' => self::BALANCE_SOURCE_UNKNOWN,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_manufactured' => 'boolean',
            'default_scrap_percentage' => 'decimal:2',
            'stock_quantity' => 'decimal:3',
            'reserved_quantity' => 'decimal:3',
            'min_stock_level' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'last_stock_sync_at' => 'datetime',
            'balance_as_of' => 'datetime',
            'extra_data' => 'array',
        ];
    }

    /**
     * Material physically present minus what is currently reserved by
     * active allocations. The truthful "free to allocate" number.
     */
    public function getAvailableQuantityAttribute(): float
    {
        return (float) $this->stock_quantity - (float) $this->reserved_quantity;
    }

    /** The ERP's number is the authoritative one for this SKU. */
    public function isErpOwned(): bool
    {
        return $this->stock_owner === self::STOCK_OWNER_ERP;
    }

    /** The MES ledger is the authoritative one for this SKU (work in progress). */
    public function isMesOwned(): bool
    {
        return $this->stock_owner === self::STOCK_OWNER_MES;
    }

    /**
     * The balance is a real, attributed number. It depends on who owns the SKU:
     * a `mes_ledger` number means nothing for an ERP-owned raw material, and an
     * `erp_projection` means nothing for WIP. Demo seeds and stale reads are
     * excluded on purpose — treating them as truth is the silent fallback this
     * column set exists to prevent. Unrecognised owners are not declared.
     */
    public function hasDeclaredBalance(): bool
    {
        $sources = match ($this->stock_owner) {
            self::STOCK_OWNER_ERP => self::ERP_DECLARED_BALANCE_SOURCES,
            self::STOCK_OWNER_MES => self::MES_DECLARED_BALANCE_SOURCES,
            default => [],
        };

        return in_array($this->balance_source, $sources, true);
    }

    public function materialType(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(MaterialSource::class);
    }

    public function bomItems(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }

    /**
     * Physical lots received against this material (ISA-95).
     */
    public function lots(): HasMany
    {
        return $this->hasMany(MaterialLot::class);
    }

    /** Per-warehouse balances for this material (#212). */
    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    protected static function booted(): void
    {
        // One currency per installation: a price nobody labelled (the material
        // form has no currency field) is in the installation currency, not in
        // the column's historical PLN default.
        static::creating(function (self $material): void {
            if (blank($material->price_currency)) {
                $material->price_currency = \App\Support\CurrencyRegistry::current();
            }
        });

        // The other way to close a BOM loop: point an item at a producing
        // template that already consumes it, at any depth. Enforced on the
        // model so every write path is covered (the BOM-line side is gated in
        // BomService). ValidationException so HTTP surfaces answer 422.
        static::saving(function (self $material): void {
            // The column is NOT NULL with a DB default, so a form field that
            // arrives blank (ConvertEmptyStringsToNull) must not be written
            // through as an explicit null.
            if ($material->is_manufactured === null) {
                $material->is_manufactured = false;
            }

            // Only an existing row can already be a component somewhere, so a
            // freshly created material can't close a loop.
            if (! $material->isDirty('producing_process_template_id')
                || $material->producing_process_template_id === null
                || ! $material->exists) {
                return;
            }

            $creates = app(BomExplosionService::class)
                ->wouldCreateCycleAsProducer($material, (int) $material->producing_process_template_id);

            if ($creates) {
                throw ValidationException::withMessages([
                    'producing_process_template_id' => __(
                        'That template already consumes :material, so it cannot also produce it.',
                        ['material' => $material->code],
                    ),
                ]);
            }
        });
    }

    /**
     * The BOM/routing that produces this material, for items made in-house.
     * Null on purchased components — and on manufactured ones whose template
     * hasn't been created yet, which BOM explosion treats as a leaf.
     */
    public function producingTemplate(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class, 'producing_process_template_id');
    }

    /**
     * Made in-house *and* explodable — what lets a BOM line descend a level.
     *
     * The relation itself has to resolve, not just the FK: process templates
     * soft-delete, and `nullOnDelete` only fires on a hard delete, so a
     * soft-deleted template leaves the id pointing at a row the relation
     * returns null for. Without this check explosion would descend into a null
     * template. Such a material falls back to being a leaf — a requirement for
     * the subassembly itself — which is the safe reading.
     */
    public function isExplodable(): bool
    {
        return $this->is_manufactured
            && $this->producing_process_template_id !== null
            && $this->producingTemplate !== null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Subassemblies produced in-house. */
    public function scopeManufactured(Builder $query): Builder
    {
        return $query->where('is_manufactured', true);
    }

    /** Purchased components and raw materials. */
    public function scopePurchased(Builder $query): Builder
    {
        return $query->where('is_manufactured', false);
    }

    public function scopeByExternalCode($query, string $code, ?string $system = null)
    {
        $query->where('external_code', $code);

        if ($system) {
            $query->where('external_system', $system);
        }

        return $query;
    }

    /**
     * Rows with no usable declared balance: ownership unknown, or a source that
     * does not count for that owner. Grouped so a `where` appended afterwards
     * still applies to the whole condition instead of only the last branch.
     */
    public function scopeWithUndeclaredBalance(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('stock_owner', self::STOCK_OWNER_UNKNOWN)
                ->orWhere(function (Builder $query): void {
                    $query->where('stock_owner', self::STOCK_OWNER_ERP)
                        ->whereNotIn('balance_source', self::ERP_DECLARED_BALANCE_SOURCES);
                })
                ->orWhere(function (Builder $query): void {
                    $query->where('stock_owner', self::STOCK_OWNER_MES)
                        ->whereNotIn('balance_source', self::MES_DECLARED_BALANCE_SOURCES);
                });
        });
    }
}
