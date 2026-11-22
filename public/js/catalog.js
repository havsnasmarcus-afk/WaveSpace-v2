// Product catalogue shown in the UI. The server (src/LockerService.php) is the authority for real prices and rules.
export const SIZE_INFO = {
  shortboard: { label: 'Shortboard', note: `Up to 6'6" + wetsuit` },
  longboard: { label: 'Longboard', note: `Up to 10'0" + wetsuit` },
};

export const TERMS = [
  { months: 1, label: '1 month', note: 'Flexible', discount: 0 },
  { months: 3, label: '3 months', note: 'Save 10%', discount: 10 },
  { months: 12, label: '12 months', note: 'Save 25%', discount: 25 },
];

export const HELP_TOPICS = ["Locker won't open", "Access code isn't working", 'Billing question', 'Something else'];

/** Amount charged per term, in cents (mirrors LockerService::termPrice). */
export function termPrice(monthlyCents, months) {
  const t = TERMS.find((x) => x.months === months) || TERMS[0];
  return Math.round((monthlyCents * months * (100 - t.discount)) / 100);
}

export const termLabel = (months) => (TERMS.find((x) => x.months === months) || TERMS[0]).label;
