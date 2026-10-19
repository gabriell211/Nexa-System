import { useState, type FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { BrowserRouter, Link, NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { Activity, Building2, ChevronDown, ClipboardList, FileText, LayoutDashboard, LogOut, MapPin, Printer, Settings, UserRound } from 'lucide-react';
import { ApiError, api, readableError, resetCsrf } from './api';
import type { ClientIdentity, Credentials } from './types';
import { ActivationPage } from './pages/ActivationPage';
import { OverviewPage } from './pages/OverviewPage';
import { PrintersPage } from './pages/PrintersPage';
import { CountersPage } from './pages/CountersPage';
import { LocationsPage } from './pages/LocationsPage';
import { AccountPage } from './pages/AccountPage';
import { TicketsPage } from './pages/TicketsPage';

function Login({ onLogin }: { onLogin: (values: Credentials) => Promise<void> }) {
  const [credentials, setCredentials] = useState<Credentials>({ email: '', password: '', tenant: '' });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true); setError(null);
    try { await onLogin(credentials); }
    catch (cause) { setError(readableError(cause)); }
    finally { setBusy(false); }
  }
  return <main className="login-screen">
    <section className="login-card">
      <div className="brand"><span className="brand-icon">N</span><span>NEXA <strong>CLIENTE</strong></span></div>
      <span className="eyebrow">PORTAL DO CLIENTE</span>
      <h1>Suas impressoras, em um só lugar.</h1>
      <p>Visualize endereços IP, localização e os contadores registrados do seu parque.</p>
      <form className="login-form" onSubmit={e => void submit(e)}>
        <label htmlFor="tenant">Identificador da prestadora</label>
        <input id="tenant" required maxLength={100} autoComplete="organization" placeholder="Ex.: minha-prestadora"
          value={credentials.tenant} onChange={e => setCredentials({ ...credentials, tenant: e.target.value })}/>
        <label htmlFor="email">E-mail</label>
        <input id="email" required type="email" autoComplete="username" placeholder="voce@empresa.com.br"
          value={credentials.email} onChange={e => setCredentials({ ...credentials, email: e.target.value })}/>
        <label htmlFor="password">Senha</label>
        <input id="password" required type="password" autoComplete="current-password"
          value={credentials.password} onChange={e => setCredentials({ ...credentials, password: e.target.value })}/>
        {error && <p className="error" role="alert">{error}</p>}
        <button className="button primary" type="submit" disabled={busy}>{busy ? 'Entrando...' : 'Entrar no portal'}</button>
      </form>
      <small>Sessão protegida por cookie HttpOnly e validação CSRF. Seus dados são restritos ao vínculo concedido pela prestadora.</small>
    </section>
    <div className="login-art" aria-hidden="true">
      <div className="login-art-content"><Printer size={56} strokeWidth={1.4}/>
        <h2>Informação para quem precisa.</h2>
        <p>Inventário, IPs e contadores reais, sem acesso administrativo.</p>
      </div>
    </div>
  </main>;
}

function Shell({ identity, onLogout }: { identity: ClientIdentity; onLogout: () => Promise<void> }) {
  const [reportsOpen, setReportsOpen] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const canViewReports = identity.customer_permissions?.view_reports ?? true;
  const nav = (to: string, label: string, Icon: typeof LayoutDashboard) =>
    <NavLink end={to === '/'} to={to} className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}>
      <Icon size={18} aria-hidden="true"/>{label}
    </NavLink>;

  return <div className="layout client-layout">
    <aside className="sidebar customer-sidebar">
      <Link className="brand" to="/"><span className="brand-icon">N</span><span>NEXA <strong>CLIENTE</strong></span></Link>
      <div className="sidebar-company"><Building2 size={18}/><span><small>EMPRESA VINCULADA</small><strong>{identity.tenant.name}</strong></span></div>
      <nav aria-label="Navegação do cliente" className="client-navigation">
        {nav('/', 'Painel', LayoutDashboard)}
        {nav('/impressoras', 'Impressoras', Printer)}
        {nav('/chamados', 'Chamados', ClipboardList)}
        <div className="nav-group">
          <button type="button" className="nav-link nav-group-button" aria-expanded={reportsOpen}
            aria-controls="customer-reports-links" onClick={() => setReportsOpen(v => !v)}>
            <FileText size={18} aria-hidden="true"/> Relatórios <ChevronDown className={reportsOpen ? 'expanded' : ''} size={16}/>
          </button>
          {reportsOpen && <div id="customer-reports-links" className="nav-submenu">
            {canViewReports && nav('/contadores', 'Contadores', Activity)}
            {nav('/localizacoes', 'Localizações', MapPin)}
          </div>}
        </div>
        <div className="nav-group">
          <button type="button" className="nav-link nav-group-button" aria-expanded={settingsOpen}
            aria-controls="customer-settings-links" onClick={() => setSettingsOpen(v => !v)}>
            <Settings size={18} aria-hidden="true"/> Configurações <ChevronDown className={settingsOpen ? 'expanded' : ''} size={16}/>
          </button>
          {settingsOpen && <div id="customer-settings-links" className="nav-submenu">
            {nav('/minha-conta', 'Minha conta', UserRound)}
          </div>}
        </div>
      </nav>
      <div className="sidebar-user"><div className="avatar">{identity.user.name.charAt(0).toUpperCase()}</div>
        <span><strong>{identity.user.name}</strong><small>Cliente — somente leitura</small></span></div>
    </aside>
    <div className="workspace">
      <header className="topbar"><span>Portal do cliente <span className="crumb">/</span> {identity.tenant.name}</span>
        <button className="button ghost" onClick={() => void onLogout()}><LogOut size={17}/> Sair</button></header>
      <main className="content"><Routes>
        <Route path="/" element={<OverviewPage canViewReports={canViewReports}/>}/>
        <Route path="/impressoras" element={<PrintersPage/>}/>
        <Route path="/chamados" element={<TicketsPage/>}/>
        {canViewReports && <Route path="/contadores" element={<CountersPage/>}/>}
        <Route path="/localizacoes" element={<LocationsPage/>}/>
        <Route path="/minha-conta" element={<AccountPage identity={identity}/>}/>
        <Route path="*" element={<Navigate to="/" replace/>}/>
      </Routes></main>
    </div>
  </div>;
}

export function App() {
  const qc = useQueryClient();
  const [logoutError, setLogoutError] = useState<string | null>(null);
  const me = useQuery({
    queryKey: ['client-session'],
    queryFn: async (): Promise<ClientIdentity | null> => {
      try { return await api<ClientIdentity>('/auth/me', null); }
      catch (error) {
        if (error instanceof ApiError && error.status === 401) return null;
        throw error;
      }
    },
    retry: false,
    staleTime: Infinity,
    refetchOnWindowFocus: false,
  });

  async function login(values: Credentials) {
    const session = await api<ClientIdentity>('/auth/login', null, {
      method: 'POST', body: JSON.stringify(values),
    });
    resetCsrf();
    // A customer must never render an internal panel or old tenant's cached data.
    qc.removeQueries({ predicate: q => q.queryKey[0] !== 'client-session' });
    qc.setQueryData(['client-session'], session);
    setLogoutError(null);
  }

  async function logout() {
    try {
      await api<{ message: string }>('/auth/logout', null, { method: 'POST' });
      resetCsrf();
      qc.removeQueries({ predicate: q => q.queryKey[0] !== 'client-session' });
      qc.setQueryData(['client-session'], null);
      setLogoutError(null);
    } catch (error) {
      setLogoutError('Não foi possível encerrar a sessão: ' + readableError(error));
    }
  }

  if (window.location.pathname === '/ativar') return <ActivationPage />;

  if (me.isPending) return <main className="boot-state" role="status">Carregando portal do cliente...</main>;
  if (me.error) return <main className="blocked"><p className="error" role="alert">{readableError(me.error)}</p>
    <button className="button primary" onClick={() => void me.refetch()}>Tentar novamente</button></main>;
  if (!me.data) return <Login onLogin={login}/>;

  if (me.data.role !== 'customer' || me.data.customer_scope !== 'assigned') {
    return <main className="blocked"><div className="brand"><span className="brand-icon">N</span> NEXA CLIENTE</div>
      <h1>Acesso do cliente não habilitado</h1>
      <p>Somente contas do tipo cliente com vínculo explícito às empresas atendidas podem consultar este portal.
        Para funcionários e administradores, use o painel administrativo.</p>
      <button className="button primary" onClick={() => void logout()}>Sair com segurança</button>
    </main>;
  }
  return <BrowserRouter>
    {logoutError && <div role="alert" className="logout-error">{logoutError}</div>}
    <Shell identity={me.data} onLogout={logout}/>
  </BrowserRouter>;
}
