<?php

function appointment_price_display(array $appointment): array
{
    $hasSnapshot = isset($appointment['service_price_snapshot_saved'])
        && (int) $appointment['service_price_snapshot_saved'] === 1;
    $price = $hasSnapshot
        ? ($appointment['service_price_at_booking'] ?? null)
        : ($appointment['current_service_price'] ?? null);

    if ($price === null) {
        $formatted = $hasSnapshot ? 'Price not set at booking' : 'Current price unavailable';
    } else {
        $formatted = '₱' . number_format((float) $price, 2);
    }

    return [
        'formatted' => $formatted,
        'source' => $hasSnapshot
            ? 'Saved at booking'
            : 'Current service price; original price not recorded',
        'is_snapshot' => $hasSnapshot,
    ];
}
