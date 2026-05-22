<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Listino;
use App\Models\ListinoItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PrezziService
{
    private const DEFAULT_IVA_PERCENTUALE = 22.0;
    private const DEFAULT_QUANTITY = 1.0;

    /**
     * @var array<string, array{
     *     prezzo:float,
     *     prezzo_lordo:float,
     *     sconto_percentuale:float,
     *     iva_percentuale:float,
     *     source:string,
     *     listino_id:?int,
     *     rule_id:?int
     * }|null>
     */
    private static array $pricingCache = [];

    /**
     * @var array<string, Collection<int, Listino>>
     */
    private static array $applicablePricelistsCache = [];

    /**
     * @var array<int, array<int>>
     */
    private static array $categoryTreeCache = [];

    private static ?bool $hasCentroCostoListinoTable = null;

    /**
     * Ritorna il prezzo da mostrare e i parametri fiscali (lordo/sconto/iva)
     */
    public static function prezzoVisibile(Product $product, ?User $user = null): array
    {
        $pricing = self::resolvePricing($product, $user ?? auth()->user(), self::DEFAULT_QUANTITY);

        return [
            'prezzo'             => $pricing['prezzo'] ?? null,
            'prezzo_lordo'       => $pricing['prezzo_lordo'] ?? 0.0,
            'sconto_percentuale' => $pricing['sconto_percentuale'] ?? 0.0,
            'iva_percentuale'    => $pricing['iva_percentuale'] ?? self::DEFAULT_IVA_PERCENTUALE,
        ];
    }

    public static function prezzoAttivo(Product $product, ?User $user = null, float $quantity = self::DEFAULT_QUANTITY): ?float
    {
        return self::resolvePricing($product, $user ?? auth()->user(), $quantity)['prezzo'] ?? null;
    }

    public static function prezzoPerListino(Product $product, int $listinoId, float $quantity = self::DEFAULT_QUANTITY): ?float
    {
        $listino = Listino::query()->find($listinoId);

        if ($listino === null) {
            return null;
        }

        return self::resolvePricingForListino($product, $listino, $quantity)['prezzo'] ?? null;
    }

    /**
     * @return array{
     *     prezzo:float,
     *     prezzo_lordo:float,
     *     sconto_percentuale:float,
     *     iva_percentuale:float,
     *     source:string,
     *     listino_id:?int,
     *     rule_id:?int
     * }|null
     */
    private static function resolvePricing(Product $product, mixed $user, float $quantity): ?array
    {
        $userId = $user instanceof User ? $user->id : 0;
        $cacheKey = implode(':', [$product->id, $userId, number_format($quantity, 4, '.', '')]);

        if (array_key_exists($cacheKey, self::$pricingCache)) {
            return self::$pricingCache[$cacheKey];
        }

        foreach (self::resolveApplicablePricelists($user instanceof User ? $user : null) as $listino) {
            $pricing = self::resolvePricingForListino($product, $listino, $quantity);

            if ($pricing !== null) {
                return self::$pricingCache[$cacheKey] = $pricing;
            }
        }

        $basePrice = $product->getPrezzoListinoBase();

        if ($basePrice !== null) {
            return self::$pricingCache[$cacheKey] = [
                'prezzo' => round($basePrice, 2),
                'prezzo_lordo' => round($basePrice, 2),
                'sconto_percentuale' => 0.0,
                'iva_percentuale' => self::DEFAULT_IVA_PERCENTUALE,
                'source' => 'fallback_base_listino',
                'listino_id' => null,
                'rule_id' => null,
            ];
        }

        return self::$pricingCache[$cacheKey] = null;
    }

    /**
     * @return array{
     *     prezzo:float,
     *     prezzo_lordo:float,
     *     sconto_percentuale:float,
     *     iva_percentuale:float,
     *     source:string,
     *     listino_id:?int,
     *     rule_id:?int
     * }|null
     */
    private static function resolvePricingForListino(
        Product $product,
        Listino $listino,
        float $quantity,
        array $visitedPricelistIds = []
    ): ?array {
        if (in_array($listino->id, $visitedPricelistIds, true)) {
            return null;
        }

        $visitedPricelistIds[] = $listino->id;
        $rule = self::findApplicableRule($product, $listino, $quantity);

        if ($rule !== null) {
            $pricing = self::resolvePricingFromRule($product, $listino, $rule, $quantity, $visitedPricelistIds);

            if ($pricing !== null) {
                return $pricing;
            }
        }

        return self::resolveLegacyPivotPricing($product, $listino);
    }

    private static function resolveApplicablePricelists(?User $user): Collection
    {
        $cacheKey = (string) ($user?->id ?? 0);

        if (isset(self::$applicablePricelistsCache[$cacheKey])) {
            return self::$applicablePricelistsCache[$cacheKey];
        }

        $baseQuery = Listino::query()
            ->where('tipo', 'vendita')
            ->where(function ($query) {
                $query->whereNull('attivo')->orWhere('attivo', true);
            })
            ->whereDate('valido_dal', '<=', Carbon::today()->toDateString())
            ->where(function ($query) {
                $query->whereNull('valido_al')->orWhereDate('valido_al', '>=', Carbon::today()->toDateString());
            })
            ->orderByRaw('sequenza is null')
            ->orderBy('sequenza')
            ->orderBy('id');

        $centroCostoId = self::resolveCentroCostoId($user);

        if ($centroCostoId !== null && self::canUseCentroCostoListini()) {
            $listini = (clone $baseQuery)
                ->whereHas('centriCosto', static fn ($query) => $query->where('centri_costo.id', $centroCostoId))
                ->get();

            if ($listini->isNotEmpty()) {
                return self::$applicablePricelistsCache[$cacheKey] = $listini;
            }
        }

        if ($user?->cliente_id && self::canUseCentroCostoListini()) {
            $listini = (clone $baseQuery)
                ->whereHas('centriCosto', static fn ($query) => $query->where('cliente_id', $user->cliente_id))
                ->get();

            if ($listini->isNotEmpty()) {
                return self::$applicablePricelistsCache[$cacheKey] = $listini;
            }
        }

        return self::$applicablePricelistsCache[$cacheKey] = $baseQuery->get();
    }

    private static function resolveCentroCostoId(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        $centroCostoId = $user->centro_costo_default_id ?? null;

        if (is_numeric($centroCostoId) && (int) $centroCostoId > 0) {
            return (int) $centroCostoId;
        }

        return null;
    }

    private static function findApplicableRule(Product $product, Listino $listino, float $quantity): ?ListinoItem
    {
        $now = Carbon::now();
        $categoryIds = self::resolveCategoryScopeIds($product->categoria_id);

        return ListinoItem::query()
            ->where('listino_id', $listino->id)
            ->where(function ($query) use ($now) {
                $query->whereNull('date_start')->orWhere('date_start', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('date_end')->orWhere('date_end', '>=', $now);
            })
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) use ($product, $categoryIds) {
                $query->where('applied_on', '3_global')
                    ->orWhere(function ($query) use ($categoryIds) {
                        $query->where('applied_on', '2_product_category');

                        if ($categoryIds === []) {
                            $query->whereRaw('1 = 0');

                            return;
                        }

                        $query->whereIn('categoria_id', $categoryIds);
                    })
                    ->orWhere(function ($query) use ($product) {
                        $query->where('applied_on', '1_product')
                            ->where(function ($query) use ($product) {
                                $query->where('product_id', $product->id);

                                if ($product->odoo_id !== null) {
                                    $query->orWhere('odoo_product_tmpl_id', $product->odoo_id);
                                }
                            });
                    });
            })
            ->orderBy('applied_on')
            ->orderByDesc('min_quantity')
            ->orderByDesc('categoria_id')
            ->orderByDesc('odoo_id')
            ->first();
    }

    /**
     * @return array{
     *     prezzo:float,
     *     prezzo_lordo:float,
     *     sconto_percentuale:float,
     *     iva_percentuale:float,
     *     source:string,
     *     listino_id:?int,
     *     rule_id:?int
     * }|null
     */
    private static function resolvePricingFromRule(
        Product $product,
        Listino $listino,
        ListinoItem $rule,
        float $quantity,
        array $visitedPricelistIds
    ): ?array {
        $price = match ($rule->compute_price) {
            'fixed' => self::toFloat($rule->fixed_price),
            'percentage' => self::resolvePercentagePrice($product, $rule, $quantity, $visitedPricelistIds),
            'formula' => self::resolveFormulaPrice($product, $rule, $quantity, $visitedPricelistIds),
            default => null,
        };

        if ($price === null) {
            return null;
        }

        $price = round($price, 2);

        return [
            'prezzo' => $price,
            'prezzo_lordo' => $price,
            'sconto_percentuale' => 0.0,
            'iva_percentuale' => self::DEFAULT_IVA_PERCENTUALE,
            'source' => 'odoo_pricelist_item',
            'listino_id' => $listino->id,
            'rule_id' => $rule->id,
        ];
    }

    private static function resolvePercentagePrice(Product $product, ListinoItem $rule, float $quantity, array $visitedPricelistIds): ?float
    {
        $basePrice = self::resolveBasePrice($product, $rule, $quantity, $visitedPricelistIds);

        if ($basePrice === null) {
            return null;
        }

        return $basePrice * (1 - (self::toFloat($rule->percent_price) / 100));
    }

    private static function resolveFormulaPrice(Product $product, ListinoItem $rule, float $quantity, array $visitedPricelistIds): ?float
    {
        $basePrice = self::resolveBasePrice($product, $rule, $quantity, $visitedPricelistIds);

        if ($basePrice === null) {
            return null;
        }

        if ($rule->base === 'standard_price') {
            $markup = self::toFloat($rule->price_markup, -self::toFloat($rule->price_discount));
            $price = $basePrice * (1 + ($markup / 100));
        } else {
            $discount = self::toFloat($rule->price_discount);
            $price = $basePrice * (1 - ($discount / 100));
        }

        $rounding = self::toFloat($rule->price_round);
        if ($rounding > 0) {
            $price = round($price / $rounding) * $rounding;
        }

        $price += self::toFloat($rule->price_surcharge);

        $minMargin = self::toFloat($rule->price_min_margin);
        if ($minMargin > 0 && $price < ($basePrice + $minMargin)) {
            $price = $basePrice + $minMargin;
        }

        $maxMargin = self::toFloat($rule->price_max_margin);
        if ($maxMargin > 0 && $price > ($basePrice + $maxMargin)) {
            $price = $basePrice + $maxMargin;
        }

        return $price;
    }

    private static function resolveBasePrice(Product $product, ListinoItem $rule, float $quantity, array $visitedPricelistIds): ?float
    {
        if ($rule->base === 'pricelist' || $rule->base_pricelist_id !== null) {
            $baseListino = $rule->baseListino;

            if ($baseListino === null && $rule->base_pricelist_id !== null) {
                $baseListino = Listino::query()->find($rule->base_pricelist_id);
            }

            if ($baseListino === null) {
                return null;
            }

            return self::resolvePricingForListino($product, $baseListino, $quantity, $visitedPricelistIds)['prezzo'] ?? null;
        }

        if ($rule->base === 'standard_price') {
            return null;
        }

        return $product->getPrezzoListinoBase();
    }

    /**
     * @return array{
     *     prezzo:float,
     *     prezzo_lordo:float,
     *     sconto_percentuale:float,
     *     iva_percentuale:float,
     *     source:string,
     *     listino_id:?int,
     *     rule_id:?int
     * }|null
     */
    private static function resolveLegacyPivotPricing(Product $product, Listino $listino): ?array
    {
        $record = $product->relationLoaded('listini')
            ? $product->listini->firstWhere('id', $listino->id)
            : $product->listini()->where('Listini.id', $listino->id)->first();

        $price = $record?->pivot?->prezzo;

        if ($price === null) {
            return null;
        }

        return [
            'prezzo' => round((float) $price, 2),
            'prezzo_lordo' => round((float) ($record->pivot->prezzo_lordo ?? $price), 2),
            'sconto_percentuale' => (float) ($record->pivot->sconto_percentuale ?? 0),
            'iva_percentuale' => (float) ($record->pivot->iva_percentuale ?? self::DEFAULT_IVA_PERCENTUALE),
            'source' => 'legacy_pivot',
            'listino_id' => $listino->id,
            'rule_id' => null,
        ];
    }

    /**
     * @return array<int>
     */
    private static function resolveCategoryScopeIds(?int $categoryId): array
    {
        if ($categoryId === null) {
            return [];
        }

        if (isset(self::$categoryTreeCache[$categoryId])) {
            return self::$categoryTreeCache[$categoryId];
        }

        $ids = [];
        $currentId = $categoryId;

        while ($currentId !== null) {
            $ids[] = $currentId;

            $currentId = Categoria::query()
                ->whereKey($currentId)
                ->value('categoria_padre_id');

            $currentId = $currentId !== null ? (int) $currentId : null;
        }

        return self::$categoryTreeCache[$categoryId] = $ids;
    }

    private static function toFloat(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    private static function canUseCentroCostoListini(): bool
    {
        if (self::$hasCentroCostoListinoTable !== null) {
            return self::$hasCentroCostoListinoTable;
        }

        return self::$hasCentroCostoListinoTable = Schema::hasTable('centro_costo_listino');
    }
}
