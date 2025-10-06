<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Development seed users

For development and testing, the following users are seeded by `php artisan migrate:fresh --seed`:

- Admin
	- email: admin@example.com
	- password: adminpass

- Tutor
	- email: tutor@example.com
	- password: password123

- Student
	- email: student@example.com
	- password: password123

Use these credentials to log in using the backend at `http://localhost:8000` (login page) or via the API for SPA authentication.

Running Laravel WebSockets (local)
--------------------------------

If you want to replace the existing Echo server with Laravel WebSockets (no Redis required for single-node dev), do the following:

1. Install PHP dependencies:

	composer require beyondcode/laravel-websockets

2. Publish the package config and migrations (optional; a minimal config is included in this repository):

	php artisan vendor:publish --provider="BeyondCode\\LaravelWebSockets\\WebSocketsServiceProvider" --tag="config"
	php artisan vendor:publish --provider="BeyondCode\\LaravelWebSockets\\WebSocketsServiceProvider" --tag="migrations"
	php artisan migrate

3. Update your `.env` to use pusher driver and local keys (example):

	BROADCAST_DRIVER=pusher
	PUSHER_APP_ID=local
	PUSHER_APP_KEY=local
	PUSHER_APP_SECRET=local
	PUSHER_HOST=127.0.0.1
	PUSHER_PORT=6001
	PUSHER_SCHEME=ws

4. Start the websockets server:

	php artisan websockets:serve

5. Point your frontend Echo client to the websocket host/port (see frontend `nuxt.config.ts` or `plugins/echo.client.js`).

The package also exposes a dashboard at `/laravel-websockets` (protect this route in production).

DB-backed chat monitoring (Node echo-server)
------------------------------------------

If you keep using the Node `laravel-echo-server`, you can still get admin monitoring without Redis by having the Node server POST a periodic heartbeat to the backend. The backend stores simple counters in the `chat_metrics` table and exposes two admin endpoints:

- `GET /api/admin/echo/health` — returns overall status (ok/down), last heartbeat timestamp, active connections, last message time.
- `GET /api/admin/echo/stats` — returns message counters and error counts.
- `POST /api/echo/heartbeat` — endpoint the echo-server should call every 10-20s with { connections: <number> } in the JSON body.

Example Node snippet (insert into your echo server to POST heartbeat):

```js
const fetch = require('node-fetch');
setInterval(async () => {
	try {
		await fetch('http://127.0.0.1:8000/api/echo/heartbeat', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-Echo-Heartbeat-Secret': process.env.ECHO_HEARTBEAT_SECRET || '' },
			body: JSON.stringify({ connections: Object.keys(global.wsClients || {}).length || 0 })
		});
	} catch (e) {
		console.error('Failed to post heartbeat', e);
	}
}, 15000); // every 15s
```

Set `ECHO_HEARTBEAT_SECRET` in your backend `.env` and in the Node environment to prevent unauthorized posts.

Viewing metrics
---------------

After the Node server posts heartbeats and messages are sent, visit:

- `GET /api/admin/echo/health` — quick health check (401/403 if not authenticated as admin via API; in this repo it's a public endpoint, so restrict in production)
- `GET /api/admin/echo/stats` — counters and metrics

You can then create a Filament page to poll these endpoints and display them in the admin UI (I can scaffold that for you if you want).
