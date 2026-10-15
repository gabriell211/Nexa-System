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
  watchdog_recent_incidents:number|null;
  watchdog_circuit_open_reported:boolean|null;
  watchdog_reported_at:string|null;
  communication_incident:{id:number;status:string;detected_at:string;last_heartbeat_at:string}|null;
  customer:{id:number;name:string}|null;
}
type IncidentStatus = 'active'|'resolved'|'closed';
interface CollectorIncident {
  id:number;collector_agent_id:number;collector_id:string|null;
  customer_id:number;customer:{id:number;name:string}|null;
  status:IncidentStatus;code:string;last_heartbeat_at:string;
  detected_at:string;resolved_at:string|null;resolution_reason:string|null;
  email_accepted_count:number;email_unconfirmed_count:number;
  email_returned_count:number;email_complaint_count:number;
}
const incidentStates:Record<IncidentStatus,string> = {
  active:'Sem heartbeat recente',resolved:'Heartbeat restabelecido',closed:'Cadastro/organização desativado',
};
const states:Record<State,string> = {
  recent:'Heartbeat recente',late:'Sem heartbeat recente',
  no_heartbeat:'Nunca recebido',revoked:'Credencial revogada',
};
function dateOrUnknown(value:string|null):string {
  return value ? new Date(value).toLocaleString('pt-BR') : 'Nunca recebido';
}

export function CollectorsPage() {
  const [page,setPage]=useState(1);
  const [incidentPage,setIncidentPage]=useState(1);
  const [incidentFilter,setIncidentFilter]=useState<'active'|'all'>('active');
  const listing=useQuery({
    queryKey:['collector-agents',page],
    queryFn:()=>api<Page<Collector>>(
      '/collector-agents'+queryString({page,per_page:25}),null
    ),
    refetchInterval:60_000,
  });
  const incidents=useQuery({
    queryKey:['collector-health-incidents',incidentPage,incidentFilter],
    queryFn:()=>api<Page<CollectorIncident>>(
      '/collector-health-incidents'+queryString({
        page:incidentPage,per_page:25,
        ...(incidentFilter==='active'?{status:'active'}:{}),
      }),null),
    refetchInterval:60_000,
  });
  const incidentCurrent=incidents.data?.meta?.current_page??incidents.data?.current_page??1;
  const incidentLast=incidents.data?.meta?.last_page??incidents.data?.last_page??1;
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
        O watchdog mostra somente o último relatório autenticado recebido, não o estado atual do serviço.
        Alertas de ausência de heartbeat identificam comunicação atrasada, não defeitos físicos das impressoras.
      </p>
      {listing.isPending?<p role="status">Carregando agentes...</p>:listing.error?
        <p role="alert" className="notice error">{readableError(listing.error)}</p>:
        listing.data.data.length===0?<p>Nenhum agente registrado nesta carteira.</p>:
        <div className="table-wrap"><table><thead><tr>
          <th>Agente</th><th>Cliente</th><th>Estado do heartbeat</th>
          <th>Alerta de comunicação</th><th>Último heartbeat</th><th>Último upload</th><th>Watchdog (último relatório)</th><th>Tolerância</th>
        </tr></thead><tbody>
          {listing.data.data.map(a=><tr key={a.id}>
            <td><strong>#{a.id}</strong><small>{a.collector_id}</small></td>
            <td>{a.customer?.name??'—'}</td>
            <td><span className="inline"><Activity size={15} aria-hidden="true"/> {states[a.heartbeat_state]}</span></td>
            <td>{a.communication_incident
              ? <span className="inline"><Activity size={15} aria-hidden="true"/>
                Atraso detectado em {dateOrUnknown(a.communication_incident.detected_at)}
                </span>
              : 'Nenhum alerta ativo'}</td>
            <td>{dateOrUnknown(a.heartbeat_at)}</td>
            <td>{dateOrUnknown(a.last_upload_at)}</td>
            <td>{a.watchdog_reported_at
              ? <span className="inline">
                  {a.watchdog_circuit_open_reported
                    ? 'Circuito aberto reportado'
                    : (a.watchdog_recent_incidents ?? 0) > 0
                      ? `${a.watchdog_recent_incidents} incidente(s) recente(s) reportado(s)`
                      : 'Sem incidentes reportados'}
                  <small> · {dateOrUnknown(a.watchdog_reported_at)}</small>
                </span>
              : 'Nunca reportado'}
            </td>
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
    <section className="data-card" aria-label="Histórico de alertas dos agentes">
      <div className="page-heading">
        <div><h2>Alertas de comunicação do Collector</h2>
          <p>Incidentes persistidos pela verificação agendada. Um atraso não comprova falha de impressora.</p>
        </div>
      </div>
      <div className="inline">
        <button type="button" className={incidentFilter==='active'?'button':'button ghost'}
          onClick={()=>{setIncidentFilter('active');setIncidentPage(1);}}>Ativos</button>
        <button type="button" className={incidentFilter==='all'?'button':'button ghost'}
          onClick={()=>{setIncidentFilter('all');setIncidentPage(1);}}>Todo o histórico</button>
      </div>
      {incidents.isPending?<p role="status">Carregando incidentes...</p>:incidents.error?
        <p role="alert" className="notice error">{readableError(incidents.error)}</p>:
        incidents.data.data.length===0?<p>Nenhum incidente registrado para este filtro.</p>:
        <div className="table-wrap"><table>
          <thead><tr><th>Collector</th><th>Cliente</th><th>Estado</th>
            <th>Último heartbeat anterior</th><th>Detectado</th><th>Encerrado</th><th>Notificações</th></tr></thead>
          <tbody>{incidents.data.data.map(incident=><tr key={incident.id}>
            <td>#{incident.collector_agent_id}<small>{incident.collector_id??'—'}</small></td>
            <td>{incident.customer?.name??'—'}</td>
            <td>{incidentStates[incident.status]}</td>
            <td>{dateOrUnknown(incident.last_heartbeat_at)}</td>
            <td>{dateOrUnknown(incident.detected_at)}</td>
            <td>{dateOrUnknown(incident.resolved_at)}</td>
            <td>{incident.email_accepted_count} aceita(s) pelo transporte
              {incident.email_unconfirmed_count>0?`, ${incident.email_unconfirmed_count} sem confirmação`:''}
              {incident.email_returned_count>0?`, ${incident.email_returned_count} devolvida(s)`:''}
              {incident.email_complaint_count>0?`, ${incident.email_complaint_count} denúncia(s) de spam`:''}</td>
          </tr>)}</tbody>
        </table></div>}
      {incidents.data&&<div className="pagination">
        <span>Página {incidentCurrent} de {incidentLast}</span>
        <div className="inline">
          <button type="button" className="button ghost" disabled={incidentCurrent<=1}
            onClick={()=>setIncidentPage(incidentCurrent-1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={incidentCurrent>=incidentLast}
            onClick={()=>setIncidentPage(incidentCurrent+1)}>Próxima</button>
        </div>
      </div>}
    </section>
  </div>;
}
