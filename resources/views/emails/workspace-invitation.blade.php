<x-mail::message>
# You've been invited!

You have been invited to join the the workspace **{{ $workspace->name }}** as a **{{ $role }}** on NetSanya.

NetSanya is a professional API client for modern development teams.

<x-mail::button :url="$url">
Accept Invitation
</x-mail::button>

If you don't have an account, you can create one after clicking the button above.

Thanks,<br>
The {{ config('app.name') }} Team
</x-mail::message>
