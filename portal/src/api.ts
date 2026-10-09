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
    const first = Object.values(errors).flatMap((value) => Array.isArray(value) ? value : []);
    if (typeof first[0] === 'string') return first[0];
  }
  return typeof data.message === 'string' ? data.message : 'Não foi possível concluir a solicitação.';
}

export async function api<T>(
  endpoint: string,
  token: string | null,
  options: RequestInit = {},
): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');
  if (options.body) headers.set('Content-Type', 'application/json');
  if (token) headers.set('Authorization', 'Bearer ' + token);

  let response: Response;
  try {
    response = await fetch('/api/v1' + endpoint, {
      ...options,
      headers,
      credentials: 'omit',
      cache: 'no-store',
    });
  } catch {
    throw new ApiError(0, 'Sem conexão com a API Nexa. Verifique o servidor.');
  }

  if (response.status === 204) return undefined as T;
  const payload: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    throw new ApiError(response.status, messageOf(payload));
  }

  return payload as T;
}

export function readableError(error: unknown): string {
  return error instanceof Error ? error.message : 'Erro inesperado. Tente novamente.';
}

export function queryString(values: Record<string, string | number | boolean>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(values)) params.set(key, String(value));
  return '?' + params.toString();
}
