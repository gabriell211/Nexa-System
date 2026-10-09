import { useDeferredValue, useState, type FormEvent, type ReactNode } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BrowserRouter, Link, NavLink, Navigate, Route, Routes } from 'react-router-dom';
import {
  Activity, ArrowRight, Building2, ChevronLeft, ChevronRight, ClipboardList,
  Database, LayoutDashboard, LogOut, Pencil, Plus, Printer as PrinterIcon,
  Search, ShieldCheck, Trash2, Users, X,
} from 'lucide-react';
import { api, queryString, readableError } from './api';
import { CustomerOrganizationPage } from './pages/CustomerOrganizationPage';
import type {
  AuditEntry, Credentials, CurrentUser, Customer, CustomerPayload, Dashboard,
  LoginResult, Page, Printer, PrinterPayload,
} from './types';

function formatDate(date: string): string {
  const parsed = new Date(date);
  return Number.isNaN(parsed.getTime()) ? '—' : parsed.toLocaleString('pt-BR');
}

function ErrorMessage({ error }: { error: unknown }) {
  return <p role="alert" className="notice error">{readableError(error)}</p>;
}

function LoadingState() {
  return <div className="loading" role="status">Carregando informações reais...</div>;
}

function EmptyState({ children }: { children: ReactNode }) {
  return <div className="empty-state"><Database size={30} aria-hidden="true" /><p>{children}</p></div>;
}

function Pagination({ page, pages, total, onChange }: {
  page: number; pages: number; total: number; onChange: (next: number) => void;
}) {
  return <div className="pagination">
    <span>{total} registro(s) • Página {page} de {Math.max(1, pages)}</span>
    <div className="inline">
      <button type="button" className="icon-button" onClick={() => onChange(page - 1)} disabled={page <= 1} aria-label="Página anterior">
        <ChevronLeft size={17} />
      </button>
      <button type="button" className="icon-button" onClick={() => onChange(page + 1)} disabled={page >= pages} aria-label="Próxima página">
        <ChevronRight size={17} />
      </button>
    </div>
  </div>;
}

function pageInfo<T>(response: Page<T> | undefined): { page: number; pages: number; total: number } {
  return {
    page: response?.meta?.current_page ?? response?.current_page ?? 1,
    pages: response?.meta?.last_page ?? response?.last_page ?? 1,
    total: response?.meta?.total ?? response?.total ?? 0,
  };
}

function Login({ onLogin }: { onLogin: (values: Credentials) => Promise<void> }) {
  const [credentials, setCredentials] = useState<Credentials>({ email: '', password: '', tenant: '' });
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    try {
      await onLogin(credentials);
    } catch (cause) {
      setError(readableError(cause));
    } finally {
      setLoading(false);
    }
  }

  return <main className="auth-page">
    <div className="auth-panel">
      <div className="brand-badge"><span className="brand-mark">N</span><span>NEXA <strong>SYSTEM</strong></span></div>
      <div className="auth-heading">
        <span className="eyebrow">PLATAFORMA DE GESTÃO MPS</span>
        <h1>Acesse sua operação.</h1>
        <p>Uma visão centralizada dos seus clientes, impressoras e informações operacionais.</p>
      </div>
      <form onSubmit={(event) => void submit(event)} className="auth-form">
        <label htmlFor="tenant">Identificador da empresa</label>
        <input id="tenant" required autoComplete="organization" value={credentials.tenant}
          onChange={(event) => setCredentials({ ...credentials, tenant: event.target.value })}
          placeholder="Ex.: minha-empresa" maxLength={100} />
        <label htmlFor="email">E-mail</label>
        <input id="email" type="email" required autoComplete="username" value={credentials.email}
          onChange={(event) => setCredentials({ ...credentials, email: event.target.value })}
          placeholder="seu@email.com" />
        <label htmlFor="password">Senha</label>
        <input id="password" type="password" required autoComplete="current-password" value={credentials.password}
          onChange={(event) => setCredentials({ ...credentials, password: event.target.value })} />
        {error && <p role="alert" className="notice error">{error}</p>}
        <button className="button primary login-action" type="submit" disabled={loading}>
          {loading ? 'Entrando...' : 'Entrar no painel'} <ArrowRight size={17} aria-hidden="true" />
        </button>
      </form>
      <p className="auth-caption">Acesso restrito a operadores autorizados. Tokens não são salvos no navegador.</p>
    </div>
    <div className="auth-visual" aria-hidden="true">
      <div className="visual-glow" />
      <div className="visual-content">
        <span className="pill">NEXA PLATFORM</span>
        <h2>Seu parque sob controle. Sua operação conectada.</h2>
        <p>Gestão centralizada com informações persistidas, permissões por empresa e histórico auditável.</p>
        <div className="visual-facts"><span><ShieldCheck size={20}/> Acesso isolado</span><span><Activity size={20}/> Operação rastreável</span></div>
      </div>
    </div>
  </main>;
}

const adminRoles = new Set(['owner', 'admin', 'manager']);
const internalRoles = new Set(['owner', 'admin', 'manager', 'supervisor', 'technician', 'finance', 'warehouse']);

function Shell({ token, identity, onLogout }: {
  token: string; identity: CurrentUser; onLogout: () => Promise<void>;
}) {
  const canWrite = adminRoles.has(identity.role);
  const canAudit = ['owner', 'admin'].includes(identity.role);

  if (!internalRoles.has(identity.role)) {
    return <main className="blocked">
      <ShieldCheck size={42} aria-hidden="true" />
      <h1>Área administrativa restrita</h1>
      <p>Seu perfil não possui acesso ao painel interno. O portal do cliente ainda está em desenvolvimento.</p>
      <button className="button primary" onClick={() => void onLogout()}>Sair com segurança</button>
    </main>;
  }

  return <div className="app-shell">
    <aside className="sidebar" aria-label="Menu lateral">
      <div className="brand-badge sidebar-brand"><span className="brand-mark">N</span><span>NEXA <strong>SYSTEM</strong></span></div>
      <div className="workspace">
        <span className="workspace-icon"><Building2 size={20} aria-hidden="true" /></span>
        <div><small>EMPRESA ATUAL</small><strong>{identity.tenant.name}</strong></div>
      </div>
      <nav aria-label="Navegação principal" className="sidebar-nav">
        <span className="nav-caption">OPERAÇÃO</span>
        <NavLink to="/dashboard" className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}><LayoutDashboard size={19}/> Visão geral</NavLink>
        <NavLink to="/clientes" className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}><Users size={19}/> Clientes</NavLink>
        <NavLink to="/parque" className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}><PrinterIcon size={19}/> Parque de impressoras</NavLink>
        {canAudit && <><span className="nav-caption nav-secondary">ADMINISTRAÇÃO</span>
          <NavLink to="/auditoria" className={({ isActive }) => 'nav-link' + (isActive ? ' active' : '')}><ClipboardList size={19}/> Auditoria</NavLink></>}
      </nav>
      <div className="sidebar-footer">
        <div className="profile-circle">{identity.user.name.slice(0, 1).toUpperCase()}</div>
        <div className="profile-text"><strong>{identity.user.name}</strong><small>{identity.role}</small></div>
        <button type="button" className="icon-button transparent" onClick={() => void onLogout()} title="Encerrar sessão" aria-label="Encerrar sessão">
          <LogOut size={19} aria-hidden="true" />
        </button>
      </div>
    </aside>
    <div className="content-area">
      <header className="topbar">
        <span className="topbar-breadcrumb">Nexa <span>/</span> Painel administrativo</span>
        <span className="system-status"><span className="status-dot" /> Dados da API</span>
      </header>
      <main className="main-content">
        <Routes>
          <Route path="/dashboard" element={<DashboardPage token={token} />} />
          <Route path="/clientes" element={<CustomersPage token={token} canWrite={canWrite} />} />
          <Route path="/clientes/:id/unidades" element={<CustomerOrganizationPage token={token} canWrite={canWrite} />} />
          <Route path="/parque" element={<PrintersPage token={token} canWrite={canWrite} />} />
          {canAudit && <Route path="/auditoria" element={<AuditPage token={token} />} />}
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </main>
    </div>
  </div>;
}

function PageTitle({ kicker, title, subtitle, action }: {
  kicker: string; title: string; subtitle: string; action?: ReactNode;
}) {
  return <div className="page-heading">
    <div><span className="eyebrow">{kicker}</span><h1>{title}</h1><p>{subtitle}</p></div>
    {action && <div className="page-actions">{action}</div>}
  </div>;
}

function DashboardPage({ token }: { token: string }) {
  const stats = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api<Dashboard>('/dashboard', token),
  });

  return <>
    <PageTitle kicker="CENTRAL DE OPERAÇÕES" title="Visão geral" subtitle="Indicadores consultados diretamente no servidor Nexa." />
    {stats.isPending ? <LoadingState /> : stats.error ? <ErrorMessage error={stats.error} /> : <>
      <div className="metrics-grid">
        <div className="metric-card"><div className="metric-icon blue"><Users size={23}/></div><span>Clientes cadastrados</span><strong>{stats.data.customers}</strong><small>Cadastros persistidos</small></div>
        <div className="metric-card"><div className="metric-icon violet"><PrinterIcon size={23}/></div><span>Impressoras cadastradas</span><strong>{stats.data.printers}</strong><small>Inventário da empresa</small></div>
        <div className="metric-card"><div className="metric-icon green"><Activity size={23}/></div><span>Com leituras registradas</span><strong>{stats.data.printers_with_readings}</strong><small>Amostras presentes no banco</small></div>
        <div className="metric-card"><div className="metric-icon blue"><Building2 size={23}/></div><span>Unidades cadastradas</span><strong>{stats.data.locations}</strong><small>Estrutura organizacional</small></div>
        <div className="metric-card"><div className="metric-icon violet"><Users size={23}/></div><span>Departamentos</span><strong>{stats.data.departments}</strong><small>Cadastros por unidade</small></div>
        <div className="metric-card"><div className="metric-icon green"><Database size={23}/></div><span>Centros de custo</span><strong>{stats.data.cost_centers}</strong><small>Cadastros por cliente</small></div>
      </div>
      <div className="info-panel"><ShieldCheck size={22} aria-hidden="true"/><div><strong>Integridade dos dados</strong><p>{stats.data.note}</p><p>Nenhum indicador sem coleta comprovada será exibido como telemetria em tempo real.</p></div></div>
    </>}
  </>;
}

function CustomerDialog({ customer, onClose, onSave, pending }: {
  customer: Customer | null;
  onClose: () => void;
  onSave: (values: CustomerPayload) => Promise<void>;
  pending: boolean;
}) {
  const [name, setName] = useState(customer?.name ?? '');
  const [document, setDocument] = useState(customer?.document ?? '');
  const [email, setEmail] = useState(customer?.email ?? '');
  const [active, setActive] = useState(customer?.active ?? true);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    try {
      await onSave({ name: name.trim(), document: document.trim() || null, email: email.trim() || null, active });
    } catch (cause) {
      setError(readableError(cause));
    }
  }

  return <div className="modal-overlay">
    <section role="dialog" aria-modal="true" aria-labelledby="customer-dialog-title" className="modal">
      <div className="modal-header"><div><span className="eyebrow">CADASTRO</span><h2 id="customer-dialog-title">{customer ? 'Editar cliente' : 'Novo cliente'}</h2></div>
        <button className="icon-button" type="button" aria-label="Fechar" onClick={onClose}><X size={20}/></button></div>
      <form onSubmit={(event) => void submit(event)} className="editor-form">
        <label htmlFor="customer-name">Nome / razão social <em>*</em></label>
        <input id="customer-name" autoFocus required minLength={2} maxLength={180} value={name} onChange={(event) => setName(event.target.value)} />
        <label htmlFor="customer-document">Documento</label>
        <input id="customer-document" maxLength={32} value={document} onChange={(event) => setDocument(event.target.value)} />
        <label htmlFor="customer-email">E-mail</label>
        <input id="customer-email" type="email" maxLength={255} value={email} onChange={(event) => setEmail(event.target.value)} />
        {customer && <label className="check-row"><input type="checkbox" checked={active} onChange={(event) => setActive(event.target.checked)}/> Cliente ativo</label>}
        {error && <p role="alert" className="notice error">{error}</p>}
        <div className="modal-actions"><button className="button ghost" type="button" onClick={onClose}>Cancelar</button>
          <button className="button primary" disabled={pending} type="submit">{pending ? 'Salvando...' : 'Salvar cliente'}</button></div>
      </form>
    </section>
  </div>;
}

function CustomersPage({ token, canWrite }: { token: string; canWrite: boolean }) {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const delayed = useDeferredValue(search);
  const [activeOnly, setActiveOnly] = useState(true);
  const [editing, setEditing] = useState<'new' | Customer | null>(null);
  const [notice, setNotice] = useState('');

  const listing = useQuery({
    queryKey: ['customers', page, delayed, activeOnly],
    queryFn: () => api<Page<Customer>>('/customers' + queryString({ page, q: delayed, active_only: activeOnly }), token),
    placeholderData: keepPreviousData,
  });

  const save = useMutation({
    mutationFn: (values: CustomerPayload) => api(
      '/customers' + (editing && editing !== 'new' ? '/' + editing.id : ''), token,
      { method: editing === 'new' ? 'POST' : 'PATCH', body: JSON.stringify(values) },
    ),
    onSuccess: async () => {
      setEditing(null); setNotice('Cliente salvo com sucesso.');
      await qc.invalidateQueries({ queryKey: ['customers'] });
      await qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });

  const deactivate = useMutation({
    mutationFn: (id: number) => api<void>('/customers/' + id, token, { method: 'DELETE' }),
    onSuccess: async () => {
      setNotice('Cliente inativado, sem exclusão do histórico.');
      await qc.invalidateQueries({ queryKey: ['customers'] });
    },
  });

  function remove(item: Customer) {
    if (window.confirm('Inativar "' + item.name + '"? O histórico será preservado.')) {
      deactivate.mutate(item.id);
    }
  }

  const pagination = pageInfo(listing.data);
  return <>
    <PageTitle kicker="CARTEIRA DE CLIENTES" title="Clientes" subtitle="Cadastro, manutenção e histórico das empresas atendidas."
      action={canWrite && <button type="button" className="button primary" onClick={() => setEditing('new')}><Plus size={17}/> Novo cliente</button>} />
    <section className="data-card">
      <div className="table-tools"><label className="search-field"><Search size={18} aria-hidden="true"/>
        <input type="search" aria-label="Pesquisar clientes" placeholder="Buscar por nome..." value={search}
          onChange={(event) => { setSearch(event.target.value); setPage(1); }} maxLength={100}/></label>
        <label className="check-row"><input type="checkbox" checked={activeOnly} onChange={(event) => {setActiveOnly(event.target.checked);setPage(1);}}/> Somente ativos</label>
      </div>
      {notice && <p role="status" className="notice success">{notice}</p>}
      {deactivate.error && <ErrorMessage error={deactivate.error}/>}
      {listing.isPending ? <LoadingState/> : listing.error ? <ErrorMessage error={listing.error}/> : listing.data.data.length === 0 ? <EmptyState>Nenhum cliente encontrado para os filtros atuais.</EmptyState> : <>
        <div className="table-wrap"><table><thead><tr><th>Cliente</th><th>Documento</th><th>E-mail</th><th>Situação</th>{canWrite && <th className="actions-head">Ações</th>}</tr></thead>
          <tbody>{listing.data.data.map((customer) => <tr key={customer.id}>
            <td><strong><Link className="entity-link" to={'/clientes/' + customer.id + '/unidades'} title="Ver unidades, departamentos e centros de custo">{customer.name}</Link></strong><small>#{customer.id} • Ver unidades e centros de custo</small></td><td>{customer.document || '—'}</td>
            <td>{customer.email || '—'}</td><td><span className={'state-pill ' + (customer.active ? 'online' : 'neutral')}>{customer.active ? 'Ativo' : 'Inativo'}</span></td>
            {canWrite && <td><div className="table-actions"><button className="icon-button" title="Editar cliente" aria-label={'Editar ' + customer.name} onClick={() => setEditing(customer)}><Pencil size={16}/></button>
              {customer.active && <button className="icon-button danger-icon" title="Inativar cliente" aria-label={'Inativar ' + customer.name}
                onClick={() => remove(customer)} disabled={deactivate.isPending}><Trash2 size={16}/></button>}</div></td>}
          </tr>)}</tbody></table></div>
        <Pagination page={pagination.page} pages={pagination.pages} total={pagination.total} onChange={setPage}/>
      </>}
    </section>
    {editing !== null && <CustomerDialog key={editing === 'new' ? 'new' : editing.id} customer={editing === 'new' ? null : editing}
      pending={save.isPending} onClose={() => setEditing(null)}
      onSave={async (values) => { await save.mutateAsync(values); }}/>}
  </>;
}

function PrinterDialog({ printer, customers, onClose, onSave, pending }: {
  printer: Printer | null; customers: Customer[];
  onClose: () => void;
  onSave: (values: PrinterPayload) => Promise<void>;
  pending: boolean;
}) {
  const [customerId, setCustomerId] = useState(String(printer?.customer_id ?? ''));
  const [manufacturer, setManufacturer] = useState(printer?.manufacturer ?? '');
  const [model, setModel] = useState(printer?.model ?? '');
  const [serial, setSerial] = useState(printer?.serial_number ?? '');
  const [ip, setIp] = useState(printer?.ip_address ?? '');
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    if (!Number.isSafeInteger(Number(customerId)) || Number(customerId) <= 0) {
      setError('Selecione um cliente válido.'); return;
    }
    try {
      await onSave({
        customer_id: Number(customerId),
        manufacturer: manufacturer.trim(),
        model: model.trim(),
        serial_number: serial.trim() || null,
        ip_address: ip.trim() || null,
      });
    } catch (cause) { setError(readableError(cause)); }
  }

  const options = customers.some((customer) => customer.id === printer?.customer_id)
    ? customers
    : printer?.customer ? [...customers, { id: printer.customer.id, name: printer.customer.name, active: false } as Customer] : customers;

  return <div className="modal-overlay"><section className="modal" role="dialog" aria-modal="true" aria-labelledby="printer-dialog-title">
    <div className="modal-header"><div><span className="eyebrow">EQUIPAMENTO</span><h2 id="printer-dialog-title">{printer ? 'Editar impressora' : 'Cadastrar impressora'}</h2></div>
      <button className="icon-button" type="button" onClick={onClose} aria-label="Fechar"><X size={20}/></button></div>
    <form className="editor-form" onSubmit={(event) => void submit(event)}>
      <label htmlFor="printer-customer">Cliente <em>*</em></label>
      <select id="printer-customer" required value={customerId} onChange={(event) => setCustomerId(event.target.value)}>
        <option value="">Selecione um cliente</option>
        {options.map((customer) => <option key={customer.id} value={customer.id} disabled={!customer.active && customer.id !== printer?.customer_id}>{customer.name}</option>)}
      </select>
      <div className="form-columns"><div><label htmlFor="printer-brand">Fabricante <em>*</em></label>
        <input id="printer-brand" required maxLength={100} value={manufacturer} onChange={(event) => setManufacturer(event.target.value)}/></div>
        <div><label htmlFor="printer-model">Modelo <em>*</em></label>
          <input id="printer-model" required maxLength={160} value={model} onChange={(event) => setModel(event.target.value)}/></div></div>
      <div className="form-columns"><div><label htmlFor="printer-serial">Número de série</label>
        <input id="printer-serial" maxLength={160} value={serial} onChange={(event) => setSerial(event.target.value)}/></div>
        <div><label htmlFor="printer-ip">IP</label><input id="printer-ip" value={ip} placeholder="192.168.1.20" onChange={(event) => setIp(event.target.value)}/></div></div>
      {error && <p className="notice error" role="alert">{error}</p>}
      <div className="modal-actions"><button type="button" className="button ghost" onClick={onClose}>Cancelar</button>
        <button type="submit" className="button primary" disabled={pending}>{pending ? 'Salvando...' : 'Salvar impressora'}</button></div>
    </form>
  </section></div>;
}

function PrintersPage({ token, canWrite }: { token: string; canWrite: boolean }) {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const delayed = useDeferredValue(search);
  const [activeOnly, setActiveOnly] = useState(true);
  const [editing, setEditing] = useState<'new' | Printer | null>(null);
  const [notice, setNotice] = useState('');

  const listing = useQuery({
    queryKey: ['printers', page, delayed, activeOnly],
    queryFn: () => api<Page<Printer>>('/printers' + queryString({ page, q: delayed, active_only: activeOnly }), token),
    placeholderData: keepPreviousData,
  });
  const customers = useQuery({
    queryKey: ['customer-options'],
    queryFn: () => api<Page<Customer>>('/customers' + queryString({ active_only: true, per_page: 100 }), token),
    enabled: canWrite && editing !== null,
  });

  const save = useMutation({
    mutationFn: (values: PrinterPayload) => {
      const payload = editing && editing !== 'new' && values.customer_id === editing.customer_id
        ? { manufacturer: values.manufacturer, model: values.model,
            serial_number: values.serial_number, ip_address: values.ip_address }
        : values;
      return api(
        '/printers' + (editing && editing !== 'new' ? '/' + editing.id : ''), token,
        { method: editing === 'new' ? 'POST' : 'PATCH', body: JSON.stringify(payload) },
      );
    },
    onSuccess: async () => {
      setEditing(null); setNotice('Impressora salva com sucesso.');
      await qc.invalidateQueries({ queryKey: ['printers'] });
      await qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
  const deactivate = useMutation({
    mutationFn: (id: number) => api<void>('/printers/' + id, token, { method: 'DELETE' }),
    onSuccess: async () => {
      setNotice('Impressora inativada, sem remover leituras anteriores.');
      await qc.invalidateQueries({ queryKey: ['printers'] });
    },
  });

  function remove(item: Printer) {
    if (window.confirm('Inativar ' + item.manufacturer + ' ' + item.model + '? O histórico será preservado.')) deactivate.mutate(item.id);
  }
  const pagination = pageInfo(listing.data);

  return <>
    <PageTitle kicker="GESTÃO DO PARQUE" title="Impressoras" subtitle="Inventário dos equipamentos vinculados aos clientes."
      action={canWrite && <button className="button primary" type="button" onClick={() => setEditing('new')}><Plus size={17}/> Nova impressora</button>} />
    <section className="data-card">
      <div className="table-tools"><label className="search-field"><Search size={18} aria-hidden="true"/>
        <input type="search" aria-label="Pesquisar impressoras" value={search} maxLength={100}
          placeholder="Fabricante, modelo ou série..." onChange={(event) => { setSearch(event.target.value); setPage(1); }}/></label>
        <label className="check-row"><input type="checkbox" checked={activeOnly} onChange={(event) => {setActiveOnly(event.target.checked);setPage(1);}}/> Somente ativas</label>
      </div>
      {notice && <p role="status" className="notice success">{notice}</p>}
      {deactivate.error && <ErrorMessage error={deactivate.error}/>}
      {listing.isPending ? <LoadingState/> : listing.error ? <ErrorMessage error={listing.error}/> : listing.data.data.length === 0 ? <EmptyState>Nenhuma impressora encontrada para estes filtros.</EmptyState> : <>
        <div className="table-wrap"><table><thead><tr><th>Impressora</th><th>Cliente</th><th>IP</th><th>Estado de coleta</th><th>Cadastro</th>{canWrite && <th className="actions-head">Ações</th>}</tr></thead>
          <tbody>{listing.data.data.map((printer) => <tr key={printer.id}>
            <td><strong>{printer.manufacturer} {printer.model}</strong><small>{printer.serial_number || 'Série não informada'}</small></td>
            <td>{printer.customer?.name || '—'}</td><td className="mono">{printer.ip_address || '—'}</td>
            <td><span className="state-pill neutral">{printer.status === 'unknown' ? 'Não determinado' : printer.status}</span></td>
            <td><span className={'state-pill ' + (printer.active ? 'online' : 'neutral')}>{printer.active ? 'Ativo' : 'Inativo'}</span></td>
            {canWrite && <td><div className="table-actions"><button className="icon-button" title="Editar impressora" aria-label={'Editar impressora ' + printer.id} onClick={() => setEditing(printer)}><Pencil size={16}/></button>
              {printer.active && <button className="icon-button danger-icon" title="Inativar impressora" aria-label={'Inativar impressora ' + printer.id}
                disabled={deactivate.isPending} onClick={() => remove(printer)}><Trash2 size={16}/></button>}</div></td>}
          </tr>)}</tbody></table></div>
        <Pagination page={pagination.page} pages={pagination.pages} total={pagination.total} onChange={setPage}/>
      </>}
    </section>
    {editing && (customers.isPending ? <div className="modal-overlay"><div className="modal"><LoadingState/></div></div> :
      customers.error ? <div className="modal-overlay"><div className="modal"><ErrorMessage error={customers.error}/><button className="button ghost" onClick={() => setEditing(null)}>Fechar</button></div></div> :
      <PrinterDialog key={editing === 'new' ? 'new' : editing.id}
        printer={editing === 'new' ? null : editing} customers={customers.data?.data ?? []}
        onClose={() => setEditing(null)} pending={save.isPending}
        onSave={async (values) => { await save.mutateAsync(values); }}/>)}
  </>;
}

function AuditPage({ token }: { token: string }) {
  const [page, setPage] = useState(1);
  const listing = useQuery({
    queryKey: ['audit', page],
    queryFn: () => api<Page<AuditEntry>>('/audit-entries' + queryString({ page }), token),
    placeholderData: keepPreviousData,
  });
  const pagination = pageInfo(listing.data);

  return <>
    <PageTitle kicker="GOVERNANÇA E CONTROLE" title="Histórico de auditoria" subtitle="Mudanças realizadas nos cadastros administrativos, conforme registros persistidos." />
    <section className="data-card">
      {listing.isPending ? <LoadingState/> : listing.error ? <ErrorMessage error={listing.error}/> :
        listing.data.data.length === 0 ? <EmptyState>Nenhum evento de auditoria registrado.</EmptyState> :
        <><div className="table-wrap"><table><thead><tr><th>Evento</th><th>Entidade</th><th>Operador</th><th>Data e hora</th></tr></thead><tbody>
          {listing.data.data.map((entry) => <tr key={entry.id}><td><strong>{entry.action}</strong><small>{entry.origin}</small></td>
            <td>{entry.entity_type} #{entry.entity_id}</td><td>{entry.actor?.name ?? 'Não identificado'}</td><td>{formatDate(entry.created_at)}</td></tr>)}
        </tbody></table></div><Pagination page={pagination.page} pages={pagination.pages} total={pagination.total} onChange={setPage}/></>}
    </section>
  </>;
}

function PrivatePortal({ session, onLogout }: { session: LoginResult; onLogout: () => Promise<void> }) {
  const identity = useQuery({
    queryKey: ['current-user', session.tenant.id],
    queryFn: () => api<CurrentUser>('/auth/me', session.token),
    retry: false,
  });

  if (identity.isPending) return <div className="boot-state"><LoadingState/></div>;
  if (identity.error) return <main className="blocked"><ErrorMessage error={identity.error}/>
    <button className="button primary" onClick={() => void onLogout()}>Voltar ao login</button></main>;
  return <Shell token={session.token} identity={identity.data} onLogout={onLogout}/>;
}

export function App() {
  const qc = useQueryClient();
  const [session, setSession] = useState<LoginResult | null>(null);

  async function login(values: Credentials) {
    const result = await api<LoginResult>('/auth/login', null, {
      method: 'POST', body: JSON.stringify(values),
    });
    qc.clear();
    setSession(result);
  }

  async function logout() {
    try {
      if (session) await api('/auth/logout', session.token, { method: 'POST' });
    } catch {
      // Local logout must work even while the API is unavailable.
    } finally {
      qc.clear();
      setSession(null);
    }
  }

  if (!session) return <Login onLogin={login}/>;

  return <BrowserRouter><PrivatePortal session={session} onLogout={logout}/></BrowserRouter>;
}
