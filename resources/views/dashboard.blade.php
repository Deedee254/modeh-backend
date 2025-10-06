<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Modeh</title>
    <link href="https://unpkg.com/tailwindcss@^2/dist/tailwind.min.css" rel="stylesheet">
  </head>
  <body class="min-h-screen bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto bg-white shadow rounded p-6">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-xl font-bold">Dashboard</h1>
        <form method="POST" action="{{ url('/logout') }}">
          @csrf
          <button class="px-4 py-2 bg-red-500 text-white rounded">Logout</button>
        </form>
      </div>

      <p>Welcome, {{ auth()->user()->email ?? 'User' }}. This is the backend dashboard.</p>
    </div>
  </body>
</html>
