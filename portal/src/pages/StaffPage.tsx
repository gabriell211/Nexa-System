import { useDeferredValue, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Copy, Plus, Search, ShieldCheck, UserRoundCog, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Customer, Page } from '../types';

interface Member {
  id: number; name: string; email: string; role: string;
  active: boolean; customer_scope: 'all' | 'assigned';
}
interface Detail {
  user: { id: number; name: string; email: string };
  role: string;
  scope: 'all' | 'assigned';
  customer_ids: number[];
}
interface AccessResult { scope: 'all' | 'assigned'; customer_ids: number[] }

function Paging({ data, onChange }: { data: Page<unknown> | undefined; onChange: (page: number) => void }) {
  const page = data?.meta?.current_page ?? data?.current_page ?? 1;
  const last = data?.meta?.last_page ?? data?.last_page ?? 1;
  return <div className="pagination"><span>Página {page} de {last}</span><div className="inline">
    <button className="icon-button" disabled={page <= 1} onClick={() => onChange(page - 1)} aria-label="Página anterior"><ChevronLeft size={17}/></button>
    <button className="icon-button" disabled={page >= last} onClick={() => onChange(page + 1)} aria-label="Próxima página"><ChevronRight size={17}/></button>
  </div></div>;
}

function PortfolioForm({ initial, token, onSaved, onClose }: {
  initial: Detail; token: string | null; onSaved: () => Promise<void>; onClose: () => void;
}) {
  const [scope, setScope] = useState(initial.scope);
  const [selected, setSelected] = useState<number[]>(initial.customer_ids);
  const [search, setSearch] = useState('');
  const delayed = useDeferredValue(search);
  const [page, setPage] = useState(1);

  const customers = useQuery({
    queryKey: ['portfolio-customers', delayed, page],
    queryFn: () => api<Page<Customer>>('/customers' + queryString({ q: delayed, page, per_page: 25 }), token),
    placeholderData: (old: Page<Customer> | undefined) => old,
  });
  const save = useMutation({
    mutationFn: () => api<AccessResult>('/staff/' + initial.user.id + '/portfolio', token, {
      method: 'PUT',
      body: JSON.stringify({
        scope, customer_ids: scope === 'assigned' ? selected : [],
      }),
    }),
    onSuccess: async () => { await onSaved(); },
  });

  function toggle(id: number, checked: boolean) {
    setSelected(current => checked
      ? current.includes(id) ? current : [...current, id]
      : current.filter(item => item !== id)
    );
  }

  function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    save.mutate();
  }

  return <form className="editor-form" onSubmit={submit}>
    <div className="portfolio-options">
      <label className="check-row"><input type="radio" name="scope" checked={scope === 'all'} disabled={initial.role === 'customer'} onChange={() => setScope('all')}/> Todas as empresas clientes</label>
      <label className="check-row"><input type="radio" name="scope" checked={scope === 'assigned'} onChange={() => setScope('assigned')}/> Somente clientes selecionados</label>
    </div>
    {scope === 'assigned' && <>
      <p className="portfolio-info">{selected.length} cliente(s) atribuído(s). Se nenhum for selecionado, o acesso ficará vazio.</p>
      <label className="search-field"><Search size={17}/>
        <input type="search" value={search} aria-label="Localizar clientes" placeholder="Buscar clientes"
          maxLength={100} onChange={e => {setSearch(e.target.value);setPage(1);}}/>
      </label>
      {customers.isPending ? <p>Buscando clientes...</p> : customers.error ?
        <p role="alert" className="notice error">{readableError(customers.error)}</p> :
        <div className="portfolio-customer-list">
          {customers.data.data.length === 0 && <p>Nenhum cliente encontrado.</p>}
          {customers.data.data.map(customer => <label className="portfolio-customer" key={customer.id}>
            <input type="checkbox" checked={selected.includes(customer.id)}
              disabled={!selected.includes(customer.id) && selected.length >= 200}
              onChange={e => toggle(customer.id, e.target.checked)}/>
            <span>{customer.name}<small>#{customer.id}</small></span>
          </label>)}
          <Paging data={customers.data} onChange={setPage}/>
        </div>}
    </>}
    {save.error && <p className="notice error" role="alert">{readableError(save.error)}</p>}
    <div className="modal-actions">
      <button className="button ghost" type="button" onClick={onClose}>Cancelar</button>
      <button className="button primary" disabled={save.isPending || (scope === 'assigned' && selected.length > 200)}
        type="submit">{save.isPending ? 'Salvando...' : 'Salvar permissões'}</button>
    </div>
  </form>;
}

function PortfolioDialog({ member, token, onClose, onSaved }: {
  member: Member; token: string | null; onClose: () => void; onSaved: () => Promise<void>;
}) {
  const detail = useQuery({
    queryKey: ['staff-portfolio', member.id],
    queryFn: () => api<Detail>('/staff/' + member.id + '/portfolio', token),
  });
  return <div className="modal-overlay"><section role="dialog" aria-modal="true" className="modal" aria-labelledby="portfolio-title">
    <div className="modal-header"><div><span className="eyebrow">CARTEIRA DE CLIENTES</span>
      <h2 id="portfolio-title">Permissões: {member.name}</h2>
      <p className="modal-subtitle">{member.role} • {member.email}</p></div>
      <button className="icon-button" type="button" onClick={onClose} aria-label="Fechar"><X size={20}/></button>
    </div>
    {detail.isPending ? <div className="loading">Carregando permissões...</div> :
      detail.error ? <p className="notice error" role="alert">{readableError(detail.error)}</p> :
      <PortfolioForm initial={detail.data} token={token} onSaved={onSaved} onClose={onClose}/>}
  </section></div>;
}


interface CreatedInvite {
  user: {id: number; name: string; email: string; active: boolean};
  role: string;
  invitation: { token: string; tenant: string; expires_at: string };
  note: string;
}

function InviteAccountDialog({ token, onClose, onCreated }: {
  token: string | null; onClose: () => void; onCreated: () => Promise<void>;
}) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [type, setType] = useState<'customer' | 'collaborator'>('customer');
  const [search, setSearch] = useState('');
  const delayed = useDeferredValue(search);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<number[]>([]);
  const [reports, setReports] = useState(true);
  const [otherTickets, setOtherTickets] = useState(false);
  const [created, setCreated] = useState<CreatedInvite | null>(null);
  const [copyError, setCopyError] = useState('');
  const clients = useQuery({
    queryKey: ['account-invite-customers', delayed, page],
    queryFn: () => api<Page<Customer>>('/customers' + queryString({
      q: delayed, page, per_page: 25, active_only: true,
    }), token),
  });
  const save = useMutation({
    mutationFn: () => api<CreatedInvite>('/accounts', token, {
      method: 'POST',
      body: JSON.stringify({
        name: name.trim(), email: email.trim(),
        account_type: type, customer_ids: selected,
        ...(type === 'customer' ? {view_reports: reports, view_other_tickets: otherTickets} : {}),
      }),
    }),
    onSuccess: async result => { setCreated(result); await onCreated(); },
  });
  const invitationLink = created
    ? window.location.origin + '/ativar#' + new URLSearchParams({
      tenant: created.invitation.tenant, token: created.invitation.token,
    }).toString()
    : '';

  function toggleClient(id: number, checked: boolean) {
    setSelected(current => checked ? current.includes(id) ? current : [...current, id]
      : current.filter(value => value !== id));
  }
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (selected.length === 0) return;
    save.mutate();
  }

  return <div className="modal-overlay">
    <section className="modal account-invite-modal" role="dialog" aria-modal="true" aria-labelledby="invite-title">
      <div className="modal-header"><div><span className="eyebrow">CONTAS DE ACESSO</span>
        <h2 id="invite-title">{created ? 'Convite criado' : 'Adicionar conta'}</h2>
        <p className="modal-subtitle">Defina o tipo, a carteira de clientes e o acesso inicial.</p></div>
        <button className="icon-button" type="button" onClick={onClose} aria-label="Fechar"><X size={20}/></button>
      </div>
      {created ? <div className="editor-form">
        <p role="status" className="notice success">Conta de {created.user.name} registrada, ainda desativada. Copie o convite abaixo e envie por um canal seguro.</p>
        <label htmlFor="invitation-link">Link individual de ativação (24 horas, uso único)</label>
        <input id="invitation-link" readOnly value={invitationLink}
          onFocus={e => e.target.select()} aria-label="Link de ativação"/>
        <button className="button primary" type="button" onClick={() => {
          setCopyError('');
          void navigator.clipboard.writeText(invitationLink)
            .catch(() => setCopyError('Não foi possível copiar automaticamente. Selecione e copie o campo.'));
        }}><Copy size={16}/> Copiar convite</button>
        {copyError && <p role="alert" className="notice error">{copyError}</p>}
        <p className="portfolio-info">Nenhuma mensagem foi enviada automaticamente. O usuário escolherá uma senha forte no link de ativação.</p>
        <div className="modal-actions"><button className="button ghost" onClick={onClose}>Concluir</button></div>
      </div> : <form className="editor-form" onSubmit={submit}>
        <label htmlFor="invite-name">Nome</label>
        <input id="invite-name" required minLength={2} maxLength={180} autoComplete="name" value={name}
          onChange={e => setName(e.target.value)} placeholder="Nome completo"/>
        <label htmlFor="invite-email">E-mail</label>
        <input id="invite-email" type="email" required maxLength={255} autoComplete="email"
          value={email} onChange={e => setEmail(e.target.value)}/>
        <div className="portfolio-options" role="group" aria-label="Tipo de conta">
          <label className="check-row"><input type="radio" name="account_type" checked={type === 'customer'}
            onChange={() => setType('customer')}/> Conta de cliente</label>
          <label className="check-row"><input type="radio" name="account_type" checked={type === 'collaborator'}
            onChange={() => setType('collaborator')}/> Conta de colaborador</label>
        </div>
        <p className="portfolio-info">{type === 'customer'
          ? 'Cliente: somente portal do cliente, com acesso apenas às empresas selecionadas.'
          : 'Colaborador: papel de técnico, sujeito às permissões internas e restrito à carteira selecionada.'}</p>
        <label htmlFor="invite-search">Clientes autorizados ({selected.length})</label>
        <label className="search-field"><Search size={17}/>
          <input id="invite-search" value={search} placeholder="Pesquisar cliente ou unidade"
            onChange={e => {setSearch(e.target.value);setPage(1);}}/>
        </label>
        <div className="portfolio-customer-list">
          {clients.isPending ? <p role="status">Buscando clientes...</p> : clients.error ?
            <p role="alert" className="notice error">{readableError(clients.error)}</p> :
            <>{clients.data.data.map(client => <label className="portfolio-customer" key={client.id}>
              <input type="checkbox" checked={selected.includes(client.id)}
                disabled={!selected.includes(client.id) && selected.length >= 200}
                onChange={e => toggleClient(client.id, e.target.checked)}/>
              <span>{client.name}<small>#{client.id}</small></span>
            </label>)}<Paging data={clients.data} onChange={setPage}/></>}
        </div>
        {type === 'customer' && <fieldset className="account-permissions">
          <legend>Permissões do cliente</legend>
          <label className="check-row"><input type="checkbox" checked={reports} onChange={e => setReports(e.target.checked)}/>
            Visualizar contadores, gráficos e relatórios de páginas</label>
          <label className="check-row"><input type="checkbox" checked={otherTickets}
            onChange={e => setOtherTickets(e.target.checked)}/>
            Visualizar chamados abertos por outros usuários dos clientes autorizados</label>
          <p className="portfolio-info">Outras permissões e notificações exigem endpoints e regras próprios; não são habilitadas por este cadastro.</p>
        </fieldset>}
        {save.error && <p role="alert" className="notice error">{readableError(save.error)}</p>}
        <div className="modal-actions">
          <button className="button ghost" type="button" onClick={onClose}>Cancelar</button>
          <button className="button primary" type="submit" disabled={save.isPending || selected.length === 0}>
            <Plus size={16}/> {save.isPending ? 'Criando...' : 'Criar e gerar convite'}
          </button>
        </div>
      </form>}
    </section>
  </div>;
}

export function StaffPage({ token }: { token: string | null }) {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Member | null>(null);
  const [inviting, setInviting] = useState(false);
  const [notice, setNotice] = useState('');
  const members = useQuery({
    queryKey: ['staff', page],
    queryFn: () => api<Page<Member>>('/staff' + queryString({ page }), token),
  });
  return <div>
    <div className="page-heading"><div><span className="eyebrow">GOVERNANÇA DE ACESSO</span>
      <h1>Contas de acesso</h1>
      <p>Convide clientes e colaboradores, atribua carteiras e consulte as permissões auditadas.</p>
    </div><button className="button primary" type="button" onClick={() => setInviting(true)}>
      <Plus size={17}/> Adicionar conta
    </button></div>
    <section className="data-card">
      {notice && <p role="status" className="notice success">{notice}</p>}
      {members.isPending ? <div className="loading">Carregando operadores...</div> :
        members.error ? <p role="alert" className="notice error">{readableError(members.error)}</p> :
        <><div className="table-wrap"><table><thead><tr><th>Operador</th><th>Perfil</th><th>Carteira</th><th>Situação</th><th>Ação</th></tr></thead>
        <tbody>{members.data.data.map(member => <tr key={member.id}>
          <td><strong>{member.name}</strong><small>{member.email}</small></td>
          <td>{member.role}</td>
          <td>{['owner', 'admin'].includes(member.role) || member.customer_scope === 'all' ? 'Todos os clientes' : 'Carteira atribuída'}</td>
          <td><span className={'state-pill ' + (member.active ? 'online' : 'neutral')}>{member.active ? 'Ativo' : 'Inativo'}</span></td>
          <td>{['owner', 'admin'].includes(member.role) ? <span className="portfolio-readonly"><ShieldCheck size={15}/> Acesso integral</span> :
            <button className="button ghost" onClick={() => setEditing(member)}><UserRoundCog size={16}/> Configurar</button>}</td>
        </tr>)}</tbody></table></div><Paging data={members.data} onChange={setPage}/></>}
    </section>
    {inviting && <InviteAccountDialog token={token} onClose={() => setInviting(false)}
      onCreated={async () => {await qc.invalidateQueries({queryKey: ['staff']});}}/>}
    {editing && <PortfolioDialog key={editing.id} member={editing} token={token} onClose={() => setEditing(null)}
      onSaved={async () => {
        await qc.invalidateQueries({queryKey: ['staff']});
        await qc.invalidateQueries({queryKey: ['staff-portfolio', editing.id]});
        setNotice('Carteira atualizada e auditada com sucesso.');
        setEditing(null);
      }}/>}
  </div>;
}
