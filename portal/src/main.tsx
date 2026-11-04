import React from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from './App';
import './styles.css';

// A browser may keep an old entrypoint after a new Vercel deployment.
// Vite reports missing hashed lazy chunks through this cancellable event.
window.addEventListener('vite:preloadError', (event: Event) => {
  event.preventDefault();
  const storageKey = 'nexa:last-asset-reload';
  const now = Date.now();
  let canReload = false;
  try {
    const previous = Number(sessionStorage.getItem(storageKey) || '0');
    canReload = now - previous > 60_000;
    if (canReload) sessionStorage.setItem(storageKey, String(now));
  } catch {
    // Without storage we cannot guarantee that an automatic reload is safe.
  }
  if (canReload) {
    window.location.reload();
    return;
  }
  if (document.getElementById('nexa-update-notice')) return;
  const notice = document.createElement('div');
  notice.id = 'nexa-update-notice';
  notice.setAttribute('role', 'alert');
  notice.style.cssText =
    'position:fixed;bottom:16px;left:16px;right:16px;z-index:9999;padding:16px;background:#fff;color:#15233a;border-radius:10px;box-shadow:0 5px 24px #0004';
  notice.textContent = 'O Nexa foi atualizado. Recarregue a página para continuar.';
  const reloadButton = document.createElement('button');
  reloadButton.type = 'button';
  reloadButton.textContent = 'Recarregar';
  reloadButton.addEventListener('click', () => {
    try { sessionStorage.removeItem(storageKey); } catch { /* Storage unavailable */ }
    window.location.reload();
  });
  notice.append(' ', reloadButton);
  document.body.appendChild(notice);
});

const client = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 30_000, refetchOnWindowFocus: true },
  },
});
const node = document.getElementById('root');
if (!node) throw new Error('Missing root container');

ReactDOM.createRoot(node).render(
  <React.StrictMode>
    <QueryClientProvider client={client}>
      <App />
    </QueryClientProvider>
  </React.StrictMode>,
);
