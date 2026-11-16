# Fork notes

This is a fork of [igornast/who-is-out-of-office](https://github.com/igornast/who-is-out-of-office), branch `kita`,
based on upstream commit `e6be329`. Changes on top of upstream:

- **German translation** (`app/translations/*.de.yaml`, Swiss spelling, informal "du").
- **Language selection**: `APP_LOCALE` sets the default language (`en` if unset; emails and scheduled jobs use it).
  In the browser the language follows `Accept-Language` among the enabled ones (`en`, `de`).
- **Dates follow the language** (`App\Shared\Service\DateLocalizer`, Twig filter `ldate`), as do the team calendar
  and the date picker.
- A few labels that were plain English text in the code now go through the translator.
- **Login throttling**: 30 wrong passwords per account and address in 10 minutes (upstream throttles only the 2FA step).

- **Slack can be switched off**: `SLACK_ENABLED=false` hides every Slack screen and setting (default `true`).
  Keep `SLACK_DSN=null://null` when Slack is unused; an empty DSN makes the Integrations page fail.
- **Roles** are chosen with named checkboxes when adding or editing a team member, not typed as `ROLE_…`.
- The **iCal feed** text follows the default language.
- A simpler welcome page.

Not translated: Slack messages.

The test suite has not been run against these changes.
