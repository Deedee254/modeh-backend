<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Modeh</title>
    <link href="https://unpkg.com/tailwindcss@^2/dist/tailwind.min.css" rel="stylesheet">
  </head>
  <body class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="w-full max-w-md bg-white rounded shadow p-6">
      <h2 class="text-2xl font-bold mb-4">Sign in to Modeh</h2>

      @if ($errors->any())
        <div class="mb-4 text-red-600">
          <ul>
            @foreach ($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      <form method="POST" action="{{ url('/login') }}">
        @csrf
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700">Email</label>
          <input type="email" name="email" value="{{ old('email') }}" required class="mt-1 block w-full border-gray-300 rounded p-2" />
        </div>

        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700">Password</label>
          <input type="password" name="password" required class="mt-1 block w-full border-gray-300 rounded p-2" />
        </div>

        <div class="flex items-center justify-between mb-4">
          <label class="flex items-center text-sm">
            <input type="checkbox" name="remember" class="mr-2" /> Remember me
          </label>
          <a href="#" class="text-sm text-blue-600">Forgot password?</a>
        </div>

        <div>
          <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded">Log in</button>
        </div>
      </form>
    </div>
  </body>
</html>
