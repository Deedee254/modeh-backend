<?php

return [
    // When true new topics will be auto-approved by default unless a subject explicitly prevents it.
    // Admins can change this environment variable or wire an admin setting to toggle it at runtime.
    'auto_approve_topics' => env('AUTO_APPROVE_TOPICS', true),

    // Heartbeat secret for monitoring echo/websockets
    'echo_heartbeat_secret' => env('ECHO_HEARTBEAT_SECRET'),
];
