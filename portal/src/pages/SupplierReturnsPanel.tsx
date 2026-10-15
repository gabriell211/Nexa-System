import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, queryString, readableError } from '../api';
import type { Page } from '../types';

interface SupplierReturn {
  id: number; status: 'pending' | 'completed' | 'cancelled'; version: number;
  purchase_receipt_match_id: number; quantity: number; reason: string;
  movement_id: number | null; review_note: string | null;
  created_by: { id: number; name: string } | null;
  reviewed_by: { id: number; name: string } | null;
  receipt: { receipt_detail_id: number; lot_number: string; invoice_reference: string } | null;
}
interface MatchedReceipt {
  purchase_receipt_match_id: number;
  receipt_detail_id: number;
  invoice_reference: string;
  lot_number: string;
  quantity: number;
  returned_quantity: number;
  pending_return_quantity: number;
}
interface PurchaseLine {
  id: number;
  item: { sku: string; name: string } | null;
  receipts: MatchedReceipt[];
}

export function SupplierReturnsPanel({ orderId, lines, token, role, userId, onChanged }: {
  orderId: number; lines: PurchaseLine[]; token: string | null;
  role: string; userId: number; onChanged: () => Promise<void>;
}) {
  const queryClient = useQueryClient();
  const [matchId, setMatchId] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [reason, setReason] = useState('');
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const [notice, setNotice] = useState('');
  const [reviewNote, setReviewNote] = useState('');
  const matches = lines.flatMap(line => line.receipts.map(receipt => ({
    ...receipt, sku: line.item?.sku ?? 'Linha #' + line.id,
  })));
  const selected = matches.find(x => x.purchase_receipt_match_id === Number(matchId));
  const remaining = selected
    ? selected.quantity - selected.returned_quantity - selected.pending_return_quantity : 0;
  const integerQuantity = Number(quantity);
  const valid = Boolean(selected && /^[1-9][0-9]*$/.test(quantity) && integerQuantity <= remaining
    && integerQuantity <= 1000000 && reason.trim().length >= 5 && reason.trim().length <= 500);
  const isManager = ['owner','admin','manager'].includes(role);

  const returns = useQuery({
    queryKey: ['supplier-returns', orderId],
    queryFn: () => api<Page<SupplierReturn>>('/stock/supplier-returns' + queryString({
      purchase_order_id: orderId, per_page: 100,
    }), token),
  });

  async function refresh(): Promise<void> {
    await Promise.all([
      returns.refetch(),
      onChanged(),
      queryClient.invalidateQueries({ queryKey: ['stock-list'] }),
      queryClient.invalidateQueries({ queryKey: ['stock-lots'] }),
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] }),
    ]);
  }

  const create = useMutation({
    mutationFn: () => api('/stock/supplier-returns', token, {
      method: 'POST',
      body: JSON.stringify({
        request_id: requestId,
        purchase_receipt_match_id: Number(matchId),
        quantity: integerQuantity, reason: reason.trim(),
      }),
    }),
    onSuccess: async () => {
      setNotice('Devolução solicitada. A baixa do lote depende da aprovação de outra pessoa.');
      setMatchId(''); setQuantity('1'); setReason(''); setRequestId(crypto.randomUUID());
      await refresh();
    },
  });
  const approve = useMutation({
    mutationFn: (returnId: number) => {
      const item = returns.data?.data.find(r => r.id === returnId);
      return api('/stock/supplier-returns/' + returnId + '/approve', token, {
        method: 'POST',
        body: JSON.stringify({ expected_version: item?.version, review_note: reviewNote.trim() || null }),
      });
    },
    onSuccess: async () => {
      setNotice('Devolução aprovada: baixa física vinculada ao lote de origem.');
      setReviewNote('');
      await refresh();
    },
  });
  const cancel = useMutation({
    mutationFn: (item: SupplierReturn) => api('/stock/supplier-returns/' + item.id + '/cancel', token, {
      method: 'POST',
      body: JSON.stringify({
        expected_version: item.version,
        reason: 'Solicitação de devolução cancelada pelo operador após revisão',
      }),
    }),
    onSuccess: async () => {
      setNotice('Solicitação cancelada, sem movimentação de estoque.');
      await refresh();
    },
  });

  function dirty(): void {
    setRequestId(crypto.randomUUID());
    setNotice('');
    create.reset();
  }

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (valid && !create.isPending) create.mutate();
  }

  return <section className="stock-form" aria-label="Devoluções ao fornecedor">
    <h3>Devoluções ao fornecedor por lote</h3>
    <p className="stock-hint">
      Solicite a devolução de material previamente conciliado. A aprovação por outra pessoa
      executa uma baixa física do lote específico, sem apagar a nota nem gerar crédito financeiro.
    </p>
    <form onSubmit={submit}>
      <div className="stock-form-grid">
        <div className="stock-field">
          <label htmlFor="supplier-return-receipt">Recebimento e lote *</label>
          <select id="supplier-return-receipt" value={matchId} required disabled={create.isPending}
            onChange={event => { setMatchId(event.target.value); dirty(); }}>
            <option value="">Selecionar lote conciliado</option>
            {matches.map(match => <option key={match.purchase_receipt_match_id}
              value={match.purchase_receipt_match_id}
              disabled={match.quantity <= match.returned_quantity + match.pending_return_quantity}>
              {match.sku} — {match.invoice_reference} · {match.lot_number} ·
              {match.quantity - match.returned_quantity - match.pending_return_quantity} disponíveis
            </option>)}
          </select>
        </div>
        <div className="stock-field">
          <label htmlFor="supplier-return-quantity">Quantidade *</label>
          <input id="supplier-return-quantity" type="number" min={1} max={Math.max(1, remaining)}
            step={1} required disabled={create.isPending} value={quantity}
            onChange={event => { setQuantity(event.target.value); dirty(); }} />
        </div>
        <div className="stock-field">
          <label htmlFor="supplier-return-reason">Motivo da devolução *</label>
          <input id="supplier-return-reason" required minLength={5} maxLength={500}
            disabled={create.isPending} value={reason}
            onChange={event => { setReason(event.target.value); dirty(); }} />
        </div>
      </div>
      {create.error && <p className="notice error" role="alert">{readableError(create.error)}</p>}
      <button className="button primary" type="submit" disabled={!valid || create.isPending}>
        {create.isPending ? 'Registrando...' : 'Solicitar devolução para revisão'}
      </button>
    </form>
    {notice && <p className="notice success" role="status">{notice}</p>}
    <h4>Histórico e aprovações</h4>
    {returns.isPending ? <p role="status">Carregando devoluções...</p> :
      returns.error ? <p role="alert" className="notice error">{readableError(returns.error)}</p> :
      returns.data.data.length === 0 ? <p>Sem devoluções registradas para este pedido.</p> :
      <div className="table-wrap"><table>
        <thead><tr><th>Devolução</th><th>Nota e lote</th><th>Quantidade</th><th>Estado</th>
          <th>Solicitante</th><th>Baixa</th><th>Ações</th></tr></thead>
        <tbody>{returns.data.data.map(entry => <tr key={entry.id}>
          <td>#{entry.id}</td>
          <td>{entry.receipt?.invoice_reference ?? '—'}<small>{entry.receipt?.lot_number ?? '—'}</small></td>
          <td>{entry.quantity.toLocaleString('pt-BR')}</td>
          <td>{entry.status === 'pending' ? 'Aguardando aprovação' :
            entry.status === 'completed' ? 'Concluída' : 'Cancelada'}</td>
          <td>{entry.created_by?.name ?? '—'}</td>
          <td>{entry.movement_id ? '#' + entry.movement_id : '—'}</td>
          <td>
            {entry.status === 'pending' && isManager && entry.created_by?.id !== userId &&
              <button type="button" className="button primary" disabled={approve.isPending || cancel.isPending}
                aria-label={'Aprovar devolução ' + entry.id}
                onClick={() => approve.mutate(entry.id)}>Aprovar</button>}
            {entry.status === 'pending' &&
              (isManager || entry.created_by?.id === userId) &&
              <button type="button" className="button ghost" disabled={approve.isPending || cancel.isPending}
                aria-label={'Cancelar devolução ' + entry.id}
                onClick={() => cancel.mutate(entry)}>Cancelar</button>}
          </td>
        </tr>)}</tbody>
      </table></div>}
    {isManager && returns.data?.data.some(entry => entry.status === 'pending' &&
      entry.created_by?.id !== userId) && <div className="stock-field">
      <label htmlFor="supplier-return-review-note">Nota da aprovação (opcional)</label>
      <input id="supplier-return-review-note" maxLength={500} value={reviewNote}
        onChange={event => setReviewNote(event.target.value)} />
    </div>}
    {approve.error && <p role="alert" className="notice error">{readableError(approve.error)}</p>}
    {cancel.error && <p role="alert" className="notice error">{readableError(cancel.error)}</p>}
    {(returns.data?.meta?.total ?? returns.data?.total ?? 0) > 100 &&
      <p className="stock-hint">Exibindo as 100 devoluções mais recentes deste pedido.</p>}
  </section>;
}
