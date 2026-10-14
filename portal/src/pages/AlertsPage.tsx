import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Link2, Plus, ShieldAlert, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Page, Printer } from '../types';

type Severity = 'info'|'warning'|'critical';
type Status = 'active'|'acknowledged'|'linked'|'resolved'|'ignored';
type Category = 'hardware'|'communication'|'maintenance'|'supplies'|'other';
interface Alert {
  id:number;printer_id:number;customer_id:number;ticket_id:number|null;
  code:string;category:Category;severity:Severity;status:Status;
  origin:'operator';title:string;description:string|null;created_at:string;
  customer?:{id:number;name:string}|null;
  printer?:{id:number;manufacturer:string;model:string}|null;
  ticket?:{id:number;subject:string;status:string}|null;
}
interface TicketOption {
  id:number;customer_id:number|null;printer_id:number|null;
  subject:string;status:string;
}
const severityLabels:Record<Severity,string> = {info:'Informativo',warning:'Atenção',critical:'Crítico'};
const statusLabels:Record<Status,string> = {
  active:'Ativo',acknowledged:'Reconhecido',linked:'Vinculado a chamado',
  resolved:'Resolvido',ignored:'Ignorado',
};
const categoryLabels:Record<Category,string> = {
  hardware:'Hardware',communication:'Comunicação',maintenance:'Manutenção',
  supplies:'Suprimentos',other:'Outro',
};

function OpenAlert({onCreated}:{onCreated:(deduplicated:boolean)=>Promise<void>}) {
  const [printerId,setPrinterId]=useState('');
  const [code,setCode]=useState('');
  const [category,setCategory]=useState<Category>('hardware');
  const [severity,setSeverity]=useState<Severity>('warning');
  const [title,setTitle]=useState('');
  const [description,setDescription]=useState('');
  const printers=useQuery({
    queryKey:['alert-printer-options'],
    queryFn:()=>api<Page<Printer>>('/printers'+queryString({per_page:100,active_only:true}),null),
  });
  const create=useMutation({
    mutationFn:()=>api<{data:Alert;deduplicated:boolean}>('/alerts',null,{
      method:'POST',
      body:JSON.stringify({
        printer_id:Number(printerId),code:code.trim(),category,severity,
        title:title.trim(),description:description.trim()||null,
      }),
    }),
    onSuccess:async result=>{
      if (!result.deduplicated) {setTitle('');setCode('');setDescription('');}
      await onCreated(result.deduplicated);
    },
  });
  function submit(event:FormEvent<HTMLFormElement>) {event.preventDefault();create.mutate();}
  return <section className="data-card">
    <form className="stock-form" onSubmit={submit}>
      <h2>Registrar observação de alerta</h2>
      <p className="stock-footnote">Origem: operador. Este cadastro não simula telemetria SNMP nem alertas automáticos.
        Um código ativo por impressora é deduplicado no servidor.</p>
      <div className="stock-form-grid">
        <div className="stock-field"><label htmlFor="alert-printer">Impressora</label>
          <select id="alert-printer" required value={printerId} onChange={e=>setPrinterId(e.target.value)}>
            <option value="">Selecione uma impressora</option>
            {(printers.data?.data??[]).map(printer=><option key={printer.id} value={printer.id}>
              #{printer.id} {printer.manufacturer} {printer.model} · {printer.customer?.name??'Cliente'}
            </option>)}
          </select></div>
        <div className="stock-field"><label htmlFor="alert-code">Código da ocorrência</label>
          <input id="alert-code" required minLength={2} maxLength={64}
            pattern="[A-Za-z0-9][A-Za-z0-9_.-]{1,63}" value={code}
            onChange={e=>setCode(e.target.value)} placeholder="Ex.: PAPER_JAM"/></div>
        <div className="stock-field"><label htmlFor="alert-category">Categoria</label>
          <select id="alert-category" value={category} onChange={e=>setCategory(e.target.value as Category)}>
            {(Object.keys(categoryLabels) as Category[]).map(item=>
              <option key={item} value={item}>{categoryLabels[item]}</option>)}
          </select></div>
        <div className="stock-field"><label htmlFor="alert-severity">Criticidade</label>
          <select id="alert-severity" value={severity} onChange={e=>setSeverity(e.target.value as Severity)}>
            {(Object.keys(severityLabels) as Severity[]).map(item=>
              <option key={item} value={item}>{severityLabels[item]}</option>)}
          </select></div>
      </div>
      <div className="stock-field"><label htmlFor="alert-title">Título</label>
        <input id="alert-title" required minLength={4} maxLength={180}
          value={title} onChange={e=>setTitle(e.target.value)}/></div>
      <div className="stock-field"><label htmlFor="alert-description">Descrição (opcional)</label>
        <textarea id="alert-description" rows={2} maxLength={5000}
          value={description} onChange={e=>setDescription(e.target.value)}/></div>
      {printers.error&&<p role="alert" className="notice error">{readableError(printers.error)}</p>}
      {create.error&&<p role="alert" className="notice error">{readableError(create.error)}</p>}
      <button type="submit" className="button primary"
        disabled={create.isPending||printers.isPending||Boolean(printers.error)}>
        <Plus size={16}/> {create.isPending?'Registrando...':'Registrar alerta'}
      </button>
    </form>
  </section>;
}

function AlertDetail({id,onClose,onChanged}:{id:number;onClose:()=>void;onChanged:()=>Promise<void>}) {
  const qc=useQueryClient();
  const [status,setStatus]=useState<'acknowledged'|'resolved'|'ignored'|''>('');
  const [reason,setReason]=useState('');
  const [ticketId,setTicketId]=useState('');
  const alert=useQuery({
    queryKey:['alert-detail',id],
    queryFn:()=>api<{data:Alert}>('/alerts/'+id,null),
  });
  const tickets=useQuery({
    queryKey:['alert-ticket-options'],
    queryFn:()=>api<Page<TicketOption>>('/tickets'+queryString({per_page:100}),null),
  });
  const mutateStatus=useMutation({
    mutationFn:()=>api('/alerts/'+id+'/transition',null,{
      method:'POST',body:JSON.stringify({status,reason:reason.trim()}),
    }),
    onSuccess:async()=>{
      setStatus('');setReason('');
      await qc.invalidateQueries({queryKey:['alert-detail',id]});
      await onChanged();
    },
  });
  const link=useMutation({
    mutationFn:()=>api('/alerts/'+id+'/link-ticket',null,{
      method:'POST',body:JSON.stringify({ticket_id:Number(ticketId)}),
    }),
    onSuccess:async()=>{
      setTicketId('');
      await qc.invalidateQueries({queryKey:['alert-detail',id]});
      await onChanged();
    },
  });
  const data=alert.data?.data;
  const transitionOptions=data?.status==='active'
    ? (['acknowledged','resolved','ignored'] as const)
    : data?.status==='acknowledged'||data?.status==='linked'
      ? (['resolved','ignored'] as const) : [];
  const availableTickets=(tickets.data?.data??[]).filter(t=>
    data!==undefined && t.customer_id===data.customer_id && t.printer_id===data.printer_id &&
    !['closed','resolved'].includes(t.status)
  );
  function transition(e:FormEvent<HTMLFormElement>){e.preventDefault();mutateStatus.mutate();}
  function linkTicket(e:FormEvent<HTMLFormElement>){e.preventDefault();link.mutate();}
  return <div className="modal-overlay">
    <section className="modal printer-assignment-modal" role="dialog" aria-modal="true"
      aria-label={'Alerta '+id}>
      <div className="modal-header"><h2>Alerta #{id}</h2>
        <button type="button" className="icon-button" aria-label="Fechar alerta" onClick={onClose}>
          <X size={18}/>
        </button>
      </div>
      {alert.isPending?<p role="status">Carregando alerta...</p>:alert.error?
        <p role="alert" className="notice error">{readableError(alert.error)}</p>:data&&<>
        <h3>{data.title}</h3>
        <p>{data.description||'Sem detalhes adicionais.'}</p>
        <p><strong>{statusLabels[data.status]}</strong> · {severityLabels[data.severity]} · Código {data.code}</p>
        <p className="stock-footnote">Origem: registrado por operador ·
          Impressora: {data.printer?.manufacturer} {data.printer?.model}</p>
        {data.ticket&&<p>Chamado vinculado: #{data.ticket.id} — {data.ticket.subject}</p>}
        {(data.status==='active'||data.status==='acknowledged')&&!data.ticket_id&&
          <form className="stock-form" onSubmit={linkTicket}>
            <h3>Vincular chamado da mesma impressora</h3>
            <div className="stock-field">
              <label htmlFor="alert-ticket">Chamado em andamento</label>
              <select id="alert-ticket" required value={ticketId} onChange={e=>setTicketId(e.target.value)}>
                <option value="">Selecione um chamado</option>
                {availableTickets.map(t=><option key={t.id} value={t.id}>#{t.id} · {t.subject}</option>)}
              </select>
            </div>
            {tickets.error&&<p role="alert" className="notice error">{readableError(tickets.error)}</p>}
            {link.error&&<p role="alert" className="notice error">{readableError(link.error)}</p>}
            <button type="submit" className="button primary" disabled={!ticketId||link.isPending}>
              <Link2 size={16}/> Vincular chamado
            </button>
          </form>}
        {transitionOptions.length>0&&<form className="stock-form" onSubmit={transition}>
          <h3>Atualizar alerta</h3>
          <div className="stock-field"><label htmlFor="alert-next-status">Novo estado</label>
            <select id="alert-next-status" value={status} required
              onChange={e=>setStatus(e.target.value as typeof status)}>
              <option value="">Selecione o estado</option>
              {transitionOptions.map(next=><option key={next} value={next}>{statusLabels[next]}</option>)}
            </select></div>
          <div className="stock-field"><label htmlFor="alert-reason">Justificativa</label>
            <input id="alert-reason" required minLength={5} maxLength={500}
              value={reason} onChange={e=>setReason(e.target.value)}/></div>
          {mutateStatus.error&&<p role="alert" className="notice error">{readableError(mutateStatus.error)}</p>}
          <button type="submit" disabled={!status||mutateStatus.isPending} className="button primary">
            <CheckCircle2 size={16}/> Salvar estado
          </button>
        </form>}
      </>}
    </section>
  </div>;
}

export function AlertsPage() {
  const qc=useQueryClient();
  const [status,setStatus]=useState<Status|'all'>('all');
  const [page,setPage]=useState(1);
  const [selected,setSelected]=useState<number|null>(null);
  const [notice,setNotice]=useState('');
  const listing=useQuery({
    queryKey:['alerts',status,page],
    queryFn:()=>api<Page<Alert>>('/alerts'+queryString({
      page,per_page:25,...(status==='all'?{}:{status}),
    }),null),
  });
  async function refresh(deduplicated?:boolean){
    setNotice(deduplicated?'Alerta ativo já existente; nenhum duplicado foi criado.'
      :'Alerta registrado ou atualizado com auditoria.');
    await qc.invalidateQueries({queryKey:['alerts']});
  }
  const last=listing.data?.meta?.last_page??listing.data?.last_page??1;
  return <div>
    <div className="page-heading"><div><span className="eyebrow">OPERAÇÃO</span>
      <h1>Alertas operacionais</h1>
      <p>Ocorrências registradas pela equipe, deduplicadas e auditáveis. Alertas automáticos aguardam homologação.</p>
    </div><ShieldAlert size={32}/></div>
    {notice&&<p role="status" className="notice success">{notice}</p>}
    <section className="data-card">
      <div className="table-tools">
        <div className="stock-field"><label htmlFor="alert-filter">Filtrar por estado</label>
          <select id="alert-filter" value={status} onChange={e=>{
            setStatus(e.target.value as Status|'all');setPage(1);
          }}>
            <option value="all">Todos</option>
            {(Object.keys(statusLabels) as Status[]).map(item=>
              <option key={item} value={item}>{statusLabels[item]}</option>)}
          </select></div>
      </div>
      {listing.isPending?<p role="status">Carregando alertas...</p>:listing.error?
        <p role="alert" className="notice error">{readableError(listing.error)}</p>:
        listing.data.data.length===0?<p>Nenhum alerta para os filtros atuais.</p>:
        <div className="table-wrap"><table><thead><tr>
          <th>Número</th><th>Ocorrência</th><th>Impressora</th>
          <th>Criticidade</th><th>Estado</th><th>Ações</th>
        </tr></thead><tbody>{listing.data.data.map(a=><tr key={a.id}>
          <td>#{a.id}</td><td><strong>{a.title}</strong><small>{a.code}</small></td>
          <td>{a.printer?.manufacturer} {a.printer?.model}</td>
          <td>{severityLabels[a.severity]}</td><td>{statusLabels[a.status]}</td>
          <td><button type="button" className="button ghost" onClick={()=>setSelected(a.id)}>
            Detalhes do alerta
          </button></td>
        </tr>)}</tbody></table></div>}
      {listing.data&&<div className="pagination"><span>Página {page} de {last}</span>
        <div className="inline">
          <button type="button" className="button ghost" disabled={page<=1} onClick={()=>setPage(page-1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={page>=last} onClick={()=>setPage(page+1)}>Próxima</button>
        </div></div>}
    </section>
    <OpenAlert onCreated={refresh}/>
    {selected!==null&&<AlertDetail id={selected} onClose={()=>setSelected(null)}
      onChanged={()=>refresh(false)}/>}
  </div>;
}
