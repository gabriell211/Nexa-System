import * as SecureStore from 'expo-secure-store';
import type { LoginResponse, Page, Person, StockItem, Ticket } from './types';

export const DEFAULT_API_URL = 'https://nexa-system-5nkp.vercel.app/api/v1';
const STORAGE_KEY = 'nexa.technician.mobile.token';
const override = process.env.EXPO_PUBLIC_NEXA_API_URL?.trim().replace(/\/+$/, '');
export const API_URL = override || DEFAULT_API_URL;
const TIMEOUT_MS = 30_000;

export class ApiError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message);
    this.name = 'ApiError';
  }
}
export type RestoredSession =
  | { state: 'authenticated'; user: Person }
  | { state: 'unauthenticated' }
  | { state: 'offline' };

let token: string | null = null;
let expiredHandler: (() => void) | null = null;

export function onExpired(handler: (() => void) | null): void {
  expiredHandler = handler;
}

function assertConfigured(): void {
  const development = __DEV__ && /^http:\/\/(?:localhost|127\.0\.0\.1|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+):\d+\/api\/v1$/.test(API_URL);
  if (!/^https:\/\/[^/]+\/api\/v1$/.test(API_URL) && !development) {
    throw new ApiError(0, 'Endereço da API inválido. Use HTTPS e o caminho /api/v1.');
  }
}

function messageOf(body: unknown): string {
  if (body && typeof body === 'object') {
    const value = body as { message?: unknown; errors?: Record<string, unknown> };
    if (value.errors && typeof value.errors === 'object') {
      for (const item of Object.values(value.errors)) {
        if (Array.isArray(item) && typeof item[0] === 'string') return item[0];
      }
    }
    if (typeof value.message === 'string' && value.message.length < 350) return value.message;
  }
  return 'O Nexa não conseguiu processar esta solicitação.';
}

async function request<T>(path: string, method: 'GET' | 'POST' = 'GET',
  body?: unknown, authorized = true): Promise<T> {
  assertConfigured();
  if (!path.startsWith('/mobile/') || path.includes('..')) {
    throw new ApiError(0, 'Rota não autorizada.');
  }
  if (authorized && !token) throw new ApiError(401, 'Entre novamente para acessar suas OS.');
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
  let response: Response;
  try {
    response = await fetch(API_URL + path, {
      method,
      redirect: 'error',
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(authorized && token ? { Authorization: 'Bearer ' + token } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    });
  } catch {
    throw new ApiError(0, 'Sem conexão com a API Nexa. Confira a internet e tente novamente.');
  } finally {
    clearTimeout(timer);
  }
  const type = response.headers.get('content-type') || '';
  const payload: unknown = type.includes('application/json')
    ? await response.json().catch(() => null) : null;

  if (response.status === 401 && authorized) {
    await clearSession().catch(() => undefined);
    expiredHandler?.();
    throw new ApiError(401, 'Sua sessão expirou. Entre novamente.');
  }
  if (!response.ok) throw new ApiError(response.status, messageOf(payload));
  if (!type.includes('application/json') || !payload || typeof payload !== 'object') {
    throw new ApiError(502, 'O servidor não retornou os dados esperados.');
  }
  return payload as T;
}

export async function restoreSession(): Promise<RestoredSession> {
  token = await SecureStore.getItemAsync(STORAGE_KEY);
  if (!token) return { state: 'unauthenticated' };
  try {
    const result = await request<{ user: Person }>('/mobile/auth/me');
    if (!result.user || typeof result.user.id !== 'number') {
      throw new ApiError(502, 'Sessão inválida.');
    }
    return { state: 'authenticated', user: result.user };
  } catch (error) {
    if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
      await clearSession();
      return { state: 'unauthenticated' };
    }
    // Keep the saved token for retry; never expose protected pages offline.
    return { state: 'offline' };
  }
}

export async function login(loginName: string, password: string): Promise<LoginResponse> {
  const login_name = loginName.trim().toLowerCase();
  if (!/^[a-z][a-z0-9._-]{2,63}$/.test(login_name) || !password) {
    throw new ApiError(422, 'Informe seu nome de usuário e senha.');
  }
  const session = await request<LoginResponse>('/mobile/auth/login', 'POST',
    { login_name, password }, false);
  if (!session.token || typeof session.user?.id !== 'number') {
    throw new ApiError(502, 'A API retornou uma sessão incompleta.');
  }
  await SecureStore.setItemAsync(STORAGE_KEY, session.token);
  token = session.token;
  return session;
}

export async function clearSession(): Promise<void> {
  token = null;
  await SecureStore.deleteItemAsync(STORAGE_KEY);
}

export async function logout(): Promise<void> {
  try {
    if (token) await request<{ message: string }>('/mobile/auth/logout', 'POST');
  } finally {
    await clearSession();
  }
}

export async function tickets(page = 1): Promise<Page<Ticket>> {
  return request<Page<Ticket>>('/mobile/tickets?page=' + encodeURIComponent(String(page)));
}
export async function ticket(id: number): Promise<Ticket> {
  return (await request<{ data: Ticket }>('/mobile/tickets/' + id)).data;
}
export async function startTicket(id: number): Promise<Ticket> {
  return (await request<{ data: Ticket }>('/mobile/tickets/' + id + '/start', 'POST')).data;
}
export async function stockItems(q: string): Promise<StockItem[]> {
  return (await request<{ data: StockItem[] }>('/mobile/stock-items?q=' + encodeURIComponent(q))).data;
}
export async function requestPart(id: number, part: {
  stock_item_id: number; quantity: number; reason: string; request_id: string;
}): Promise<void> {
  await request('/mobile/tickets/' + id + '/part-requests', 'POST', part);
}
export async function signTicket(id: number, data: {
  signer_name: string; service_summary: string; signature_png_base64: string; consent: true;
}): Promise<Ticket> {
  return (await request<{ data: Ticket }>('/mobile/tickets/' + id + '/signature', 'POST', data)).data;
}
