import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import { currencyFromCents } from '../utils/currency';

interface MatchableReceipt {
  id: number;
  invoice_reference: string;
  lot_number: string;
  unit_cost_cents: number;
  supplier_id: number;
  movement: {
    item_id: number;
    destination_location_id: number;
    quantity: number;
  };
}

interface PurchaseLine {
  id: number;
  quantity: number;
  unit_cost_cents: number;
  received_quantity: number;
  outstanding_quantity: number;
  item: { id: number; sku: string; name: string; unit: string } | null;
  destination: { id: number; name: string } | null;
}

export function PurchaseReceiptMatchPanel({ orderId, supplierId, lines, token, onChanged }: {
  orderId: number;
  supplierId: number;
  lines: PurchaseLine[];
  token: string | null;
  onChanged: () => Promise<void>;
}) {
  const queryClient = useQueryClient();
  const [lineId, setLineId] = useState('');
  const [receiptId, setReceiptId] = useState('');
  const [receipt, setReceipt] = useState<MatchableReceipt | null>(null);
  const [reason, setReason] = useState('');
  const [notice, setNotice] = useState('');
  const line = lines.find(entry => entry.id === Number(lineId)) ?? null;
  const variance = Boolean(line && receipt && line.unit_cost_cents !== receipt.unit_cost_cents);
  const matching = Boolean(line && receipt &&
    line.outstanding_quantity >= receipt.movement.quantity &&
    line.item?.id === receipt.movement.item_id &&
    line.destination?.id === receipt.movement.destination_location_id);
  const canSubmit = matching && (!variance || (reason.trim().length >= 5 && reason.trim().length <= 500));

  const create = useMutation({
    mutationFn: () => api('/stock/purchase-orders/' + orderId + '/reconcile-receipt', token, {
      method: 'POST',
      body: JSON.stringify({
        purchase_order_line_id: Number(lineId),
        receipt_detail_id: Number(receiptId),
        variance_reason: variance ? reason.trim() : null,
      }),
    }),
    onSuccess: async () => {
      setNotice('Recebimento conciliado e auditado, sem nova entrada de estoque.');
      setReceiptId(''); setReceipt(null); setReason('');
      await Promise.all([
        onChanged(),
        queryClient.invalidateQueries({ queryKey: ['purchase-receipt-options'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-receipts'] }),
      ]);
    },
  });

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (canSubmit && !create.isPending) create.mutate();
  }

  return <section className="stock-form" aria-label="Conciliação de recebimentos">
    <h3>Conciliar recebimento físico com compra</h3>
    <p className="stock-hint">
      Escolha uma entrada documentada já registrada. A conciliação apenas vincula nota, lote,
      quantidade e custo ao pedido aprovado; não cria outro lançamento no estoque.
    </p>
    <form onSubmit={submit}>
      <div className="stock-form-grid">
        <div className="stock-field">
          <label htmlFor="purchase-match-line">Linha do pedido *</label>
          <select id="purchase-match-line" required value={lineId} disabled={create.isPending}
            onChange={event => {
              setLineId(event.target.value); setReceiptId(''); setReceipt(null);
              setReason(''); setNotice(''); create.reset();
            }}>
            <option value="">Selecione a linha</option>
            {lines.map(entry => <option key={entry.id} value={entry.id} disabled={entry.outstanding_quantity === 0}>
              {entry.item?.sku ?? '#' + entry.id} — {entry.outstanding_quantity} restantes
            </option>)}
          </select>
        </div>
        {line?.item && line.destination && <PagedEntitySelect<MatchableReceipt>
          key={lineId} id="purchase-match-receipt" label="Entrada documentada *"
          searchLabel="Buscar nota ou lote para conciliar" placeholder="Número da nota ou lote"
          emptyLabel="Selecione o recebimento" value={receiptId}
          onChange={(value, chosen) => { setReceiptId(value); setReceipt(chosen); create.reset(); setNotice(''); }}
          queryKey={['purchase-receipt-options', lineId]} endpoint="/stock/receipts"
          filters={{
            supplier_id: supplierId, item_id: line.item.id,
            destination_location_id: line.destination.id, unmatched_only: 1,
          }}
          optionLabel={entry =>
            '#' + entry.id + ' · ' + entry.invoice_reference + ' · ' + entry.lot_number +
            ' · ' + entry.movement.quantity + ' un. · ' + currencyFromCents(entry.unit_cost_cents)}
          required disabled={create.isPending} />}
      </div>
      {line && <p className="stock-hint">
        Pedido: {line.received_quantity} de {line.quantity} unidades recebidas.
        Custo aprovado: {currencyFromCents(line.unit_cost_cents)} por unidade.
      </p>}
      {receipt && <p className="stock-hint">
        Entrada #{receipt.id}: {receipt.movement.quantity} un. ·
        Custo registrado: {currencyFromCents(receipt.unit_cost_cents)}.
      </p>}
      {receipt && !matching && <p role="alert" className="notice error">
        A entrada selecionada excede o saldo restante ou não pertence ao material/destino da compra.
      </p>}
      {variance && <div className="stock-field">
        <label htmlFor="purchase-match-variance">Justificativa da diferença de custo *</label>
        <textarea id="purchase-match-variance" required minLength={5} maxLength={500}
          value={reason} rows={2} disabled={create.isPending}
          onChange={event => setReason(event.target.value)} />
      </div>}
      {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
      {notice && <p role="status" className="notice success">{notice}</p>}
      <button type="submit" className="button primary" disabled={!canSubmit || create.isPending}>
        {create.isPending ? 'Conciliando...' : 'Confirmar conciliação sem movimentar estoque'}
      </button>
    </form>
  </section>;
}
