<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_admin();
require_once __DIR__ . '/../config/database.php';

$adminFirstName = (string) ($_SESSION['first_name'] ?? 'admin');
$activeAdminPage = 'grooming-records';
$manilaTimezone = new DateTimeZone('Asia/Manila');
$manilaNow = new DateTimeImmutable('now', $manilaTimezone);
$today = $manilaNow->format('Y-m-d');
$monthStart = $manilaNow->modify('first day of this month')->format('Y-m-d');
$nextMonthStart = $manilaNow->modify('first day of next month')->format('Y-m-d');
$searchTerm = $_GET['search'] ?? '';
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';
$speciesFilter = $_GET['species'] ?? 'All';
$isFragment = ($_GET['fragment'] ?? null) === 'records';
$speciesOptions = ['Dog', 'Cat', 'Other'];

$sendJson = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

$isValidDate = static function (string $date) use ($manilaTimezone): bool {
    if ($date === '') {
        return true;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $manilaTimezone);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $sendJson(['error' => 'Your session expired. Reload the page and try again.'], 400);
    }
    if (!isset($_POST['action']) || !is_string($_POST['action']) || $_POST['action'] !== 'create_record') {
        $sendJson(['error' => 'Choose a valid grooming record action.'], 400);
    }

    $appointmentIdValue = isset($_POST['appointment_id']) && is_string($_POST['appointment_id'])
        ? trim($_POST['appointment_id'])
        : '';
    $appointmentId = filter_var(
        $appointmentIdValue,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $groomingDate = isset($_POST['grooming_date']) && is_string($_POST['grooming_date'])
        ? trim($_POST['grooming_date'])
        : '';
    $groomingNotes = isset($_POST['grooming_notes']) && is_string($_POST['grooming_notes'])
        ? trim($_POST['grooming_notes'])
        : '';
    $performedConfirmation = isset($_POST['service_performed']) && is_string($_POST['service_performed'])
        ? $_POST['service_performed']
        : '';

    if (
        $appointmentId === false
        || !$isValidDate($groomingDate)
        || $groomingDate === ''
        || $groomingDate > $today
        || $performedConfirmation !== 'yes'
        || auth_string_length($groomingNotes) > 10000
    ) {
        $sendJson(['error' => 'Confirm the service was performed and enter a valid grooming date and notes.'], 400);
    }

    $groomerId = (int) $_SESSION['user_id'];
    $transactionStarted = false;
    try {
        $conn->begin_transaction();
        $transactionStarted = true;
        $statement = $conn->prepare(
            "SELECT a.appointment_id, a.pet_id, a.appointment_date, a.status
             FROM appointments AS a
             INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
             INNER JOIN users AS u ON u.user_id = a.user_id AND u.role = 'customer'
             WHERE a.appointment_id = ?
               AND a.status IN ('In Progress', 'Ready for Pickup', 'Completed')
             FOR UPDATE"
        );
        $statement->bind_param('i', $appointmentId);
        $statement->execute();
        $eligibleAppointment = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$eligibleAppointment) {
            $conn->rollback();
            $transactionStarted = false;
            $sendJson(['error' => 'Only In Progress or Ready for Pickup appointments can receive a grooming record.'], 409);
        }

        $statement = $conn->prepare(
            'SELECT record_id FROM grooming_records WHERE appointment_id = ? LIMIT 1'
        );
        $statement->bind_param('i', $appointmentId);
        $statement->execute();
        $existingRecord = $statement->get_result()->fetch_assoc();
        $statement->close();
        if ($existingRecord) {
            $conn->rollback();
            $transactionStarted = false;
            $sendJson(['error' => 'A grooming record already exists for this appointment.'], 409);
        }

        $notesValue = $groomingNotes === '' ? null : $groomingNotes;
        $insert = $conn->prepare(
            'INSERT INTO grooming_records
                (appointment_id, pet_id, groomer_id, grooming_date, grooming_notes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $petId = (int) $eligibleAppointment['pet_id'];
        $insert->bind_param('iiiss', $appointmentId, $petId, $groomerId, $groomingDate, $notesValue);
        $insert->execute();
        $recordId = (int) $conn->insert_id;
        $insert->close();
        $conn->commit();
        $transactionStarted = false;
        $sendJson(['success' => true, 'record_id' => $recordId], 201);
    } catch (mysqli_sql_exception $exception) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (mysqli_sql_exception $rollbackException) {
                error_log('Pawsome grooming record rollback failed: ' . $rollbackException->getMessage());
            }
        }
        error_log('Pawsome grooming record creation failed: ' . $exception->getMessage());
        $isDuplicate = $exception->getCode() === 1062;
        $sendJson([
            'error' => $isDuplicate
                ? 'A grooming record already exists for this appointment.'
                : 'We could not create the grooming record right now. Please try again.',
        ], $isDuplicate ? 409 : 500);
    }
}

if (
    !is_string($searchTerm)
    || auth_string_length(trim($searchTerm)) > 100
    || !is_string($fromDate)
    || !is_string($toDate)
    || !$isValidDate($fromDate)
    || !$isValidDate($toDate)
    || ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate)
    || !is_string($speciesFilter)
    || !in_array($speciesFilter, array_merge(['All'], $speciesOptions), true)
) {
    if ($isFragment) {
        $sendJson(['error' => 'Choose valid grooming record filters.'], 400);
    }
    http_response_code(400);
    exit('Choose valid grooming record filters.');
}
$searchTerm = trim($searchTerm);

$summary = null;
$records = [];
$eligibleAppointments = [];
$loadError = false;
try {
    $statement = $conn->prepare(
        'SELECT COUNT(*) AS total_records,
                COUNT(CASE WHEN grooming_date >= ? AND grooming_date < ? THEN 1 END) AS records_this_month,
                COUNT(DISTINCT pet_id) AS unique_pets_groomed
         FROM grooming_records'
    );
    $statement->bind_param('ss', $monthStart, $nextMonthStart);
    $statement->execute();
    $summary = $statement->get_result()->fetch_assoc();
    $statement->close();

    $sql =
        'SELECT gr.record_id, gr.appointment_id, gr.pet_id, gr.grooming_date,
                gr.grooming_notes, p.pet_name, p.species,
                u.first_name, u.last_name, s.service_name
         FROM grooming_records AS gr
         INNER JOIN appointments AS a ON a.appointment_id = gr.appointment_id
         INNER JOIN pets AS p ON p.pet_id = gr.pet_id AND p.user_id = a.user_id
         INNER JOIN users AS u ON u.user_id = a.user_id AND u.role = \'customer\'
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE 1 = 1';
    $parameters = [];
    $parameterTypes = '';
    if ($searchTerm !== '') {
        $sql .= " AND (p.pet_name LIKE ?
                      OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?
                      OR s.service_name LIKE ?)";
        $pattern = '%' . $searchTerm . '%';
        array_push($parameters, $pattern, $pattern, $pattern);
        $parameterTypes .= 'sss';
    }
    if ($fromDate !== '') {
        $sql .= ' AND gr.grooming_date >= ?';
        $parameters[] = $fromDate;
        $parameterTypes .= 's';
    }
    if ($toDate !== '') {
        $sql .= ' AND gr.grooming_date <= ?';
        $parameters[] = $toDate;
        $parameterTypes .= 's';
    }
    if ($speciesFilter !== 'All') {
        $sql .= ' AND p.species = ?';
        $parameters[] = $speciesFilter;
        $parameterTypes .= 's';
    }
    $sql .= ' ORDER BY gr.grooming_date DESC, gr.record_id DESC';

    $statement = $conn->prepare($sql);
    if ($parameters) {
        $statement->bind_param($parameterTypes, ...$parameters);
    }
    $statement->execute();
    $records = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    $statement = $conn->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time,
                p.pet_name, p.species, u.first_name, u.last_name, s.service_name
         FROM appointments AS a
         INNER JOIN pets AS p ON p.pet_id = a.pet_id AND p.user_id = a.user_id
         INNER JOIN users AS u ON u.user_id = a.user_id AND u.role = 'customer'
         INNER JOIN services AS s ON s.service_id = a.service_id
         WHERE a.status IN ('In Progress', 'Ready for Pickup', 'Completed')
           AND NOT EXISTS (
               SELECT 1
               FROM grooming_records AS gr
               WHERE gr.appointment_id = a.appointment_id
           )
         ORDER BY a.appointment_date ASC, a.appointment_time ASC, a.appointment_id ASC"
    );
    $statement->execute();
    $eligibleAppointments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Pawsome admin grooming records query failed: ' . $exception->getMessage());
    $loadError = true;
}

if ($isFragment) {
    $sendJson([
        'records' => $records,
        'summary' => $summary,
        'eligible_appointments' => $eligibleAppointments,
        'error' => $loadError,
    ], $loadError ? 500 : 200);
}

$csrfToken = auth_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Review Pawsome grooming records.">
    <meta name="theme-color" content="#fbf8ff">
    <title>Grooming Records | Pawsome Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/shared/portal.css">
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin/grooming-records.css">
</head>
<body class="customer-app admin-app admin-grooming-records-page">
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
                    <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                    <button class="button button-primary button-small admin-logout" type="submit">Log out</button>
                </form>
            </header>

            <main class="customer-main admin-main admin-grooming-records-main" id="admin-main">
                <section class="customer-page-heading admin-page-heading">
                    <div>
                        <span class="eyebrow">Admin area</span>
                        <h1>Grooming <span>Records</span></h1>
                        <p>Review completed grooming work and its linked appointment and pet details.</p>
                    </div>
                    <button
                        class="button button-primary admin-grooming-create-trigger"
                        id="create-record-button"
                        type="button"
                        <?= !$eligibleAppointments ? 'disabled' : '' ?>
                    >+ Create Record</button>
                </section>

                <?php if ($loadError): ?>
                    <p class="admin-alert" role="alert">We couldn't load grooming records right now. Please try again later.</p>
                <?php endif; ?>

                <section class="admin-overview-grid admin-grooming-summary-grid" aria-label="Grooming record summary">
                    <?php
                    $summaryCards = [
                        ['Total Records', 'total_records', 'All recorded grooming visits'],
                        ['Records This Month', 'records_this_month', 'Based on grooming date'],
                        ['Unique Pets Groomed', 'unique_pets_groomed', 'Pets with at least one record'],
                    ];
                    ?>
                    <?php foreach ($summaryCards as [$label, $key, $description]): ?>
                        <article class="admin-overview-card">
                            <span class="admin-overview-label"><?= auth_escape($label) ?></span>
                            <strong class="admin-overview-value" data-summary="<?= auth_escape($key) ?>">
                                <?= $loadError || $summary === null ? '—' : number_format((int) $summary[$key]) ?>
                            </strong>
                            <span class="admin-overview-description"><?= auth_escape($description) ?></span>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="admin-panel admin-grooming-records-panel" aria-labelledby="grooming-records-title">
                    <div class="admin-panel-heading admin-grooming-records-panel-heading">
                        <div>
                            <span class="eyebrow">Grooming history</span>
                            <h2 id="grooming-records-title">Records</h2>
                        </div>
                        <form class="admin-grooming-filter-form" id="grooming-filter-form" method="get" action="grooming-records.php" role="search">
                            <div class="admin-grooming-filter-field admin-grooming-search-field">
                                <label for="grooming-search">Search</label>
                                <input
                                    id="grooming-search"
                                    name="search"
                                    type="search"
                                    maxlength="100"
                                    value="<?= auth_escape($searchTerm) ?>"
                                    placeholder="Pet, customer, or service"
                                >
                            </div>
                            <div class="admin-grooming-filter-field">
                                <label for="grooming-from-date">From date</label>
                                <input id="grooming-from-date" name="from_date" type="date" value="<?= auth_escape($fromDate) ?>">
                            </div>
                            <div class="admin-grooming-filter-field">
                                <label for="grooming-to-date">To date</label>
                                <input id="grooming-to-date" name="to_date" type="date" value="<?= auth_escape($toDate) ?>">
                            </div>
                            <div class="admin-grooming-filter-field">
                                <label for="grooming-species">Species</label>
                                <select id="grooming-species" name="species">
                                    <option value="All">All Species</option>
                                    <?php foreach ($speciesOptions as $species): ?>
                                        <option value="<?= auth_escape($species) ?>"<?= $speciesFilter === $species ? ' selected' : '' ?>>
                                            <?= auth_escape($species === 'Other' ? 'Other Small Animal' : $species) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                    <p class="admin-grooming-feedback" id="grooming-feedback" role="alert" hidden></p>
                    <div class="admin-table-scroll" id="grooming-record-results">
                        <?php if ($loadError): ?>
                            <p class="admin-panel-message" role="alert">We couldn't load grooming records. Please try again later.</p>
                        <?php elseif (!$records): ?>
                            <p class="admin-panel-message">No grooming records match the selected filters.</p>
                        <?php else: ?>
                            <table class="admin-table admin-grooming-records-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Record ID</th>
                                        <th scope="col">Appointment ID</th>
                                        <th scope="col">Pet Name</th>
                                        <th scope="col">Customer Name</th>
                                        <th scope="col">Service Performed</th>
                                        <th scope="col">Grooming Date</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $record): ?>
                                        <tr>
                                            <td class="admin-reference">#<?= (int) $record['record_id'] ?></td>
                                            <td class="admin-reference">#<?= (int) $record['appointment_id'] ?></td>
                                            <td><strong><?= auth_escape((string) $record['pet_name']) ?></strong></td>
                                            <td><?= auth_escape(trim((string) $record['first_name'] . ' ' . (string) $record['last_name'])) ?></td>
                                            <td><?= auth_escape((string) $record['service_name']) ?></td>
                                            <td><?= auth_escape(date('M j, Y', strtotime((string) $record['grooming_date']))) ?></td>
                                            <td><a class="admin-grooming-view-button" href="grooming-record-details.php?id=<?= (int) $record['record_id'] ?>">View Record</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if (!$eligibleAppointments): ?>
                    <p class="admin-grooming-eligibility-note">
                        No eligible appointments are currently In Progress, Ready for Pickup, or Completed. A record is created only after an admin confirms the grooming service was performed.
                    </p>
                <?php endif; ?>
            </main>
            <footer class="customer-footer">
                <p>&copy; <?= date('Y') ?> Pawsome. Made with care for pets and their people.</p>
            </footer>
        </div>
    </div>

    <dialog class="admin-grooming-create-dialog" id="create-record-dialog" aria-labelledby="create-record-title">
        <form class="admin-grooming-create-form" id="create-record-form" method="post" action="grooming-records.php">
            <header class="admin-grooming-dialog-header">
                <div>
                    <span class="eyebrow">After service completion</span>
                    <h2 id="create-record-title">Create Grooming Record</h2>
                </div>
                <button class="admin-grooming-dialog-close" id="close-create-record" type="button" aria-label="Close form">&times;</button>
            </header>
            <p class="admin-grooming-form-error" id="create-record-error" role="alert" hidden></p>
            <p class="admin-grooming-create-help">Only appointments marked In Progress, Ready for Pickup, or Completed are eligible. Creating a record does not change appointment status.</p>
            <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="create_record">
            <div class="admin-grooming-create-field">
                <label for="eligible-appointment">Eligible appointment</label>
                <select id="eligible-appointment" name="appointment_id" required>
                    <option value="">Select an appointment</option>
                    <?php foreach ($eligibleAppointments as $appointment): ?>
                        <?php
                        $ownerName = trim((string) $appointment['first_name'] . ' ' . (string) $appointment['last_name']);
                        $dateTime = new DateTimeImmutable(
                            (string) $appointment['appointment_date'] . ' ' . (string) $appointment['appointment_time'],
                            $manilaTimezone
                        );
                        ?>
                        <option value="<?= (int) $appointment['appointment_id'] ?>">
                            #<?= (int) $appointment['appointment_id'] ?> · <?= auth_escape((string) $appointment['pet_name']) ?>
                            · <?= auth_escape($ownerName) ?> · <?= auth_escape((string) $appointment['service_name']) ?>
                            · <?= auth_escape($dateTime->format('M j, Y g:i A')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-grooming-create-field">
                <label for="grooming-date">Grooming date</label>
                <input id="grooming-date" name="grooming_date" type="date" value="<?= auth_escape($today) ?>" max="<?= auth_escape($today) ?>" required>
            </div>
            <div class="admin-grooming-create-field">
                <label for="grooming-notes">Grooming notes and observations</label>
                <textarea id="grooming-notes" name="grooming_notes" rows="5" maxlength="10000" placeholder="Enter the grooming notes supported by this record."></textarea>
            </div>
            <label class="admin-grooming-confirm-label">
                <input type="checkbox" name="service_performed" value="yes" required>
                I confirm the grooming service has actually been performed.
            </label>
            <footer class="admin-grooming-dialog-actions">
                <button class="admin-grooming-cancel" id="cancel-create-record" type="button">Cancel</button>
                <button class="button button-primary" id="save-grooming-record" type="submit">Create Record</button>
            </footer>
        </form>
    </dialog>

    <script>
    (() => {
        const filterForm = document.getElementById('grooming-filter-form');
        const searchInput = document.getElementById('grooming-search');
        const fromDate = document.getElementById('grooming-from-date');
        const toDate = document.getElementById('grooming-to-date');
        const species = document.getElementById('grooming-species');
        const results = document.getElementById('grooming-record-results');
        const feedback = document.getElementById('grooming-feedback');
        const dialog = document.getElementById('create-record-dialog');
        const createForm = document.getElementById('create-record-form');
        const createError = document.getElementById('create-record-error');
        const createButton = document.getElementById('create-record-button');
        const eligibleSelect = document.getElementById('eligible-appointment');
        const saveButton = document.getElementById('save-grooming-record');
        let requestController = null;
        let searchTimer = null;

        const escapeHtml = (value) => {
            const node = document.createElement('span');
            node.textContent = value == null ? '' : String(value);
            return node.innerHTML;
        };

        const formatDate = (value) => {
            const date = new Date(`${String(value)}T00:00:00`);
            return Number.isNaN(date.getTime())
                ? String(value)
                : date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        };

        const formatDateTime = (date, time) => {
            const parsed = new Date(`${String(date)}T${String(time)}`);
            return Number.isNaN(parsed.getTime())
                ? `${date} ${time}`
                : parsed.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
        };

        const renderRecords = (records) => {
            if (!records.length) {
                results.innerHTML = '<p class="admin-panel-message">No grooming records match the selected filters.</p>';
                return;
            }
            const rows = records.map((record) => {
                const customerName = [record.first_name, record.last_name].filter(Boolean).join(' ').trim();
                return `<tr>
                    <td class="admin-reference">#${Number(record.record_id)}</td>
                    <td class="admin-reference">#${Number(record.appointment_id)}</td>
                    <td><strong>${escapeHtml(record.pet_name)}</strong></td>
                    <td>${escapeHtml(customerName)}</td>
                    <td>${escapeHtml(record.service_name)}</td>
                    <td>${escapeHtml(formatDate(record.grooming_date))}</td>
                    <td><a class="admin-grooming-view-button" href="grooming-record-details.php?id=${Number(record.record_id)}">View Record</a></td>
                </tr>`;
            }).join('');
            results.innerHTML = `<table class="admin-table admin-grooming-records-table">
                <thead><tr>
                    <th scope="col">Record ID</th><th scope="col">Appointment ID</th>
                    <th scope="col">Pet Name</th><th scope="col">Customer Name</th>
                    <th scope="col">Service Performed</th><th scope="col">Grooming Date</th><th scope="col">Actions</th>
                </tr></thead><tbody>${rows}</tbody>
            </table>`;
        };

        const refreshRecords = async () => {
            if (requestController) requestController.abort();
            requestController = new AbortController();
            const savedScroll = window.scrollY;
            feedback.hidden = true;
            const url = new URL('grooming-records.php', window.location.href);
            url.searchParams.set('fragment', 'records');
            if (searchInput.value.trim()) url.searchParams.set('search', searchInput.value.trim());
            if (fromDate.value) url.searchParams.set('from_date', fromDate.value);
            if (toDate.value) url.searchParams.set('to_date', toDate.value);
            if (species.value !== 'All') url.searchParams.set('species', species.value);

            try {
                const response = await fetch(url.toString(), {
                    headers: { Accept: 'application/json' },
                    signal: requestController.signal,
                    cache: 'no-store'
                });
                const payload = await response.json();
                if (!response.ok || payload.error) throw new Error(payload.error || 'Records could not be loaded.');
                renderRecords(payload.records || []);
                ['total_records', 'records_this_month', 'unique_pets_groomed'].forEach((key) => {
                    const target = document.querySelector(`[data-summary="${key}"]`);
                    if (target) target.textContent = Number(payload.summary[key]).toLocaleString();
                });

                const selectedAppointment = eligibleSelect.value;
                eligibleSelect.innerHTML = '<option value="">Select an appointment</option>';
                (payload.eligible_appointments || []).forEach((appointment) => {
                    const option = document.createElement('option');
                    option.value = String(appointment.appointment_id);
                    option.textContent = `#${appointment.appointment_id} · ${appointment.pet_name} · ${appointment.first_name} ${appointment.last_name} · ${appointment.service_name} · ${formatDateTime(appointment.appointment_date, appointment.appointment_time)}`;
                    eligibleSelect.append(option);
                });
                if ((payload.eligible_appointments || []).some((appointment) => String(appointment.appointment_id) === selectedAppointment)) {
                    eligibleSelect.value = selectedAppointment;
                }
                createButton.disabled = !(payload.eligible_appointments || []).length;

                const displayUrl = new URL(window.location.href);
                ['search', 'from_date', 'to_date', 'species', 'fragment'].forEach((key) => displayUrl.searchParams.delete(key));
                if (searchInput.value.trim()) displayUrl.searchParams.set('search', searchInput.value.trim());
                if (fromDate.value) displayUrl.searchParams.set('from_date', fromDate.value);
                if (toDate.value) displayUrl.searchParams.set('to_date', toDate.value);
                if (species.value !== 'All') displayUrl.searchParams.set('species', species.value);
                window.history.replaceState({}, '', displayUrl);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    feedback.textContent = 'We could not refresh grooming records. Please try again.';
                    feedback.hidden = false;
                }
            } finally {
                window.requestAnimationFrame(() => window.scrollTo({ top: savedScroll, behavior: 'auto' }));
            }
        };

        createButton.addEventListener('click', () => {
            createError.hidden = true;
            createForm.reset();
            document.getElementById('grooming-date').value = <?= json_encode($today) ?>;
            dialog.showModal();
        });
        document.getElementById('close-create-record').addEventListener('click', () => dialog.close());
        document.getElementById('cancel-create-record').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });

        createForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            createError.hidden = true;
            saveButton.disabled = true;
            try {
                const response = await fetch('grooming-records.php', {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new FormData(createForm)
                });
                const payload = await response.json();
                if (!response.ok || payload.error) throw new Error(payload.error || 'The grooming record could not be created.');
                dialog.close();
                await refreshRecords();
            } catch (error) {
                createError.textContent = error.message || 'We could not create this grooming record. Please try again.';
                createError.hidden = false;
            } finally {
                saveButton.disabled = false;
            }
        });

        searchInput.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(refreshRecords, 200);
        });
        [fromDate, toDate, species].forEach((control) => control.addEventListener('change', refreshRecords));
        filterForm.addEventListener('submit', (event) => event.preventDefault());
    })();
    </script>
</body>
</html>
