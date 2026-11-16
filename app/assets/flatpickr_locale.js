const GERMAN = {
  weekdays: {
    shorthand: ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'],
    longhand: ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'],
  },
  months: {
    shorthand: ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'],
    longhand: ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
  },
  weekAbbreviation: 'KW',
  scrollTitle: 'Zum Ändern scrollen',
  toggleTitle: 'Zum Umschalten klicken',
};

export function flatpickrLocale() {
  const language = (document.documentElement.lang || 'en').toLowerCase();

  return language.startsWith('de') ? { ...GERMAN, firstDayOfWeek: 1 } : { firstDayOfWeek: 1 };
}
