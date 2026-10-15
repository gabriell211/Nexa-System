import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { History, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Page, Printer } from '../types';

interface MeterSample {
  id: number;
  meter_total: string | null;
  meter_mono: string | null;
  meter_color: string | null;
  collected_at: string;
  received_at: string;
}

interface Props {
  printer: Printer;
  token: string | null;
  onClose: () => void;
}

function meter(value: string | null): string {
  if (value === null) return '—';
  try { return BigInt(value).toLocaleString('pt-BR'); }
  catch { return 'Valor inválido'; }
}

function date(value: string): string {
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? '—' : parsed.toLocaleString('pt-BR');
}

export function PrinterReadingHistoryPanel({ printer, token, onClose }: Props) {
  const [page, setPage] = useState(1);
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const invalid = Boolean(startDate && endDate && startDate > endDate);

  const listing = useQuery({
    queryKey: ['printer-reading-history', printer.id, page, startDate, endDate],
    queryFn: () => api<Page<MeterSample>>(
      '/printers/' + printer.id + '/readings' + queryString({
        page,
        per_page: 25,
        ...(startDate ? { start_date: startDate } : {}),
        ...(endDate ? { end_date: endDate } : {}),
      }),
      token,
    ),
    enabled: !invalid,
  });

  const current = listing.data?.meta?.current_page ?? listing.data?.current_page ?? 1;
  const last = listing.data?.meta?.last_page ?? listing.data?.last_page ?? 1;
  const total = listing.data?.meta?.total ?? listing.data?.total ?? 0;

  return <div className="modal-overlay">
    <section className="modal meter-history-modal" role="dialog" aria-modal="true"
      aria-labelledby="meter-history-heading">
      <div className="modal-header">
        <div>
          <span className="eyebrow">HISTÓRICO DE COLETA</span>
          <h2 id="meter-history-heading">Contadores — {printer.manufacturer} {printer.model}</h2>
          <p>{printer.serial_number || 'Sem número de série informado'}</p>
        </div>
        <button type="button" className="icon-button" onClick={onClose}
          aria-label="Fechar histórico de contadores"><X size={20}/></button>
      </div>
      <p className="stock-footnote">
        Somente amostras gravadas na API. A data da coleta vem do dispositivo;
        recebimento indica quando chegou ao servidor. Não indica impressora online,
        produção por período nem consumo calculado.
      </p>
      <div className="inline meter-history-filters">
        <label>Data inicial <input type="date" value={startDate}
          onChange={event => {setStartDate(event.target.value); setPage(1);}} /></label>
        <label>Data final <input type="date" value={endDate}
          onChange={event => {setEndDate(event.target.value); setPage(1);}} /></label>
        <button type="button" className="button ghost" onClick={() => {
          setStartDate(''); setEndDate(''); setPage(1);
        }}>Limpar período</button>
      </div>
      {invalid ? <p role="alert" className="notice error">A data final não pode ser anterior à inicial.</p>
        : listing.isPending ? <p role="status">Carregando leituras...</p>
        : listing.error ? <p role="alert" className="notice error">{readableError(listing.error)}</p>
        : listing.data.data.length === 0 ? <p>Nenhuma leitura encontrada para o período.</p>
        : <div className="table-wrap"><table>
            <thead><tr><th>Coletado em</th><th>Recebido em</th><th>Total</th><th>P&B</th><th>Cor</th></tr></thead>
            <tbody>{listing.data.data.map(sample => <tr key={sample.id}>
              <td>{date(sample.collected_at)}</td>
              <td>{date(sample.received_at)}</td>
              <td>{meter(sample.meter_total)}</td>
              <td>{meter(sample.meter_mono)}</td>
              <td>{meter(sample.meter_color)}</td>
            </tr>)}</tbody>
          </table></div>}
      {!invalid && listing.data && <div className="pagination">
        <span>{total} leitura(s) · Página {current} de {Math.max(1, last)}</span>
        <div className="inline">
          <button type="button" className="button ghost" disabled={current <= 1}
            onClick={() => setPage(current - 1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={current >= last}
            onClick={() => setPage(current + 1)}>Próxima</button>
        </div>
      </div>}
    </section>
  </div>;
}
