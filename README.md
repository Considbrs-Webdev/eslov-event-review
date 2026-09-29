# Eslöv Event Review

A site-specific WordPress plugin for reviewing submitted events before publication. It adds moderation actions to the event list, completes organizer creation when an event is accepted, and restricts accounts created for organizers through the configured frontend forms.

The plugin integrates with **Modularity Frontend Form** and **Event Manager**. It keeps the local moderation rules in a separate plugin without requiring changes to either dependency.

## Problems it addresses

- **Password protection is not an approval workflow.** A password-protected event can still have `publish` status and be picked up by integrations. Configure the form to save submissions as **Pending Review** so they await approval. This plugin supplies the review actions and removes the generated password.
- **Publishing alone does not create the submitted organizer.** Event Manager normally processes new organizer fields during an ACF save. The Accept action explicitly invokes that handler so the organizer is created and assigned to the event.
- **Submitting an organizer should not grant organizer administration access.** New accounts created through the configured submission flow become subscribers, receive no welcome email, and cannot log in on this site while this plugin is active.
- **A frontend editing link is misleading without its token.** The plugin hides the “Frontend Submission / View on frontend” notice when the event has no password to use as an editing token.

## Review workflow

The event list gains a **Granskning** (Review) column for events with `pending` or `draft` status. The buttons use the same WordPress button classes and layout as the review actions in `eslov-customisation`.

| Button | Behavior |
| --- | --- |
| **Acceptera** (Accept) | Publishes the event without a password. If a new organizer was submitted, processes and assigns it through Event Manager, then saves the event again so save hooks can see the completed relationship. |
| **Neka** (Reject) | Moves the event to the WordPress Trash, where it can be restored. |
| **Granska** (Review) | Opens the standard WordPress editor for inspection and changes. |

Actions require the appropriate WordPress capabilities. Accept and Reject links also use nonces. The server checks that the target is an event in a reviewable status.

### New and existing organizers

When `submitNewOrganization` is enabled, Accept reuses Event Manager's registered `CreateNewOrganizerFromEventSubmit` handler. Event Manager creates or resolves the organizer term, assigns it to the event, clears the submitted organizer fields, and runs its organizer-account creation flow.

When an existing organizer was selected, the existing relationship is preserved. Accepting the event again after returning it to Pending Review does not recreate an organizer once the new-organizer fields have been cleared.

If the handler is unavailable, approval is refused. If processing throws an error or leaves the new-organizer flag set, the plugin attempts to restore the event's previous status and password. This is not a database transaction: organizer or account side effects that have already occurred are not automatically rolled back.

## Setup and scope

The current implementation is intentionally specific to this installation:

| Setting | Current value |
| --- | --- |
| Allowed site hostname | `event.eslov.local` |
| Event post type | `event` |
| Organizer taxonomy used by Event Manager | `organization` |
| Restricted-account marker | `_eslov_event_review_blocked` |

1. Use the existing WordPress installation with Modularity Frontend Form, Event Manager (`api-event-manager`), and their ACF/Modularity dependencies available.
2. Activate **Eslöv Event Review** on `event.eslov.local` only. Network activation and activation on another hostname are rejected.
3. In the frontend form's database handler settings, choose **Pending Review** as the saved post status.
4. Review incoming events in the WordPress event list.

**The form controls the initial post status.** The plugin does not change form settings on activation or deactivation and does not force submissions to `pending`. If a form is configured to publish immediately, this plugin does not prevent that publication.

Review buttons apply to all draft and pending `event` posts on the allowed site. Password removal during form saves and organizer-account restrictions are scoped to the configured frontend forms, identified through `mod_frontend_form_module_id`. These values are currently defined in code, not exposed as settings.

## Organizer accounts and email

During Event Manager's organizer creation flow for an event submitted through the configured frontend forms, the plugin:

- Marks newly created accounts and assigns the `subscriber` role, overriding the organizer-administrator role assigned by Event Manager during that flow.
- Suppresses outgoing WordPress mail while that scoped organizer-creation action is running, including Event Manager's welcome email.
- Suppresses WordPress new-user notifications to both the user and administrator for marked accounts.
- Blocks authentication, recognition of logged-in users, password resets, and application-password availability for marked accounts on this site.

Existing accounts are not marked or downgraded by this flow. Mail outside the scoped creation action is unaffected, apart from the new-user notification filters for marked accounts.

These are site-local restrictions, not a network-wide account suspension. They apply only where this plugin is loaded and its hostname check passes.

## Passwords and frontend editing

New event submissions from the configured frontend forms have their generated `post_password` cleared before the database write. Passwords added later in WordPress are preserved when an existing event is edited. Accept also clears any password on the approved event. Activating the plugin does not bulk-update existing events.

Modularity Frontend Form uses `post_password` as the token for its frontend editing link. Password-free events use the standard WordPress editor via **Granska**. To enable a frontend editing link for an existing event, an administrator can set a password in WordPress and use the **View on frontend** link.

The current form plugin requires an editing token of **exactly 32 characters** in the browser and truncates API tokens to 32 characters. Use a random 32-character alphanumeric password; a shorter password such as `testing` does not start frontend data loading, even if it matches the event password. The link grants access to the event's frontend editing interface to anyone who has it. Changing or removing the password invalidates the old token.

Accept still removes the event password as part of publication. Set a password after acceptance if frontend editing should remain available. Preserving the password does not itself verify the form plugin's complete update/save workflow.

The “Frontend Submission / View on frontend” notice is retained only for `event` posts with a non-empty password; the form plugin's own submitted-by-form check still applies. Other admin notices are left alone.

## Publication and integrations

Pending and draft events are excluded from the standard anonymous WordPress REST event listing. Integrations that consume published events can pick them up after acceptance.

The plugin does not manage Typesense or External Content directly. After creating the organizer relationship, it saves the event again to notify existing save hooks. Approval first publishes the event, then processes the organizer; integrations can therefore receive an initial save before the relationship is complete, followed by the final save. Downstream synchronization remains the responsibility of the installed integrations.

## Deactivation and recovery

Deactivation removes the plugin's review actions and runtime restrictions. It leaves form settings, event statuses, organizer terms, and account data unchanged.

- Rejected events can be restored from the WordPress Trash.
- Accounts retain their subscriber role and restriction marker, but this plugin no longer enforces the login restrictions while inactive.
- Previously removed event passwords are not restored automatically.
- Deactivation does not reverse organizer creation or publication.

## Verification

The local implementation has been checked against the real WordPress and Event Manager hooks for:

- Pending submissions remaining inaccessible through anonymous REST reads until accepted.
- Unauthorized approval being rejected.
- Accept creating and linking a submitted organizer, with subsequent approval preserving the relationship.
- New organizer accounts becoming restricted subscribers and welcome email being suppressed.
- Reject moving an event to Trash and allowing restoration.
- The frontend editing notice being retained for password-protected events and removed otherwise, without removing unrelated notices.

Local integration scripts and backups are development artifacts outside this plugin directory; they are not a bundled automated test suite.
