import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, queryString, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import type { Page } from '../types';

interface LocationOption { id: number; name: string; active: boolean }
interface ApprovedLine {
  id: number;
  item: { sku: string; name: string; unit: string };
  location: { name: string };
  quantity: number;
  allocated_quantity: number;
  remaining_quantity: number;
}
interface LinkedShipment {
  id: number;
  status: string;
  quantity: number;
  shipped_quantity: number;
  received_quantity: number;
  replenishment_plan_item_id: number | null;
}

export function ApprovedPlanShipmentPanel({ planId, lines, token, onChanged }: {
  planId: number;
  lines: ApprovedLine[];
  token: string | null;
  onChanged: (message: string) => Promise<void>;
}) {
  const queryClient = useQueryClient();
  const [lineId, setLineId] = useState('');
  const [sourceId, setSourceId] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [reason, setReason] = useState('');
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const [notice, setNotice] = useState('');
  const selectedLine = lines.find(line => line.id === Number(lineId)) ?? null;
  const activeLine = selectedLine && selectedLine.remaining_quantity > 0 ? selectedLine : null;
  const numericQuantity = Number(quantity);
  const canReserve = Boolean(activeLine && sourceId && reason.trim().length >= 5 &&
    reason.trim().length <= 500 && /^[1-9][0-9]*$/.test(quantity) &&
    numericQuantity <= activeLine.remaining_quantity && numericQuantity <= 1000000);

  const shipments = useQuery({
    queryKey: ['plan-linked-shipments', lineId],
    queryFn: () => api<Page<LinkedShipment>>('/stock/shipments' + queryString({
      replenishment_plan_item_id: Number(lineId), per_page: 25,
    }), token),
    enabled: lineId !== '',
  });

  const create = useMutation({
    mutationFn: () => api<{ data: LinkedShipment }>(
      '/stock/replenishment-plans/' + planId + '/items/' + lineId + '/reserve-shipment', token, {
        method: 'POST',
        body: JSON.stringify({
          request_id: requestId,
          source_location_id: Number(sourceId),
          quantity: numericQuantity,
          reason: reason.trim(),
        }),
      },
    ),
    onSuccess: async response => {
      setNotice('Remessa #' + response.data.id + ' vinculada e reservada. A expedição ainda exige confirmação.');
      setRequestId(crypto.randomUUID());
      setQuantity('1');
      setReason('');
      await Promise.all([
        onChanged('Reserva vinculada ao plano e auditada.'),
        queryClient.invalidateQueries({ queryKey: ['plan-linked-shipments'] }),
        queryClient.invalidateQueries({ queryKey: ['shipment-list'] }),
        queryClient.invalidateQueries({ queryKey: ['stock-list'] }),
      ]);
    },
  });

  function dirty(): void {
    setRequestId(crypto.randomUUID());
    setNotice('');
    create.reset();
  }

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (canReserve && !create.isPending) create.mutate();
  }

  return <section className="stock-form" aria-label="Executar reposição aprovada">
    <h3>Vincular remessa à proposta aprovada</h3>
    <p className="stock-hint">
      O operador escolhe a origem e confirma cada reserva. O destino é o local aprovado na proposta.
      Reservar não movimenta estoque; expedição e recebimento são confirmados separadamente em Remessas.
    </p>
    <form onSubmit={submit}>
      <div className="stock-form-grid">
        <div className="stock-field">
          <label htmlFor="plan-shipment-line">Item aprovado *</label>
          <select id="plan-shipment-line" required value={lineId} disabled={create.isPending}
            onChange={event => { setLineId(event.target.value); dirty(); }}>
            <option value="">Selecionar linha</option>
            {lines.map(line => <option key={line.id} value={line.id}>
              {line.item.sku} — {line.location.name} · disponível {line.remaining_quantity}/{line.quantity}
            </option>)}
          </select>
        </div>
        <PagedEntitySelect<LocationOption> id="plan-shipment-source" label="Origem da remessa *"
          searchLabel="Buscar origem da remessa" placeholder="Depósito ou operador"
          emptyLabel="Selecionar origem" value={sourceId}
          onChange={value => { setSourceId(value); dirty(); }}
          queryKey={['plan-shipment-location-options']} endpoint="/stock/locations"
          filters={{ active_only: true }} required disabled={create.isPending}
          optionLabel={location => location.name} />
        <div className="stock-field">
          <label htmlFor="plan-shipment-quantity">Quantidade para reservar *</label>
          <input id="plan-shipment-quantity" type="number" min={1} max={activeLine?.remaining_quantity ?? 1}
            step={1} required value={quantity} disabled={create.isPending}
            onChange={event => { setQuantity(event.target.value); dirty(); }} />
        </div>
        <div className="stock-field">
          <label htmlFor="plan-shipment-reason">Justificativa da reserva *</label>
          <input id="plan-shipment-reason" required minLength={5} maxLength={500}
            value={reason} disabled={create.isPending}
            onChange={event => { setReason(event.target.value); dirty(); }} />
        </div>
      </div>
      {selectedLine && <p className="stock-hint">
        {selectedLine.allocated_quantity.toLocaleString('pt-BR')} já alocados de {selectedLine.quantity.toLocaleString('pt-BR')}.
        Restam {selectedLine.remaining_quantity.toLocaleString('pt-BR')} {selectedLine.item.unit} para reservar.
      </p>}
      {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
      <button className="button primary" type="submit" disabled={!canReserve || create.isPending}>
        {create.isPending ? 'Reservando...' : 'Confirmar reserva vinculada'}
      </button>
    </form>
    {notice && <p role="status" className="notice success">{notice}</p>}
    {lineId && <div>
      <h4>Remessas vinculadas à linha</h4>
      {shipments.isPending ? <p role="status">Carregando remessas...</p> :
        shipments.error ? <p role="alert" className="notice error">{readableError(shipments.error)}</p> :
        shipments.data.data.length === 0 ? <p>Nenhuma remessa vinculada.</p> :
        <div className="table-wrap"><table><thead><tr>
          <th>Remessa</th><th>Estado</th><th>Reservado</th><th>Enviado</th><th>Recebido</th>
        </tr></thead><tbody>{shipments.data.data.map(shipment => <tr key={shipment.id}>
          <td>#{shipment.id}</td><td>{shipment.status}</td>
          <td>{shipment.quantity.toLocaleString('pt-BR')}</td>
          <td>{shipment.shipped_quantity.toLocaleString('pt-BR')}</td>
          <td>{shipment.received_quantity.toLocaleString('pt-BR')}</td>
        </tr>)}</tbody></table></div>}
      {shipments.data && (shipments.data.meta?.total ?? shipments.data.total ?? 0) > 25 &&
        <p className="stock-hint">Exibindo as primeiras 25 remessas. Consulte o módulo Remessas para o histórico completo.</p>}
    </div>}
  </section>;
}
