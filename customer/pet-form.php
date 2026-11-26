<?php
require_once __DIR__ . '/../includes/auth.php';
auth_require_customer();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pet_uploads.php';
require_once __DIR__ . '/../includes/activity_log.php';

$customerId = (int) $_SESSION['user_id'];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$rawPetId = $isPost ? auth_post_string('pet_id') : ($_GET['pet_id'] ?? '');
$hasPetId = $rawPetId !== '';
$petId = $hasPetId && is_string($rawPetId)
    ? filter_var($rawPetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : ($hasPetId ? false : null);

if ($petId === false) {
    http_response_code(404);
    exit('Pet profile not found.');
}

$existingPet = null;
if ($petId !== null) {
    try {
        $lookup = $conn->prepare(
            'SELECT pet_id, pet_name, species, breed, gender, age, notes, birthday,
                    photo_path, vaccination_proof_path, color_markings, weight_kg, breed_size
             FROM pets
             WHERE pet_id = ? AND user_id = ?
             LIMIT 1'
        );
        $lookup->bind_param('ii', $petId, $customerId);
        $lookup->execute();
        $existingPet = $lookup->get_result()->fetch_assoc();
        $lookup->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Pawsome pet profile lookup failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('We could not load this pet profile right now.');
    }

    if (!$existingPet) {
        http_response_code(404);
        exit('Pet profile not found.');
    }
}

$isEditing = $existingPet !== null;
$pageTitle = $isEditing ? 'Edit Pet' : 'Add Pet';
$activeCustomerPage = 'pets';
$formValues = [
    'species' => $existingPet['species'] ?? 'Dog',
    'pet_name' => $existingPet['pet_name'] ?? '',
    'breed' => $existingPet['breed'] ?? '',
    'birthday' => $existingPet['birthday'] ?? '',
    'gender' => $existingPet['gender'] ?? 'Female',
    'color_markings' => $existingPet['color_markings'] ?? '',
    'weight_kg' => $existingPet['weight_kg'] ?? '',
    'breed_size' => $existingPet['breed_size'] ?? '',
];
$error = '';

if ($isPost) {
    foreach ($formValues as $key => $value) {
        $formValues[$key] = trim(auth_post_string($key));
    }

    if (!auth_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please reload the form and try again.';
    } elseif (!in_array($formValues['species'], ['Dog', 'Cat', 'Other'], true)) {
        $error = 'Choose Dog, Cat, or Other Small Animal for the pet type.';
    } elseif ($formValues['pet_name'] === '') {
        $error = 'Enter your pet’s name.';
    } elseif (auth_string_length($formValues['pet_name']) > 100) {
        $error = 'Pet names must be 100 characters or fewer.';
    } elseif (auth_string_length($formValues['breed']) > 100) {
        $error = 'Breed must be 100 characters or fewer.';
    } elseif (!in_array($formValues['gender'], ['Male', 'Female'], true)) {
        $error = 'Choose a valid gender.';
    } elseif (auth_string_length($formValues['color_markings']) > 150) {
        $error = 'Color and markings must be 150 characters or fewer.';
    } elseif (
        $formValues['weight_kg'] !== ''
        && (!preg_match('/\A\d{1,3}(?:\.\d{1,2})?\z/D', $formValues['weight_kg']) || (float) $formValues['weight_kg'] <= 0)
    ) {
        $error = 'Enter a weight greater than 0 and no more than 999.99 kg.';
    } elseif (
        $formValues['breed_size'] !== ''
        && !in_array($formValues['breed_size'], ['Small', 'Medium', 'Large', 'Giant'], true)
    ) {
        $error = 'Choose a valid breed size.';
    } elseif ($formValues['species'] === 'Dog' && $formValues['breed_size'] === '') {
        $error = 'Choose your dog’s breed size so we can show the correct grooming services.';
    }

    $birthday = null;
    $age = $existingPet['age'] ?? null;
    if ($error === '' && $formValues['birthday'] !== '') {
        $birthday = DateTimeImmutable::createFromFormat('!Y-m-d', $formValues['birthday']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $hasDateErrors = $dateErrors !== false
            && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0);

        if (
            $birthday === false
            || $hasDateErrors
            || $birthday->format('Y-m-d') !== $formValues['birthday']
        ) {
            $error = 'Enter a valid pet birthday.';
        } elseif ($birthday > new DateTimeImmutable('today')) {
            $error = 'A pet birthday cannot be in the future.';
        } else {
            $age = $birthday->diff(new DateTimeImmutable('today'))->y;
        }
    } elseif ($error === '' && !$isEditing) {
        $age = null;
    }

    $storedUploads = [];
    if ($error === '') {
        $photoUpload = pet_store_upload($_FILES['photo'] ?? null, 'photo');
        $proofUpload = pet_store_upload($_FILES['vaccination_proof'] ?? null, 'vaccination');

        if ($photoUpload['error'] !== null) {
            $error = $photoUpload['error'];
        } elseif ($proofUpload['error'] !== null) {
            $error = $proofUpload['error'];
        }

        foreach ([$photoUpload, $proofUpload] as $upload) {
            if ($upload['path'] !== null) {
                $storedUploads[] = $upload['path'];
            }
        }
    }

    if ($error !== '') {
        foreach ($storedUploads as $filename) {
            if (!pet_remove_uploaded_file($filename)) {
                error_log('Pawsome could not remove an uncommitted pet upload.');
            }
        }
    } else {
        $breed = $formValues['breed'] === '' ? null : $formValues['breed'];
        $birthdayValue = $formValues['birthday'] === '' ? null : $formValues['birthday'];
        $colorMarkings = $formValues['color_markings'] === '' ? null : $formValues['color_markings'];
        $weight = $formValues['weight_kg'] === '' ? null : $formValues['weight_kg'];
        $breedSize = $formValues['breed_size'] === '' ? null : $formValues['breed_size'];
        $photoPath = $existingPet['photo_path'] ?? null;
        $proofPath = $existingPet['vaccination_proof_path'] ?? null;

        if ($photoUpload['path'] !== null) {
            $photoPath = $photoUpload['path'];
        }
        if ($proofUpload['path'] !== null) {
            $proofPath = $proofUpload['path'];
        }

        try {
            $conn->begin_transaction();
            if ($isEditing) {
                $statement = $conn->prepare(
                    'UPDATE pets
                     SET pet_name = ?, species = ?, breed = ?, birthday = ?, age = ?, gender = ?,
                         color_markings = ?, weight_kg = ?, breed_size = ?, photo_path = ?,
                         vaccination_proof_path = ?
                     WHERE pet_id = ? AND user_id = ?'
                );
                $statement->bind_param(
                    'ssssissssssii',
                    $formValues['pet_name'],
                    $formValues['species'],
                    $breed,
                    $birthdayValue,
                    $age,
                    $formValues['gender'],
                    $colorMarkings,
                    $weight,
                    $breedSize,
                    $photoPath,
                    $proofPath,
                    $petId,
                    $customerId
                );
                $statement->execute();
                $statement->close();
                $activityPetId = (int) $petId;
                $successMessage = 'Pet details updated successfully.';
            } else {
                $statement = $conn->prepare(
                    'INSERT INTO pets (
                        user_id, pet_name, species, breed, birthday, age, gender,
                        color_markings, weight_kg, breed_size, photo_path, vaccination_proof_path
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->bind_param(
                    'issssissssss',
                    $customerId,
                    $formValues['pet_name'],
                    $formValues['species'],
                    $breed,
                    $birthdayValue,
                    $age,
                    $formValues['gender'],
                    $colorMarkings,
                    $weight,
                    $breedSize,
                    $photoPath,
                    $proofPath
                );
                $statement->execute();
                $activityPetId = (int) $conn->insert_id;
                $statement->close();
                $successMessage = 'Pet profile added successfully.';
            }

            activity_log_current_user(
                $conn,
                $isEditing ? 'pet.updated' : 'pet.created',
                ($isEditing ? 'Updated' : 'Created') . ' pet profile for ' . $formValues['pet_name'] . '.',
                [
                    'pet_id' => $activityPetId,
                    'pet_name' => $formValues['pet_name'],
                    'species' => $formValues['species'],
                ]
            );
            $conn->commit();

            if ($isEditing) {
                if ($photoUpload['path'] !== null && !pet_remove_uploaded_file($existingPet['photo_path'])) {
                    error_log('Pawsome could not remove a replaced pet photo.');
                }
                if ($proofUpload['path'] !== null && !pet_remove_uploaded_file($existingPet['vaccination_proof_path'])) {
                    error_log('Pawsome could not remove replaced vaccination proof.');
                }
            }

            auth_set_flash('pet_success', $successMessage);
            header('Location: pets.php', true, 303);
            exit;
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            foreach ($storedUploads as $filename) {
                if (!pet_remove_uploaded_file($filename)) {
                    error_log('Pawsome could not remove a pet upload after a database error.');
                }
            }
            error_log('Pawsome pet profile save failed: ' . $exception->getMessage());
            $error = 'We could not save this pet profile right now. Please try again.';
        }
    }
}

require __DIR__ . '/../includes/customer_header.php';
?>
<section class="customer-page-heading">
    <span class="eyebrow"><?= $isEditing ? 'Keep their profile up to date' : 'Add a furry family member' ?></span>
    <h1><?= $isEditing ? 'Edit pet ' : 'Add a ' ?><span><?= $isEditing ? 'details' : 'profile' ?></span></h1>
    <p>Fields marked required help us keep your pet's profile accurate.</p>
</section>

<?php if ($error !== ''): ?>
    <p class="customer-alert" role="alert"><?= auth_escape($error) ?></p>
<?php endif; ?>

<form class="pet-form-card" id="pet-profile-form" method="post" action="pet-form.php<?= $isEditing ? '?pet_id=' . (int) $petId : '' ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= auth_escape(auth_csrf_token()) ?>">
    <?php if ($isEditing): ?>
        <input type="hidden" name="pet_id" value="<?= (int) $petId ?>">
    <?php endif; ?>

    <div class="pet-form-grid">
        <div class="pet-form-field">
            <label for="species">Pet type <span aria-hidden="true">*</span></label>
            <select id="species" name="species" required>
                <option value="Dog"<?= $formValues['species'] === 'Dog' ? ' selected' : '' ?>>Dog</option>
                <option value="Cat"<?= $formValues['species'] === 'Cat' ? ' selected' : '' ?>>Cat</option>
                <option value="Other"<?= $formValues['species'] === 'Other' ? ' selected' : '' ?>>Other Small Animal</option>
            </select>
        </div>
        <div class="pet-form-field">
            <label for="pet_name">Pet name <span aria-hidden="true">*</span></label>
            <input id="pet_name" name="pet_name" type="text" maxlength="100" value="<?= auth_escape($formValues['pet_name']) ?>" required>
        </div>
        <div class="pet-form-field">
            <label for="breed">Breed</label>
            <input id="breed" name="breed" type="text" maxlength="100" placeholder="e.g. Aspin, Shih Tzu, Persian" value="<?= auth_escape($formValues['breed']) ?>">
        </div>
        <div class="pet-form-field">
            <label for="birthday">Pet birthday</label>
            <input id="birthday" name="birthday" type="date" max="<?= date('Y-m-d') ?>" value="<?= auth_escape($formValues['birthday']) ?>">
        </div>
        <div class="pet-form-field">
            <label for="gender">Gender <span aria-hidden="true">*</span></label>
            <select id="gender" name="gender" required>
                <option value="Female"<?= $formValues['gender'] === 'Female' ? ' selected' : '' ?>>Female</option>
                <option value="Male"<?= $formValues['gender'] === 'Male' ? ' selected' : '' ?>>Male</option>
            </select>
        </div>
        <div class="pet-form-field">
            <label>Age</label>
            <output
                class="pet-age-output"
                id="age-preview"
                aria-live="polite"
                data-saved-age="<?= isset($existingPet['age']) && $existingPet['age'] !== null ? (int) $existingPet['age'] : '' ?>"
            >
                <?= $formValues['birthday'] !== '' ? 'Calculating age…' : (isset($existingPet['age']) && $existingPet['age'] !== null ? auth_escape((string) $existingPet['age']) . ' years (previously saved)' : 'Add a birthday to calculate age') ?>
            </output>
        </div>
        <div class="pet-form-field">
            <label for="color_markings">Color / markings</label>
            <input id="color_markings" name="color_markings" type="text" maxlength="150" value="<?= auth_escape($formValues['color_markings']) ?>">
        </div>
        <div class="pet-form-field">
            <label for="weight_kg">Weight (kg)</label>
            <input id="weight_kg" name="weight_kg" type="number" min="0.01" max="999.99" step="0.01" value="<?= auth_escape((string) $formValues['weight_kg']) ?>">
        </div>
        <div class="pet-form-field">
            <label for="breed_size">Breed size</label>
            <select id="breed_size" name="breed_size"<?= $formValues['species'] === 'Dog' ? ' required' : '' ?>>
                <option value="">Choose size</option>
                <?php foreach (['Small', 'Medium', 'Large', 'Giant'] as $size): ?>
                    <option value="<?= auth_escape($size) ?>"<?= $formValues['breed_size'] === $size ? ' selected' : '' ?>><?= auth_escape($size) ?></option>
                <?php endforeach; ?>
            </select>
            <small id="breed-size-help">Required for dogs; other pets use their listed service rate.</small>
        </div>
    </div>

    <section class="pet-upload-section" aria-labelledby="photo-title">
        <h2 id="photo-title">Pet photo <span>(optional)</span></h2>
        <div class="pet-upload-grid">
            <div class="pet-form-field">
                <label for="photo">Choose a photo</label>
                <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
                <small>JPG, PNG, or WebP. Maximum 5 MB.</small>
            </div>
            <div class="pet-photo-preview-wrap">
                <img
                    class="pet-photo-preview<?= $isEditing && !empty($existingPet['photo_path']) ? '' : ' is-hidden' ?>"
                    id="photo-preview"
                    data-saved-photo="<?= $isEditing && !empty($existingPet['photo_path']) ? auth_escape('pet-media.php?pet_id=' . (int) $petId . '&type=photo') : '' ?>"
                    <?php if ($isEditing && !empty($existingPet['photo_path'])): ?>
                        src="pet-media.php?pet_id=<?= (int) $petId ?>&amp;type=photo"
                    <?php endif; ?>
                    alt="Preview of the selected pet photo"
                >
                <p id="photo-preview-empty" class="pet-preview-empty<?= $isEditing && !empty($existingPet['photo_path']) ? ' is-hidden' : '' ?>">Photo preview will appear here</p>
            </div>
        </div>
        <p id="photo-upload-message" class="pet-upload-message" aria-live="polite"></p>
    </section>

    <section class="pet-upload-section" aria-labelledby="proof-title">
        <h2 id="proof-title">Vaccination proof <span>(optional)</span></h2>
        <div class="pet-form-field">
            <label for="vaccination_proof">Choose a document</label>
            <input id="vaccination_proof" name="vaccination_proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf">
            <small>JPG, PNG, WebP, or PDF. Maximum 10 MB.</small>
        </div>
        <?php if ($isEditing && !empty($existingPet['vaccination_proof_path'])): ?>
            <p class="pet-existing-proof">
                Current proof:
                <a href="pet-media.php?pet_id=<?= (int) $petId ?>&amp;type=vaccination" target="_blank" rel="noopener">View uploaded vaccination proof</a>
            </p>
        <?php endif; ?>
    </section>

    <div class="pet-form-actions">
        <a class="button button-small pet-cancel-button" href="pets.php">Cancel</a>
        <button class="button button-primary pet-save-button" type="submit"><?= $isEditing ? 'Save changes' : 'Add Pet' ?></button>
    </div>
</form>

<script>
    (() => {
        const birthday = document.querySelector('#birthday');
        const agePreview = document.querySelector('#age-preview');
        const photoInput = document.querySelector('#photo');
        const photoPreview = document.querySelector('#photo-preview');
        const photoPreviewEmpty = document.querySelector('#photo-preview-empty');
        const photoMessage = document.querySelector('#photo-upload-message');
        const species = document.querySelector('#species');
        const breedSize = document.querySelector('#breed_size');
        let previewUrl = null;

        const updateBreedSizeRequirement = () => {
            breedSize.required = species.value === 'Dog';
        };

        const updateAge = () => {
            if (!birthday.value) {
                const savedAge = agePreview.dataset.savedAge;
                agePreview.textContent = savedAge !== ''
                    ? `${savedAge} ${savedAge === '1' ? 'year' : 'years'} (previously saved)`
                    : 'Add a birthday to calculate age';
                return;
            }

            const birthDate = new Date(`${birthday.value}T00:00:00`);
            if (Number.isNaN(birthDate.getTime())) {
                agePreview.textContent = 'Enter a valid birthday';
                return;
            }
            const today = new Date();
            let years = today.getFullYear() - birthDate.getFullYear();
            const hasHadBirthday = today.getMonth() > birthDate.getMonth()
                || (today.getMonth() === birthDate.getMonth() && today.getDate() >= birthDate.getDate());
            if (!hasHadBirthday) {
                years -= 1;
            }

            agePreview.textContent = years < 0
                ? 'Birthday cannot be in the future'
                : years === 0 ? 'Under 1 year' : `${years} ${years === 1 ? 'year' : 'years'}`;
        };

        birthday.addEventListener('input', updateAge);
        updateAge();
        species.addEventListener('change', updateBreedSizeRequirement);
        updateBreedSizeRequirement();

        photoInput.addEventListener('change', () => {
            photoMessage.textContent = '';
            const file = photoInput.files[0];
            if (!file) {
                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                    previewUrl = null;
                }
                photoPreview.classList.toggle('is-hidden', !photoPreview.dataset.savedPhoto);
                photoPreviewEmpty.classList.toggle('is-hidden', Boolean(photoPreview.dataset.savedPhoto));
                if (photoPreview.dataset.savedPhoto) {
                    photoPreview.src = photoPreview.dataset.savedPhoto;
                }
                return;
            }

            const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!allowedTypes.includes(file.type) || file.size > 5 * 1024 * 1024) {
                photoInput.value = '';
                photoMessage.textContent = !allowedTypes.includes(file.type)
                    ? 'Choose a JPG, PNG, or WebP image.'
                    : 'Choose an image smaller than 5 MB.';
                return;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
            previewUrl = URL.createObjectURL(file);
            photoPreview.src = previewUrl;
            photoPreview.classList.remove('is-hidden');
            photoPreviewEmpty.classList.add('is-hidden');
        });

        window.addEventListener('beforeunload', () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        });
    })();
</script>
<?php require __DIR__ . '/../includes/customer_footer.php'; ?>
