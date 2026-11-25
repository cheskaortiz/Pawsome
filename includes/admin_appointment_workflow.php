<?php
return [
    'confirm' => [
        'target' => 'Confirmed',
        'from' => ['Pending'],
        'label' => 'Confirm',
        'button_class' => 'confirm',
        'confirmation_prompt' => 'Confirm this appointment?',
    ],
    'reject' => [
        'target' => 'Rejected',
        'from' => ['Pending'],
        'label' => 'Reject',
        'button_class' => 'reject',
        'confirmation_prompt' => 'Reject this pending appointment?',
    ],
    'start' => [
        'target' => 'In Progress',
        'from' => ['Confirmed'],
        'label' => 'Mark In Progress',
        'button_class' => 'start',
        'confirmation_prompt' => 'Mark this appointment as in progress?',
    ],
    'ready' => [
        'target' => 'Ready for Pickup',
        'from' => ['In Progress'],
        'label' => 'Ready for Pickup',
        'button_class' => 'ready',
        'confirmation_prompt' => 'Mark this pet as ready for pickup?',
    ],
    'complete' => [
        'target' => 'Completed',
        'from' => ['Ready for Pickup'],
        'label' => 'Mark Completed',
        'button_class' => 'complete',
        'confirmation_prompt' => 'Confirm the pet has been picked up and complete this appointment?',
    ],
    'cancel' => [
        'target' => 'Cancelled',
        'from' => ['Pending', 'Confirmed', 'In Progress'],
        'label' => 'Cancel',
        'button_class' => 'cancel',
        'confirmation_prompt' => 'Cancel this appointment?',
        'requires_no_grooming_record' => true,
    ],
];
