@component('mail::message')
# Welcome to {{ $institution->name }}!

You've been invited to join **{{ $institution->name }}** on Modeh.

Invited by: **{{ $invitedBy->name }}**

@component('mail::button', ['url' => $inviteUrl])
Accept Invitation
@endcomponent

**About this invitation:**
- This invitation expires on {{ $expiresAt->format('M d, Y \a\t H:i A') }}
- If you don't have a Modeh account yet, you'll be able to create one after clicking the link
- You'll need to be signed in with this email ({{ $email }}) to accept the invitation

If you have any questions about {{ $institution->name }}, feel free to reach out to the institution manager.

---

Thanks,  
**The Modeh Team**

{{ config('app.name') }}
@endcomponent
