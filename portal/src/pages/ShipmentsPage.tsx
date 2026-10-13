import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRightLeft, CheckCircle2, PackageCheck, PackageOpen, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Page } from '../types';

interface StockItem { id: number; sku: string; name: string; active: boolean }
interface StockLocation { id: number; name: string; active: boolean }
type EventKind = 'dispatch' | 'receive' | 'close';
interface ShipmentEvent {
  id: number; kind: EventKind; quantity: number; reason: string; created_at: string;
  actor: { id: number; name: string } | null;
}
interface Shipment {
  id: number; item_id: number; source_location_id: number; destination_location_id: number;
  quantity: number; shipped_quantity: number; received_quantity: number; cancelled_quantity: number;
  lost_quantity: number; reserved_quantity: number; in_transit_quantity: number;
  status: string; reason: string; created_at: string;
  item: StockItem; source: StockLocation; destination: StockLocation;
  events?: ShipmentEvent[];
}

const statusNames: Record<string, string> = {
  reserved: 'Reservada', partially_dispatched: 'Envio parcial',
  in_transit: 'Em trânsito', partially_received: 'Recebimento parcial',
  received: 'Recebida', cancelled: 'Cancelada',
  closed_partial: 'Encerrada parcialmente', closed_with_loss: 'Encerrada com falta',
};

function ShipmentCreate({ token, onSaved }: { token: string | null; onSaved: () => Promise<void> }) {
  const [itemId, setItemId] = useState('');
  const [sourceId, setSourceId] = useState('');
  const [destinationId, setDestinationId] = useState('');
  const [quantity, setQuantity] = useState(1);
  const [reason, setReason] = useState('');
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const items = useQuery({
    queryKey: ['shipment-item-options'],
    queryFn: () => api<Page<StockItem>>('/stock/items' + queryString({per_page:100}), token),
  });
  const locations = useQuery({
    queryKey: ['shipment-location-options'],
    queryFn: () => api<Page<StockLocation>>('/stock/locations' + queryString({per_page:100}), token),
  });
  const create = useMutation({
    mutationFn: () => api<unknown>('/stock/shipments',token,{
      method:'POST',
      body: JSON.stringify({
        request_id:requestId,item_id:Number(itemId),
        source_location_id:Number(sourceId),destination_location_id:Number(destinationId),
        quantity,reason:reason.trim(),
      }),
    }),
    onSuccess: async () => {
      setReason('');setRequestId(crypto.randomUUID());await onSaved();
    },
  });
  function change<T>(set: (value:T)=>void, value:T) {
    set(value);setRequestId(crypto.randomUUID());
  }
  function submit(e: FormEvent<HTMLFormElement>) {e.preventDefault();create.mutate();}
  const optionsError=items.error||locations.error;
  const activePlaces=(locations.data?.data??[]).filter(x=>x.active);
  return <form className="stock-form" onSubmit={submit}>
    <h3>Nova reserva e remessa</h3>
    <p className="stock-hint">A reserva separa o material disponível sem dar baixa. A baixa acontece ao confirmar a expedição.</p>
    <div className="stock-form-grid">
      <div className="stock-field"><label htmlFor="ship-item">Material *</label>
        <select required id="ship-item" value={itemId} onChange={e=>change(setItemId,e.target.value)}>
          <option value="">Selecionar</option>
          {(items.data?.data??[]).filter(x=>x.active).map(x=><option key={x.id} value={x.id}>{x.sku} — {x.name}</option>)}
        </select></div>
      <div className="stock-field"><label htmlFor="ship-qty">Quantidade *</label>
        <input id="ship-qty" type="number" min={1} max={1000000} step={1} value={quantity}
          required onChange={e=>change(setQuantity,Number(e.target.value))}/></div>
      <div className="stock-field"><label htmlFor="ship-from">Origem *</label>
        <select required id="ship-from" value={sourceId} onChange={e=>change(setSourceId,e.target.value)}>
          <option value="">Selecionar</option>
          {activePlaces.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}
        </select></div>
      <div className="stock-field"><label htmlFor="ship-to">Destino *</label>
        <select required id="ship-to" value={destinationId} onChange={e=>change(setDestinationId,e.target.value)}>
          <option value="">Selecionar</option>
          {activePlaces.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}
        </select></div>
      <div className="stock-field"><label htmlFor="ship-reason">Justificativa *</label>
        <input id="ship-reason" value={reason} required minLength={5} maxLength={500}
          onChange={e=>change(setReason,e.target.value)}/></div>
    </div>
    {optionsError && <p role="alert" className="notice error">{readableError(optionsError)}</p>}
    {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
    <button className="button primary" type="submit" disabled={
      create.isPending || items.isPending || locations.isPending ||
      Boolean(optionsError) || !itemId || !sourceId || !destinationId || sourceId===destinationId
    }><PackageOpen size={17}/> {create.isPending ? 'Reservando...' : 'Reservar estoque'}</button>
  </form>;
}

function ShipmentDetail({ id, token, canClose, onClose, onChanged }: {
  id: number;token:string|null;canClose:boolean;onClose:()=>void;onChanged:()=>Promise<void>;
}) {
  const qc=useQueryClient();
  const [kind,setKind]=useState<EventKind>('dispatch');
  const [quantity,setQuantity]=useState(1);
  const [reason,setReason]=useState('');
  const [requestId,setRequestId]=useState(()=>crypto.randomUUID());
  const details=useQuery({
    queryKey:['shipment-detail',id],
    queryFn:()=>api<{data:Shipment}>('/stock/shipments/'+id,token),
  });
  const shipment=details.data?.data;
  const pending=shipment?.reserved_quantity??0;
  const transit=shipment?.in_transit_quantity??0;
  // Hide invalid actions after a partial receipt or full dispatch without
  // accidentally submitting an action absent from the select options.
  const activeKind: EventKind = kind==='dispatch'&&pending===0
    ? (transit>0?'receive':'close')
    : kind==='receive'&&transit===0 ? (pending>0?'dispatch':'close') : kind;
  const mutation=useMutation({
    mutationFn:()=>api<{data:Shipment}>('/stock/shipments/'+id+'/'+activeKind,token,{
      method:'POST',
      body:JSON.stringify({
        request_id:requestId,reason:reason.trim(),
        ...(activeKind==='close'?{}:{quantity}),
      }),
    }),
    onSuccess:async()=>{
      setReason('');setRequestId(crypto.randomUUID());
      await Promise.all([
        qc.invalidateQueries({queryKey:['shipment-detail',id]}),
        onChanged(),
      ]);
    },
  });
  function change<T>(set:(value:T)=>void,value:T){set(value);setRequestId(crypto.randomUUID());}
  function submit(e:FormEvent<HTMLFormElement>){e.preventDefault();mutation.mutate();}
  const closed=['received','cancelled','closed_with_loss','closed_partial'].includes(shipment?.status??'');
  const max=activeKind==='dispatch'?pending:transit;
  const valid=activeKind==='close'||(quantity>0&&quantity<=max);
  const when=(date:string)=>new Date(date).toLocaleString('pt-BR');
  return <div className="modal-overlay"><section className="modal printer-assignment-modal" role="dialog"
    aria-modal="true" aria-labelledby="shipment-heading">
    <div className="modal-header">
      <div><span className="eyebrow">RASTREAMENTO DE MATERIAIS</span>
        <h2 id="shipment-heading">Remessa #{id}</h2></div>
      <button className="icon-button" aria-label="Fechar detalhes" onClick={onClose}><X size={18}/></button>
    </div>
    {details.isPending?<div className="loading">Carregando remessa...</div>:details.error?
      <p role="alert" className="notice error">{readableError(details.error)}</p>:shipment&&<>
      <p className="stock-hint">{shipment.item.sku} — {shipment.item.name}</p>
      <p>{shipment.source.name} → {shipment.destination.name}</p>
      <p><strong>{statusNames[shipment.status]??shipment.status}</strong> · {shipment.reason}</p>
      <div className="shipment-metrics">
        <div><strong>{shipment.quantity}</strong><small>Solicitado</small></div>
        <div><strong>{pending}</strong><small>Reservado</small></div>
        <div><strong>{shipment.shipped_quantity}</strong><small>Enviado</small></div>
        <div><strong>{transit}</strong><small>Em trânsito</small></div>
        <div><strong>{shipment.received_quantity}</strong><small>Recebido</small></div>
        <div><strong>{shipment.lost_quantity}</strong><small>Falta</small></div>
      </div>
      {!closed&&<form className="stock-form shipment-event-form" onSubmit={submit}>
        <h3>Registrar evento</h3>
        <div className="stock-form-grid">
          <div className="stock-field"><label htmlFor="ship-action">Operação</label>
            <select id="ship-action" value={activeKind} onChange={e=>{
              change(setKind,e.target.value as EventKind);setQuantity(1);
            }}>
              {pending>0&&<option value="dispatch">Expedir material</option>}
              {transit>0&&<option value="receive">Confirmar recebimento</option>}
              {canClose&&<option value="close">Encerrar (falta/cancelamento)</option>}
            </select></div>
          {activeKind!=='close'&&<div className="stock-field"><label htmlFor="ship-event-qty">Quantidade · máximo {max}</label>
            <input id="ship-event-qty" type="number" min={1} max={max} value={quantity}
              onChange={e=>change(setQuantity,Number(e.target.value))} required/></div>}
          <div className="stock-field"><label htmlFor="ship-event-reason">Justificativa</label>
            <input id="ship-event-reason" minLength={5} maxLength={500} required
              value={reason} onChange={e=>change(setReason,e.target.value)}/></div>
        </div>
        {activeKind==='close'&&<p className="stock-hint">Ao encerrar, o saldo ainda reservado é cancelado. Quantidades expedidas e não recebidas são registradas como falta, sem entrada fictícia.</p>}
        {mutation.error&&<p role="alert" className="notice error">{readableError(mutation.error)}</p>}
        <button className="button primary" disabled={mutation.isPending||!valid}
          type="submit"><CheckCircle2 size={16}/>{mutation.isPending?'Registrando...':'Confirmar operação'}</button>
      </form>}
      <section className="assignment-history">
        <h3>Eventos auditáveis</h3>
        {shipment.events?.length===0?<p>Nenhum evento registrado.</p>:
          <div className="table-wrap"><table><thead><tr><th>Tipo</th><th>Qtd.</th><th>Quem</th><th>Data</th><th>Justificativa</th></tr></thead>
            <tbody>{(shipment.events??[]).map(e=><tr key={e.id}>
              <td>{({dispatch:'Expedição',receive:'Recebimento',close:'Encerramento'} as Record<string,string>)[e.kind]}</td>
              <td>{e.quantity}</td><td>{e.actor?.name??'—'}</td><td>{when(e.created_at)}</td>
              <td>{e.reason}</td></tr>)}</tbody></table></div>}
      </section>
    </>}
  </section></div>;
}

export function ShipmentsPage({token,canClose}:{token:string|null;canClose:boolean}) {
  const qc=useQueryClient();
  const [page,setPage]=useState(1);
  const [selected,setSelected]=useState<number|null>(null);
  const [notice,setNotice]=useState('');
  const listing=useQuery({
    queryKey:['shipments',page],
    queryFn:()=>api<Page<Shipment>>('/stock/shipments'+queryString({page,per_page:25}),token),
  });
  async function refresh(){
    setNotice('Remessa registrada e atualizada no estoque.');
    await qc.invalidateQueries({queryKey:['shipments']});
    await qc.invalidateQueries({queryKey:['stock-list']});
  }
  const current=listing.data?.meta?.current_page??listing.data?.current_page??1;
  const last=listing.data?.meta?.last_page??listing.data?.last_page??1;
  return <div>
    <div className="page-heading"><div><span className="eyebrow">LOGÍSTICA DE REPOSIÇÃO</span>
      <h1>Remessas e recebimentos</h1>
      <p>Reserva, expedição, trânsito e recebimentos parciais com rastreabilidade.</p>
    </div><ArrowRightLeft size={33} color="#397cf7"/></div>
    {notice&&<p role="status" className="notice success">{notice}</p>}
    <section className="data-card">
      {listing.isPending?<div className="loading">Carregando remessas...</div>:listing.error?
        <p role="alert" className="notice error">{readableError(listing.error)}</p>:
        listing.data.data.length===0?<div className="empty-state">Nenhuma remessa cadastrada.</div>:
        <div className="table-wrap"><table><thead><tr><th>Remessa</th><th>Item</th><th>Origem / destino</th><th>Solicitado</th><th>Em trânsito</th><th>Recebido</th><th>Estado</th><th>Ações</th></tr></thead>
          <tbody>{listing.data.data.map(s=><tr key={s.id}>
            <td><strong>#{s.id}</strong><small>{new Date(s.created_at).toLocaleString('pt-BR')}</small></td>
            <td>{s.item.sku}<small>{s.item.name}</small></td>
            <td>{s.source.name} → {s.destination.name}</td><td>{s.quantity}</td>
            <td>{s.in_transit_quantity}</td><td>{s.received_quantity}</td>
            <td>{statusNames[s.status]??s.status}</td><td><button className="button ghost"
              onClick={()=>setSelected(s.id)}><PackageCheck size={16}/> Detalhes</button></td>
          </tr>)}</tbody></table></div>}
      {listing.data&&<div className="pagination"><span>Página {current} de {last}</span>
        <div className="inline">
          <button className="button ghost" disabled={current<=1} onClick={()=>setPage(current-1)}>Anterior</button>
          <button className="button ghost" disabled={current>=last} onClick={()=>setPage(current+1)}>Próxima</button>
        </div></div>}
    </section>
    <ShipmentCreate token={token} onSaved={refresh}/>
    {selected!==null&&<ShipmentDetail key={selected} id={selected} token={token} canClose={canClose}
      onClose={()=>setSelected(null)} onChanged={refresh}/>}
  </div>;
}
