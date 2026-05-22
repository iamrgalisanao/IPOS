@component('mail::message')
# Welcome to IPOS!

Hello {{ $user->first_name }},

Your account has been created successfully! You're invited to complete your setup and get started with IPOS.

## Setup Instructions

1. Click the button below to complete your setup
2. Create a secure password
3. Select your timezone and language
4. Log in and start using IPOS

@component('mail::button', ['url' => $bootstrapLink])
Complete Your Setup
@endcomponent

**Setup Link Expiration:**
This link will expire on {{ \Carbon\Carbon::parse($expiresAt)->format('F j, Y \a\t g:i A') }}.

**Company:** {{ $companyName }}

If you didn't create this account or have any questions, please contact your system administrator.

---

**Note:** For security reasons, never share this link with others. This is a one-time setup link.

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent
