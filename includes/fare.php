<?php
declare(strict_types=1);

// Fare/commission formula reverse-engineered from public.booking_payment —
// confirmed exactly against 10 real recent rows (ids 504-513, 2026-07-23 to
// 2026-08-08). These constants matched every sampled row, so they look like
// fixed platform constants rather than something configurable per booking;
// revisit if a fare_config-style table ever surfaces.
const FARE_MINIMUM_FARE     = 40.0;  // flat base, covers the first FARE_KM_BASIS km
const FARE_PESOS_PER_KM     = 15.0;  // rate for km within the base
const FARE_PESOS_PER_KM2    = 10.0;  // rate for km beyond the base
const FARE_KM_BASIS         = 7.0;   // km threshold where the rate switches
const FARE_COMMISSION_RATE  = 0.15;  // flat platform commission

// Compute the full fare/commission breakdown for a booking.
//
// $distanceKm     — route distance (pickup -> dropoff), used only when
//                    $manualFare is null/blank (auto-compute path).
// $manualFare     — dispatcher-entered fare; when set (> 0) it's used
//                    directly as total_amount instead of computing one.
// $bookingFee/$tip/$promoDiscount — optional extras, default 0.
//
// Returns the same shape as public.booking_payment's numeric columns, plus
// 'fare_source' ('manual'|'auto') and the km_basis/pesos_per_km2/exceeding_*
// figures (stored in all_payment_details, mirroring the real app's payload).
function compute_fare(float $distanceKm, ?float $manualFare, float $bookingFee = 0.0, float $tip = 0.0, float $promoDiscount = 0.0): array
{
    $distanceKm     = round(max(0.0, $distanceKm), 2);
    $baseAmount     = round(min($distanceKm, FARE_KM_BASIS) * FARE_PESOS_PER_KM, 2);
    $exceedingKms   = round(max(0.0, $distanceKm - FARE_KM_BASIS), 2);
    $exceedingTotal = round($exceedingKms * FARE_PESOS_PER_KM2, 2);
    $autoTotalWoPromo = round(FARE_MINIMUM_FARE + $baseAmount + $exceedingTotal + $bookingFee + $tip, 2);

    if ($manualFare !== null && $manualFare > 0) {
        $fareSource   = 'manual';
        $totalAmount  = round($manualFare, 2);
        $totalWoPromo = round($totalAmount + $promoDiscount, 2);
    } else {
        $fareSource   = 'auto';
        $totalWoPromo = $autoTotalWoPromo;
        $totalAmount  = round($totalWoPromo - $promoDiscount, 2);
    }

    // Truncated (not rounded) to 2dp — matches every sampled row exactly,
    // e.g. total_amount 184.30 -> commission 27.64, not 27.65. The epsilon
    // guards against binary float representations like 145.2 landing just
    // under the truncation boundary (e.g. 2177.999999999997 instead of 2178).
    $commission = floor($totalAmount * FARE_COMMISSION_RATE * 100 + 1e-6) / 100;
    $riderNet   = round($totalAmount - $commission, 2);

    return [
        'fare_source'           => $fareSource,
        'minimum_fare'          => FARE_MINIMUM_FARE,
        'pesos_per_km'          => FARE_PESOS_PER_KM,
        'pesos_per_km2'         => FARE_PESOS_PER_KM2,
        'km_basis'              => FARE_KM_BASIS,
        'distance_km_round'     => $distanceKm,
        'base_amount'           => $baseAmount,
        'exceeding_kms'         => $exceedingKms,
        'exceeding_kms_total'   => $exceedingTotal,
        'booking_fee'           => round($bookingFee, 2),
        'tip'                   => round($tip, 2),
        'promo_discount'        => round($promoDiscount, 2),
        'total_amount_wo_promo' => $totalWoPromo,
        'total_amount'          => $totalAmount,
        'commission'            => $commission,
        'rider_net_amount'      => $riderNet,
    ];
}
