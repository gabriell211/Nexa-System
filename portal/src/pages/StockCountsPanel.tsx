import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardCheck, Plus, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import type { Page } from '../types';

type CountStatus = 'pending' | 'approved' | 'rejected';
type Decision = 'approve' | 'reject';

interface ItemOption { id: number; sku: string; name: string; unit: string; active: boolean }
interface LocationOption { id: number; name: string; active: boolean }
interface Actor { id: number; name: string }
interface StockCount {
  id: number;
  request_id: string;
  item_id: number;
  location_id: number;
  expected_quantity: number;
  counted_quantity: number;
  observed_movement_id: number | null;
  difference: number;
  reason: string;
  status: CountStatus;
  counted_at: string;
  reviewed_at: string | null;
  review_reason: string | null;
  movement_id: number | null;
  item: ItemOption;
  location: LocationOption;
  counted_by: Actor | null;
  reviewed_by: Actor | null;
}

const statusLabel: Record<CountStatus, string> = {
  pending: 'Pendente', approved: 'Aprovada', rejected: 'Rejeitada',
};

function dateTime(value: string | null): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('pt-BR');
}

function quantity(value: number, unit?: string): string {
  return `${value.toLocaleString('pt-BR')}${unit ? ` ${unit}` : ''}`;
}

function signedDifference(count: StockCount): string {
  const sign = count.difference > 0 ? '+' : '';
  return `${sign}${quantity(count.difference, count.item?.unit)}`;
}

function CountDetails({ id, token, role, userId, onClose, onChanged }: {
  id: number;
  token: string | null;
  role: string;
  userId: number;
  onClose: () => void;
  onChanged: () => Promise<void>;
}) {
  const [reviewReason, setReviewReason] = useState('');
  const details = useQuery({
    queryKey: ['stock-count-detail', id],
    queryFn: () => api<{ data: StockCount }>(`/stock/counts/${id}`, token),
  });
  const review = useMutation({
    mutationFn: (decision: Decision) => api<{ data: StockCount }>(
      `/stock/counts/${id}/${decision}`, token,
      { method: 'POST', body: JSON.stringify({ reason: reviewReason.trim() }) },
    ),
    onSuccess: async () => {
      setReviewReason('');
      await onChanged();
    },
  });
  const count = details.data?.data;
  const reviewerRole = ['owner', 'admin', 'manager'].includes(role);
  const canReview = count?.status === 'pending' && reviewerRole && count.counted_by?.id !== userId;
  const validReason = reviewReason.trim().length >= 5 && reviewReason.trim().length <= 400;

  return <div className="modal-overlay"><section className="modal printer-assignment-modal" role="dialog"
    aria-modal="true" aria-labelledby="stock-count-heading">
    <div className="modal-header"><div><span className="eyebrow">INVENTÁRIO FÍSICO</span>
      <h2 id="stock-count-heading">Contagem #{id}</h2></div>
      <button type="button" className="icon-button" aria-label="Fechar contagem" onClick={onClose}><X size={18}/></button>
    </div>
    {details.isPending ? <p role="status">Carregando contagem...</p> : details.error ?
      <p role="alert" className="notice error">{readableError(details.error)}</p> : count && <>
      <p><strong>{count.item.sku} — {count.item.name}</strong> · {count.location.name}</p>
      <p><strong>{statusLabel[count.status]}</strong> · Identificador da operação: <code>{count.request_id}</code></p>
      <div className="shipment-metrics">
        <div><strong>{quantity(count.expected_quantity, count.item.unit)}</strong><small>Saldo no registro</small></div>
        <div><strong>{quantity(count.counted_quantity, count.item.unit)}</strong><small>Contado</small></div>
        <div><strong>{signedDifference(count)}</strong><small>Diferença</small></div>
      </div>
      <p><strong>Contagem:</strong> {count.reason}</p>
      <p><strong>Registrada por:</strong> {count.counted_by?.name ?? '—'} · {dateTime(count.counted_at)}</p>
      <p><strong>Última movimentação observada:</strong> {count.observed_movement_id ?? 'Nenhuma'}</p>
      {count.status !== 'pending' && <>
        <p><strong>Revisada por:</strong> {count.reviewed_by?.name ?? '—'} · {dateTime(count.reviewed_at)}</p>
        <p><strong>Justificativa da revisão:</strong> {count.review_reason ?? '—'}</p>
        {count.movement_id !== null && <p><strong>Movimentação de ajuste:</strong> #{count.movement_id}</p>}
      </>}
      {count.status === 'pending' && !canReview && <p className="stock-hint">
        {reviewerRole ? 'A contagem exige revisão por outra pessoa com papel de gestão.' :
          'A revisão está disponível para owner, admin ou manager.'}
      </p>}
      {canReview && <form className="stock-form" onSubmit={(event: FormEvent<HTMLFormElement>) => event.preventDefault()}>
        <h3>Revisar contagem</h3>
        <p className="stock-hint">A aprovação ajusta o saldo somente se a posição ainda corresponder ao snapshot registrado.
          Em caso de alteração posterior, registre uma nova contagem.</p>
        <div className="stock-field"><label htmlFor="count-review-reason">Justificativa da decisão *</label>
          <input id="count-review-reason" required minLength={5} maxLength={400} value={reviewReason}
            onChange={event => setReviewReason(event.target.value)}/></div>
        {review.error && <p role="alert" className="notice error">{readableError(review.error)}</p>}
        <div className="inline">
          <button type="button" className="button primary" disabled={review.isPending || !validReason}
            onClick={() => review.mutate('approve')}>
            {review.isPending ? 'Revisando...' : 'Aprovar contagem'}
          </button>
          <button type="button" className="button ghost" disabled={review.isPending || !validReason}
            onClick={() => review.mutate('reject')}>Rejeitar contagem</button>
        </div>
      </form>}
    </>}
  </section></div>;
}

export function StockCountsPanel({ token, role, userId }: {
  token: string | null;
  role: string;
  userId: number;
}) {
  const qc = useQueryClient();
  const [itemId, setItemId] = useState('');
  const [locationId, setLocationId] = useState('');
  const [countedQuantity, setCountedQuantity] = useState('');
  const [reason, setReason] = useState('');
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const [status, setStatus] = useState<CountStatus | 'all'>('all');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<number | null>(null);
  const [notice, setNotice] = useState('');
  const listing = useQuery({
    queryKey: ['stock-counts', status, page],
    queryFn: () => api<Page<StockCount>>('/stock/counts' + queryString({
      page, per_page: 25, ...(status === 'all' ? {} : { status }),
    }), token),
  });
  const create = useMutation({
    mutationFn: () => api<{ data: StockCount }>('/stock/counts', token, {
      method: 'POST',
      body: JSON.stringify({
        request_id: requestId,
        item_id: Number(itemId),
        location_id: Number(locationId),
        counted_quantity: Number(countedQuantity),
        reason: reason.trim(),
      }),
    }),
    onSuccess: async response => {
      setItemId(''); setLocationId(''); setCountedQuantity(''); setReason('');
      setRequestId(crypto.randomUUID()); setPage(1);
      setNotice(`Contagem #${response.data.id} registrada para revisão.`);
      setSelected(response.data.id);
      await qc.invalidateQueries({ queryKey: ['stock-counts'] });
    },
  });

  function changeDraft(setter: (value: string) => void, value: string): void {
    setter(value);
    setRequestId(crypto.randomUUID());
    create.reset();
  }

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (create.isPending) return;
    create.mutate();
  }

  async function reviewed(): Promise<void> {
    setNotice('Decisão registrada e auditada.');
    setPage(1);
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['stock-counts'] }),
      qc.invalidateQueries({ queryKey: ['stock-count-detail', selected] }),
      qc.invalidateQueries({ queryKey: ['stock-list'] }),
    ]);
  }

  const counted = Number(countedQuantity);
  const validCount = countedQuantity !== '' && Number.isInteger(counted) && counted >= 0 && counted <= 1000000;
  const validReason = reason.trim().length >= 5 && reason.trim().length <= 500;
  const currentPage = listing.data?.meta?.current_page ?? listing.data?.current_page ?? page;
  const lastPage = listing.data?.meta?.last_page ?? listing.data?.last_page ?? 1;
  const total = listing.data?.meta?.total ?? listing.data?.total ?? 0;

  return <>
    {notice && <p role="status" className="notice success">{notice}</p>}
    <section className="data-card stock-listing" aria-label="Contagens físicas">
      <div className="inline"><label htmlFor="count-status-filter">Estado</label>
        <select id="count-status-filter" value={status} onChange={event => {
          setStatus(event.target.value as CountStatus | 'all'); setPage(1);
        }}>
          <option value="all">Todos</option><option value="pending">Pendentes</option>
          <option value="approved">Aprovadas</option><option value="rejected">Rejeitadas</option>
        </select></div>
      {listing.isPending ? <p role="status">Carregando contagens...</p> : listing.error ?
        <p role="alert" className="notice error">{readableError(listing.error)}</p> :
        listing.data.data.length === 0 ? <p>Nenhuma contagem neste filtro.</p> :
        <div className="table-wrap"><table><thead><tr>
          <th>Item</th><th>Local</th><th>Saldo no registro</th><th>Contado</th><th>Diferença</th>
          <th>Estado</th><th>Responsáveis</th><th>Ação</th>
        </tr></thead><tbody>{listing.data.data.map(count => <tr key={count.id}>
          <td><strong>{count.item.sku}</strong><small>{count.item.name}</small></td>
          <td>{count.location.name}</td>
          <td>{quantity(count.expected_quantity, count.item.unit)}</td>
          <td><strong>{quantity(count.counted_quantity, count.item.unit)}</strong></td>
          <td><strong>{signedDifference(count)}</strong></td>
          <td>{statusLabel[count.status]}</td>
          <td>{count.counted_by?.name ?? '—'}<small>{dateTime(count.counted_at)}</small>
            {count.reviewed_by && <small>Revisão: {count.reviewed_by.name}</small>}</td>
          <td><button type="button" className="button ghost" onClick={() => setSelected(count.id)}>
            Detalhes{count.status === 'pending' ? ' / revisão' : ''}</button></td>
        </tr>)}</tbody></table></div>}
      {!listing.isPending && !listing.error && <div className="pagination">
        <span>Página {currentPage} de {lastPage} · {total} registros</span>
        <div className="inline">
          <button type="button" className="button ghost" disabled={currentPage <= 1}
            onClick={() => setPage(currentPage - 1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={currentPage >= lastPage}
            onClick={() => setPage(currentPage + 1)}>Próxima</button>
        </div>
      </div>}
    </section>
    <form className="stock-form" onSubmit={submit}>
      <h3><ClipboardCheck size={18}/> Registrar contagem física</h3>
      <p className="stock-hint">O saldo atual é capturado ao registrar. Uma segunda pessoa revisa a diferença.
        Se houver falha de conexão, reenviar sem alterar os campos reutiliza o mesmo identificador.</p>
      <div className="stock-form-grid">
        <PagedEntitySelect<ItemOption> id="count-item" label="Item *" searchLabel="Buscar item para contagem"
          placeholder="Buscar por SKU ou descrição" emptyLabel="Selecionar item" value={itemId}
          onChange={value => changeDraft(setItemId, value)} queryKey={['count-item-options']}
          endpoint="/stock/items" filters={{ active_only: true }} required
          optionLabel={item => `${item.sku} — ${item.name}`} />
        <PagedEntitySelect<LocationOption> id="count-location" label="Local *" searchLabel="Buscar local para contagem"
          placeholder="Buscar local" emptyLabel="Selecionar local" value={locationId}
          onChange={value => changeDraft(setLocationId, value)} queryKey={['count-location-options']}
          endpoint="/stock/locations" filters={{ active_only: true }} required
          optionLabel={location => location.name} />
        <div className="stock-field"><label htmlFor="count-quantity">Quantidade contada *</label>
          <input id="count-quantity" required type="number" min={0} max={1000000} step={1}
            value={countedQuantity} onChange={event => changeDraft(setCountedQuantity, event.target.value)}/></div>
        <div className="stock-field"><label htmlFor="count-reason">Justificativa *</label>
          <input id="count-reason" required minLength={5} maxLength={500} value={reason}
            onChange={event => changeDraft(setReason, event.target.value)}/></div>
      </div>
      {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
      <button type="submit" className="button primary"
        disabled={create.isPending || !itemId || !locationId || !validCount || !validReason}>
        <Plus size={17}/>{create.isPending ? 'Registrando...' : 'Registrar contagem'}
      </button>
    </form>
    {selected !== null && <CountDetails key={selected} id={selected} token={token} role={role}
      userId={userId} onClose={() => setSelected(null)} onChanged={reviewed} />}
  </>;
}
