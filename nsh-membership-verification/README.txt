NSH Membership Verification Demo
================================

This is a demo WordPress plugin. It does not process payments or verify real membership status. It enforces a three-event trial limit using registrations recorded by this demo.

Install
-------
1. Zip the nsh-membership-verification folder.
2. In WordPress, go to Plugins > Add New Plugin > Upload Plugin and upload the zip.
3. Activate "NSH Membership Verification Demo".
4. No separate participant page is required. The plugin serves the verification form through an event-specific URL on the site's home route.
5. Create an organizer page and add the shortcode [nsh_organizer_portal].
6. Sign in as a WordPress site administrator to access the organizer dashboard.

Demo flow
---------
The organizer dashboard shows the event list first. To create an event, enter only its name. The plugin builds a shareable event-specific URL on the site's home route with `nshmv_verify=1` and an opaque `event` parameter. The plugin handles that route directly, so it does not depend on a verification page, page ID, or custom page-path rewrites.

The verification page reads the event token from the URL and loads that event's name from WordPress. Participants submit their name and email address. If that email already has a paid-member registration, the participant receives a code for the new event without seeing the payment page. Returning to an event already registered for with the same email re-displays the existing code instead of creating another record.

Other participants can choose Credit Card, PayPal, or Trial on the demo payment page. Credit Card and PayPal are simulations only; they do not collect credentials or process a charge. Choosing either marks the registration "Paid member" in this demo. Trial registrations are marked "Trial member" and allowed for up to three distinct events per email address. The trial limit is checked again when the participant submits the form.

Each event in the organizer dashboard has a "View verified participants and codes" link. That opens the list of participants, email addresses, status, verification codes, selected demo payment option, and registration time for that event. After installing this plugin update, copy the refreshed participant verification link from the dashboard into the Meetup event; older links may still return 404.

The organizer dashboard is restricted to WordPress administrators, and forms use WordPress nonces. Use HTTPS and a privacy notice before collecting real participant information. This demo's paid status is based on a simulated payment selection, not an actual charge or verified membership; use a payment provider and trusted membership records before production use. Also add retention controls and an appropriate organizer role/capability.
