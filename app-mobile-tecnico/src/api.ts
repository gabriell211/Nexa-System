import * as SecureStore from 'expo-secure-store';
import type { LoginResponse, Page, StockItem, Ticket } from './types';

const STORAGE_KEY = 'nexa.technician.mobile.token';
const configured = process.env.EXPO_PUBLIC_NEXA_API_URL?.replace(/\/$/, '');
const API_URL = configured ?? '';

export class ApiError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message);
    this.name = 'ApiError';
  }
}
let token: string | null = null;

function assertConfigured(): void {
  if (!API_URL || !/^https?:\/\//.test(API_URL)) {
    throw new ApiError(0, 'Configure EXPO_PUBLIC_NEXA_API_URL em app-mobile-tecnico/.env.');
  }
  if (!__DEV__ && !API_URL.startsWith('https://')) {
    throw new ApiError(0, 'A API deve utilizar HTTPS em produção.');
  }
}

function messageOf(payload: unknown): string {
  if (payload && typeof payload === 'object') {
    const body = payload as { message?: unknown; errors?: Record<string, unknown> };
    if (body.errors && typeof body.errors === 'object') {
      const first = Object.values(body.errors).flatMap(v => Array.isArray(v) ? v : []);
      if (typeof first[0] === 'string') return first[0];
    }
    if (typeof body.message === 'string') return body.message;
  }
  return 'Não foi possível concluir a operação.';
}

async function request<T>(path: string, method: 'GET'|'POST' = 'GET', body?: unknown, authorized = true): Promise<T> {
  assertConfigured();
  if (authorized && !token) throw new ApiError(401, 'Faça login novamente.');
  let response: Response;
  try {
    response = await fetch(API_URL + path, {
      method,
      headers: {
        Accept: 'application/json',
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(authorized && token ? { Authorization: 'Bearer ' + token } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(0, 'Sem conexão com o servidor Nexa.');
  }
  const payload: unknown = await response.json().catch(() => null);
  if (!response.ok) throw new ApiError(response.status, messageOf(payload));
  return payload as T;
}

export async function restoreSession(): Promise<boolean> {
  token = await SecureStore.getItemAsync(STORAGE_KEY);
  if (!token) return false;
  try {
    await request('/mobile/auth/me');
    return true;
  } catch {
    await clearSession();
    return false;
  }
}

export async function login(email: string, password: string, tenant: string): Promise<LoginResponse> {
  const session = await request<LoginResponse>('/mobile/auth/login','POST',{email,password,tenant},false);
  token = session.token;
  await SecureStore.setItemAsync(STORAGE_KEY, token);
  return session;
}

async function clearSession(): Promise<void> {
  token = null;
  await SecureStore.deleteItemAsync(STORAGE_KEY);
}

export async function logout(): Promise<void> {
  try { if (token) await request('/mobile/auth/logout','POST'); }
  finally { await clearSession(); }
}

export async function tickets(): Promise<Ticket[]> {
  const page = await request<Page<Ticket>>('/mobile/tickets');
  return page.data;
}
export async function ticket(id: number): Promise<Ticket> {
  return (await request<{data:Ticket}>(`/mobile/tickets/${id}`)).data;
}
export async function startTicket(id: number): Promise<Ticket> {
  return (await request<{data:Ticket}>(`/mobile/tickets/${id}/start`,'POST')).data;
}
export async function stockItems(q: string): Promise<StockItem[]> {
  return (await request<{data:StockItem[]}>(`/mobile/stock-items?q=${encodeURIComponent(q)}`)).data;
}
export async function requestPart(id: number, part: {
  stock_item_id: number; quantity: number; reason: string; request_id: string;
}): Promise<void> {
  await request(`/mobile/tickets/${id}/part-requests`,'POST',part);
}
export async function signTicket(id: number, data: {
  signer_name: string; service_summary: string; signature_png_base64: string; consent: true;
}): Promise<Ticket> {
  return (await request<{data:Ticket}>(`/mobile/tickets/${id}/signature`,'POST',data)).data;
}
