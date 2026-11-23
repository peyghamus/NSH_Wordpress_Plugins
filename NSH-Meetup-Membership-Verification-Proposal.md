# Proposal: NSH Meetup Membership Verification

## Purpose

Require every participant in an NSH Meetup event to verify their membership before attending. A verified participant receives a unique code and enters it as the answer to a required question in the Meetup event questionnaire. Event organizers check submitted codes in the NSH organizer portal.

## Participant experience

1. The event instructions link to the NSH Membership Verification page. Participants with an account log in; new participants use the sign-up form.
2. The system checks the participant's WordPress **Site Role**:
   - **Active:** issue a unique verification code for the event.
   - **Unpaid or Expired:** direct the participant to membership payment options; issue a code after the membership is confirmed.
   - **Other or missing status:** direct the participant to the Membership Secretary.
3. New applicants provide their email, first and last names, and short hiking experience. If their email already belongs to a WordPress account, they are asked to log in. Otherwise, they can choose a membership payment option or an available trial membership.
4. The participant copies the resulting code into the required Meetup questionnaire answer. Event-specific codes prevent one event's code from being reused for another event.

## Organizer workflow

Organizers open the NSH code-validation portal, enter the code from a participant's Meetup response, and confirm whether it is valid before approving participation. Access to the portal is restricted to authorized WordPress administrators. Organizers should check the code against the event's RSVP rather than accept a screenshot or a participant's membership-status claim.

## Benefits

- Applies one consistent membership check to all Meetup events.
- Keeps membership records and eligibility decisions in NSH's WordPress site.
- Gives organizers a quick, consistent way to validate RSVP responses.
- Reduces sharing or reuse of codes by associating codes with the participant and event.
- Provides a clear path for new applicants and members who need to renew or contact the Membership Secretary.

## Rollout and safeguards

Add the verification link and an instruction such as **“Complete NSH verification and enter your verification code here”** to each Meetup event. Make the code question required. Train organizers to validate codes before confirming attendance, and provide a contact path for people who cannot complete verification.

Before using this process for real membership or payment decisions, connect a payment provider and issue codes for Unpaid or Expired members only after trusted payment confirmation. The current plugin's PayPal, e-transfer, and credit-card selections are demonstrations and do **not** process or confirm payments. Confirm the WordPress Site Role field mapping, limit code-portal access to designated organizers, use HTTPS, and publish an appropriate privacy and data-retention notice.
