export class ApiError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message);
    this.name = 'ApiError';
  }
}

function messageOf(payload: unknown): string {
  if (!payload || typeof payload !== 'object') return 'Não foi possível concluir a solicitação.';
  const data = payload as Record<string, unknown>;
  const errors = data.errors;
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flatMap(value => Array.isArray(value) ? value : []);
    if (typeof first[0] === 'string') return first[0];
  }
  return typeof data.message === 'string' ? data.message : 'Não foi possível concluir a solicitação.';
}

// CSRF values are public-to-this-origin request tokens, NOT login secrets.
// Session identity is held by a server-side session and HttpOnly cookie.
let csrf: string | null = null;
let pendingCsrf: Promise<string> | null = null;
const base = '/api/v1/browser';

async function getCsrf(force = false): Promise<string> {
  if (csrf && !force) return csrf;
  if (pendingCsrf) return pendingCsrf;

  pendingCsrf = (async () => {
    const response = await fetch(base + '/auth/csrf', {
      method: 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new ApiError(response.status, 'Não foi possível iniciar a sessão de segurança.');
    const payload: unknown = await response.json();
    if (!payload || typeof payload !== 'object' ||
      !('csrf_token' in payload) || typeof payload.csrf_token !== 'string') {
      throw new ApiError(0, 'Resposta CSRF inválida.');
    }
    csrf = payload.csrf_token;
    return csrf;
  })().finally(() => { pendingCsrf = null; });
  return pendingCsrf;
}

export function resetCsrf(): void {
  csrf = null;
}

export async function api<T>(
  endpoint: string,
  _legacyToken: string | null,
  options: RequestInit = {},
): Promise<T> {
  const method = (options.method ?? 'GET').toUpperCase();
  const unsafe = !['GET', 'HEAD', 'OPTIONS'].includes(method);

  async function send(retry: boolean): Promise<T> {
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    if (options.body) headers.set('Content-Type', 'application/json');
    if (unsafe) headers.set('X-CSRF-TOKEN', await getCsrf());

    let response: Response;
    try {
      response = await fetch(base + endpoint, {
        ...options,
        headers,
        credentials: 'same-origin',
        cache: 'no-store',
      });
    } catch {
      throw new ApiError(0, 'Sem conexão com a API Nexa. Verifique o servidor.');
    }

    if (response.status === 419 && retry) {
      resetCsrf();
      return send(false);
    }
    if (response.status === 204) return undefined as T;
    const payload: unknown = await response.json().catch(() => null);
    if (!response.ok) throw new ApiError(response.status, messageOf(payload));
    return payload as T;
  }

  return send(true);
}

export function readableError(error: unknown): string {
  return error instanceof Error ? error.message : 'Erro inesperado. Tente novamente.';
}

export function queryString(values: Record<string, string | number | boolean>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(values)) params.set(key, String(value));
  return '?' + params.toString();
}
