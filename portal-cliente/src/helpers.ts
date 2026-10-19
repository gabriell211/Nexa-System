import type { Page } from './types';

export function pagination<T>(response: Page<T> | undefined) {
  return {
    page: response?.meta?.current_page ?? response?.current_page ?? 1,
    pages: response?.meta?.last_page ?? response?.last_page ?? 1,
    total: response?.meta?.total ?? response?.total ?? 0,
  };
}

export function meter(value: string | null | undefined): string {
  if (value === null || value === undefined || !/^\d+$/.test(value)) return '—';
  try { return BigInt(value).toLocaleString('pt-BR'); }
  catch { return '—'; }
}

export function dateTime(value: string | null | undefined): string {
  if (!value) return 'Sem leitura';
  const time = new Date(value);
  return Number.isNaN(time.getTime()) ? 'Data indisponível' : time.toLocaleString('pt-BR');
}

export function label(value: string | null | undefined): string {
  return value && value.trim() ? value : 'Não informado';
}
