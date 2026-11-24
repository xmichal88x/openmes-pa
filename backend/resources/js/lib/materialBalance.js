// A balance nobody has vouched for carries `balance_known: false` and no
// numbers: `available_qty`/`on_hand_qty` are null and `missing_qty` is the whole
// requirement. Printed as a dash that reads as "the store is empty" — a far more
// confident claim than the truth. This helper names the state instead, so the UI
// can say "unknown" rather than invent a number.
//
// Pure and i18n-free: the caller owns the wording.

export function balanceStateOf(line) {
    if (!line || line.balance_known !== false) return null;

    return {
        state: line.balance_state || 'unknown',
        source: line.balance_source || null,
        asOf: line.balance_as_of || null,
        owner: line.balance_owner || null,
    };
}
