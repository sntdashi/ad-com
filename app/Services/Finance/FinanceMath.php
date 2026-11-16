<?php

namespace App\Services\Finance;

use InvalidArgumentException;

class FinanceMath
{
    /**
     * Parse a non-negative decimal string or integer into a scaled integer without float math.
     */
    public static function toScaledInt(string|int $value, int $scale = 2): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException('Nilai desimal tidak boleh negatif.');
            }

            return $value * (10 ** $scale);
        }

        $normalized = str_replace(',', '.', trim($value));
        if ($normalized === '' || preg_match('/^\d+(?:\.\d+)?$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Format angka tidak valid: {$value}");
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $paddedFraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        return ((int) $whole * (10 ** $scale)) + (int) $paddedFraction;
    }

    /**
     * Compute line total = round_half_up(quantity * unit_price) using scaled integer arithmetic.
     */
    public static function lineTotal(string|int $quantity, int $unitPrice): int
    {
        $scaledQuantity = self::toScaledInt($quantity, 2);

        return self::divideHalfUp($scaledQuantity * max(0, $unitPrice), 100);
    }

    /**
     * Compute tax amount = round_half_up(taxable_base * tax_rate / 100) using scaled basis points.
     */
    public static function taxAmount(int $taxableBase, string|int $taxRate): int
    {
        if ($taxableBase <= 0) {
            return 0;
        }

        $scaledRate = self::toScaledInt($taxRate, 2);
        if ($scaledRate <= 0) {
            return 0;
        }

        return self::divideHalfUp($taxableBase * $scaledRate, 10000);
    }

    /**
     * Format scaled quantity cleanly to fixed 2-decimal string.
     */
    public static function normalizeDecimalString(string|int $value, int $scale = 2): string
    {
        $scaled = self::toScaledInt($value, $scale);
        $divisor = 10 ** $scale;
        $whole = intdiv($scaled, $divisor);
        $fraction = str_pad((string) ($scaled % $divisor), $scale, '0', STR_PAD_LEFT);

        return "{$whole}.{$fraction}";
    }

    /**
     * Calculate authoritative quotation/invoice totals from raw line items.
     *
     * @param  iterable<int, array<string, mixed>>  $items
     * @return array{
     *     labor_total: int,
     *     spare_parts_total: int,
     *     operational_total: int,
     *     subtotal: int,
     *     discount_amount: int,
     *     tax_rate: string,
     *     tax_amount: int,
     *     total: int,
     *     lines: list<array<string, mixed>>
     * }
     */
    public static function calculateDocumentTotals(iterable $items, int $discountAmount = 0, string|int $taxRate = '0'): array
    {
        $laborTotal = 0;
        $sparePartsTotal = 0;
        $operationalTotal = 0;
        $subtotal = 0;
        $computedLines = [];

        foreach ($items as $index => $line) {
            $type = is_string($line['item_type'] ?? null) ? $line['item_type'] : 'labor';
            $unitPrice = max(0, (int) ($line['unit_price'] ?? 0));
            $unitCost = max(0, (int) ($line['unit_cost'] ?? 0));
            $rawQty = $line['quantity'] ?? '1';
            $qtyInput = is_int($rawQty) ? $rawQty : (string) $rawQty;
            $qtyString = self::normalizeDecimalString($qtyInput, 2);
            $lineTotal = self::lineTotal($qtyString, $unitPrice);

            match ($type) {
                'labor' => $laborTotal += $lineTotal,
                'spare_part' => $sparePartsTotal += $lineTotal,
                default => $operationalTotal += $lineTotal,
            };

            $subtotal += $lineTotal;
            $computedLines[] = [
                ...$line,
                'item_type' => $type,
                'quantity' => $qtyString,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
                'sort_order' => (int) ($line['sort_order'] ?? $index),
            ];
        }

        $safeDiscount = min(max(0, $discountAmount), $subtotal);
        $taxableBase = max(0, $subtotal - $safeDiscount);
        $normalizedTaxRate = self::normalizeDecimalString($taxRate, 2);
        $taxAmount = self::taxAmount($taxableBase, $normalizedTaxRate);
        $total = $taxableBase + $taxAmount;

        return [
            'labor_total' => $laborTotal,
            'spare_parts_total' => $sparePartsTotal,
            'operational_total' => $operationalTotal,
            'subtotal' => $subtotal,
            'discount_amount' => $safeDiscount,
            'tax_rate' => $normalizedTaxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'lines' => $computedLines,
        ];
    }

    private static function divideHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator <= 0) {
            return 0;
        }

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
