<?php

function grooming_get_available_services(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT service_id, species, pet_size, category, service_name, description,
                price, price_note, duration
         FROM services
         WHERE status = 'Available' AND duration > 0
         ORDER BY species, category, service_name, pet_size, service_id"
    );
    $services = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();

    return $services;
}

function grooming_group_services_by_species(array $services): array
{
    $grouped = ['Dog' => [], 'Cat' => [], 'Other' => []];
    foreach ($services as $service) {
        $species = (string) $service['species'];
        if (!array_key_exists($species, $grouped)) {
            $grouped[$species] = [];
        }

        $category = (string) $service['category'];
        if (!array_key_exists($category, $grouped[$species])) {
            $grouped[$species][$category] = [];
        }
        $grouped[$species][$category][] = $service;
    }

    return $grouped;
}

function grooming_service_category_description(string $category): string
{
    $descriptions = [
        'A La Carte Services (Flat Rate)' => "Individual grooming services with fixed prices, regardless of your pet's size.",
        'A La Carte Services (Size-Dependent)' => "Individual grooming services with prices that vary depending on your pet's size.",
    ];

    return $descriptions[$category] ?? '';
}

function grooming_format_service_price(array $service): string
{
    if ($service['price'] === null) {
        return 'Not provided';
    }

    $price = '₱' . number_format((float) $service['price'], 2);
    $priceNote = trim((string) ($service['price_note'] ?? ''));

    return $priceNote === '' ? $price : $price . ' (' . $priceNote . ')';
}
