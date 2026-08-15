<?php

namespace App\Support\Settings;

/**
 * The policy pages, as they read before anybody edits them.
 *
 * Held as defaults rather than seeded rows so a business that has never opened
 * the settings screen still has three complete pages - a payment gateway asks
 * to see them during onboarding, and an empty page fails that check as surely
 * as a missing one. The moment somebody edits one, their version is stored and
 * these stop being used for it.
 *
 * The markup is the same short whitelist the editor produces and the sanitiser
 * allows: headings, paragraphs and lists, no attributes.
 *
 * These describe how this software actually behaves - what it stores, when it
 * charges, what a cancellation does to a running plan. They are a starting
 * point written from the code, not legal advice, and the business should have
 * them read before relying on them.
 */
final class PolicyText
{
    public const PRIVACY = <<<'HTML'
        <p>This policy explains what we collect when you buy doorstep car cleaning from us, why we hold it, and what you can ask us to do with it.</p>
        <h3>What we hold</h3>
        <ul>
        <li>Your name, mobile number and email address.</li>
        <li>Where the car is kept: state, city, area, sector, society and flat or house number, and the time of day you would like it cleaned.</li>
        <li>Your car's registration number and model.</li>
        <li>Your plan, what it costs, when it runs from and to, and the record of each cleaning visit.</li>
        <li>Your payments: the amount, the date, the method, and the reference the payment gateway gives us.</li>
        <li>Any complaint you raise and what was done about it.</li>
        </ul>
        <h3>What we do not hold</h3>
        <p>We never see or store your card number, UPI PIN, or bank credentials. Payments are taken by our payment gateway on their own page; what comes back to us is a reference and a yes or no.</p>
        <h3>Why we hold it</h3>
        <ul>
        <li>To send a cleaner to the right car at the right address.</li>
        <li>To take payment for the plan you chose and to give you a receipt.</li>
        <li>To tell you when your plan is about to run out, by WhatsApp or SMS to the number you gave us.</li>
        <li>To answer a complaint and keep a record that it was answered.</li>
        </ul>
        <p>We do not sell your details, and we do not send you marketing for anybody else.</p>
        <h3>Who else sees it</h3>
        <p>The franchise that services your sector sees your address, your car and your plan, because they are the people who come to the car. Our payment gateway sees what it needs to take the payment. Our messaging provider sees your mobile number and the message. Nobody else.</p>
        <h3>How long we keep it</h3>
        <p>For as long as you have a plan with us, and afterwards for as long as the law requires us to keep financial records. A cancelled plan and its payments stay on the books because they are accounts, not because we want the data.</p>
        <h3>What you can ask for</h3>
        <ul>
        <li>A copy of what we hold about you.</li>
        <li>A correction, if something is wrong. You can change your name, email, address detail and preferred time yourself once you sign in.</li>
        <li>Deletion of anything we are not required to keep.</li>
        <li>To stop receiving reminders.</li>
        </ul>
        <p>Call or write to us using the details on the contact page and we will act on it.</p>
        <h3>Signing in</h3>
        <p>We sign you in with a six digit code sent to your mobile. The code is stored in a scrambled form, expires after five minutes, stops working after a handful of wrong attempts, and can only be used once.</p>
        HTML;

    public const TERMS = <<<'HTML'
        <p>These terms apply when you buy a car cleaning plan from us. Please read them before you pay.</p>
        <h3>What you are buying</h3>
        <p>A plan covers one car, at one address, for the period shown when you bought it. The package, the cleaning type and the duration you chose decide the price and what is done at each visit.</p>
        <h3>The price</h3>
        <p>The price is worked out by us from the package, the cleaning type, the duration, your car's size and any society charge that applies. The figure shown before you pay is the figure charged. Prices can change, but never for a period you have already paid for.</p>
        <h3>Visits</h3>
        <ul>
        <li>We clean on the days your plan covers, at roughly the time you asked for. We cannot promise an exact minute.</li>
        <li>The car needs to be parked where our cleaner can reach it. If the car is not there, or the parking is locked, that visit cannot be made up.</li>
        <li>We may not be able to clean in heavy rain, or where the society has closed access. We will tell you when that happens.</li>
        </ul>
        <h3>Renewing</h3>
        <p>A plan does not renew itself and we do not keep your card on file. We will remind you before it runs out, and you renew when you choose to. A plan left unpaid past the grace period is paused, and cleaning stops until it is renewed.</p>
        <h3>Cloths</h3>
        <p>Where your plan includes cloths, the number bought is the number available. When they run out you can buy more; cleaning continues either way.</p>
        <h3>Changing your details</h3>
        <p>You can change your name, email, house number and preferred time yourself. Moving to a different sector or society changes which franchise services you and may change the price, so please call the office for that.</p>
        <h3>If something goes wrong</h3>
        <p>Raise a complaint and we will look at it. If a visit was missed because of us, we will make it up or credit it. Damage claims must be raised on the same day, before the car is moved, so we can see what happened.</p>
        <h3>Ending a plan</h3>
        <p>Either of us can end a plan. What happens to money already paid is covered in our cancellation and refunds policy.</p>
        <h3>What we are not liable for</h3>
        <p>We are not responsible for pre-existing damage, for items left in an unlocked car, or for wear to paint, trim or fittings that were already loose or damaged. Our liability for any claim is limited to what you paid for the current period.</p>
        HTML;

    public const REFUNDS = <<<'HTML'
        <p>How cancellations and refunds work.</p>
        <h3>Before the first visit</h3>
        <p>If you cancel before the first cleaning of a new plan, you get the full amount back.</p>
        <h3>During a plan</h3>
        <p>If you cancel part way through, we refund the unused whole months, counted from the end of the month in which you tell us. The month in progress is not refunded, and any joining or one-off charge is not refunded.</p>
        <h3>Cloths</h3>
        <p>A cloth bundle is not refundable once any of it has been used. An untouched bundle bought within the last thirty days can be refunded in full.</p>
        <h3>If we cancel</h3>
        <p>If we stop servicing your sector, or cannot continue for any reason of our own, we refund every day you have paid for and not received, without deduction.</p>
        <h3>Missed visits</h3>
        <p>A visit missed because of us is made up, or credited to your next renewal if it cannot be. A visit missed because the car was not there, or could not be reached, is not refundable.</p>
        <h3>How to ask</h3>
        <p>Call or write to the office using the details on the contact page and tell us your car number. We will confirm the amount in writing before anything is paid back.</p>
        <h3>How long it takes</h3>
        <p>Refunds go back to the way you paid. Once we send it, a card or UPI refund usually reaches you within five to seven working days, depending on your bank. Cash payments are refunded in cash at the office.</p>
        <h3>Duplicate payments</h3>
        <p>If you were charged twice for the same thing, tell us and we will return the extra in full. We also check for this ourselves and will refund it whether or not you have noticed.</p>
        HTML;
}
