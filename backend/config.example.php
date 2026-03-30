<?php
/**
 * AWS Event-in-a-Box - Configuration Template
 *
 * INSTRUCTIONS:
 * 1. Copy this file to config.php
 * 2. Replace placeholder values with your actual credentials
 * 3. NEVER commit config.php to Git!
 */

// Event Platform Public API Configuration
define('EP_API_BASE_URL', 'https://events-api.nvp1.ovp.kaltura.com');

// EPM Admin KS (for checking team members)
// Get this from your EPM administrator
define('EPM_ADMIN_KS', getenv('EPM_ADMIN_KS') ?: 'REPLACE_WITH_YOUR_ADMIN_KS');

// Kaltura Configuration (for generating user KS)
// Get these from your Kaltura account settings
define('KALTURA_PARTNER_ID', getenv('KALTURA_PARTNER_ID') ?: 'REPLACE_WITH_PARTNER_ID');
define('KALTURA_ADMIN_SECRET', getenv('KALTURA_ADMIN_SECRET') ?: 'REPLACE_WITH_ADMIN_SECRET');

// Kaltura API URL
define('KALTURA_API_URL', 'https://www.kaltura.com/api_v3');

// Caching Configuration
define('TEAM_MEMBERS_CACHE_TTL', 600); // 10 minutes
define('USER_KS_EXPIRY', 86400); // 24 hours

// Logging Configuration
define('ENABLE_AUDIT_LOG', true);
define('AUDIT_LOG_PATH', __DIR__ . '/../logs/audit.log');

// CORS Configuration (development only - disable in production!)
define('ALLOW_CORS', false);

// Error Reporting (enable for development, disable for production)
if (getenv('APP_ENV') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
