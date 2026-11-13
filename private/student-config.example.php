<?php
// Copy outside public_html to private/student-config.php (permission 0600).
// Only explicitly authorised, verified accounts may manage private student records.
return ['admin_emails'=>[], 'super_admin_emails'=>[]];
// Super administrators may read private learner profiles/gradebooks, restrict
// account actions, approve/hide course messages and manage room access.
// Add only an explicitly authorised, verified school-owner account.
