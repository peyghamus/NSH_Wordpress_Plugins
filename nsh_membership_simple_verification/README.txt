NSH Membership Simple Verification
==================================

This is a separate WordPress plugin from the NSH Membership Verification Demo. It provides WordPress-account verification, a membership sign-up form, payment/trial demo steps, and an organizer code validator. It does not process real payments.

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
The verification page lets existing WordPress users log in and new participants open the sign-up form. A logged-in user's WordPress user-meta value for `site_role` (or `Site Role`, including capitalization, underscore, or hyphen variations) determines the next step: `Active` receives a code; `Unpaid` or `Expired` continues to the payment choices; every other or missing value is directed to contact the Membership Secretary. Array-valued fields and matching WordPress roles such as `active` are also recognized. Sites storing this field under another user-meta key can configure the lookup using the `nshmsv_site_role_meta_keys` filter.

The sign-up form collects email, first name, last name, and short hike experience. If the email already belongs to a WordPress user, the participant is asked to log in instead. Otherwise, the participant can choose PayPal, e-transfer, credit card, or an available trial membership. Existing users with an `Unpaid` or `Expired` Site Role can choose PayPal, e-transfer, or credit card. Selecting an option in this demo displays a verification code; it does not process or confirm payment and does not change the WordPress Site Role.

Trial membership is limited to three distinct event uses per email. After the third trial use, future event verifications require a payment choice. The trial limit is checked again when the form is submitted.

For event-specific repeat protection and distinct trial-event counts, add an event identifier to the verification link as `?event=event-id`. A repeat request for the same email and event returns the existing code and does not consume another trial. Without an event identifier, each completed trial submission counts as a trial use.

The organizer page only validates a submitted code and is administrator-only. Payment choices are simulations; integrate a payment provider and update Site Role only from a trusted payment confirmation before production use. New sign-up email is not verified by this demo, so confirm control of the email address before issuing production membership or codes.
