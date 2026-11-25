<?php

function customer_booking_clear_selection(): void
{
    unset(
        $_SESSION['booking_pet_id'],
        $_SESSION['booking_service_id'],
        $_SESSION['booking_date'],
        $_SESSION['booking_time'],
        $_SESSION['booking_notes'],
        $_SESSION['booking_submission_token']
    );
}

function customer_booking_service_categories(): array
{
    return [
        'Basic Bath',
        'Full Grooming',
        'Haircut & Styling',
        'Nail Trimming',
    ];
}

function customer_booking_get_selection(mysqli $conn, int $customerId): ?array
{
    $petId = $_SESSION['booking_pet_id'] ?? null;
    $serviceId = $_SESSION['booking_service_id'] ?? null;
    if (!is_int($petId) || !is_int($serviceId)) {
        customer_booking_clear_selection();
        return null;
    }

    $statement = $conn->prepare(
        "SELECT p.pet_id, p.pet_name, p.species, p.breed, p.photo_path,
                s.service_id, s.species AS service_species, s.category,
                s.service_name, s.description, s.price, s.duration
         FROM pets AS p
         INNER JOIN services AS s ON s.service_id = ? AND s.species = p.species
         WHERE p.pet_id = ?
           AND p.user_id = ?
           AND s.status = 'Available'
           AND s.duration > 0
         LIMIT 1"
    );
    $statement->bind_param('iii', $serviceId, $petId, $customerId);
    $statement->execute();
    $selection = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$selection) {
        customer_booking_clear_selection();
        return null;
    }

    return $selection;
}

function customer_booking_get_customer(mysqli $conn, int $customerId): ?array
{
    $statement = $conn->prepare(
        "SELECT first_name, last_name, email, phone
         FROM users
         WHERE user_id = ? AND role = 'customer'
         LIMIT 1"
    );
    $statement->bind_param('i', $customerId);
    $statement->execute();
    $customer = $statement->get_result()->fetch_assoc();
    $statement->close();

    return $customer ?: null;
}

function customer_booking_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function customer_booking_parse_date(string $date): ?DateTimeImmutable
{
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)) {
        return null;
    }

    $timezone = customer_booking_timezone();
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $parsed === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $parsed->format('Y-m-d') !== $date
    ) {
        return null;
    }

    return $parsed;
}

function customer_booking_is_open_date(string $date): bool
{
    $parsed = customer_booking_parse_date($date);
    if ($parsed === null) {
        return false;
    }

    $today = new DateTimeImmutable('today', customer_booking_timezone());
    return $parsed >= $today && (int) $parsed->format('N') <= 6;
}

function customer_booking_slot_times(): array
{
    return [
        '09:00:00',
        '09:30:00',
        '10:00:00',
        '10:30:00',
        '11:00:00',
        '13:00:00',
        '13:30:00',
        '14:00:00',
        '14:30:00',
        '15:00:00',
        '15:30:00',
        '16:00:00',
        '16:30:00',
        '17:00:00',
    ];
}

function customer_booking_get_slot_options(
    mysqli $conn,
    string $date,
    int $serviceDuration,
    bool $lockExistingAppointments = false
): array {
    $parsedDate = customer_booking_parse_date($date);
    if ($parsedDate === null || !customer_booking_is_open_date($date)) {
        throw new InvalidArgumentException('A valid appointment date is required.');
    }
    if ($serviceDuration < 1) {
        throw new InvalidArgumentException('A positive service duration is required.');
    }

    $sql =
        "SELECT TIME_FORMAT(a.appointment_time, '%H:%i:%s') AS appointment_time,
                s.duration
         FROM appointments AS a
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.appointment_date = ?
           AND a.status IN ('Pending', 'Confirmed')";
    if ($lockExistingAppointments) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $conn->prepare($sql);
    $statement->bind_param('s', $date);
    $statement->execute();
    $existingAppointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $timezone = customer_booking_timezone();
    $currentTime = new DateTimeImmutable('now', $timezone);
    $openingTime = new DateTimeImmutable($date . ' 09:00:00', $timezone);
    $closingTime = new DateTimeImmutable($date . ' 17:00:00', $timezone);
    $lunchStart = new DateTimeImmutable($date . ' 12:00:00', $timezone);
    $lunchEnd = new DateTimeImmutable($date . ' 13:00:00', $timezone);
    $options = [];

    foreach (customer_booking_slot_times() as $time) {
        $start = new DateTimeImmutable($date . ' ' . $time, $timezone);
        $end = $start->modify('+' . $serviceDuration . ' minutes');
        $available = true;
        $reason = '';

        if ($start < $currentTime) {
            $available = false;
            $reason = 'This time has passed.';
        } elseif ($start < $openingTime || $end > $closingTime) {
            $available = false;
            $reason = 'The service would extend past closing time.';
        } elseif ($start < $lunchEnd && $end > $lunchStart) {
            $available = false;
            $reason = 'The service overlaps the lunch break.';
        } else {
            foreach ($existingAppointments as $appointment) {
                $existingDuration = (int) $appointment['duration'];
                if ($existingDuration < 1) {
                    $available = false;
                    $reason = 'Another appointment has no valid duration on record.';
                    break;
                }

                $existingStart = new DateTimeImmutable(
                    $date . ' ' . (string) $appointment['appointment_time'],
                    $timezone
                );
                $existingEnd = $existingStart->modify('+' . $existingDuration . ' minutes');

                if ($start < $existingEnd && $end > $existingStart) {
                    $available = false;
                    $reason = 'This time overlaps another active appointment.';
                    break;
                }
            }
        }

        $slot = new DateTimeImmutable($date . ' ' . $time, $timezone);
        $options[] = [
            'time' => $time,
            'label' => $slot->format('g:i A'),
            'period' => $slot->format('A') === 'AM' ? 'Morning' : 'Afternoon',
            'available' => $available,
            'reason' => $reason,
        ];
    }

    return $options;
}
