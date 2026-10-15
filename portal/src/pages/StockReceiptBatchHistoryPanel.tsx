import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import { currencyFromCents } from '../utils/currency';
import type { Page } from '../types';

interface BatchReceipt {
  id: number;
  invoice_reference: string;
  supplier_id: number;
  supplier?: { id: number; code: string; name: string } | null;
  line_count: number;
  request_id: string;
  created_at: string;
  lines?: Array<{
    line_number: number;
    receipt: {
      id: number;
      lot_number: string;
      expires_on: string | null;
      unit_cost_cents: number;
      movement: {
        quantity: number;
        item_id: number;
        item: { sku: string; name: string } | null;
        destination: { name: string } | null;
      };
    };
  }>;
}

export function StockReceiptBatchHistoryPanel({ token }: { token: string | null }) {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<number | null>(null);
  const list = useQuery({
    queryKey: ['stock-receipt-batches', page, search],
    queryFn: () => api<Page<BatchReceipt>>('/stock/receipt-batches' + queryString({
      page, per_page: 25, ...(search.trim() ? { q: search.trim() } : {}),
    }), token),
  });
  const detail = useQuery({
    queryKey: ['stock-receipt-batch', selected],
    queryFn: () => api<{ data: BatchReceipt }>('/stock/receipt-batches/' + selected, token),
    enabled: selected !== null,
  });

  const currentPage = list.data?.meta?.current_page ?? list.data?.current_page ?? page;
  const lastPage = list.data?.meta?.last_page ?? list.data?.last_page ?? 1;
  const total = list.data?.meta?.total ?? list.data?.total ?? 0;

  return <>
    <section className="data-card stock-listing" aria-label="Notas recebidas em lote">
      <h2>Notas com múltiplos itens</h2>
      <p className="stock-hint">
        Cada registro agrupa as linhas confirmadas na mesma operação; notas individuais
        continuam no histórico de entradas acima.
      </p>
      <div className="stock-field">
        <label htmlFor="receipt-batch-search">Buscar referência da nota</label>
        <input id="receipt-batch-search" type="search" maxLength={100}
          value={search} placeholder="Número ou referência"
          onChange={event => { setSearch(event.target.value); setPage(1); }} />
      </div>
      {list.isPending ? <p role="status">Carregando notas em lote...</p> :
        list.error ? <p role="alert" className="notice error">{readableError(list.error)}</p> :
        list.data.data.length === 0 ? <p>Nenhuma nota em lote encontrada.</p> :
        <div className="table-wrap">
          <table><thead><tr>
            <th>Nota</th><th>Fornecedor</th><th>Itens</th><th>Registrada em</th><th>Ações</th>
          </tr></thead>
          <tbody>{list.data.data.map(batch => <tr key={batch.id}>
            <td><strong>{batch.invoice_reference}</strong><small>#{batch.id}</small></td>
            <td>{batch.supplier?.name ?? 'Fornecedor #' + batch.supplier_id}
              <small>{batch.supplier?.code ?? '—'}</small></td>
            <td>{batch.line_count}</td>
            <td>{new Date(batch.created_at).toLocaleString('pt-BR')}</td>
            <td><button className="button ghost" type="button"
              aria-label={'Ver itens da nota ' + batch.invoice_reference}
              onClick={() => setSelected(batch.id)}>Ver itens</button></td>
          </tr>)}</tbody></table>
        </div>}
      {!list.isPending && !list.error && <div className="pagination">
        <span>Página {currentPage} de {lastPage} · {total} notas</span>
        <div className="inline">
          <button type="button" className="button ghost" disabled={currentPage <= 1}
            onClick={() => setPage(currentPage - 1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={currentPage >= lastPage}
            onClick={() => setPage(currentPage + 1)}>Próxima</button>
        </div>
      </div>}
    </section>

    {selected !== null && <div className="modal-overlay">
      <section className="modal printer-assignment-modal" role="dialog" aria-modal="true"
        aria-labelledby="receipt-batch-details-heading">
        <div className="modal-header">
          <div><span className="eyebrow">RECEBIMENTO DOCUMENTADO</span>
            <h2 id="receipt-batch-details-heading">Nota em lote #{selected}</h2></div>
          <button type="button" className="icon-button" aria-label="Fechar nota em lote"
            onClick={() => setSelected(null)}><X size={18} /></button>
        </div>
        {detail.isPending ? <p role="status">Carregando itens da nota...</p> :
          detail.error ? <p role="alert" className="notice error">{readableError(detail.error)}</p> :
          detail.data && <>
            <p><strong>Referência:</strong> {detail.data.data.invoice_reference}</p>
            <p><strong>Linhas confirmadas:</strong> {detail.data.data.line_count}</p>
            <p><strong>Identificador imutável:</strong> <code>{detail.data.data.request_id}</code></p>
            <div className="table-wrap"><table><thead><tr>
              <th>Material</th><th>Local</th><th>Lote</th><th>Quantidade</th><th>Valor unitário</th>
            </tr></thead><tbody>
              {(detail.data.data.lines ?? []).map(line => <tr key={line.line_number}>
                <td>{line.receipt.movement.item?.sku ?? '#' + line.receipt.movement.item_id}</td>
                <td>{line.receipt.movement.destination?.name ?? '—'}</td>
                <td>{line.receipt.lot_number}</td>
                <td>{line.receipt.movement.quantity.toLocaleString('pt-BR')}</td>
                <td>{currencyFromCents(line.receipt.unit_cost_cents)}</td>
              </tr>)}
            </tbody></table></div>
          </>}
      </section>
    </div>}
  </>;
}
