import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, queryString, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import type { Page } from '../types';

type Action = 'follow_up' | 'rescheduled' | 'supplier_confirmed' | 'closed_unresolved';
type Status = 'awaiting_supplier' | 'supplier_confirmed' | 'closed_unresolved';

interface ReturnRow {
  id: number;
  status: 'pending' | 'completed' | 'cancelled';
  receipt: { invoice_reference: string; lot_number: string } | null;
}
interface Operator { id: number; name: string; active: boolean }
interface EventRow {
  id: number; action: string; note: string; supplier_reference: string | null;
  due_on: string | null; actor: { id: number; name: string } | null;
  created_at: string;
}
interface Followup {
  id: number; supplier_return_id: number; assigned_user_id: number;
  version: number; status: Status; due_on: string; overdue: boolean;
  supplier_reference: string | null; supplier_confirmed_at: string | null;
  assignee: { id: number; name: string } | null;
  creator: { id: number; name: string } | null;
  events?: EventRow[];
}
const labels: Record<Status, string> = {
  awaiting_supplier: 'Aguardando fornecedor',
  supplier_confirmed: 'Confirmado pelo fornecedor',
  closed_unresolved: 'Encerrado sem solução',
};
const actions: Record<Action, string> = {
  follow_up: 'Registrar contato',
  rescheduled: 'Alterar prazo',
  supplier_confirmed: 'Registrar confirmação documental',
  closed_unresolved: 'Encerrar sem solução',
};

export function SupplierReplacementFollowupsPanel({ orderId, returns, token, role, userId }: {
  orderId: number; returns: ReturnRow[]; token: string | null;
  role: string; userId: number;
}) {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [returnId, setReturnId] = useState('');
  const [assigneeId, setAssigneeId] = useState('');
  const [dueOn, setDueOn] = useState('');
  const [openingNote, setOpeningNote] = useState('');
  const [createId, setCreateId] = useState(() => crypto.randomUUID());
  const [caseId, setCaseId] = useState<number | null>(null);
  const [action, setAction] = useState<Action>('follow_up');
  const [eventNote, setEventNote] = useState('');
  const [reference, setReference] = useState('');
  const [nextDue, setNextDue] = useState('');
  const [eventId, setEventId] = useState(() => crypto.randomUUID());
  const [notice, setNotice] = useState('');
  const isManager = ['owner', 'admin', 'manager'].includes(role);

  const cases = useQuery({
    queryKey: ['replacement-cases', orderId, page],
    queryFn: () => api<Page<Followup>>('/stock/supplier-replacements' + queryString({
      purchase_order_id: orderId, page, per_page: 25,
    }), token),
  });
  const selected = useQuery({
    queryKey: ['replacement-case', caseId],
    queryFn: () => api<{ data: Followup }>('/stock/supplier-replacements/' + caseId, token),
    enabled: caseId !== null,
  });
  const detail = selected.data?.data;
  const lastPage = cases.data?.meta?.last_page ?? cases.data?.last_page ?? 1;
  const alreadyOpened = new Set(cases.data?.data.map(item => item.supplier_return_id) ?? []);
  const completed = returns.filter(entry => entry.status === 'completed');
  const canOpen = Boolean(returnId && assigneeId && dueOn && openingNote.trim().length >= 5 &&
    openingNote.trim().length <= 500 && !alreadyOpened.has(Number(returnId)));
  const canEvent = Boolean(detail && detail.status === 'awaiting_supplier' && eventNote.trim().length >= 5 &&
    eventNote.trim().length <= 500 &&
    (action !== 'supplier_confirmed' || (reference.trim().length >= 3 && reference.trim().length <= 120)) &&
    (action !== 'rescheduled' || Boolean(nextDue)) &&
    (action === 'follow_up' || (isManager &&
      (action === 'rescheduled' || detail.creator?.id !== userId))));

  async function refresh(): Promise<void> {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['replacement-cases', orderId] }),
      queryClient.invalidateQueries({ queryKey: ['replacement-case'] }),
    ]);
  }

  const create = useMutation({
    mutationFn: () => api<{ data: Followup }>('/stock/supplier-replacements', token, {
      method: 'POST',
      body: JSON.stringify({
        request_id: createId,
        supplier_return_id: Number(returnId),
        assigned_user_id: Number(assigneeId),
        due_on: dueOn,
        note: openingNote.trim(),
      }),
    }),
    onSuccess: async result => {
      setNotice('Acompanhamento #' + result.data.id + ' aberto. Nenhum recebimento foi registrado.');
      setCaseId(result.data.id); setReturnId(''); setOpeningNote(''); setDueOn('');
      setCreateId(crypto.randomUUID()); setPage(1);
      await refresh();
    },
  });

  const addEvent = useMutation({
    mutationFn: () => api('/stock/supplier-replacements/' + caseId + '/events', token, {
      method: 'POST',
      body: JSON.stringify({
        request_id: eventId, expected_version: detail?.version,
        action, note: eventNote.trim(),
        supplier_reference: action === 'supplier_confirmed' ? reference.trim() : null,
        due_on: action === 'rescheduled' ? nextDue : null,
      }),
    }),
    onSuccess: async () => {
      setNotice('Contato/documento registrado no histórico sem movimentação de estoque.');
      setEventId(crypto.randomUUID()); setEventNote(''); setReference(''); setNextDue('');
      await refresh();
    },
  });

  function submitCreate(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (canOpen && !create.isPending) create.mutate();
  }
  function submitEvent(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (canEvent && !addEvent.isPending) addEvent.mutate();
  }

  return <section className="stock-form" aria-label="Acompanhamento de substituições">
    <h3>Acompanhamento de substituições do fornecedor</h3>
    <p className="stock-hint">
      Contato, prazo e resposta do fornecedor são registros externos. A confirmação documental
      não comprova recebimento físico, não cria estoque e não fecha a conciliação.
    </p>
    <form onSubmit={submitCreate}>
      <h4>Abrir acompanhamento de devolução concluída</h4>
      <div className="stock-form-grid">
        <div className="stock-field">
          <label htmlFor="replacement-return">Devolução concluída *</label>
          <select id="replacement-return" value={returnId} required
            onChange={event => { setReturnId(event.target.value); setCreateId(crypto.randomUUID()); }}>
            <option value="">Selecione uma devolução</option>
            {completed.map(entry => <option key={entry.id} value={entry.id}
              disabled={alreadyOpened.has(entry.id)}>
              #{entry.id} · {entry.receipt?.invoice_reference ?? 'Sem nota'}
            </option>)}
          </select>
        </div>
        <PagedEntitySelect<Operator> id="replacement-assignee" label="Responsável interno *"
          searchLabel="Buscar responsável pela substituição" placeholder="Nome do responsável"
          emptyLabel="Selecione o responsável" value={assigneeId}
          onChange={value => { setAssigneeId(value); setCreateId(crypto.randomUUID()); }}
          queryKey={['replacement-operators']} endpoint="/stock/operators"
          filters={{ eligible_for_replenishment: 1 }} required
          optionLabel={operator => operator.name} />
        <div className="stock-field">
          <label htmlFor="replacement-due">Prazo de acompanhamento *</label>
          <input id="replacement-due" type="date" required value={dueOn}
            onChange={event => { setDueOn(event.target.value); setCreateId(crypto.randomUUID()); }} />
        </div>
        <div className="stock-field">
          <label htmlFor="replacement-opening-note">Descrição do contato inicial *</label>
          <textarea id="replacement-opening-note" rows={2} required minLength={5} maxLength={500}
            value={openingNote}
            onChange={event => { setOpeningNote(event.target.value); setCreateId(crypto.randomUUID()); }} />
        </div>
      </div>
      <button type="submit" className="button primary" disabled={!canOpen || create.isPending}>
        {create.isPending ? 'Abrindo...' : 'Abrir acompanhamento'}
      </button>
      {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
    </form>
    {notice && <p role="status" className="notice success">{notice}</p>}
    <h4>Histórico por prazo</h4>
    {cases.isPending ? <p role="status">Carregando acompanhamentos...</p> :
      cases.error ? <p role="alert" className="notice error">{readableError(cases.error)}</p> :
      cases.data.data.length === 0 ? <p>Nenhum acompanhamento aberto.</p> :
      <div className="table-wrap"><table>
        <thead><tr><th>Caso</th><th>Devolução</th><th>Responsável</th>
          <th>Prazo</th><th>Estado</th><th>Ação</th></tr></thead>
        <tbody>{cases.data.data.map(entry => <tr key={entry.id}>
          <td>#{entry.id}</td><td>#{entry.supplier_return_id}</td>
          <td>{entry.assignee?.name ?? '—'}</td>
          <td>{entry.due_on}{entry.overdue ? ' · Atrasado' : ''}</td>
          <td>{labels[entry.status]}</td>
          <td><button type="button" className="button ghost"
            aria-label={'Abrir acompanhamento ' + entry.id}
            onClick={() => { setCaseId(entry.id); setEventNote(''); setEventId(crypto.randomUUID()); }}>
            Histórico</button></td>
        </tr>)}</tbody>
      </table></div>}
    {cases.data && <div className="pagination">
      <span>Página {cases.data.meta?.current_page ?? page} de {lastPage}</span>
      <div className="inline">
        <button className="button ghost" type="button" disabled={page <= 1}
          onClick={() => setPage(page - 1)}>Anterior</button>
        <button className="button ghost" type="button" disabled={page >= lastPage}
          onClick={() => setPage(page + 1)}>Próxima</button>
      </div>
    </div>}
    {caseId !== null && <div aria-label="Detalhes do acompanhamento">
      <h4>Histórico do acompanhamento #{caseId}</h4>
      {selected.isPending ? <p role="status">Carregando histórico...</p> :
        selected.error ? <p role="alert">{readableError(selected.error)}</p> : detail && <>
          <p><strong>Estado:</strong> {labels[detail.status]} · <strong>Prazo:</strong> {detail.due_on}</p>
          <p><strong>Confirmação externa:</strong> {detail.supplier_reference ?? 'Não confirmada'}</p>
          <div className="table-wrap"><table><thead><tr><th>Data</th><th>Autor</th>
            <th>Tipo</th><th>Registro</th><th>Referência</th></tr></thead>
            <tbody>{detail.events?.map(entry => <tr key={entry.id}>
              <td>{new Date(entry.created_at).toLocaleString('pt-BR')}</td>
              <td>{entry.actor?.name ?? '—'}</td><td>{entry.action}</td>
              <td>{entry.note}</td><td>{entry.supplier_reference ?? '—'}</td>
            </tr>)}</tbody></table></div>
          {detail.status === 'awaiting_supplier' && <form onSubmit={submitEvent}>
            <div className="stock-form-grid">
              <div className="stock-field">
                <label htmlFor="replacement-event-action">Atualização *</label>
                <select id="replacement-event-action" value={action}
                  onChange={event => { setAction(event.target.value as Action); setEventId(crypto.randomUUID()); }}>
                  <option value="follow_up">{actions.follow_up}</option>
                  {isManager && <option value="rescheduled">{actions.rescheduled}</option>}
                  {isManager && detail.creator?.id !== userId && <>
                    <option value="supplier_confirmed">{actions.supplier_confirmed}</option>
                    <option value="closed_unresolved">{actions.closed_unresolved}</option>
                  </>}
                </select>
              </div>
              <div className="stock-field">
                <label htmlFor="replacement-event-note">Descrição da atualização *</label>
                <textarea id="replacement-event-note" rows={2} required minLength={5} maxLength={500}
                  value={eventNote} onChange={event => { setEventNote(event.target.value); setEventId(crypto.randomUUID()); }} />
              </div>
              {action === 'supplier_confirmed' && <div className="stock-field">
                <label htmlFor="replacement-external-reference">Referência documental do fornecedor *</label>
                <input id="replacement-external-reference" required minLength={3} maxLength={120}
                  value={reference} onChange={event => { setReference(event.target.value); setEventId(crypto.randomUUID()); }} />
              </div>}
              {action === 'rescheduled' && <div className="stock-field">
                <label htmlFor="replacement-next-due">Novo prazo *</label>
                <input id="replacement-next-due" type="date" required value={nextDue}
                  onChange={event => { setNextDue(event.target.value); setEventId(crypto.randomUUID()); }} />
              </div>}
            </div>
            <button type="submit" className="button primary" disabled={!canEvent || addEvent.isPending}>
              {addEvent.isPending ? 'Registrando...' : 'Registrar atualização'}
            </button>
            {addEvent.error && <p role="alert" className="notice error">{readableError(addEvent.error)}</p>}
          </form>}
        </>}
    </div>}
  </section>;
}
