/** Converts a pt-BR amount to integer cents without floating-point arithmetic. */
export function centsFromBrazilianValue(value: string): number | null {
  if (!value.trim()) return null;
  const normalized = value.trim().replace(',', '.');
  if (!/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/.test(normalized)) return null;
  const [whole, fractional = ''] = normalized.split('.');
  const cents = Number(whole) * 100 + Number(fractional.padEnd(2, '0'));
  return Number.isSafeInteger(cents) && cents <= 1000000000 ? cents : null;
}

export function currencyFromCents(value: number): string {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency', currency: 'BRL',
  }).format(value / 100);
}
