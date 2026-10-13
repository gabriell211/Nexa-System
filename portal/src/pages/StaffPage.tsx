import { useDeferredValue, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Search, ShieldCheck, UserRoundCog, X } from 'lucide-react';
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
      <label className="check-row"><input type="radio" name="scope" checked={scope === 'all'} onChange={() => setScope('all')}/> Todas as empresas clientes</label>
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

export function StaffPage({ token }: { token: string | null }) {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Member | null>(null);
  const [notice, setNotice] = useState('');
  const members = useQuery({
    queryKey: ['staff', page],
    queryFn: () => api<Page<Member>>('/staff' + queryString({ page }), token),
  });
  return <div>
    <div className="page-heading"><div><span className="eyebrow">GOVERNANÇA DE ACESSO</span>
      <h1>Equipe e carteiras</h1>
      <p>Defina quais clientes cada operador pode consultar ou administrar. Todas as mudanças são auditadas.</p>
    </div></div>
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
    {editing && <PortfolioDialog key={editing.id} member={editing} token={token} onClose={() => setEditing(null)}
      onSaved={async () => {
        await qc.invalidateQueries({queryKey: ['staff']});
        await qc.invalidateQueries({queryKey: ['staff-portfolio', editing.id]});
        setNotice('Carteira atualizada e auditada com sucesso.');
        setEditing(null);
      }}/>}
  </div>;
}
