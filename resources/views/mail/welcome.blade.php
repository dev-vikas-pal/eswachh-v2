{{--
    The welcome email. Deliberately plain: the useful content is the plan and
    how to sign in, and an email a customer can read on a slow phone beats one
    that looks like a brochure.
--}}
<x-mail::message>
# Welcome, {{ $name }}

Your cleaning plan is live. Here is what you bought.

<x-mail::table>
| | |
|:--- |:--- |
| **Car** | {{ $car ?? '—' }} |
| **Plan** | {{ $package ?? 'Cleaning' }}@if ($months), {{ $months }} month(s)@endif |
| **Renews on** | {{ $renews ?? '—' }} |
| **Paid** | ₹{{ $amount }} |
</x-mail::table>

We will let you know as soon as a cleaner is assigned to your car, and again
each day once it has been cleaned.

## Signing in

There is no password to remember. Go to the sign-in page, enter
**{{ $phone }}**, and we send a six digit code to that number.

<x-mail::button :url="$signInUrl">See my plan</x-mail::button>

@if ($office)
Anything not right? Call us on {{ $office }} — the same day, if you can, so we
can put it right on the next round.
@endif

Thanks,<br>
Team eSwachh
</x-mail::message>
