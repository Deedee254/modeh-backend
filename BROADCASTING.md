Local broadcasting setup
=======================

This project uses Laravel broadcasting for realtime chat. Two common local setups are supported:

1) Pusher JS + Pusher (recommended for simplicity)
   - Set the following in your `.env`:
     - BROADCAST_DRIVER=pusher
     - PUSHER_APP_ID=your_app_id
     - PUSHER_APP_KEY=your_app_key
     - PUSHER_APP_SECRET=your_app_secret
     - PUSHER_APP_CLUSTER=mt1

   - On the frontend, include the Pusher client and Laravel Echo (npm packages `pusher-js` and `laravel-echo`). Configure Echo in `resources/js/bootstrap.js` or similar:
     const Echo = new Echo({ broadcaster: 'pusher', key: process.env.MIX_PUSHER_APP_KEY, cluster: process.env.MIX_PUSHER_APP_CLUSTER, encrypted: true });

2) laravel-echo-server + Redis (recommended if you prefer open-source stack)
   - Install and run Redis locally (or use Docker). Set in `.env`:
     - BROADCAST_DRIVER=redis
     - CACHE_DRIVER=redis
     - SESSION_DRIVER=redis
     - QUEUE_CONNECTION=sync (or redis if you run workers)

   - Install laravel-echo-server globally and configure `laravel-echo-server.json` to point to your app and Redis.
   - On the frontend configure Echo to use `socket.io`:
     const Echo = new Echo({ broadcaster: 'socket.io', host: window.location.hostname + ':6001' });

Notes
- Remember private channels require auth endpoints to be available at `/broadcasting/auth` (Laravel handles this if `api` middleware includes auth). Ensure cookies or Authorization headers are sent by the client.
- For local work you can use `QUEUE_CONNECTION=sync` to avoid needing a queue worker. For production use queue worker and set `QUEUE_CONNECTION=redis`.

Troubleshooting
- If you see "403 forbidden" when subscribing to private channels, check that the logged-in user is authenticated in the request used by Echo to authorize the subscription (cookie-based auth + XSRF token for SPA).
- If broadcasts don't show up, ensure you configured `BROADCAST_DRIVER` and started echo server or have valid Pusher credentials.

3) Laravel WebSockets (self-hosted, Pusher-compatible)
  - Install the package `beyondcode/laravel-websockets` and follow its setup.
  - Set `.env`:
    - BROADCAST_DRIVER=pusher
    - PUSHER_APP_ID=local
    - PUSHER_APP_KEY=local
    - PUSHER_APP_SECRET=local
    - PUSHER_APP_CLUSTER=mt1
  - Publish the websockets config and run the server:
    php artisan vendor:publish --provider="BeyondCode\\LaravelWebSockets\\WebSocketsServiceProvider" --tag="config"
    php artisan websockets:serve
  - Laravel WebSockets acts like a Pusher-compatible server — configure Echo to use the same Pusher options but point host to your websockets server and disable wss key verification for local dev.

Notes specific to private channels
- Laravel's broadcasting auth endpoint (`/broadcasting/auth`) will be used when subscribing to private channels. When using cookie-based Sanctum authentication, make sure Echo's auth uses `withCredentials: true` so the browser sends the session cookie.

