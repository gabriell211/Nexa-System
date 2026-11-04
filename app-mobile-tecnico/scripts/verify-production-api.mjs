/**
 * Read-only mobile→Vercel contract verification, deliberately without secrets.
 * A malformed login payload checks validation without guessing any credentials.
 */
const origin = process.env.NEXA_MOBILE_SMOKE_URL || 'https://nexa-system-5nkp.vercel.app/api/v1';
const api = origin.replace(/\/+$/, '');
if (!api.startsWith('https://')) {
  throw new Error('Production smoke endpoint must use HTTPS.');
}

async function check(path, { method = 'GET', body, expected, label }) {
  const response = await fetch(api + path, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
    signal: AbortSignal.timeout(30000),
    redirect: 'manual',
  });
  const type = response.headers.get('content-type') || '';
  if (response.status !== expected || !type.includes('application/json')) {
    throw new Error(label + ': expected ' + expected +
      ' with JSON; got ' + response.status + ' / ' + type);
  }
  const payload = await response.json();
  if (!payload || typeof payload !== 'object') {
    throw new Error(label + ': unexpected JSON payload');
  }
  console.log(label + ': HTTP ' + response.status + ', JSON');
}

try {
  await check('/mobile/auth/me', {
    expected: 401,
    label: 'Unauthenticated session is rejected',
  });
  await check('/mobile/tickets', {
    expected: 401,
    label: 'Technician tickets require Bearer authentication',
  });
  await check('/mobile/auth/login', {
    method: 'POST',
    body: { login_name: 'x', password: '' },
    expected: 422,
    label: 'Malformed login is validated',
  });
  console.log('Nexa Technician: public API contract verified.');
} catch (error) {
  console.error('Nexa Technician API smoke FAILED:', error instanceof Error ? error.message : error);
  process.exitCode = 1;
}
