<?php

function grooming_get_available_services(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT service_id, species, category, service_name, description, price, duration
         FROM services
         WHERE status = 'Available' AND duration > 0
         ORDER BY species, category, service_name, service_id"
    );
    $services = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();

    return $services;
}

function grooming_group_services_by_species(array $services): array
{
    $grouped = ['Dog' => [], 'Cat' => []];
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
