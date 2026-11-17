NSH Membership Simple Verification
==================================

This is a separate WordPress plugin from the NSH Membership Verification Demo. It provides email-first verification, sign-up and payment-demo steps, and an organizer code validator. It does not process real payments.

Install
-------
1. Zip the nsh_membership_simple_verification folder.
2. In WordPress, go to Plugins > Add New Plugin > Upload Plugin and upload the zip.
3. Activate "NSH Membership Simple Verification".
4. The plugin creates pages for Membership Verification, Membership Sign Up, Membership Payment Demo, and Organizer Code Validation. An existing published page is reused only when it already contains that page's shortcode; otherwise WordPress creates a separate page.
5. Restrict the Organizer Code Validation page to organizers as appropriate. The shortcode itself requires a WordPress administrator account.

Shortcodes
----------
Verification page: [nshmsv_verification]
Sign-up page: [nshmsv_signup]
Payment demo page: [nshmsv_payment]
Organizer page: [nshmsv_organizer]

The page slug `membership-verification` is not a shortcode. If you add the page manually, put `[nshmsv_verification]` in its content.

Flow
----
Participants enter their email on the verification page. A known paid member receives a code immediately. A known trial or unpaid member continues to the payment demo. An unknown email goes to sign-up first, then to the payment demo.

The payment demo offers credit-card/PayPal selection, which only marks the demo member as paid, and a trial option when fewer than three trial uses are recorded. The third trial use completes that event and changes the member's status to unpaid for subsequent events. Unpaid members cannot select trial. The trial limit is checked again when the payment form is submitted.

For event-specific repeat protection and distinct trial-event counts, add an event identifier to the verification link as `?event=event-id`. A repeat request for the same email and event returns the existing code and does not consume another trial. Without an event identifier, each completed trial submission counts as a trial use.

The organizer page only validates a submitted code and is administrator-only. Payment choices are simulations; use a payment provider and trusted membership records before production use. Email is the demo's identity key, so production verification should also confirm control of the email address.
