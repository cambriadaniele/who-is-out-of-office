# Fork notes

This is a fork of [igornast/who-is-out-of-office](https://github.com/igornast/who-is-out-of-office), branch `kita`,
based on upstream commit `e6be329`. Changes on top of upstream:

- **German translation** (`app/translations/*.de.yaml`, Swiss spelling, informal "du").
- **Language selection**: `APP_LOCALE` sets the default language (`en` if unset; emails and scheduled jobs use it).
  In the browser the language follows `Accept-Language` among the enabled ones (`en`, `de`).
  `APP_LOCALE_FROM_BROWSER=false` turns that off, so everyone gets the default language.
- **Dates follow the language** (`App\Shared\Service\DateLocalizer`, Twig filter `ldate`), as do the team calendar
  and the date picker.
- A few labels that were plain English text in the code now go through the translator.
- **Login throttling**: 30 wrong passwords per account and address in 10 minutes (upstream throttles only the 2FA step).

- **Slack can be switched off**: `SLACK_ENABLED=false` hides every Slack screen and setting (default `true`).
  Keep `SLACK_DSN=null://null` when Slack is unused; an empty DSN makes the Integrations page fail.
- **Roles** are chosen with named checkboxes when adding or editing a team member, not typed as `ROLE_…`.
- The **iCal feed** text follows the default language.
- A simpler welcome page.
- **Vacation days count in the year they are taken** (`YearlyBalanceCalculator`). The ledger stays one running
  account, but what a person may still book is worked out per leave year: allowance of that year minus
  what is booked in it. A booking is checked against its own year and the years after it, so future years can be booked
  before the year starts. **No carry-over**: days not taken by the end of the year are forfeited (the 1 January reset
  adds that year's allowance minus the forfeited days, with the reason written on the ledger entry). A leave year's allowance can differ from the default (table `user_leave_allowance`, field
  "Ferientage pro Jahr (Ausnahmen)" on the Anstellung tab, one `2027: 24` line per year). The dashboard shows the days
  left this year and next year; the history and adjust pages show the per-year numbers above the running account.
  A migration adds the table; run `doctrine:migrations:migrate` when deploying.
- **"Ferientage verwalten" page** per person (admins; from the team member list, detail and edit pages): one card per
  leave year (current + next two) with allowance, corrections, booked and free days. "Tage anpassen" adds or removes
  days with a required reason: in the current year it is a ledger entry, for a coming year it is stored in
  `user_leave_year_adjustment` and added by the 1 January reset. "Anspruch ändern" sets a coming year's allowance.
  Replaces the old "Saldo anpassen" form (its URL redirects here) and the "2027: 24" text field.
- **School holidays** (`school_holiday`, admin list "Schulferien" under Einstellungen): shown to everyone as blue bars in
  the team calendar (🏫, the group name appears on click). Information only, they do not change workdays.
- **Phones**: list rows (for example the leave requests) are shown as cards instead of stacked label/value rows.

Not translated: Slack messages.

The test suite has not been run against these changes.
