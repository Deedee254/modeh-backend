<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invitation - Modeh</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-100">
    <div id="app" class="flex items-center justify-center min-h-screen">
        <div class="w-full max-w-md">
            <script>
                window.invitationToken = '{{ $token }}';
            </script>

            <div class="bg-white rounded-lg shadow-lg p-8 text-center">
                <div class="mb-4">
                    <svg class="w-16 h-16 mx-auto text-blue-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-2">Processing Invitation</h2>
                <p class="text-gray-600">Please wait while we load your invitation details...</p>

                <div class="mt-8 space-y-4">
                    <p class="text-sm text-gray-600">
                        If you're not redirected, <a href="{{ route('login') }}?invitation={{ $token }}" class="text-blue-600 hover:underline">click here</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            fetch(`/api/institutions/invitation/${window.invitationToken}`)
                .then(response => {
                    if (!response.ok) {
                        window.location.href = '/';
                    }
                    return response.json();
                })
                .then(data => {
                    if ({{ auth()->check() ? 'true' : 'false' }}) {
                        window.location.href = `/admin/resources/institutions/${data.data.institution.id}/members`;
                    } else {
                        window.location.href = `/login?invitation=${window.invitationToken}`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    window.location.href = '/';
                });
        });
    </script>
</body>
</html>
