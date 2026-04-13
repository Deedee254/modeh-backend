<?php

/**
 * Admin Testing Configuration
 * 
 * This configuration allows using a master/admin password to log into any user account
 * for testing and debugging purposes. This should ONLY be enabled in development/staging.
 */

return [
    /**
     * Enable master password for testing
     * 
     * When enabled, users can log in with their email and EITHER:
     * - Their own password, OR
     * - The master admin password
     * 
     * SECURITY WARNING: Never enable this in production!
     */
    'enable_master_password' => env('ENABLE_MASTER_PASSWORD', false),

    /**
     * Master/admin password for testing
     * 
     * When enabled, this password works for ANY user account
     * Ensure it's strong and only shared with authorized developers/testers
     */
    'master_password' => env('MASTER_PASSWORD', 'Admin@Testing123'),

    /**
     * Enable debug logging for master password attempts
     * 
     * Log all login attempts using the master password
     */
    'enable_debug_logging' => env('MASTER_PASSWORD_DEBUG', false),
];
