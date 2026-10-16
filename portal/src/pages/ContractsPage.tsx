import { useDeferredValue, useState, type FormEvent } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, Pencil, Plus, Search, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import type { Customer, Page } from '../types';

interface Contract {
  id: number; customer_id: number; customer_name: string; document_number: string;
  title: string; provider_unit_name: string; billing_address: string;
  billing_timezone: string; effective_on: string; billing_starts_on: string;
  ends_on: string | null; active: boolean; is_default: boolean; version: number;
  notes: string | null;
}
interface Cycle { starts_on: string; ends_on: string; scheduled_ends_on: string }
type Fields = Omit<Contract, 'id' | 'customer_name' | 'version' | 'notes' | 'ends_on'> &
  { ends_on: string; notes: string };

const blank: Fields = {
  customer_id: 0, document_number: '', title: '', provider_unit_name: '',
  billing_address: '', billing_timezone: 'America/Sao_Paulo',
  effective_on: '', billing_starts_on: '', ends_on: '', active: true,
  is_default: false, notes: '',
};

function localToday(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function ContractEditor({ contract, pending, onClose, onSave }: {
  contract: Contract | null; pending: boolean;
  onClose: () => void; onSave: (fields: Fields) => Promise<void>;
}) {
  const [fields, setFields] = useState<Fields>(() => contract ? {
    customer_id: contract.customer_id, document_number: contract.document_number,
    title: contract.title, provider_unit_name: contract.provider_unit_name,
    billing_address: contract.billing_address, billing_timezone: contract.billing_timezone,
    effective_on: contract.effective_on, billing_starts_on: contract.billing_starts_on,
    ends_on: contract.ends_on ?? '', active: contract.active,
    is_default: contract.is_default, notes: contract.notes ?? '',
  } : blank);
  const [error, setError] = useState<string | null>(null);
  function set<K extends keyof Fields>(key: K, value: Fields[K]) {
    setFields(previous => ({ ...previous, [key]: value }));
  }
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setError(null);
    try { await onSave(fields); }
    catch (cause) { setError(readableError(cause)); }
  }
  return <div className="modal-overlay" role="presentation">
    <section className="modal contract-modal" role="dialog" aria-modal="true" aria-label={contract ? 'Editar contrato' : 'Novo contrato'}>
      <div className="modal-header"><div><span className="eyebrow">FINANCEIRO</span><h2>{contract ? 'Editar contrato' : 'Novo contrato'}</h2></div>
        <button type="button" className="icon-button" onClick={onClose} aria-label="Fechar"><X size={18}/></button></div>
      <form className="editor-form" onSubmit={event => void submit(event)}>
        {contract ? <p>Cliente: <strong>{contract.customer_name}</strong></p> :
          <PagedEntitySelect<Customer> id="contract-customer" label="Cliente" searchLabel="Buscar cliente"
            placeholder="Buscar cliente ativo..." emptyLabel="Selecione um cliente" value={fields.customer_id ? String(fields.customer_id) : ''}
            onChange={value => set('customer_id', Number(value))} queryKey={['contract-customer-options']}
            endpoint="/customers" filters={{ active_only: true }} optionLabel={item => item.name} required />}
        <div className="form-columns">
          <div><label htmlFor="contract-number">Número do documento</label><input id="contract-number" required maxLength={80}
            value={fields.document_number} onChange={e => set('document_number', e.target.value)} /></div>
          <div><label htmlFor="contract-title">Identificação</label><input id="contract-title" required maxLength={180}
            value={fields.title} onChange={e => set('title', e.target.value)} /></div>
          <div><label htmlFor="contract-provider">Unidade prestadora</label><input id="contract-provider" required maxLength={180}
            value={fields.provider_unit_name} onChange={e => set('provider_unit_name', e.target.value)} /></div>
          <div><label htmlFor="contract-timezone">Fuso de cobrança</label><input id="contract-timezone" required maxLength={64}
            value={fields.billing_timezone} onChange={e => set('billing_timezone', e.target.value)} /></div>
          <div><label htmlFor="contract-effective">Início da vigência</label><input id="contract-effective" type="date" required
            value={fields.effective_on} onChange={e => set('effective_on', e.target.value)} /></div>
          <div><label htmlFor="contract-billing">Início do faturamento</label><input id="contract-billing" type="date" required
            value={fields.billing_starts_on} onChange={e => set('billing_starts_on', e.target.value)} /></div>
          <div><label htmlFor="contract-end">Fim da vigência, se houver</label><input id="contract-end" type="date"
            value={fields.ends_on} onChange={e => set('ends_on', e.target.value)} /></div>
        </div>
        <label htmlFor="contract-address">Local de cobrança</label><input id="contract-address" required maxLength={500}
          value={fields.billing_address} onChange={e => set('billing_address', e.target.value)} />
        <label htmlFor="contract-notes">Observações</label><input id="contract-notes" maxLength={500}
          value={fields.notes} onChange={e => set('notes', e.target.value)} />
        <label className="check-row"><input type="checkbox" checked={fields.active}
          onChange={e => set('active', e.target.checked)} /> Contrato ativo</label>
        <label className="check-row"><input type="checkbox" checked={fields.is_default} disabled={!fields.active}
          onChange={e => set('is_default', e.target.checked)} /> Padrão deste cliente</label>
        {error && <p role="alert" className="notice error">{error}</p>}
        <div className="modal-actions"><button type="button" className="button ghost" onClick={onClose}>Cancelar</button>
          <button type="submit" className="button primary" disabled={pending}>{pending ? 'Salvando...' : 'Salvar contrato'}</button></div>
      </form>
    </section>
  </div>;
}

export function ContractsPage({ token, canWrite }: { token: string | null; canWrite: boolean }) {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const delayed = useDeferredValue(search);
  const [activeOnly, setActiveOnly] = useState(true);
  const [editing, setEditing] = useState<Contract | 'new' | null>(null);
  const [previewId, setPreviewId] = useState<number | null>(null);
  const [asOf, setAsOf] = useState(localToday);
  const [notice, setNotice] = useState('');
  const listing = useQuery({
    queryKey: ['contracts', page, delayed, activeOnly],
    queryFn: () => api<Page<Contract>>('/contracts' + queryString({
      page, q: delayed, active_only: activeOnly,
    }), token),
    placeholderData: keepPreviousData,
  });
  const cycle = useQuery({
    queryKey: ['contract-cycle', previewId, asOf],
    queryFn: () => api<{ cycle: Cycle | null }>(`/contracts/${previewId}/cycle${queryString({ as_of: asOf })}`, token),
    enabled: previewId !== null && Boolean(asOf),
  });
  const save = useMutation({
    mutationFn: async (fields: Fields) => {
      const existing = editing && editing !== 'new' ? editing : null;
      const payload = {
        document_number: fields.document_number.trim(), title: fields.title.trim(),
        provider_unit_name: fields.provider_unit_name.trim(),
        billing_address: fields.billing_address.trim(), billing_timezone: fields.billing_timezone.trim(),
        effective_on: fields.effective_on, billing_starts_on: fields.billing_starts_on,
        ends_on: fields.ends_on || null, active: fields.active, is_default: fields.is_default,
        notes: fields.notes.trim() || null,
        ...(existing ? { expected_version: existing.version } : { customer_id: fields.customer_id }),
      };
      return api(`/contracts${existing ? '/' + existing.id : ''}`, token, {
        method: existing ? 'PATCH' : 'POST', body: JSON.stringify(payload),
      });
    },
    onSuccess: async () => {
      setEditing(null); setNotice('Contrato salvo com histórico auditável.');
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['contracts'] }),
        qc.invalidateQueries({ queryKey: ['contract-cycle'] }),
      ]);
    },
  });
  const meta = listing.data?.meta;
  const current = meta?.current_page ?? listing.data?.current_page ?? page;
  const last = meta?.last_page ?? listing.data?.last_page ?? 1;
  return <>
    <div className="page-heading"><div><span className="eyebrow">FINANCEIRO</span><h1>Contratos</h1>
      <p>Vigência, ciclo de faturamento e contrato padrão por cliente.</p></div>
      {canWrite && <div className="page-actions"><button className="button primary" type="button"
        onClick={() => setEditing('new')}><Plus size={17}/> Novo contrato</button></div>}</div>
    {notice && <p className="notice success" role="status">{notice}</p>}
    <section className="data-card">
      <div className="table-tools"><label className="search-field"><Search size={18} aria-hidden="true"/>
        <input type="search" aria-label="Pesquisar contratos" placeholder="Documento ou identificação..."
          value={search} maxLength={100} onChange={e => { setSearch(e.target.value); setPage(1); }} /></label>
        <label className="check-row"><input type="checkbox" checked={activeOnly}
          onChange={e => { setActiveOnly(e.target.checked); setPage(1); }} /> Somente ativos</label></div>
      {listing.isPending ? <p className="loading">Carregando contratos...</p> : listing.error ?
        <p className="notice error" role="alert">{readableError(listing.error)}</p> : <>
        <div className="table-wrap"><table><thead><tr><th>Contrato</th><th>Cliente</th><th>Vigência</th>
          <th>Ciclo</th><th>Estado</th><th className="actions-head">Ações</th></tr></thead><tbody>
          {listing.data.data.map(item => <tr key={item.id}>
            <td><strong>{item.title}</strong><small>{item.document_number} · {item.provider_unit_name}</small></td>
            <td>{item.customer_name}</td><td>{item.effective_on} — {item.ends_on ?? 'indeterminado'}</td>
            <td>Início {item.billing_starts_on}<small>{item.billing_timezone}</small></td>
            <td><span className={'state-pill ' + (item.active ? 'online' : 'neutral')}>{item.active ? 'Ativo' : 'Inativo'}</span>
              {item.is_default && <small>Padrão</small>}</td>
            <td><div className="table-actions"><button type="button" className="icon-button" title="Ver ciclo"
              aria-label={`Ver ciclo de ${item.document_number}`} onClick={() => setPreviewId(item.id)}><CalendarDays size={17}/></button>
              {canWrite && <button type="button" className="icon-button" title="Editar"
                aria-label={`Editar ${item.document_number}`} onClick={() => setEditing(item)}><Pencil size={17}/></button>}</div></td>
          </tr>)}</tbody></table></div>
        {listing.data.data.length === 0 && <p className="empty-state">Nenhum contrato encontrado.</p>}
        <div className="pagination"><span>{meta?.total ?? listing.data.total ?? 0} contrato(s) · Página {current} de {last}</span>
          <div className="inline"><button type="button" className="button ghost" disabled={current <= 1}
            onClick={() => setPage(current - 1)}>Anterior</button><button type="button" className="button ghost"
            disabled={current >= last} onClick={() => setPage(current + 1)}>Próxima</button></div></div>
      </>}
    </section>
    {previewId !== null && <div className="modal-overlay" role="presentation"><section className="modal" role="dialog"
      aria-modal="true" aria-label="Ciclo de faturamento">
      <div className="modal-header"><h2>Ciclo de faturamento</h2><button type="button" className="icon-button"
        onClick={() => setPreviewId(null)} aria-label="Fechar"><X size={18}/></button></div>
      <div className="editor-form"><label htmlFor="cycle-date">Data de referência</label><input id="cycle-date" type="date"
        value={asOf} onChange={e => setAsOf(e.target.value)} />
        {cycle.isPending ? <p>Calculando...</p> : cycle.error ? <p className="notice error" role="alert">{readableError(cycle.error)}</p> :
          cycle.data?.cycle ? <p>Competência: <strong>{cycle.data.cycle.starts_on} a {cycle.data.cycle.ends_on}</strong>
            {cycle.data.cycle.ends_on !== cycle.data.cycle.scheduled_ends_on &&
              <small> (encerrada pela vigência contratual)</small>}</p> :
          <p>Sem ciclo nesta data, pois o faturamento ainda não começou ou a vigência terminou.</p>}
        <p className="stock-footnote">Esta consulta mostra apenas datas. Não calcula produção nem emite fatura.</p></div>
    </section></div>}
    {editing && <ContractEditor key={editing === 'new' ? 'new' : editing.id}
      contract={editing === 'new' ? null : editing} pending={save.isPending}
      onClose={() => setEditing(null)} onSave={async fields => { await save.mutateAsync(fields); }} />}
  </>;
}
