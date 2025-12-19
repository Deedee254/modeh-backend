@component('mail::message')
# Password Reset Successful

Hello {{ $user->name }},

Your password has been successfully reset. You can now log in to your Modeh account with your new password.

If you did not reset your password and believe your account has been compromised, please contact our support team immediately.

Thanks,
Modeh Team

@endcomponent
