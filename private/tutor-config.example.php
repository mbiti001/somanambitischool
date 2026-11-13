<?php
// Copy to /home/somanamb/private/tutor-config.php (permission 0600).
// NEVER place the real key in public_html, JavaScript, a ZIP, or chat.
return [
 'enabled'=>false, // Set true after adding your own API key and enabling API billing.
 'api_key'=>'REPLACE_WITH_YOUR_OPENAI_API_KEY',
 'model'=>'gpt-5.6-terra',
 'user_daily_limit'=>20,
 'site_daily_limit'=>100, // Hard request cap, not a guaranteed currency spending cap.
];
