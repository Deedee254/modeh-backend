@component('mail::message')
# Join Modeh and support {{ $inviter->name }}

Hello,

{{ $inviter->name ?? 'A friend' }} invited you to register on Modeh and use their referral code **{{ $referralCode }}**. Use the link below to register and the referrer will receive credit.

@component('mail::button', ['url' => $inviteUrl])
Register with referral code
@endcomponent

If the button doesn't work, paste this URL into your browser:

{{ $inviteUrl }}

Thanks,
Modeh Team

@endcomponent
