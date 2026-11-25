<section class="admin-panel admin-appointments-panel" aria-labelledby="appointments-list-title">
    <div class="admin-panel-heading">
        <div>
            <span class="eyebrow">Matching records</span>
            <h2 id="appointments-list-title">Appointments</h2>
        </div>
        <span class="admin-panel-count"><?= $loadError ? '—' : count($appointments) ?></span>
    </div>

    <?php if ($loadError): ?>
        <p class="admin-panel-message" role="alert">We couldn't load appointments right now. Please try again later.</p>
    <?php elseif (!$appointments): ?>
        <p class="admin-panel-message">No appointments match this status filter.</p>
    <?php else: ?>
        <div class="admin-table-scroll">
            <table class="admin-table admin-appointments-table">
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Date &amp; time</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Contact</th>
                        <th scope="col">Pet</th>
                        <th scope="col">Service</th>
                        <th scope="col">Status</th>
                        <th scope="col">Manage</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $appointment): ?>
                        <?php
                        $status = is_string($appointment['status']) && $appointment['status'] !== ''
                            ? $appointment['status']
                            : 'Unknown';
                        $statusClass = $statusClasses[$status] ?? 'unknown';
                        $statusLabel = array_key_exists($status, $statusClasses) ? $status : 'Unknown';
                        $appointmentDateTime = new DateTimeImmutable(
                            (string) $appointment['appointment_date'] . ' ' . (string) $appointment['appointment_time'],
                            $manilaTimezone
                        );
                        $requestedAt = new DateTimeImmutable((string) $appointment['created_at'], $manilaTimezone);
                        $availableActions = [];
                        foreach ($actionTransitions as $actionKey => $transition) {
                            if (in_array($status, $transition['from'], true)) {
                                $availableActions[] = [
                                    $actionKey,
                                    $transition['label'],
                                    $transition['button_class'],
                                    $transition['confirmation_prompt'],
                                ];
                            }
                        }
                        $hasGroomingRecord = (int) $appointment['has_grooming_record'] === 1;
                        if ($hasGroomingRecord) {
                            $availableActions = array_values(array_filter(
                                $availableActions,
                                static fn (array $availableAction): bool => $availableAction[0] !== 'cancel'
                            ));
                        }
                        $petDescription = array_filter([
                            (string) $appointment['species'],
                            (string) ($appointment['breed'] ?? ''),
                            (string) ($appointment['breed_size'] ?? ''),
                        ], static fn (string $value): bool => trim($value) !== '');
                        ?>
                        <tr>
                            <td class="admin-reference">#<?= (int) $appointment['appointment_id'] ?></td>
                            <td>
                                <strong><?= auth_escape($appointmentDateTime->format('M j, Y')) ?></strong>
                                <span class="admin-table-muted"><?= auth_escape($appointmentDateTime->format('g:i A')) ?></span>
                            </td>
                            <td><?= auth_escape(trim((string) $appointment['first_name'] . ' ' . (string) $appointment['last_name'])) ?></td>
                            <td>
                                <strong><?= auth_escape((string) ($appointment['email'] ?? '')) ?></strong>
                                <span class="admin-table-muted"><?= auth_escape(trim((string) ($appointment['phone'] ?? '')) ?: 'No phone provided') ?></span>
                            </td>
                            <td>
                                <strong><?= auth_escape((string) $appointment['pet_name']) ?></strong>
                                <span class="admin-table-muted"><?= auth_escape(implode(' · ', $petDescription)) ?></span>
                            </td>
                            <td>
                                <strong><?= auth_escape((string) $appointment['service_name']) ?></strong>
                                <span class="admin-table-muted"><?= (int) $appointment['duration'] ?> min</span>
                            </td>
                            <td>
                                <span class="customer-status customer-status--<?= auth_escape($statusClass) ?>">
                                    <?= auth_escape($statusLabel) ?>
                                </span>
                            </td>
                            <td>
                                <a
                                    class="admin-appointment-manage"
                                    href="appointment-details.php?<?= auth_escape(http_build_query([
                                        'id' => (int) $appointment['appointment_id'],
                                        'return' => $returnUrl,
                                    ])) ?>"
                                >Manage</a>
                            </td>
                            <td class="admin-appointment-actions">
                                <button
                                    class="admin-appointment-details-trigger"
                                    type="button"
                                    aria-haspopup="dialog"
                                    data-appointment-details
                                    data-appointment-id="<?= (int) $appointment['appointment_id'] ?>"
                                    data-date="<?= auth_escape($appointmentDateTime->format('M j, Y')) ?>"
                                    data-time="<?= auth_escape($appointmentDateTime->format('g:i A')) ?>"
                                    data-customer="<?= auth_escape(trim((string) $appointment['first_name'] . ' ' . (string) $appointment['last_name'])) ?>"
                                    data-email="<?= auth_escape((string) ($appointment['email'] ?? '')) ?>"
                                    data-phone="<?= auth_escape(trim((string) ($appointment['phone'] ?? '')) ?: 'Not provided') ?>"
                                    data-pet-name="<?= auth_escape((string) $appointment['pet_name']) ?>"
                                    data-pet="<?= auth_escape(implode(' · ', $petDescription)) ?>"
                                    data-service="<?= auth_escape((string) $appointment['service_name']) ?>"
                                    data-duration="<?= (int) $appointment['duration'] ?> minutes"
                                    data-status="<?= auth_escape($statusLabel) ?>"
                                    data-grooming-record="<?= $hasGroomingRecord ? 'Linked' : 'Not linked' ?>"
                                    data-notes="<?= auth_escape(trim((string) ($appointment['notes'] ?? '')) ?: 'None provided') ?>"
                                    data-requested="<?= auth_escape($requestedAt->format('M j, Y g:i A')) ?>"
                                >View Details</button>

                                <?php if ($availableActions): ?>
                                    <div class="admin-appointment-action-group">
                                        <?php foreach ($availableActions as [$action, $label, $buttonClass, $confirmationPrompt]): ?>
                                            <form
                                                method="post"
                                                action="<?= auth_escape($returnUrl) ?>"
                                                onsubmit="return confirm('<?= auth_escape($confirmationPrompt) ?>');"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= auth_escape($csrfToken) ?>">
                                                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                                                <input type="hidden" name="appointment_action" value="<?= auth_escape($action) ?>">
                                                <button class="admin-appointment-action is-<?= auth_escape($buttonClass) ?>" type="submit"><?= auth_escape($label) ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
