@component('mail::message')
# Reset Your Password

Hello {{ $user->name }},

We received a request to reset the password for your Modeh account. Click the button below to reset your password.

@component('mail::button', ['url' => config('app.frontend_url') . $resetUrl])
Reset Password
@endcomponent

If you didn't request a password reset, you can ignore this email. This link will expire in 1 hour.

If the button doesn't work, paste this URL into your browser:

{{ config('app.frontend_url') . $resetUrl }}

Thanks,
Modeh Team

@endcomponent
