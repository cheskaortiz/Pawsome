<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'reports';
$manilaTimezone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $manilaTimezone);
$preset = $_GET['preset'] ?? 'month';
$presetOptions = [
    'today' => 'Today',
    'week' => 'This Week',
    'month' => 'This Month',
    'year' => 'This Year',
    'custom' => 'Custom Range',
];
$rawFromDate = $_GET['from_date'] ?? '';
$rawToDate = $_GET['to_date'] ?? '';
$isFragment = ($_GET['fragment'] ?? '') === '1';
$isCsvExport = ($_GET['export'] ?? '') === 'csv';

$sendJson = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

$isValidDate = static function (string $date) use ($manilaTimezone): bool {
    if ($date === '') {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $manilaTimezone);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
};

if (
    !is_string($preset)
    || !array_key_exists($preset, $presetOptions)
    || !is_string($rawFromDate)
    || !is_string($rawToDate)
) {
    if ($isFragment) {
        $sendJson(['error' => 'Choose a valid report date range.'], 400);
    }
    http_response_code(400);
    exit('Choose a valid report date range.');
}

if ($preset === 'today') {
    $startDate = $today;
    $endDate = $today;
} elseif ($preset === 'week') {
    $startDate = $today->modify('monday this week');
    $endDate = $today;
} elseif ($preset === 'year') {
    $startDate = $today->setDate((int) $today->format('Y'), 1, 1);
    $endDate = $today;
} elseif ($preset === 'custom') {
    $fromDateInput = $rawFromDate !== '' ? $rawFromDate : $today->modify('first day of this month')->format('Y-m-d');
    $toDateInput = $rawToDate !== '' ? $rawToDate : $today->format('Y-m-d');
    if (!$isValidDate($fromDateInput) || !$isValidDate($toDateInput) || $fromDateInput > $toDateInput) {
        if ($isFragment) {
            $sendJson(['error' => 'Choose a valid custom date range.'], 400);
        }
        http_response_code(400);
        exit('Choose a valid custom date range.');
    }
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDateInput, $manilaTimezone);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $toDateInput, $manilaTimezone);
} else {
    $startDate = $today->modify('first day of this month');
    $endDate = $today;
}

$fromDate = $startDate->format('Y-m-d');
$toDate = $endDate->format('Y-m-d');
$endExclusiveDate = $endDate->modify('+1 day')->format('Y-m-d');
$startDateTime = $fromDate . ' 00:00:00';
$endExclusiveDateTime = $endExclusiveDate . ' 00:00:00';
$rangeLabel = $startDate->format('M j, Y') . ' – ' . $endDate->format('M j, Y');
$rangeDays = (int) $startDate->diff($endDate)->days + 1;

$fetchRows = static function (string $sql, string $parameterTypes, array $parameters) use ($conn): array {
    $statement = $conn->prepare($sql);
    if ($parameters) {
        $bindArguments = [$parameterTypes];
        foreach (array_keys($parameters) as $parameterIndex) {
            $bindArguments[] = &$parameters[$parameterIndex];
        }
        call_user_func_array([$statement, 'bind_param'], $bindArguments);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
};
$fetchOne = static function (string $sql, string $parameterTypes, array $parameters) use ($fetchRows): array {
    return $fetchRows($sql, $parameterTypes, $parameters)[0] ?? [];
};

$appointmentStatusNames = [
    'Pending',
    'Confirmed',
    'In Progress',
    'Ready for Pickup',
    'Completed',
    'Cancelled',
    'Rejected',
];
$statusCounts = array_fill_keys($appointmentStatusNames, 0);
$trendMode = $rangeDays <= 31 ? 'Daily' : ($rangeDays <= 180 ? 'Weekly' : 'Monthly');
$reportData = [
    'range' => ['from' => $fromDate, 'to' => $toDate, 'label' => $rangeLabel],
    'overview' => [
        'appointment_count' => 0,
        'completed_service_value' => 0.0,
        'total_customers' => 0,
        'average_rating' => null,
    ],
    'period_values' => ['today' => 0.0, 'month' => 0.0, 'year' => 0.0],
    'appointments' => [
        'status_counts' => $statusCounts,
        'trend_mode' => $trendMode,
        'trend' => [],
    ],
    'customers' => ['new' => 0, 'returning' => 0],
    'pets' => ['total' => 0, 'dogs' => 0, 'cats' => 0, 'other' => 0],
    'services' => [],
    'categories' => [],
    'monthly_service_value' => [],
    'reviews' => [
        'total' => 0,
        'average' => null,
        'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
    ],
    'grooming_records' => 0,
    'missing_price_snapshots' => 0,
];
$dataLoadError = false;

try {
    $appointmentSummary = $fetchOne(
        "SELECT COUNT(*) AS appointment_count,
                COALESCE(SUM(status = 'Completed'), 0) AS completed_count,
                COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled_count,
                COALESCE(SUM(status = 'Rejected'), 0) AS rejected_count,
                COALESCE(SUM(status = 'Pending'), 0) AS pending_count,
                COALESCE(SUM(status = 'Confirmed'), 0) AS confirmed_count,
                COALESCE(SUM(status = 'In Progress'), 0) AS in_progress_count,
                COALESCE(SUM(status = 'Ready for Pickup'), 0) AS ready_for_pickup_count,
                COALESCE(SUM(status = 'Completed'
                    AND (service_price_snapshot_saved = 0 OR service_price_at_booking IS NULL)), 0) AS missing_price_snapshots,
                COALESCE(SUM(CASE
                    WHEN status = 'Completed'
                     AND service_price_snapshot_saved = 1
                     AND service_price_at_booking IS NOT NULL
                    THEN service_price_at_booking
                    ELSE 0
                END), 0) AS completed_service_value
         FROM appointments
         WHERE appointment_date >= ? AND appointment_date < ?",
        'ss',
        [$fromDate, $endExclusiveDate]
    );
    $reportData['overview']['appointment_count'] = (int) ($appointmentSummary['appointment_count'] ?? 0);
    $reportData['overview']['completed_service_value'] = (float) ($appointmentSummary['completed_service_value'] ?? 0);
    $reportData['missing_price_snapshots'] = (int) ($appointmentSummary['missing_price_snapshots'] ?? 0);
    $statusColumns = [
        'Pending' => 'pending_count',
        'Confirmed' => 'confirmed_count',
        'In Progress' => 'in_progress_count',
        'Ready for Pickup' => 'ready_for_pickup_count',
        'Completed' => 'completed_count',
        'Cancelled' => 'cancelled_count',
        'Rejected' => 'rejected_count',
    ];
    foreach ($statusColumns as $status => $column) {
        $reportData['appointments']['status_counts'][$status] = (int) ($appointmentSummary[$column] ?? 0);
    }
    foreach ($fetchRows(
        "SELECT COALESCE(status, 'Unknown') AS status, COUNT(*) AS status_count
         FROM appointments
         WHERE appointment_date >= ? AND appointment_date < ?
         GROUP BY status",
        'ss',
        [$fromDate, $endExclusiveDate]
    ) as $statusRow) {
        $status = (string) $statusRow['status'];
        if (!array_key_exists($status, $reportData['appointments']['status_counts'])) {
            $reportData['appointments']['status_counts'][$status] = 0;
        }
        $reportData['appointments']['status_counts'][$status] = (int) $statusRow['status_count'];
    }

    $customerSummary = $fetchOne(
        "SELECT COUNT(*) AS total_customers,
                COALESCE(SUM(created_at >= ? AND created_at < ?), 0) AS new_customers
         FROM users
         WHERE role = 'customer' AND created_at < ?",
        'sss',
        [$startDateTime, $endExclusiveDateTime, $endExclusiveDateTime]
    );
    $reportData['overview']['total_customers'] = (int) ($customerSummary['total_customers'] ?? 0);
    $reportData['customers']['new'] = (int) ($customerSummary['new_customers'] ?? 0);
    $reportData['customers']['returning'] = (int) ($fetchOne(
        "SELECT COUNT(*) AS returning_customers
         FROM (
             SELECT user_id
             FROM appointments
             WHERE appointment_date >= ? AND appointment_date < ? AND status = 'Completed'
             GROUP BY user_id
             HAVING COUNT(*) > 1
         ) AS repeat_customers",
        'ss',
        [$fromDate, $endExclusiveDate]
    )['returning_customers'] ?? 0);

    $petSummary = $fetchOne(
        "SELECT COUNT(*) AS total_pets,
                COALESCE(SUM(species = 'Dog'), 0) AS dogs,
                COALESCE(SUM(species = 'Cat'), 0) AS cats,
                COALESCE(SUM(species NOT IN ('Dog', 'Cat')), 0) AS other
         FROM pets
         WHERE created_at < ?",
        's',
        [$endExclusiveDateTime]
    );
    $reportData['pets'] = [
        'total' => (int) ($petSummary['total_pets'] ?? 0),
        'dogs' => (int) ($petSummary['dogs'] ?? 0),
        'cats' => (int) ($petSummary['cats'] ?? 0),
        'other' => (int) ($petSummary['other'] ?? 0),
    ];

    $reviewSummary = $fetchOne(
        "SELECT COUNT(*) AS approved_reviews, AVG(rating) AS average_rating
         FROM reviews
         WHERE status = 'Approved' AND created_at >= ? AND created_at < ?",
        'ss',
        [$startDateTime, $endExclusiveDateTime]
    );
    $reportData['reviews']['total'] = (int) ($reviewSummary['approved_reviews'] ?? 0);
    $reportData['reviews']['average'] = $reviewSummary['average_rating'] === null
        ? null
        : (float) $reviewSummary['average_rating'];
    foreach ($fetchRows(
        "SELECT rating, COUNT(*) AS rating_count
         FROM reviews
         WHERE status = 'Approved' AND created_at >= ? AND created_at < ?
         GROUP BY rating",
        'ss',
        [$startDateTime, $endExclusiveDateTime]
    ) as $ratingRow) {
        $rating = (int) $ratingRow['rating'];
        if (array_key_exists($rating, $reportData['reviews']['distribution'])) {
            $reportData['reviews']['distribution'][$rating] = (int) $ratingRow['rating_count'];
        }
    }

    $serviceValueForPeriod = static function (string $start, string $end) use ($fetchOne): float {
        $row = $fetchOne(
            "SELECT COALESCE(SUM(service_price_at_booking), 0) AS service_value
             FROM appointments
             WHERE appointment_date >= ? AND appointment_date < ?
               AND status = 'Completed'
               AND service_price_snapshot_saved = 1
               AND service_price_at_booking IS NOT NULL",
            'ss',
            [$start, $end]
        );
        return (float) ($row['service_value'] ?? 0);
    };
    $todayStart = $today->format('Y-m-d');
    $tomorrow = $today->modify('+1 day')->format('Y-m-d');
    $monthStart = $today->modify('first day of this month')->format('Y-m-d');
    $yearStart = $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d');
    $reportData['period_values'] = [
        'today' => $serviceValueForPeriod($todayStart, $tomorrow),
        'month' => $serviceValueForPeriod($monthStart, $tomorrow),
        'year' => $serviceValueForPeriod($yearStart, $tomorrow),
    ];

    $reportData['services'] = $fetchRows(
        'SELECT s.service_id, s.service_name, s.category,
                COUNT(a.appointment_id) AS booking_count,
                COALESCE(SUM(CASE
                    WHEN a.status = \'Completed\'
                     AND a.service_price_snapshot_saved = 1
                     AND a.service_price_at_booking IS NOT NULL
                    THEN a.service_price_at_booking
                    ELSE 0
                END), 0) AS completed_service_value
         FROM services AS s
         LEFT JOIN appointments AS a
            ON a.service_id = s.service_id
           AND a.appointment_date >= ?
           AND a.appointment_date < ?
         GROUP BY s.service_id, s.service_name, s.category
         ORDER BY booking_count DESC, s.service_name ASC',
        'ss',
        [$fromDate, $endExclusiveDate]
    );
    $reportData['categories'] = $fetchRows(
        'SELECT s.category,
                COUNT(a.appointment_id) AS booking_count,
                COALESCE(SUM(CASE
                    WHEN a.status = \'Completed\'
                     AND a.service_price_snapshot_saved = 1
                     AND a.service_price_at_booking IS NOT NULL
                    THEN a.service_price_at_booking
                    ELSE 0
                END), 0) AS completed_service_value
         FROM services AS s
         LEFT JOIN appointments AS a
            ON a.service_id = s.service_id
           AND a.appointment_date >= ?
           AND a.appointment_date < ?
         GROUP BY s.category
         ORDER BY completed_service_value DESC, booking_count DESC, s.category ASC',
        'ss',
        [$fromDate, $endExclusiveDate]
    );

    $monthlyRows = $fetchRows(
        "SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS month_key,
                COALESCE(SUM(service_price_at_booking), 0) AS service_value
         FROM appointments
         WHERE appointment_date >= ? AND appointment_date < ?
           AND status = 'Completed'
           AND service_price_snapshot_saved = 1
           AND service_price_at_booking IS NOT NULL
         GROUP BY DATE_FORMAT(appointment_date, '%Y-%m')
         ORDER BY month_key",
        'ss',
        [$fromDate, $endExclusiveDate]
    );
    $monthlyValueByKey = [];
    foreach ($monthlyRows as $monthlyRow) {
        $monthlyValueByKey[(string) $monthlyRow['month_key']] = (float) $monthlyRow['service_value'];
    }
    $monthCursor = $startDate->modify('first day of this month');
    $lastMonth = $endDate->modify('first day of this month');
    while ($monthCursor <= $lastMonth) {
        $monthKey = $monthCursor->format('Y-m');
        $reportData['monthly_service_value'][] = [
            'key' => $monthKey,
            'label' => $monthCursor->format('M Y'),
            'value' => $monthlyValueByKey[$monthKey] ?? 0.0,
        ];
        $monthCursor = $monthCursor->modify('+1 month');
    }

    $trendExpression = match ($trendMode) {
        'Daily' => 'appointment_date',
        'Weekly' => 'DATE_SUB(appointment_date, INTERVAL WEEKDAY(appointment_date) DAY)',
        default => "DATE_FORMAT(appointment_date, '%Y-%m-01')",
    };
    $trendRows = $fetchRows(
        "SELECT {$trendExpression} AS period_start, COUNT(*) AS appointment_count
         FROM appointments
         WHERE appointment_date >= ? AND appointment_date < ?
         GROUP BY period_start
         ORDER BY period_start",
        'ss',
        [$fromDate, $endExclusiveDate]
    );
    $trendValueByKey = [];
    foreach ($trendRows as $trendRow) {
        $trendValueByKey[(string) $trendRow['period_start']] = (int) $trendRow['appointment_count'];
    }
    $trendCursor = $trendMode === 'Daily'
        ? $startDate
        : ($trendMode === 'Weekly'
            ? $startDate->modify('monday this week')
            : $startDate->modify('first day of this month'));
    $lastTrendPeriod = $trendMode === 'Daily'
        ? $endDate
        : ($trendMode === 'Weekly'
            ? $endDate->modify('monday this week')
            : $endDate->modify('first day of this month'));
    while ($trendCursor <= $lastTrendPeriod) {
        $trendKey = $trendCursor->format('Y-m-d');
        $reportData['appointments']['trend'][] = [
            'key' => $trendKey,
            'label' => $trendMode === 'Monthly'
                ? $trendCursor->format('M Y')
                : ($trendMode === 'Weekly' ? 'Week of ' . $trendCursor->format('M j') : $trendCursor->format('M j')),
            'count' => $trendValueByKey[$trendKey] ?? 0,
        ];
        $trendCursor = $trendMode === 'Daily'
            ? $trendCursor->modify('+1 day')
            : ($trendMode === 'Weekly'
                ? $trendCursor->modify('+1 week')
                : $trendCursor->modify('+1 month'));
    }

    $groomingSummary = $fetchOne(
        'SELECT COUNT(*) AS grooming_records
         FROM grooming_records
         WHERE grooming_date >= ? AND grooming_date < ?',
        'ss',
        [$fromDate, $endExclusiveDate]
    );
    $reportData['grooming_records'] = (int) ($groomingSummary['grooming_records'] ?? 0);
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin reports query failed: ' . $exception->getMessage());
    $dataLoadError = true;
}

if ($isFragment) {
    if ($dataLoadError) {
        $sendJson(['error' => 'We could not load reports for this date range. Please try again.'], 500);
    }
}

if ($isCsvExport) {
    if ($dataLoadError) {
        http_response_code(500);
        exit('We could not export reports right now. Please try again.');
    }

    $csvSafeValue = static function ($value): string {
        $value = (string) $value;
        return preg_match('/^[\t\r ]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    };
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('We could not export reports right now. Please try again.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pawsome-reports-' . $fromDate . '-to-' . $toDate . '.csv"');
    header('Cache-Control: no-store');
    fwrite($output, "\xEF\xBB\xBF");
    $writeCsvRow = static function (array $row) use ($output, $csvSafeValue): void {
        fputcsv($output, array_map($csvSafeValue, $row), ',', '"', '');
    };
    $writeCsvRow(['Pawsome Admin Reports', 'Date range', $rangeLabel]);
    $writeCsvRow([]);
    $writeCsvRow(['Overview']);
    $writeCsvRow(['Metric', 'Value', 'Notes']);
    $writeCsvRow(['Total appointments', $reportData['overview']['appointment_count'], 'Appointment date within selected range']);
    $writeCsvRow(['Completed service value', number_format($reportData['overview']['completed_service_value'], 2, '.', ''), 'Completed appointments with a saved booking price; not payment-confirmed']);
    $writeCsvRow(['Total customers as of range end', $reportData['overview']['total_customers'], 'Customer accounts registered by the selected end date']);
    $writeCsvRow(['Average approved review rating', $reportData['reviews']['average'] === null ? '' : number_format($reportData['reviews']['average'], 2, '.', ''), 'Approved reviews submitted in selected range']);
    $writeCsvRow([]);
    $writeCsvRow(['Current calendar service value']);
    $writeCsvRow(['Today', number_format($reportData['period_values']['today'], 2, '.', '')]);
    $writeCsvRow(['This month', number_format($reportData['period_values']['month'], 2, '.', '')]);
    $writeCsvRow(['This year', number_format($reportData['period_values']['year'], 2, '.', '')]);
    $writeCsvRow(['Note', 'Values use completed appointment price snapshots and do not confirm payment collection.']);
    $writeCsvRow([]);
    $writeCsvRow(['Appointments by status']);
    $writeCsvRow(['Status', 'Appointments']);
    foreach ($reportData['appointments']['status_counts'] as $status => $count) {
        $writeCsvRow([$status, $count]);
    }
    $writeCsvRow([]);
    $writeCsvRow(['Appointment trends (' . $reportData['appointments']['trend_mode'] . ')']);
    $writeCsvRow(['Period', 'Appointments']);
    foreach ($reportData['appointments']['trend'] as $trend) {
        $writeCsvRow([$trend['label'], $trend['count']]);
    }
    $writeCsvRow([]);
    $writeCsvRow(['Completed service value by month']);
    $writeCsvRow(['Month', 'Completed service value']);
    foreach ($reportData['monthly_service_value'] as $month) {
        $writeCsvRow([$month['label'], number_format((float) $month['value'], 2, '.', '')]);
    }
    $writeCsvRow([]);
    $writeCsvRow(['Service category performance']);
    $writeCsvRow(['Category', 'Bookings', 'Completed service value']);
    foreach ($reportData['categories'] as $category) {
        $writeCsvRow([$category['category'], $category['booking_count'], number_format((float) $category['completed_service_value'], 2, '.', '')]);
    }
    $writeCsvRow([]);
    $writeCsvRow(['Service performance']);
    $writeCsvRow(['Service', 'Category', 'Bookings', 'Completed service value']);
    foreach ($reportData['services'] as $service) {
        $writeCsvRow([$service['service_name'], $service['category'], $service['booking_count'], number_format((float) $service['completed_service_value'], 2, '.', '')]);
    }
    $writeCsvRow([]);
    $writeCsvRow(['Customers and pets']);
    $writeCsvRow(['New customers', $reportData['customers']['new']]);
    $writeCsvRow(['Returning customers (2+ completed appointments in range)', $reportData['customers']['returning']]);
    $writeCsvRow(['Registered pets as of range end', $reportData['pets']['total']]);
    $writeCsvRow(['Dogs', $reportData['pets']['dogs']]);
    $writeCsvRow(['Cats', $reportData['pets']['cats']]);
    $writeCsvRow(['Other species', $reportData['pets']['other']]);
    $writeCsvRow([]);
    $writeCsvRow(['Approved review rating distribution']);
    $writeCsvRow(['Rating', 'Reviews']);
    foreach ($reportData['reviews']['distribution'] as $rating => $count) {
        $writeCsvRow([$rating . ' stars', $count]);
    }
    $writeCsvRow([]);
    $writeCsvRow(['Grooming records in range', $reportData['grooming_records']]);
    $writeCsvRow(['Groomer performance', 'Unavailable: grooming records reference users, and staff profiles have no linked assignment key.']);
    fclose($output);
    exit;
}

$formatMoney = static function (float $amount): string {
    return '₱' . number_format($amount, 2);
};
$percentage = static function (float $value, float $maximum): string {
    if ($maximum <= 0) {
        return '0';
    }
    return number_format(max(0, min(100, ($value / $maximum) * 100)), 1, '.', '');
};
$renderReportContent = static function (array $data) use ($formatMoney, $percentage): string {
    $serviceValueCaption = 'Saved completed appointment prices; not confirmed collected payments.';
    $bookedServices = array_values(array_filter(
        $data['services'],
        static fn (array $service): bool => (int) $service['booking_count'] > 0
    ));
    $mostBookedServices = array_slice($bookedServices, 0, 5);
    $leastBookedServices = $bookedServices;
    usort($leastBookedServices, static function (array $left, array $right): int {
        return (int) $left['booking_count'] <=> (int) $right['booking_count']
            ?: strcmp((string) $left['service_name'], (string) $right['service_name']);
    });
    $leastBookedServices = array_slice($leastBookedServices, 0, 5);
    $maximumMonthlyValue = max(array_map(
        static fn (array $month): float => (float) $month['value'],
        $data['monthly_service_value']
    ) ?: [0.0]);
    $maximumTrendCount = max(array_map(
        static fn (array $period): int => (int) $period['count'],
        $data['appointments']['trend']
    ) ?: [0]);
    $maximumCategoryValue = max(array_map(
        static fn (array $category): float => (float) $category['completed_service_value'],
        $data['categories']
    ) ?: [0.0]);
    $maximumRatingCount = max($data['reviews']['distribution'] ?: [0]);
    $maximumPetCount = max([
        (int) $data['pets']['dogs'],
        (int) $data['pets']['cats'],
        (int) $data['pets']['other'],
    ]);

    ob_start();
    ?>
    <section class="admin-overview-grid admin-report-overview" aria-label="Report overview">
        <article class="admin-overview-card">
            <span class="admin-overview-label">Completed Service Value</span>
            <strong class="admin-overview-value"><?= auth_escape($formatMoney((float) $data['overview']['completed_service_value'])) ?></strong>
            <span class="admin-overview-description"><?= auth_escape($serviceValueCaption) ?></span>
        </article>
        <article class="admin-overview-card">
            <span class="admin-overview-label">Total Appointments</span>
            <strong class="admin-overview-value"><?= number_format((int) $data['overview']['appointment_count']) ?></strong>
            <span class="admin-overview-description">Appointments dated <?= auth_escape($data['range']['label']) ?></span>
        </article>
        <article class="admin-overview-card">
            <span class="admin-overview-label">Total Customers</span>
            <strong class="admin-overview-value"><?= number_format((int) $data['overview']['total_customers']) ?></strong>
            <span class="admin-overview-description">Registered by the selected end date</span>
        </article>
        <article class="admin-overview-card">
            <span class="admin-overview-label">Average Review Rating</span>
            <strong class="admin-overview-value"><?= $data['reviews']['average'] === null ? '—' : number_format((float) $data['reviews']['average'], 2) . ' / 5' ?></strong>
            <span class="admin-overview-description">Approved reviews only</span>
        </article>
    </section>

    <?php if ((int) $data['missing_price_snapshots'] > 0): ?>
        <p class="admin-report-notice" role="status">
            <?= number_format((int) $data['missing_price_snapshots']) ?> completed appointment(s) in this range have no saved booking price and are omitted from service-value totals.
        </p>
    <?php endif; ?>

    <section class="admin-panel admin-report-panel" aria-labelledby="report-revenue-title">
        <div class="admin-panel-heading">
            <div>
                <span class="eyebrow">Revenue reports</span>
                <h2 id="report-revenue-title">Completed Service Value</h2>
                <p class="admin-report-panel-note"><?= auth_escape($serviceValueCaption) ?> All values use appointment dates. Today, month, and year cards use current calendar periods; the remaining reports follow the selected range.</p>
            </div>
        </div>
        <div class="admin-report-period-grid">
            <?php foreach ([
                ['Today', $data['period_values']['today']],
                ['This Month', $data['period_values']['month']],
                ['This Year', $data['period_values']['year']],
            ] as [$label, $amount]): ?>
                <article class="admin-report-period-card">
                    <span><?= auth_escape($label) ?></span>
                    <strong><?= auth_escape($formatMoney((float) $amount)) ?></strong>
                    <small>Saved completed appointment prices</small>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="admin-report-chart-grid">
            <section class="admin-report-chart-panel" aria-labelledby="monthly-value-title">
                <div class="admin-report-section-heading">
                    <div>
                        <h3 id="monthly-value-title">Monthly Service Value</h3>
                        <p><?= auth_escape($data['range']['label']) ?></p>
                    </div>
                </div>
                <?php if ($maximumMonthlyValue <= 0): ?>
                    <p class="admin-panel-message">No completed appointments with saved prices in this range.</p>
                <?php else: ?>
                    <div class="admin-report-chart-scroll">
                        <div class="admin-report-column-chart" role="img" aria-label="Monthly completed service value chart">
                            <?php foreach ($data['monthly_service_value'] as $month): ?>
                                <?php $barHeight = $percentage((float) $month['value'], $maximumMonthlyValue); ?>
                                <div class="admin-report-chart-column">
                                    <span class="admin-report-chart-value"><?= auth_escape($formatMoney((float) $month['value'])) ?></span>
                                    <div class="admin-report-chart-track">
                                        <span class="admin-report-chart-bar" style="height: <?= auth_escape($barHeight) ?>%"></span>
                                    </div>
                                    <span class="admin-report-chart-label"><?= auth_escape($month['label']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="admin-report-chart-panel" aria-labelledby="category-value-title">
                <div class="admin-report-section-heading">
                    <div>
                        <h3 id="category-value-title">Service Category Value</h3>
                        <p>Bookings and saved completed prices</p>
                    </div>
                </div>
                <?php if (!$data['categories']): ?>
                    <p class="admin-panel-message">No service categories are available.</p>
                <?php else: ?>
                    <div class="admin-report-horizontal-chart">
                        <?php foreach ($data['categories'] as $category): ?>
                            <?php $barWidth = $percentage((float) $category['completed_service_value'], $maximumCategoryValue); ?>
                            <div class="admin-report-horizontal-item">
                                <div class="admin-report-horizontal-heading">
                                    <strong><?= auth_escape((string) $category['category']) ?></strong>
                                    <span><?= number_format((int) $category['booking_count']) ?> bookings · <?= auth_escape($formatMoney((float) $category['completed_service_value'])) ?></span>
                                </div>
                                <div class="admin-report-horizontal-track">
                                    <span style="width: <?= auth_escape($barWidth) ?>%"></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </section>

    <section class="admin-panel admin-report-panel" aria-labelledby="appointment-report-title">
        <div class="admin-panel-heading">
            <div>
                <span class="eyebrow">Appointment reports</span>
                <h2 id="appointment-report-title">Appointments</h2>
                <p class="admin-report-panel-note">Status counts and trends use appointment dates in the selected range.</p>
            </div>
        </div>
        <div class="admin-report-status-grid">
            <?php foreach ($data['appointments']['status_counts'] as $status => $count): ?>
                <?php $statusClass = strtolower(str_replace(' ', '-', (string) $status)); ?>
                <article class="admin-report-status-card">
                    <span class="customer-status customer-status--<?= auth_escape($statusClass) ?>"><?= auth_escape((string) $status) ?></span>
                    <strong><?= number_format((int) $count) ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
        <section class="admin-report-trend-panel" aria-labelledby="appointment-trend-title">
            <div class="admin-report-section-heading">
                <div>
                    <h3 id="appointment-trend-title"><?= auth_escape($data['appointments']['trend_mode']) ?> Appointment Trend</h3>
                    <p><?= auth_escape($data['range']['label']) ?></p>
                </div>
            </div>
            <?php if (!$data['appointments']['trend']): ?>
                <p class="admin-panel-message">No appointment activity in this range.</p>
            <?php else: ?>
                <div class="admin-report-chart-scroll">
                    <div class="admin-report-column-chart admin-report-appointment-chart" role="img" aria-label="<?= auth_escape($data['appointments']['trend_mode']) ?> appointment trend chart">
                        <?php foreach ($data['appointments']['trend'] as $period): ?>
                            <?php $barHeight = $percentage((float) $period['count'], (float) $maximumTrendCount); ?>
                            <div class="admin-report-chart-column">
                                <span class="admin-report-chart-value"><?= number_format((int) $period['count']) ?></span>
                                <div class="admin-report-chart-track">
                                    <span class="admin-report-chart-bar is-appointment" style="height: <?= auth_escape($barHeight) ?>%"></span>
                                </div>
                                <span class="admin-report-chart-label"><?= auth_escape($period['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </section>

    <section class="admin-panel admin-report-panel" aria-labelledby="service-performance-title">
        <div class="admin-panel-heading">
            <div>
                <span class="eyebrow">Service performance</span>
                <h2 id="service-performance-title">Most &amp; Least Booked Services</h2>
                <p class="admin-report-panel-note">Booking counts include all appointment statuses. Service value includes completed appointments with saved prices only.</p>
            </div>
        </div>
        <div class="admin-report-service-insights">
            <?php foreach ([
                ['Most Booked', $mostBookedServices],
                ['Least Booked (with bookings)', $leastBookedServices],
            ] as [$title, $serviceRows]): ?>
                <section class="admin-report-service-list" aria-label="<?= auth_escape($title) ?>">
                    <h3><?= auth_escape($title) ?></h3>
                    <?php if (!$serviceRows): ?>
                        <p class="admin-report-empty">No services were booked in this range.</p>
                    <?php else: ?>
                        <ol>
                            <?php foreach ($serviceRows as $service): ?>
                                <li>
                                    <span>
                                        <strong><?= auth_escape((string) $service['service_name']) ?></strong>
                                        <small><?= auth_escape((string) $service['category']) ?></small>
                                    </span>
                                    <span class="admin-report-service-result">
                                        <strong><?= number_format((int) $service['booking_count']) ?> bookings</strong>
                                        <small><?= auth_escape($formatMoney((float) $service['completed_service_value'])) ?></small>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <div class="admin-report-table-scroll">
            <table class="admin-table admin-report-service-table">
                <caption>Revenue per service for the selected date range. The table includes all service records.</caption>
                <thead>
                    <tr>
                        <th scope="col">Service</th>
                        <th scope="col">Category</th>
                        <th scope="col">Bookings</th>
                        <th scope="col">Completed Service Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['services'] as $service): ?>
                        <tr>
                            <td><?= auth_escape((string) $service['service_name']) ?></td>
                            <td><?= auth_escape((string) $service['category']) ?></td>
                            <td><?= number_format((int) $service['booking_count']) ?></td>
                            <td><?= auth_escape($formatMoney((float) $service['completed_service_value'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data['services']): ?>
                        <tr><td colspan="4">No services found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="admin-report-two-column">
        <section class="admin-panel admin-report-panel" aria-labelledby="customer-pet-title">
            <div class="admin-panel-heading">
                <div>
                    <span class="eyebrow">Customer &amp; pet reports</span>
                    <h2 id="customer-pet-title">Customers and Pets</h2>
                </div>
            </div>
            <div class="admin-report-compact-metrics">
                <article><span>New Customers</span><strong><?= number_format((int) $data['customers']['new']) ?></strong><small>Registered in this range</small></article>
                <article><span>Returning Customers</span><strong><?= number_format((int) $data['customers']['returning']) ?></strong><small>2+ completed appointments in this range</small></article>
                <article><span>Registered Pets</span><strong><?= number_format((int) $data['pets']['total']) ?></strong><small>Registered by the selected end date</small></article>
            </div>
            <section class="admin-report-inner-section" aria-labelledby="pet-distribution-title">
                <h3 id="pet-distribution-title">Dog vs. Cat Distribution</h3>
                <?php foreach (['Dogs' => $data['pets']['dogs'], 'Cats' => $data['pets']['cats'], 'Other species' => $data['pets']['other']] as $label => $count): ?>
                    <?php $barWidth = $percentage((float) $count, (float) $maximumPetCount); ?>
                    <div class="admin-report-horizontal-item">
                        <div class="admin-report-horizontal-heading">
                            <strong><?= auth_escape($label) ?></strong>
                            <span><?= number_format((int) $count) ?></span>
                        </div>
                        <div class="admin-report-horizontal-track">
                            <span style="width: <?= auth_escape($barWidth) ?>%"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        </section>

        <section class="admin-panel admin-report-panel" aria-labelledby="review-report-title">
            <div class="admin-panel-heading">
                <div>
                    <span class="eyebrow">Review reports</span>
                    <h2 id="review-report-title">Approved Reviews</h2>
                    <p class="admin-report-panel-note">Pending and removed reviews are excluded.</p>
                </div>
            </div>
            <div class="admin-report-compact-metrics">
                <article><span>Average Rating</span><strong><?= $data['reviews']['average'] === null ? '—' : number_format((float) $data['reviews']['average'], 2) . ' / 5' ?></strong><small>Approved reviews only</small></article>
                <article><span>Total Approved Reviews</span><strong><?= number_format((int) $data['reviews']['total']) ?></strong><small>Submitted in this range</small></article>
            </div>
            <section class="admin-report-inner-section" aria-labelledby="rating-distribution-title">
                <h3 id="rating-distribution-title">Rating Distribution</h3>
                <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                    <?php $ratingCount = (int) $data['reviews']['distribution'][$rating]; ?>
                    <?php $barWidth = $percentage((float) $ratingCount, (float) $maximumRatingCount); ?>
                    <div class="admin-report-horizontal-item">
                        <div class="admin-report-horizontal-heading">
                            <strong><?= $rating ?> star<?= $rating === 1 ? '' : 's' ?></strong>
                            <span><?= number_format($ratingCount) ?></span>
                        </div>
                        <div class="admin-report-horizontal-track">
                            <span class="is-rating" style="width: <?= auth_escape($barWidth) ?>%"></span>
                        </div>
                    </div>
                <?php endfor; ?>
            </section>
        </section>
    </section>

    <section class="admin-panel admin-report-panel" aria-labelledby="groomer-performance-title">
        <div class="admin-panel-heading">
            <div>
                <span class="eyebrow">Groomer performance</span>
                <h2 id="groomer-performance-title">Staff Assignments</h2>
                <p class="admin-report-panel-note"><?= number_format((int) $data['grooming_records']) ?> grooming record(s) were created in this range.</p>
            </div>
        </div>
        <div class="admin-report-empty-state">
            <strong>Per-groomer performance is unavailable</strong>
            <p>Grooming records reference user accounts, while staff profiles have no linked assignment key. No staff attribution is inferred from those records.</p>
        </div>
    </section>
    <?php
    return (string) ob_get_clean();
};

if ($isFragment) {
    $sendJson(['html' => $renderReportContent($reportData)]);
}

$reportQuery = ['preset' => $preset];
if ($preset === 'custom') {
    $reportQuery['from_date'] = $fromDate;
    $reportQuery['to_date'] = $toDate;
}
$csvUrl = 'reports.php?' . http_build_query($reportQuery + ['export' => 'csv']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Pawsome admin reports and analytics.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Reports | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/reports.css">
</head>
<body class="customer-app admin-app admin-reports-page">
    <a class="skip-link" href="#admin-main">Skip to content</a>
    <div class="customer-layout admin-layout">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <div class="customer-main-column">
            <header class="customer-topbar admin-topbar">
                <div>
                    <span class="customer-topbar-label">Pawsome administration</span>
                    <p class="customer-topbar-greeting">Hello, <?= auth_escape($adminFirstName) ?></p>
                </div>
                <form method="post" action="../customer/logout.php">
                    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
                    <button class="button button-primary button-small admin-logout" type="submit">Log out</button>
                </form>
            </header>

            <main class="customer-main admin-main admin-reports-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading admin-reports-heading">
                    <div>
                        <span class="eyebrow">Business insights</span>
                        <h1>Reports &amp; <span>Analytics</span></h1>
                        <p>Operational activity and completed service value for <?= auth_escape($rangeLabel) ?>.</p>
                    </div>
                    <div class="admin-reports-toolbar">
                        <a class="admin-report-export-button" id="export-csv" href="<?= auth_escape($csvUrl) ?>">Export CSV</a>
                        <button class="admin-report-export-button" id="export-pdf" type="button" title="Choose Save as PDF in the print dialog">Export PDF</button>
                        <button class="admin-report-export-button is-secondary" id="print-report" type="button">Print</button>
                    </div>
                </section>

                <section class="admin-panel admin-report-filter-panel" aria-label="Report date filters">
                    <form id="report-filter-form" class="admin-report-filter-form" method="get" action="reports.php">
                        <div class="admin-report-filter-field">
                            <label for="report-preset">Date range</label>
                            <select id="report-preset" name="preset">
                                <?php foreach ($presetOptions as $value => $label): ?>
                                    <option value="<?= auth_escape($value) ?>"<?= $preset === $value ? ' selected' : '' ?>><?= auth_escape($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="admin-report-custom-dates" id="report-custom-dates"<?= $preset === 'custom' ? '' : ' hidden' ?>>
                            <div class="admin-report-filter-field">
                                <label for="report-from-date">From</label>
                                <input id="report-from-date" type="date" name="from_date" value="<?= auth_escape($fromDate) ?>">
                            </div>
                            <div class="admin-report-filter-field">
                                <label for="report-to-date">To</label>
                                <input id="report-to-date" type="date" name="to_date" value="<?= auth_escape($toDate) ?>">
                            </div>
                        </div>
                        <span class="admin-report-filter-hint">Date filters refresh all range-based reports automatically.</span>
                    </form>
                </section>

                <p class="admin-alert admin-report-error" id="report-error" role="alert" hidden></p>
                <div class="admin-report-content" id="report-content" aria-live="polite" aria-busy="false">
                    <?php if ($dataLoadError): ?>
                        <p class="admin-panel-message" role="alert">We couldn't load reports right now. Please try again later.</p>
                    <?php else: ?>
                        <?= $renderReportContent($reportData) ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script>
    (() => {
        const form = document.getElementById('report-filter-form');
        const preset = document.getElementById('report-preset');
        const customDates = document.getElementById('report-custom-dates');
        const fromDate = document.getElementById('report-from-date');
        const toDate = document.getElementById('report-to-date');
        const content = document.getElementById('report-content');
        const errorMessage = document.getElementById('report-error');
        const csvLink = document.getElementById('export-csv');
        let activeController = null;
        let requestVersion = 0;

        const updateCsvLink = (url) => {
            const exportUrl = new URL(url);
            exportUrl.searchParams.set('export', 'csv');
            exportUrl.searchParams.delete('fragment');
            csvLink.href = `${exportUrl.pathname}?${exportUrl.searchParams}`;
        };

        const refreshReports = async () => {
            if (preset.value === 'custom') {
                if (!fromDate.value || !toDate.value) {
                    errorMessage.textContent = 'Choose both dates for a custom report range.';
                    errorMessage.hidden = false;
                    return;
                }
                if (fromDate.value > toDate.value) {
                    errorMessage.textContent = 'The start date must be on or before the end date.';
                    errorMessage.hidden = false;
                    return;
                }
            }

            if (activeController) activeController.abort();
            activeController = new AbortController();
            const controller = activeController;
            const thisRequest = ++requestVersion;
            const requestUrl = new URL(form.action, window.location.href);
            const pageUrl = new URL(window.location.href);
            ['preset', 'from_date', 'to_date', 'fragment', 'export'].forEach((key) => {
                requestUrl.searchParams.delete(key);
                pageUrl.searchParams.delete(key);
            });
            for (const [key, value] of new FormData(form).entries()) {
                if (key === 'preset' || (preset.value === 'custom' && value !== '')) {
                    requestUrl.searchParams.set(key, value);
                    pageUrl.searchParams.set(key, value);
                }
            }
            requestUrl.searchParams.set('fragment', '1');
            updateCsvLink(pageUrl.href);
            content.setAttribute('aria-busy', 'true');
            errorMessage.hidden = true;

            try {
                const response = await fetch(requestUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal
                });
                const payload = await response.json();
                if (!response.ok || !payload.html) {
                    throw new Error(payload.error || 'Reports could not be loaded.');
                }
                content.innerHTML = payload.html;
                history.replaceState(null, '', pageUrl.pathname + (pageUrl.searchParams.toString() ? `?${pageUrl.searchParams}` : ''));
                updateCsvLink(pageUrl.href);
            } catch (error) {
                if (error.name !== 'AbortError' && thisRequest === requestVersion) {
                    errorMessage.textContent = error.message || 'We could not load reports for this date range. Please try again.';
                    errorMessage.hidden = false;
                }
            } finally {
                if (activeController === controller) {
                    content.setAttribute('aria-busy', 'false');
                    activeController = null;
                }
            }
        };

        preset.addEventListener('change', () => {
            customDates.hidden = preset.value !== 'custom';
            refreshReports();
        });
        [fromDate, toDate].forEach((input) => input.addEventListener('change', refreshReports));
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            refreshReports();
        });
        document.getElementById('export-pdf').addEventListener('click', () => window.print());
        document.getElementById('print-report').addEventListener('click', () => window.print());
    })();
    </script>
</body>
</html>
