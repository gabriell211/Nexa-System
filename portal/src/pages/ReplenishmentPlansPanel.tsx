import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardList, Plus, Trash2, X } from 'lucide-react';
import { ApiError, api, queryString, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import type { Page } from '../types';

type PlanStatus = 'draft' | 'approved' | 'cancelled';
interface ItemOption { id: number; sku: string; name: string; unit: string; active: boolean }
interface LocationOption { id: number; name: string; active: boolean }
interface Operator { id: number; name: string }
interface Need {
  id: number; item_id: number; location_id: number;
  item: ItemOption; location: LocationOption;
  available_quantity: number; suggested_quantity: number;
  status: string;
}
interface PlanLine {
  id: number; item_id: number; location_id: number; policy_id: number | null;
  origin: 'suggested' | 'manual';
  available_quantity: number | null; suggested_quantity: number | null;
  quantity: number; safety_item: boolean; note: string | null;
  item: ItemOption; location: LocationOption;
}
interface Plan {
  id: number; request_id: string; status: PlanStatus; version: number;
  due_on: string; responsible_user_id: number; notes: string | null;
  created_by: Operator | null; responsible: Operator | null;
  approved_by: Operator | null; cancelled_by: Operator | null;
  approved_at: string | null; cancelled_at: string | null;
  cancel_reason: string | null; review_note: string | null;
  items: PlanLine[]; created_at: string; updated_at: string;
}
type PlanSummary = Omit<Plan, 'items'> & { items_count: number };
interface DraftLine {
  id?: number; item_id: number; location_id: number;
  quantity: string; safety_item: boolean; note: string;
  item: ItemOption | null; location: LocationOption | null;
  origin: 'suggested' | 'manual';
  suggested_quantity: number | null;
}

const statusLabel: Record<PlanStatus, string> = {
  draft: 'Em revisão', approved: 'Aprovada', cancelled: 'Cancelada',
};
const needStatusLabel: Record<string, string> = {
  critical: 'Crítico', safety: 'Segurança', low: 'Abaixo do mínimo',
};
const maxQuantity = 1_000_000;

function localToday(): string {
  const now = new Date();
  return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10);
}

function displayDate(value: string | null): string {
  if (!value) return '—';
  const date = new Date(value.includes('T') ? value : `${value}T12:00:00`);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('pt-BR');
}

function displayDateTime(value: string | null): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('pt-BR');
}

function validQuantity(value: string): boolean {
  return /^[1-9][0-9]*$/.test(value) && Number(value) <= maxQuantity;
}

function planMutationError(error: unknown): string {
  if (error instanceof ApiError && error.status === 409) {
    const cause = readableError(error);
    const staleSuggestion = /sugestão|política|inativ/i.test(cause);
    return `${cause} Recarregue a proposta para ver a versão atual.${staleSuggestion ?
      ' Para uma sugestão obsoleta, crie uma nova proposta a partir das necessidades atuais.' : ''}`;
  }
  return readableError(error);
}

function toDraft(line: PlanLine): DraftLine {
  return {
    id: line.id, item_id: line.item_id, location_id: line.location_id,
    quantity: String(line.quantity), safety_item: line.safety_item,
    note: line.note ?? '', item: line.item, location: line.location,
    origin: line.origin, suggested_quantity: line.suggested_quantity,
  };
}

function PlanEditor({ plan, token, role, userId, onChanged, onReload }: {
  plan: Plan; token: string | null; role: string; userId: number;
  onChanged: (message: string) => Promise<void>;
  onReload: () => Promise<void>;
}) {
  const [dueOn, setDueOn] = useState(plan.due_on);
  const [responsibleId, setResponsibleId] = useState(String(plan.responsible_user_id));
  const [notes, setNotes] = useState(plan.notes ?? '');
  const [lines, setLines] = useState<DraftLine[]>(() => plan.items.map(toDraft));
  const [newItemId, setNewItemId] = useState('');
  const [newItem, setNewItem] = useState<ItemOption | null>(null);
  const [newLocationId, setNewLocationId] = useState('');
  const [newLocation, setNewLocation] = useState<LocationOption | null>(null);
  const [reviewNote, setReviewNote] = useState('');
  const [cancelReason, setCancelReason] = useState('');

  const initialLines = plan.items.map(line => ({
    id: line.id, item_id: line.item_id, location_id: line.location_id,
    quantity: String(line.quantity), safety_item: line.safety_item,
    note: line.note ?? '',
  }));
  const currentLines = lines.map(line => ({
    id: line.id, item_id: line.item_id, location_id: line.location_id,
    quantity: line.quantity, safety_item: line.safety_item, note: line.note,
  }));
  const dirty = dueOn !== plan.due_on || responsibleId !== String(plan.responsible_user_id) ||
    notes !== (plan.notes ?? '') || JSON.stringify(currentLines) !== JSON.stringify(initialLines);
  const duplicatePosition = lines.some((line, index) => lines.findIndex(other =>
    other.item_id === line.item_id && other.location_id === line.location_id) !== index);
  const validLines = lines.every(line => validQuantity(line.quantity) && line.note.trim().length <= 500) &&
    !duplicatePosition && lines.length <= 100;
  const validMetadata = Boolean(dueOn && responsibleId && notes.trim().length <= 1000);
  const canReview = ['owner', 'admin', 'manager'].includes(role);

  const save = useMutation({
    mutationFn: () => api<{ data: Plan }>(`/stock/replenishment-plans/${plan.id}`, token, {
      method: 'PATCH',
      body: JSON.stringify({
        expected_version: plan.version, due_on: dueOn,
        responsible_user_id: Number(responsibleId), notes: notes.trim() || null,
        items: lines.map(line => ({
          ...(line.id ? { id: line.id } : {}),
          item_id: line.item_id, location_id: line.location_id,
          quantity: Number(line.quantity), safety_item: line.safety_item,
          note: line.note.trim() || null,
        })),
      }),
    }),
    onSuccess: async () => onChanged('Proposta atualizada. A revisão usará a nova versão.'),
  });
  const approve = useMutation({
    mutationFn: () => api<{ data: Plan }>(`/stock/replenishment-plans/${plan.id}/approve`, token, {
      method: 'POST', body: JSON.stringify({
        expected_version: plan.version, review_note: reviewNote.trim() || null,
      }),
    }),
    onSuccess: async () => onChanged('Proposta aprovada e auditada. Nenhuma compra ou remessa foi criada.'),
  });
  const cancel = useMutation({
    mutationFn: () => api<{ data: Plan }>(`/stock/replenishment-plans/${plan.id}/cancel`, token, {
      method: 'POST', body: JSON.stringify({
        expected_version: plan.version, reason: cancelReason.trim(),
      }),
    }),
    onSuccess: async () => onChanged('Proposta cancelada e auditada.'),
  });
  const busy = save.isPending || approve.isPending || cancel.isPending;
  const conflict = [save.error, approve.error, cancel.error].some(error =>
    error instanceof ApiError && error.status === 409);

  function updateLine(index: number, change: Partial<DraftLine>): void {
    setLines(current => current.map((line, position) => position === index ? { ...line, ...change } : line));
  }

  function addManual(): void {
    if (!newItemId || !newLocationId || lines.length >= 100 ||
      lines.some(line => line.item_id === Number(newItemId) && line.location_id === Number(newLocationId))) return;
    setLines(current => [...current, {
      item_id: Number(newItemId), location_id: Number(newLocationId), quantity: '1',
      safety_item: false, note: '', item: newItem, location: newLocation,
      origin: 'manual', suggested_quantity: null,
    }]);
    setNewItemId(''); setNewLocationId(''); setNewItem(null); setNewLocation(null);
  }

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (dirty && validLines && validMetadata && !busy) save.mutate();
  }

  return <>
    <form className="stock-form plan-editor" onSubmit={submit}>
      <h3>Revisar proposta</h3>
      <p className="stock-hint">Ajuste as quantidades, indique itens de segurança e inclua materiais manuais. Salve antes de aprovar; outra pessoa deve fazer a aprovação.</p>
      <div className="stock-form-grid">
        <div className="stock-field"><label htmlFor="plan-due">Data limite *</label>
          <input id="plan-due" type="date" required value={dueOn} disabled={busy}
            onChange={event => setDueOn(event.target.value)} /></div>
        <PagedEntitySelect<Operator> id="plan-responsible" label="Responsável *"
          searchLabel="Buscar responsável" placeholder="Buscar pessoa" emptyLabel="Selecionar responsável"
          value={responsibleId} onChange={value => setResponsibleId(value)}
          queryKey={['plan-operators']} endpoint="/stock/operators"
          filters={{ eligible_for_replenishment: true }}
          optionLabel={operator => operator.name} initialOption={plan.responsible}
          required disabled={busy} />
      </div>
      <div className="stock-field"><label htmlFor="plan-notes">Observações</label>
        <textarea id="plan-notes" rows={2} maxLength={1000} value={notes} disabled={busy}
          onChange={event => setNotes(event.target.value)} /></div>
      <div className="table-wrap"><table className="plan-items-table"><thead><tr>
        <th>Material</th><th>Local</th><th>Origem / sugestão</th><th>Quantidade *</th>
        <th>Segurança</th><th>Nota</th><th>Ação</th>
      </tr></thead><tbody>{lines.map((line, index) => <tr key={line.id ?? `new-${index}`}>
        <td><strong>{line.item?.sku ?? `#${line.item_id}`}</strong><small>{line.item?.name ?? 'Item manual'}</small></td>
        <td>{line.location?.name ?? `#${line.location_id}`}</td>
        <td>{line.origin === 'suggested' ? `Política · ${line.suggested_quantity ?? '—'} sugerido` : 'Manual'}</td>
        <td><input aria-label={`Quantidade do item ${line.item?.sku ?? line.item_id}`} type="number"
          min={1} max={maxQuantity} step={1} required value={line.quantity} disabled={busy}
          onChange={event => updateLine(index, { quantity: event.target.value })} /></td>
        <td><label className="plan-safety"><input type="checkbox" checked={line.safety_item}
          disabled={busy} onChange={event => updateLine(index, { safety_item: event.target.checked })} />
          <span>Item de segurança</span></label></td>
        <td><input aria-label={`Nota do item ${line.item?.sku ?? line.item_id}`} maxLength={500}
          value={line.note} disabled={busy} onChange={event => updateLine(index, { note: event.target.value })} /></td>
        <td><button type="button" className="button ghost" disabled={busy}
          aria-label={`Remover item ${line.item?.sku ?? line.item_id}`}
          onClick={() => setLines(current => current.filter((_, position) => position !== index))}>
          <Trash2 size={15}/> Remover</button></td>
      </tr>)}</tbody></table></div>
      {lines.length === 0 && <p className="stock-hint">Esta proposta ainda não possui itens. Inclua um item manual para prosseguir.</p>}
      <div className="plan-add-manual">
        <h4>Adicionar item manual</h4>
        <div className="stock-form-grid">
          <PagedEntitySelect<ItemOption> id="plan-manual-item" label="Material"
            searchLabel="Buscar material para proposta" placeholder="SKU ou descrição"
            emptyLabel="Selecionar material" value={newItemId}
            onChange={(value, option) => { setNewItemId(value); setNewItem(option); }}
            queryKey={['plan-items']} endpoint="/stock/items" filters={{ active_only: true }}
            optionLabel={item => `${item.sku} — ${item.name}`} disabled={busy} />
          <PagedEntitySelect<LocationOption> id="plan-manual-location" label="Local"
            searchLabel="Buscar local para proposta" placeholder="Nome do local"
            emptyLabel="Selecionar local" value={newLocationId}
            onChange={(value, option) => { setNewLocationId(value); setNewLocation(option); }}
            queryKey={['plan-locations']} endpoint="/stock/locations" filters={{ active_only: true }}
            optionLabel={location => location.name} disabled={busy} />
        </div>
        <button type="button" className="button ghost" onClick={addManual} disabled={busy || !newItemId ||
          !newLocationId || lines.length >= 100 || lines.some(line => line.item_id === Number(newItemId) &&
            line.location_id === Number(newLocationId))}><Plus size={16}/> Incluir item</button>
      </div>
      {duplicatePosition && <p role="alert" className="notice error">A mesma combinação de material e local aparece mais de uma vez.</p>}
      {!validLines && !duplicatePosition && <p role="alert" className="notice error">Use quantidades inteiras de 1 a 1.000.000 e até 100 itens.</p>}
      {save.error && <p role="alert" className="notice error">{planMutationError(save.error)}</p>}
      <button type="submit" className="button primary" disabled={!dirty || !validLines || !validMetadata || busy}>
        {save.isPending ? 'Salvando...' : 'Salvar ajustes'}</button>
    </form>
    {canReview && <section className="stock-form plan-decision">
      <h3>Decisão da revisão</h3>
      {plan.created_by?.id === userId ? <p className="stock-hint">A aprovação exige outra pessoa com papel de gestão.</p> : <>
        <div className="stock-field"><label htmlFor="plan-review-note">Nota da aprovação</label>
          <textarea id="plan-review-note" rows={2} maxLength={500} value={reviewNote}
            disabled={busy} onChange={event => setReviewNote(event.target.value)} /></div>
        <button type="button" className="button primary" disabled={busy || dirty || lines.length === 0 || !validLines}
          onClick={() => approve.mutate()}>{approve.isPending ? 'Aprovando...' : 'Aprovar proposta'}</button>
        {approve.error && <p role="alert" className="notice error">{planMutationError(approve.error)}</p>}
      </>}
      <div className="stock-field"><label htmlFor="plan-cancel-reason">Justificativa do cancelamento *</label>
        <input id="plan-cancel-reason" minLength={5} maxLength={500} value={cancelReason}
          disabled={busy} onChange={event => setCancelReason(event.target.value)} /></div>
      <button type="button" className="button ghost" disabled={busy || dirty ||
        cancelReason.trim().length < 5 || cancelReason.trim().length > 500}
        onClick={() => cancel.mutate()}>Cancelar proposta</button>
      {cancel.error && <p role="alert" className="notice error">{planMutationError(cancel.error)}</p>}
      {dirty && <p className="stock-hint">Salve ou descarte os ajustes antes de registrar a decisão.</p>}
    </section>}
    {conflict && <button type="button" className="button ghost" onClick={() => void onReload()}
      disabled={busy}>Recarregar proposta</button>}
  </>;
}

function ApprovedPlanCancel({ plan, token, onChanged, onReload }: {
  plan: Plan; token: string | null;
  onChanged: (message: string) => Promise<void>;
  onReload: () => Promise<void>;
}) {
  const [reason, setReason] = useState('');
  const cancel = useMutation({
    mutationFn: () => api<{ data: Plan }>(`/stock/replenishment-plans/${plan.id}/cancel`, token, {
      method: 'POST', body: JSON.stringify({
        expected_version: plan.version, reason: reason.trim(),
      }),
    }),
    onSuccess: async () => onChanged('Proposta aprovada cancelada e auditada.'),
  });
  const conflict = cancel.error instanceof ApiError && cancel.error.status === 409;
  return <section className="stock-form plan-decision">
    <h3>Cancelar proposta aprovada</h3>
    <div className="stock-field"><label htmlFor="plan-approved-cancel-reason">Justificativa *</label>
      <input id="plan-approved-cancel-reason" minLength={5} maxLength={500} value={reason}
        disabled={cancel.isPending} onChange={event => setReason(event.target.value)} /></div>
    <button type="button" className="button ghost" disabled={cancel.isPending ||
      reason.trim().length < 5 || reason.trim().length > 500}
      onClick={() => cancel.mutate()}>Cancelar proposta</button>
    {cancel.error && <p role="alert" className="notice error">{planMutationError(cancel.error)}</p>}
    {conflict && <button type="button" className="button ghost" disabled={cancel.isPending}
      onClick={() => void onReload()}>Recarregar proposta</button>}
  </section>;
}

function PlanDetails({ id, token, role, userId, onClose, onChanged }: {
  id: number; token: string | null; role: string; userId: number;
  onClose: () => void; onChanged: (message: string) => Promise<void>;
}) {
  const [reloadKey, setReloadKey] = useState(0);
  const details = useQuery({
    queryKey: ['replenishment-plan-detail', id],
    queryFn: () => api<{ data: Plan }>(`/stock/replenishment-plans/${id}`, token),
  });
  const plan = details.data?.data;
  async function reload(): Promise<void> {
    await details.refetch();
    setReloadKey(current => current + 1);
  }
  return <div className="modal-overlay"><section className="modal plan-modal" role="dialog"
    aria-modal="true" aria-labelledby="plan-detail-heading">
    <div className="modal-header"><div><span className="eyebrow">PLANEJAMENTO DE ESTOQUE</span>
      <h2 id="plan-detail-heading">Proposta #{id}</h2></div>
      <button type="button" className="icon-button" aria-label="Fechar proposta" onClick={onClose}><X size={18}/></button>
    </div>
    {details.isPending ? <p role="status">Carregando proposta...</p> : details.error ?
      <p role="alert" className="notice error">{readableError(details.error)}</p> : plan && <>
      <p><strong>{statusLabel[plan.status]}</strong> · versão {plan.version} · prazo {displayDate(plan.due_on)}</p>
      <p><strong>Responsável:</strong> {plan.responsible?.name ?? `#${plan.responsible_user_id}`} ·
        <strong> Criada por:</strong> {plan.created_by?.name ?? '—'} em {displayDateTime(plan.created_at)}</p>
      {plan.status === 'draft' ? <PlanEditor key={`${plan.version}-${reloadKey}`} plan={plan} token={token}
        role={role} userId={userId} onChanged={onChanged} onReload={reload} /> : <>
        <p><strong>Observações:</strong> {plan.notes || '—'}</p>
        <div className="table-wrap"><table><thead><tr><th>Material</th><th>Local</th><th>Origem</th>
          <th>Quantidade</th><th>Segurança</th><th>Nota</th></tr></thead><tbody>
          {plan.items.map(line => <tr key={line.id}><td><strong>{line.item.sku}</strong><small>{line.item.name}</small></td>
            <td>{line.location.name}</td><td>{line.origin === 'suggested' ? 'Sugerido' : 'Manual'}</td>
            <td>{line.quantity.toLocaleString('pt-BR')} {line.item.unit}</td>
            <td>{line.safety_item ? 'Sim' : 'Não'}</td><td>{line.note || '—'}</td></tr>)}
          </tbody></table></div>
        {plan.status === 'approved' ? <p><strong>Aprovada por:</strong> {plan.approved_by?.name ?? '—'} em {displayDateTime(plan.approved_at)}.
          {plan.review_note && <> <strong>Nota:</strong> {plan.review_note}</>}</p> :
          <p><strong>Cancelada por:</strong> {plan.cancelled_by?.name ?? '—'} em {displayDateTime(plan.cancelled_at)}.
            <strong> Motivo:</strong> {plan.cancel_reason ?? '—'}</p>}
        {plan.status === 'approved' && ['owner', 'admin', 'manager'].includes(role) &&
          <ApprovedPlanCancel key={`${plan.version}-${reloadKey}`} plan={plan} token={token}
            onChanged={onChanged} onReload={reload} />}
      </>}
      <p className="stock-hint">A proposta registra a decisão de reposição. Expedição, recebimento e compra são operações separadas.</p>
    </>}
  </section></div>;
}

export function ReplenishmentPlansPanel({ token, role, userId }: {
  token: string | null; role: string; userId: number;
}) {
  const qc = useQueryClient();
  const [status, setStatus] = useState<PlanStatus | 'all'>('draft');
  const [page, setPage] = useState(1);
  const [needPage, setNeedPage] = useState(1);
  const [selectedNeeds, setSelectedNeeds] = useState<Map<number, Need>>(() => new Map());
  const [dueOn, setDueOn] = useState(localToday);
  const [responsibleId, setResponsibleId] = useState('');
  const [notes, setNotes] = useState('');
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const [selectedPlan, setSelectedPlan] = useState<number | null>(null);
  const [notice, setNotice] = useState('');
  const canCreate = ['owner', 'admin', 'manager', 'warehouse'].includes(role);

  const plans = useQuery({
    queryKey: ['replenishment-plans', status, page],
    queryFn: () => api<Page<PlanSummary>>('/stock/replenishment-plans' + queryString({
      page, per_page: 25, ...(status === 'all' ? {} : { status }),
    }), token),
  });
  const needs = useQuery({
    queryKey: ['plan-reorder-needs', needPage],
    queryFn: () => api<Page<Need>>('/stock/reorder-needs' + queryString({
      page: needPage, per_page: 25, needs_only: true,
    }), token),
    enabled: canCreate,
  });
  const create = useMutation({
    mutationFn: () => api<{ data: Plan }>('/stock/replenishment-plans', token, {
      method: 'POST', body: JSON.stringify({
        request_id: requestId, due_on: dueOn,
        responsible_user_id: Number(responsibleId), notes: notes.trim() || null,
        policy_ids: [...selectedNeeds.keys()],
      }),
    }),
    onSuccess: async response => {
      setNotice(`Proposta #${response.data.id} criada para revisão.`);
      setSelectedPlan(response.data.id); setSelectedNeeds(new Map());
      setDueOn(localToday()); setResponsibleId(''); setNotes('');
      setRequestId(crypto.randomUUID()); setStatus('draft'); setPage(1);
      await qc.invalidateQueries({ queryKey: ['replenishment-plans'] });
    },
  });

  function changeDraft(): void {
    setRequestId(crypto.randomUUID()); create.reset();
  }

  function toggleNeed(need: Need): void {
    setSelectedNeeds(current => {
      const next = new Map(current);
      if (next.has(need.id)) next.delete(need.id);
      else if (next.size < 100) next.set(need.id, need);
      return next;
    });
    changeDraft();
  }

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (canCreate && dueOn && responsibleId && notes.trim().length <= 1000 && !create.isPending) create.mutate();
  }

  async function changed(message: string): Promise<void> {
    setNotice(message);
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['replenishment-plans'] }),
      qc.invalidateQueries({ queryKey: ['replenishment-plan-detail', selectedPlan] }),
      qc.invalidateQueries({ queryKey: ['plan-reorder-needs'] }),
    ]);
  }

  const currentPage = plans.data?.meta?.current_page ?? plans.data?.current_page ?? page;
  const lastPage = plans.data?.meta?.last_page ?? plans.data?.last_page ?? 1;
  const total = plans.data?.meta?.total ?? plans.data?.total ?? 0;
  const needCurrentPage = needs.data?.meta?.current_page ?? needs.data?.current_page ?? needPage;
  const needLastPage = needs.data?.meta?.last_page ?? needs.data?.last_page ?? 1;

  return <>
    <p className="stock-hint">Propostas persistidas para revisão humana. A aprovação não reserva estoque e não cria compra ou remessa automaticamente.</p>
    {notice && <p role="status" className="notice success">{notice}</p>}
    <section className="data-card stock-listing" aria-label="Propostas de reposição">
      <div className="stock-field reorder-filter"><label htmlFor="plan-status-filter">Estado</label>
        <select id="plan-status-filter" value={status} onChange={event => {
          setStatus(event.target.value as PlanStatus | 'all'); setPage(1);
        }}><option value="draft">Em revisão</option><option value="approved">Aprovadas</option>
          <option value="cancelled">Canceladas</option><option value="all">Todas</option></select></div>
      {plans.isPending ? <p role="status">Carregando propostas...</p> : plans.error ?
        <p role="alert" className="notice error">{readableError(plans.error)}</p> :
        plans.data.data.length === 0 ? <div className="empty-state"><ClipboardList size={30}/>
          <p>Nenhuma proposta neste estado.</p></div> : <div className="table-wrap"><table><thead><tr>
          <th>Proposta</th><th>Estado</th><th>Itens</th><th>Data limite</th>
          <th>Responsável</th><th>Criada por</th><th>Ação</th>
        </tr></thead><tbody>{plans.data.data.map(plan => <tr key={plan.id}>
          <td><strong>#{plan.id}</strong><small>{displayDateTime(plan.created_at)}</small></td>
          <td>{statusLabel[plan.status]}</td><td>{plan.items_count}</td>
          <td>{displayDate(plan.due_on)}</td><td>{plan.responsible?.name ?? `#${plan.responsible_user_id}`}</td>
          <td>{plan.created_by?.name ?? '—'}</td>
          <td><button type="button" className="button ghost" onClick={() => setSelectedPlan(plan.id)}>
            Detalhes{plan.status === 'draft' ? ' / revisão' : ''}</button></td>
        </tr>)}</tbody></table></div>}
      {!plans.isPending && !plans.error && <div className="pagination">
        <span>Página {currentPage} de {lastPage} · {total} propostas</span>
        <div className="inline"><button type="button" className="button ghost" disabled={currentPage <= 1}
          onClick={() => setPage(currentPage - 1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={currentPage >= lastPage}
            onClick={() => setPage(currentPage + 1)}>Próxima</button></div></div>}
    </section>
    {canCreate && <form className="stock-form" onSubmit={submit}>
      <h3><Plus size={18}/> Criar proposta de reposição</h3>
      <p className="stock-hint">Selecione necessidades calculadas em qualquer página. O servidor reconfirma as quantidades ao criar o rascunho. Sem seleção, a proposta começa vazia para inclusão manual.</p>
      <div className="stock-form-grid">
        <div className="stock-field"><label htmlFor="new-plan-due">Data limite *</label>
          <input id="new-plan-due" required type="date" value={dueOn} disabled={create.isPending}
            onChange={event => { setDueOn(event.target.value); changeDraft(); }} /></div>
        <PagedEntitySelect<Operator> id="new-plan-responsible" label="Responsável *"
          searchLabel="Buscar responsável da proposta" placeholder="Buscar pessoa"
          emptyLabel="Selecionar responsável" value={responsibleId}
          onChange={value => { setResponsibleId(value); changeDraft(); }}
          queryKey={['new-plan-operators']} endpoint="/stock/operators"
          filters={{ eligible_for_replenishment: true }}
          optionLabel={operator => operator.name} required disabled={create.isPending} />
      </div>
      <div className="stock-field"><label htmlFor="new-plan-notes">Observações</label>
        <textarea id="new-plan-notes" rows={2} maxLength={1000} value={notes} disabled={create.isPending}
          onChange={event => { setNotes(event.target.value); changeDraft(); }} /></div>
      <div className="plan-needs">
        <h4>Necessidades selecionadas ({selectedNeeds.size}/100)</h4>
        {selectedNeeds.size > 0 && <div className="plan-selected-needs">
          {[...selectedNeeds.values()].map(need => <button key={need.id} type="button" className="button ghost"
            disabled={create.isPending} onClick={() => toggleNeed(need)}
            aria-label={`Remover ${need.item.sku} em ${need.location.name} da proposta`}>
            {need.item.sku} · {need.location.name} <X size={14}/></button>)}
        </div>}
        {needs.isPending ? <p role="status">Carregando necessidades...</p> : needs.error ?
          <p role="alert" className="notice error">{readableError(needs.error)}</p> :
          needs.data.data.length === 0 ? <p>Nenhuma necessidade atual. É possível criar uma proposta manual.</p> :
          <div className="table-wrap"><table><thead><tr><th>Selecionar</th><th>Material</th>
            <th>Local</th><th>Disponível</th><th>Sugerido</th><th>Estado</th></tr></thead><tbody>
            {needs.data.data.map(need => <tr key={need.id}><td><input type="checkbox"
              aria-label={`Selecionar ${need.item.sku} em ${need.location.name}`}
              checked={selectedNeeds.has(need.id)} disabled={create.isPending ||
                (!selectedNeeds.has(need.id) && selectedNeeds.size >= 100)}
              onChange={() => toggleNeed(need)} /></td>
              <td><strong>{need.item.sku}</strong><small>{need.item.name}</small></td>
              <td>{need.location.name}</td>
              <td>{need.available_quantity.toLocaleString('pt-BR')} {need.item.unit}</td>
              <td>{need.suggested_quantity.toLocaleString('pt-BR')} {need.item.unit}</td>
              <td>{needStatusLabel[need.status] ?? need.status}</td></tr>)}
          </tbody></table></div>}
        {!needs.isPending && !needs.error && <div className="pagination"><span>Página {needCurrentPage} de {needLastPage}</span>
          <div className="inline"><button type="button" className="button ghost" disabled={needCurrentPage <= 1}
            onClick={() => setNeedPage(needCurrentPage - 1)}>Anterior</button>
            <button type="button" className="button ghost" disabled={needCurrentPage >= needLastPage}
              onClick={() => setNeedPage(needCurrentPage + 1)}>Próxima</button></div></div>}
      </div>
      {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
      <button type="submit" className="button primary" disabled={create.isPending || !dueOn || !responsibleId ||
        notes.trim().length > 1000}>
        {create.isPending ? 'Criando...' : selectedNeeds.size ? 'Criar proposta com sugestões' : 'Criar proposta manual'}
      </button>
    </form>}
    {selectedPlan !== null && <PlanDetails id={selectedPlan} token={token} role={role} userId={userId}
      onClose={() => setSelectedPlan(null)} onChanged={changed} />}
  </>;
}
