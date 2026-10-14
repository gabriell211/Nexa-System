import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Activity, RadioTower } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Page } from '../types';

type State = 'recent'|'late'|'no_heartbeat'|'revoked';
interface Collector {
  id:number;collector_id:string;customer_id:number;active:boolean;
  heartbeat_at:string|null;last_upload_at:string|null;
  heartbeat_state:State;heartbeat_tolerance_seconds:number;
  customer:{id:number;name:string}|null;
}
const states:Record<State,string> = {
  recent:'Heartbeat recente',late:'Sem heartbeat recente',
  no_heartbeat:'Nunca recebido',revoked:'Credencial revogada',
};
function dateOrUnknown(value:string|null):string {
  return value ? new Date(value).toLocaleString('pt-BR') : 'Nunca recebido';
}

export function CollectorsPage() {
  const [page,setPage]=useState(1);
  const listing=useQuery({
    queryKey:['collector-agents',page],
    queryFn:()=>api<Page<Collector>>(
      '/collector-agents'+queryString({page,per_page:25}),null
    ),
    refetchInterval:60_000,
  });
  const current=listing.data?.meta?.current_page??listing.data?.current_page??1;
  const last=listing.data?.meta?.last_page??listing.data?.last_page??1;

  return <div>
    <div className="page-heading">
      <div><span className="eyebrow">MONITORAMENTO DOS PONTOS</span>
        <h1>Agentes de coleta</h1>
        <p>Heartbeat do serviço e última entrega de contadores são sinais independentes.</p>
      </div><RadioTower size={32} aria-hidden="true"/>
    </div>
    <section className="data-card">
      <h2>Estado observado dos agentes</h2>
      <p className="stock-footnote">
        Um heartbeat recente comprova somente que o serviço conseguiu se autenticar na API.
        Não comprova que a impressora esteja ligada, que SNMP funcione nem que existam novos contadores.
      </p>
      {listing.isPending?<p role="status">Carregando agentes...</p>:listing.error?
        <p role="alert" className="notice error">{readableError(listing.error)}</p>:
        listing.data.data.length===0?<p>Nenhum agente registrado nesta carteira.</p>:
        <div className="table-wrap"><table><thead><tr>
          <th>Agente</th><th>Cliente</th><th>Estado do heartbeat</th>
          <th>Último heartbeat</th><th>Último upload</th><th>Tolerância</th>
        </tr></thead><tbody>
          {listing.data.data.map(a=><tr key={a.id}>
            <td><strong>#{a.id}</strong><small>{a.collector_id}</small></td>
            <td>{a.customer?.name??'—'}</td>
            <td><span className="inline"><Activity size={15} aria-hidden="true"/> {states[a.heartbeat_state]}</span></td>
            <td>{dateOrUnknown(a.heartbeat_at)}</td>
            <td>{dateOrUnknown(a.last_upload_at)}</td>
            <td>{a.heartbeat_tolerance_seconds} segundos</td>
          </tr>)}
        </tbody></table></div>}
      {listing.data&&<div className="pagination">
        <span>Página {current} de {last}</span>
        <div className="inline">
          <button type="button" className="button ghost" disabled={current<=1}
            onClick={()=>setPage(current-1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={current>=last}
            onClick={()=>setPage(current+1)}>Próxima</button>
        </div>
      </div>}
    </section>
  </div>;
}
