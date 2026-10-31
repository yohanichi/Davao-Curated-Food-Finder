<?php
// Paste your key here for local testing, or set the GEMINI_API_KEY environment variable.
// Never put the key in Main.html/client JavaScript or commit a real key to a public repository.
$envKey = getenv('GEMINI_API_KEY');
define('GEMINI_API_KEY', ($envKey !== false && $envKey !== '') ? $envKey : 'PASTE_YOUR_GEMINI_API_KEY_HERE');
define('GEMINI_MODEL', 'gemini-3.8-flash');
