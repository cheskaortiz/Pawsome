<div class="admin-staff-form-grid">
    <div class="admin-staff-form-field">
        <label for="staff-first-name">First name</label>
        <input id="staff-first-name" name="first_name" type="text" maxlength="100" required value="<?= auth_escape($staffFormValues['first_name']) ?>">
    </div>
    <div class="admin-staff-form-field">
        <label for="staff-last-name">Last name</label>
        <input id="staff-last-name" name="last_name" type="text" maxlength="100" required value="<?= auth_escape($staffFormValues['last_name']) ?>">
    </div>
    <div class="admin-staff-form-field">
        <label for="staff-position">Position</label>
        <input id="staff-position" name="position" type="text" maxlength="100" required placeholder="e.g. Pet Groomer" value="<?= auth_escape($staffFormValues['position']) ?>">
    </div>
    <div class="admin-staff-form-field">
        <label for="staff-contact-number">Contact number</label>
        <input id="staff-contact-number" name="contact_number" type="tel" maxlength="20" value="<?= auth_escape($staffFormValues['contact_number']) ?>">
    </div>
    <div class="admin-staff-form-field">
        <label for="staff-email">Email</label>
        <input id="staff-email" name="email" type="email" maxlength="150" required value="<?= auth_escape($staffFormValues['email']) ?>">
    </div>
    <div class="admin-staff-form-field">
        <label for="staff-experience">Experience</label>
        <input id="staff-experience" name="experience" type="text" maxlength="150" placeholder="e.g. 5 years" value="<?= auth_escape($staffFormValues['experience']) ?>">
    </div>
    <div class="admin-staff-form-field">
        <label for="staff-specialization">Specialization</label>
        <input id="staff-specialization" name="specialization" type="text" maxlength="255" value="<?= auth_escape($staffFormValues['specialization']) ?>">
    </div>
    <div class="admin-staff-form-field">
        <label for="staff-employment-status">Employment status</label>
        <select id="staff-employment-status" name="employment_status" required>
            <option value="Active"<?= $staffFormValues['employment_status'] === 'Active' ? ' selected' : '' ?>>Active</option>
            <option value="Inactive"<?= $staffFormValues['employment_status'] === 'Inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="admin-staff-form-field admin-staff-bio-field">
        <label for="staff-bio">Short bio</label>
        <textarea id="staff-bio" name="bio" rows="4" maxlength="2000"><?= auth_escape($staffFormValues['bio']) ?></textarea>
    </div>
    <div class="admin-staff-form-field admin-staff-photo-field">
        <label for="staff-profile-photo">Profile photo</label>
        <input id="staff-profile-photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp">
        <span class="admin-staff-field-hint">Optional; JPG, PNG, or WebP, up to 5 MB.</span>
    </div>
</div>
