import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import {
  AlertCircle, ArrowUpRight, CheckCircle2, ChevronLeft, ChevronRight,
  ClipboardList, Clock3, Inbox, MessageSquarePlus, Plus,
  RefreshCcw, RotateCcw, SlidersHorizontal, UserRound, Wrench,
} from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Page, Printer, User } from '../types';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import { PartRequestsPanel } from './PartRequestsPanel';

type Status = 'triage'|'unassigned'|'pending'|'in_progress'|'resolved'|'closed';
type Priority = 'low'|'normal'|'high'|'critical';
type Comment = {id:number;body:string;internal:boolean;created_at:string;author:{id:number;name:string}|null};
interface Ticket {
  id:number; subject:string; description:string; category:string; priority:Priority; status:Status; origin?:string;
  customer_id:number|null; printer_id:number|null; assigned_user_id:number|null; created_at:string;
  customer?:{id:number;name:string}|null;assignee?:{id:number;name:string}|null;comments?:Comment[];
  opener?:{id:number;name:string;email:string}|null; opened_by_user_id:number;
  linked_printers?:Array<{id:number;manufacturer:string;model:string;serial_number:string|null;ip_address:string|null}>;
  attachments?:Array<{id:number;name:string;mime_type:string;size:number}>;
  signature?:{signer_name:string;service_summary:string;signed_at:string;sha256:string}|null;
  part_requests?:Array<{id:number;quantity:number;status:string;item:{name:string;sku:string}|null}>;
}
type Staff = {id:number;name:string;role?:string};
const priorities:Record<Priority,string> = {low:'Baixa',normal:'Normal',high:'Alta',critical:'Crítica'};
const statuses:Record<Status,string> = {
  triage:'Triagem',unassigned:'Sem responsável',pending:'Pendente',
  in_progress:'Em atendimento',resolved:'Resolvido',closed:'Encerrado',
};
const options:Record<Status,Status[]> = {
  triage:['unassigned','pending','in_progress','resolved'],
  unassigned:['pending','in_progress'],pending:['in_progress','resolved'],
  in_progress:['pending','resolved'],resolved:['closed','in_progress'],closed:['triage'],
};

function ticketLabel(ticket: Ticket): string {
  return ticket.origin === 'backoffice' ? `OS #${ticket.id}` : `Chamado #${ticket.id}`;
}

function CreateTicket({onSuccess,currentUser}:{onSuccess:(ticket:Ticket)=>Promise<void>;currentUser:User}) {
  const [customer,setCustomer]=useState('');
  const [printer,setPrinter]=useState('');
  const [subject,setSubject]=useState('');
  const [description,setDescription]=useState('');
  const [category,setCategory]=useState('suporte');
  const [priority,setPriority]=useState<Priority>('normal');
  const [assignee,setAssignee]=useState('');
  const staff=useQuery({
    queryKey:['ticket-staff'],
    queryFn:()=>api<Page<Staff>>('/tickets/operators'+queryString({per_page:100}),null),
  });
  const create=useMutation({
    mutationFn:()=>api<{data:Ticket}>('/tickets',null,{
      method:'POST',body:JSON.stringify({
        customer_id:customer ? Number(customer) : null,
        printer_id:printer ? Number(printer) : null,subject:subject.trim(),description:description.trim(),
        category:category.trim(),priority,
        ...(assignee?{assigned_user_id:Number(assignee)}:{}),
      }),
    }),
    onSuccess:async({data:record})=>{
      setCustomer('');setPrinter('');setSubject('');setDescription('');
      setCategory('suporte');setPriority('normal');setAssignee('');
      await onSuccess(record);
    },
  });
  function submit(event:FormEvent<HTMLFormElement>){event.preventDefault();create.mutate();}
  return <form onSubmit={submit} className="stock-form tickets-create-form">
    <h3>Abrir ordem de serviço</h3>
    <p className="portfolio-info">Responsável pela abertura: <strong>{currentUser.name}</strong> ({currentUser.email??'E-mail não informado'}).</p>
    <p>Informe o atendimento. Se escolher um técnico, a OS será enviada como pendente para o aplicativo dele.</p>
    <div className="stock-form-grid">
      <div className="stock-field"><PagedEntitySelect<{id:number;name:string}>
        id="ticket-customer" label="Cliente" searchLabel="Buscar cliente do chamado"
        placeholder="Buscar cliente por nome..." emptyLabel="Interno (sem cliente)"
        value={customer} onChange={value=>{setCustomer(value);setPrinter('');}}
        queryKey={['ticket-customer-options']} endpoint="/customers" filters={{active_only:true}}
        optionLabel={item=>item.name}/></div>
      <div className="stock-field"><PagedEntitySelect<Printer> key={customer}
        id="ticket-printer" label="Impressora (opcional)"
        searchLabel="Buscar impressora do chamado" placeholder="Buscar fabricante, modelo ou série..."
        emptyLabel="Sem impressora" value={printer} onChange={setPrinter}
        queryKey={['ticket-printer-options',customer]} endpoint="/printers"
        filters={{active_only:true,...(customer?{customer_id:Number(customer)}:{})}}
        optionLabel={item=>`#${item.id} ${item.manufacturer} ${item.model}`}
        disabled={!customer}/></div>
      <div className="stock-field"><label htmlFor="ticket-priority">Prioridade</label>
        <select id="ticket-priority" value={priority} onChange={e=>setPriority(e.target.value as Priority)}>
          {(Object.keys(priorities) as Priority[]).map(p=><option key={p} value={p}>{priorities[p]}</option>)}
        </select></div>
      <div className="stock-field"><label htmlFor="ticket-category">Categoria</label>
        <input id="ticket-category" minLength={2} maxLength={60} required
          value={category} onChange={e=>setCategory(e.target.value)}/></div>
      <div className="stock-field"><label htmlFor="ticket-subject">Assunto</label>
        <input id="ticket-subject" minLength={4} maxLength={180} required
          value={subject} onChange={e=>setSubject(e.target.value)}/></div>
      <div className="stock-field"><label htmlFor="ticket-new-assignee">Técnico responsável</label>
        <select id="ticket-new-assignee" value={assignee} onChange={e=>setAssignee(e.target.value)}>
          <option value="">Atribuir depois</option>
          {(staff.data?.data??[]).filter(user=>user.role==='technician')
            .map(user=><option key={user.id} value={user.id}>{user.name}</option>)}
        </select></div>
    </div>
    <div className="stock-field"><label htmlFor="ticket-description">Descrição</label>
      <textarea id="ticket-description" required minLength={5} maxLength={12000}
        value={description} onChange={e=>setDescription(e.target.value)} rows={3}/></div>
    {create.error&&<p role="alert" className="notice error">{readableError(create.error)}</p>}
    <button className="button primary" disabled={create.isPending}
      type="submit"><Plus size={16}/> {create.isPending?'Salvando...':'Abrir OS'}</button>
  </form>;
}

function Detail({id,canReopen,onClose,onChanged}:{
  id:number;canReopen:boolean;onClose:()=>void;onChanged:()=>Promise<void>;
}) {
  const qc=useQueryClient();
  const [status,setStatus]=useState<Status|null>(null);
  const [assignee,setAssignee]=useState('');
  const [reason,setReason]=useState('');
  const [dispatchTech,setDispatchTech]=useState('');
  const [dispatchReason,setDispatchReason]=useState('');
  const [message,setMessage]=useState('');
  const [internal,setInternal]=useState(true);
  const ticket=useQuery({
    queryKey:['ticket-detail',id],
    queryFn:()=>api<{data:Ticket}>('/tickets/'+id,null),
  });
  const staff=useQuery({
    queryKey:['ticket-staff'],
    queryFn:()=>api<Page<Staff>>('/tickets/operators'+queryString({per_page:100}),null),
  });
  const mutation=useMutation({
    mutationFn:()=>api<{data:Ticket}>('/tickets/'+id+'/transition',null,{
      method:'POST',body:JSON.stringify({
        status:status??ticket.data?.data.status,reason:reason.trim(),
        ...(assignee?{assigned_user_id:Number(assignee)}:{}),
      }),
    }),
    onSuccess:async()=>{
      setReason('');setStatus(null);
      await qc.invalidateQueries({queryKey:['ticket-detail',id]});await onChanged();
    },
  });
  const assignment=useMutation({
    mutationFn:()=>api<{data:Ticket}>('/tickets/'+id+'/assign',null,{
      method:'POST',body:JSON.stringify({
        assigned_user_id:Number(dispatchTech),reason:dispatchReason.trim(),
      }),
    }),
    onSuccess:async()=>{
      setDispatchReason('');setDispatchTech('');
      await qc.invalidateQueries({queryKey:['ticket-detail',id]});
      await onChanged();
    },
  });
  const comment=useMutation({
    mutationFn:()=>api('/tickets/'+id+'/comments',null,{
      method:'POST',body:JSON.stringify({body:message.trim(),internal}),
    }),
    onSuccess:async()=>{setMessage('');await qc.invalidateQueries({queryKey:['ticket-detail',id]});},
  });
  const data=ticket.data?.data;
  const allowed=data?options[data.status].filter(next=>{
    if((next==='triage'||next==='closed')&&!canReopen)return false;
    if(data.customer_id!==null&&!data.signature&&(next==='resolved'||next==='closed'))return false;
    if(data.signature&&next==='in_progress')return false;
    if(data.signature&&next==='triage')return false;
    return true;
  }):[];
  function transition(e:FormEvent<HTMLFormElement>){e.preventDefault();mutation.mutate();}
  function sendComment(e:FormEvent<HTMLFormElement>){e.preventDefault();comment.mutate();}
  return <div className="modal-overlay tickets-detail-overlay">
    <section className="modal printer-assignment-modal tickets-detail-modal" role="dialog" aria-modal="true"
      aria-label={data ? ticketLabel(data) : `Atendimento #${id}`}>
      <div className="modal-header"><h2>{data ? ticketLabel(data) : `Atendimento #${id}`}</h2>
        <button type="button" className="button ghost" onClick={onClose}>Fechar detalhes</button></div>
      {ticket.isPending?<p>Carregando chamado...</p>:ticket.error?
        <p className="notice error" role="alert">{readableError(ticket.error)}</p>:data&&<>
        <h3>{data.subject}</h3>
        <p>{data.description}</p>
        <p><strong>Aberta por:</strong> {data.opener?.name??'Não identificado'}{data.opener?.email ? ` (${data.opener.email})` : ''}</p>
        <p><strong>{statuses[data.status]}</strong> · {data.customer?.name??'Interno'} · {priorities[data.priority]}
          · Técnico: {data.assignee?.name??'Não designado'}</p>
        {(data.linked_printers??[]).length>0&&<div className="stock-form">
          <h3>Equipamentos informados pelo cliente</h3>
          <ul>{data.linked_printers!.map(p=><li key={p.id}>
            {p.manufacturer} {p.model} — {p.ip_address??'IP não informado'}
          </li>)}</ul>
        </div>}
        {(data.attachments??[]).length>0&&<div className="stock-form">
          <h3>Fotos e vídeos do cliente</h3>
          <p>Arquivos armazenados de forma privada; download sujeito às permissões do chamado.</p>
          <ul>{data.attachments!.map(file=><li key={file.id}>
            <a href={`/api/v1/browser/tickets/${id}/attachments/${file.id}`}>{file.name}</a>
            {' '}({(file.size/1024/1024).toFixed(1)} MB)
          </li>)}</ul>
        </div>}
        {data.signature&&<div className="stock-form">
          <h3>Confirmação do cliente</h3>
          <p><strong>Assinado por:</strong> {data.signature.signer_name}</p>
          <p><strong>Serviço:</strong> {data.signature.service_summary}</p>
          <p><strong>Data:</strong> {new Date(data.signature.signed_at).toLocaleString('pt-BR')}</p>
          <p><a href={`/api/v1/browser/tickets/${id}/signature/image`}>
            Baixar assinatura registrada (PNG)
          </a></p>
        </div>}
        {(data.part_requests??[]).length>0&&<div className="stock-form">
          <h3>Peças solicitadas</h3>
          <ul>{data.part_requests!.map(part=><li key={part.id}>
            {part.quantity}x {part.item?.name??'Item'} — {part.status}
          </li>)}</ul>
        </div>}
        {canReopen&&['triage','unassigned','pending'].includes(data.status)&&
          <form className="stock-form" onSubmit={event=>{event.preventDefault();assignment.mutate();}}>
            <h3>Encaminhar para o técnico</h3>
            <p>O chamado ficará pendente e aparecerá no aplicativo do profissional escolhido.</p>
            <div className="stock-form-grid">
              <div className="stock-field"><label htmlFor="ticket-dispatch-tech">Técnico</label>
                <select id="ticket-dispatch-tech" required value={dispatchTech} onChange={e=>setDispatchTech(e.target.value)}>
                  <option value="">Selecione um técnico ativo</option>
                  {(staff.data?.data??[]).filter(person=>person.role==='technician').map(person=>
                    <option key={person.id} value={person.id}>{person.name}</option>)}
                </select></div>
              <div className="stock-field"><label htmlFor="ticket-dispatch-reason">Justificativa da designação</label>
                <input id="ticket-dispatch-reason" value={dispatchReason} required minLength={5} maxLength={500}
                  onChange={e=>setDispatchReason(e.target.value)} placeholder="Ex.: visita técnica agendada"/></div>
            </div>
            {assignment.error&&<p role="alert" className="notice error">{readableError(assignment.error)}</p>}
            <button className="button primary" type="submit" disabled={assignment.isPending||!dispatchTech||dispatchReason.trim().length<5}>
              Designar técnico
            </button>
          </form>}
        {data.customer_id!==null&&!data.signature&&<p className="notice">
          A conclusão requer assinatura do cliente no Nexa Técnico. Após o aceite, o administrativo poderá encerrar.
        </p>}
        {data.signature&&data.status==='resolved'&&<p className="notice success">
          Aceite registrado. O chamado pode ser encerrado pela administração.
        </p>}
        {allowed.length>0&&<form onSubmit={transition} className="stock-form">
          <h3>Atualizar atendimento</h3>
          <div className="stock-form-grid">
            <div className="stock-field"><label htmlFor="ticket-status">Novo estado</label>
              <select id="ticket-status" required value={status??''} onChange={e=>setStatus(e.target.value as Status)}>
                <option value="">Selecionar</option>
                {allowed.map(x=><option key={x} value={x}>{statuses[x]}</option>)}
              </select></div>
            {canReopen&&<div className="stock-field"><label htmlFor="ticket-assignee">Responsável</label>
              <select id="ticket-assignee" value={assignee} onChange={e=>setAssignee(e.target.value)}>
                <option value="">Manter atual</option>
                {(staff.data?.data??[]).filter(user=>user.role==='technician')
                  .map(user=><option key={user.id} value={user.id}>{user.name}</option>)}
              </select></div>}
            <div className="stock-field"><label htmlFor="ticket-reason">Motivo</label>
              <input id="ticket-reason" required minLength={5} maxLength={500}
                value={reason} onChange={e=>setReason(e.target.value)}/></div>
          </div>
          {mutation.error&&<p role="alert" className="notice error">{readableError(mutation.error)}</p>}
          <button type="submit" className="button primary" disabled={!status||mutation.isPending}>
            <RotateCcw size={16}/> Atualizar estado</button>
        </form>}
        <div className="stock-form"><h3>Comentários</h3>
          {(data.comments??[]).length===0?<p>Nenhuma mensagem.</p>:
            (data.comments??[]).map(c=><div key={c.id} className="ticket-comment">
              <strong>{c.author?.name??'Operador'}</strong> <small>{c.internal?'Interno':'Público'} · {new Date(c.created_at).toLocaleString('pt-BR')}</small>
              <p>{c.body}</p></div>)}
          {data.status!=='closed'&&<form onSubmit={sendComment} className="stock-form">
            <div className="stock-field"><label htmlFor="ticket-comment">Nova mensagem</label>
              <textarea id="ticket-comment" value={message} minLength={2} maxLength={6000}
                onChange={e=>setMessage(e.target.value)} required rows={2}/></div>
            <label><input type="checkbox" checked={internal} onChange={e=>setInternal(e.target.checked)}/>
              Comentário interno (não visível ao cliente)</label>
            {comment.error&&<p role="alert" className="notice error">{readableError(comment.error)}</p>}
            <button type="submit" className="button primary" disabled={comment.isPending}>
              <MessageSquarePlus size={16}/> Enviar comentário</button>
          </form>}
        </div>
      </>}
    </section>
  </div>;
}

export function TicketsPage({canReopen,canCreateOrder,currentUser}:{
  canReopen:boolean;canCreateOrder:boolean;currentUser:User;
}) {
  const qc=useQueryClient();
  const [selected,setSelected]=useState<number|null>(null);
  const [filterParams, setFilterParams] = useSearchParams();
  const creating = filterParams.get('novo') === '1';
  function toggleCreate(open:boolean) {
    setFilterParams(previous => {
      const params = new URLSearchParams(previous);
      if (open) params.set('novo','1');
      else params.delete('novo');
      return params;
    });
  }
  const requested = filterParams.get('status');
  const status: Status | 'open' | 'all' = requested === 'open' ? 'open'
    : requested && Object.hasOwn(statuses, requested) ? requested as Status : 'all';
  const [page,setPage]=useState(1);
  useEffect(()=>setPage(1),[status]);
  const [notice,setNotice]=useState('');
  const results=useQuery({
    queryKey:['tickets',status,page],
    queryFn:()=>api<Page<Ticket>>('/tickets'+queryString({
      page,per_page:25,...(status==='open'?{open_only:true}:status==='all'?{}:{status}),
    }),null),
  });
  async function refresh(){
    setNotice('Atendimento atualizado e registrado na auditoria.');
    await qc.invalidateQueries({queryKey:['tickets']});
  }
  async function created(record:Ticket){
    setNotice(`OS #${record.id} aberta com sucesso. ${record.assigned_user_id ? 'Enviada ao técnico responsável.' : 'Aguardando triagem e designação.'}`);
    toggleCreate(false);
    setSelected(record.id);
    await qc.invalidateQueries({queryKey:['tickets']});
  }
  const current=results.data?.meta?.current_page??results.data?.current_page??1;
  const last=results.data?.meta?.last_page??results.data?.last_page??1;
  const tickets = results.data?.data ?? [];
  const total = results.data?.meta?.total ?? results.data?.total ?? null;
  const inProgress = tickets.filter(item => item.status === 'in_progress').length;
  const waiting = tickets.filter(item =>
    ['triage','unassigned','pending'].includes(item.status)).length;
  function changeFilter(next: Status|'open'|'all') {
    setFilterParams(previous => {
      const params = new URLSearchParams(previous);
      if (next==='all') params.delete('status');
      else params.set('status',next);
      return params;
    });
    setPage(1);
  }
  const quickFilters: Array<{value:Status|'open'|'all';label:string}> = [
    {value:'all',label:'Todos'},
    {value:'open',label:'Em aberto'},
    {value:'in_progress',label:'Em atendimento'},
    {value:'resolved',label:'Resolvidos'},
  ];
  const metrics = [
    {label:'Chamados encontrados',value:total,icon:ClipboardList,note:'Total no filtro',tone:'blue'},
    {label:'Em atendimento',value:inProgress,icon:Wrench,note:'Nesta página',tone:'indigo'},
    {label:'Aguardando ação',value:waiting,icon:Clock3,note:'Nesta página',tone:'amber'},
  ] as const;

  return <div className="tickets-workspace">
    <header className="tickets-hero">
      <div className="tickets-hero-title">
        <span className="tickets-hero-icon" aria-hidden="true"><ClipboardList size={24}/></span>
        <div>
          <span className="eyebrow">CENTRAL DE ATENDIMENTO</span>
          <h1>Chamados técnicos</h1>
          <p>Organize as ordens de serviço, acompanhe a equipe e tenha controle de cada atendimento.</p>
        </div>
      </div>
      <div className="tickets-hero-actions">
        <button type="button" className="button tickets-refresh"
          disabled={results.isFetching}
          onClick={() => void qc.invalidateQueries({queryKey:['tickets']})}>
          <RefreshCcw size={16} aria-hidden="true"/> Atualizar
        </button>
        {canCreateOrder && <button type="button" className="button primary tickets-new"
          aria-expanded={creating} onClick={() => toggleCreate(!creating)}>
          <Plus size={18} aria-hidden="true"/> {creating?'Cancelar abertura':'Abrir OS'}
        </button>}
      </div>
    </header>

    {notice && <div role="status" className="tickets-feedback">
      <CheckCircle2 size={18} aria-hidden="true"/><span>{notice}</span>
      <button type="button" aria-label="Dispensar mensagem" onClick={() => setNotice('')}>×</button>
    </div>}

    {canCreateOrder && creating && <section className="data-card tickets-create-section"
      aria-label="Nova ordem de serviço">
      <CreateTicket currentUser={currentUser} onSuccess={created}/>
    </section>}

    <div className="tickets-metrics" aria-label="Resumo de chamados">
      {metrics.map(metric => <article className={'tickets-metric tickets-metric-'+metric.tone}
        key={metric.label}>
        <span className="tickets-metric-icon" aria-hidden="true"><metric.icon size={19}/></span>
        <div className="tickets-metric-copy">
          <span>{metric.label}</span>
          <strong>{results.isPending?'—':metric.value??'—'}</strong>
          <small>{metric.note}</small>
        </div>
      </article>)}
    </div>

    <section className="data-card tickets-list-card" aria-labelledby="tickets-list-title">
      <div className="tickets-section-heading">
        <div>
          <span className="tickets-section-kicker">GESTÃO DE ATENDIMENTOS</span>
          <h2 id="tickets-list-title">Ordens de serviço</h2>
          <p>Consulte o andamento e acesse os detalhes de cada chamado.</p>
        </div>
        <span className="tickets-result-count">
          {results.isPending?'Consultando…':total===null?
            tickets.length+' nesta página':total+' registro'+(total===1?'':'s')}
        </span>
      </div>
      <div className="tickets-toolbar">
        <div className="tickets-quick-filters" role="group" aria-label="Filtros rápidos">
          {quickFilters.map(item => <button key={item.value} type="button"
            className={'tickets-filter-chip'+(status===item.value?' is-active':'')}
            aria-pressed={status===item.value} onClick={() => changeFilter(item.value)}>
            {item.label}
          </button>)}
        </div>
        <div className="tickets-status-filter">
          <SlidersHorizontal size={16} aria-hidden="true"/>
          <label htmlFor="ticket-filter">Filtrar estado</label>
          <select id="ticket-filter" value={status}
            onChange={e => changeFilter(e.target.value as Status|'open'|'all')}>
            <option value="all">Todos os estados</option>
            <option value="open">Ativos (não encerrados)</option>
            {(Object.keys(statuses) as Status[]).map(item =>
              <option value={item} key={item}>{statuses[item]}</option>)}
          </select>
        </div>
      </div>

      {results.isPending?<div className="tickets-empty" role="status">
        <span className="tickets-empty-icon"><RefreshCcw size={26}/></span>
        <h3>Carregando atendimentos</h3>
        <p>Buscando as ordens de serviço registradas no Nexa.</p>
      </div>:results.error?<div className="tickets-empty" role="alert">
        <span className="tickets-empty-icon tickets-empty-error"><AlertCircle size={28}/></span>
        <h3>Não foi possível carregar os chamados</h3>
        <p>{readableError(results.error)}</p>
        <button type="button" className="button tickets-refresh" onClick={() => void results.refetch()}>
          <RefreshCcw size={16}/> Tentar novamente
        </button>
      </div>:tickets.length===0?<div className="tickets-empty">
        <span className="tickets-empty-icon"><Inbox size={32}/></span>
        <h3>{status==='all'?'Nenhum chamado por enquanto':'Nenhum chamado neste filtro'}</h3>
        <p>{status==='all'?
          'Quando uma ordem de serviço for aberta, ela aparecerá aqui para acompanhamento.':
          'Não encontramos atendimentos nesse estado. Experimente outra seleção.'}</p>
        <div className="tickets-empty-actions">
          {status!=='all' && <button type="button" className="button tickets-refresh"
            onClick={() => changeFilter('all')}>Limpar filtros</button>}
          {status==='all' && canCreateOrder && <button type="button" className="button primary"
            onClick={() => toggleCreate(true)}><Plus size={16}/> Abrir primeira OS</button>}
        </div>
      </div>:<div className="table-wrap tickets-table-wrap">
        <table className="tickets-table">
          <thead><tr><th>Ordem de serviço</th><th>Cliente</th><th>Técnico</th>
            <th>Prioridade</th><th>Estado</th><th>Aberta em</th><th>Ação</th>
          </tr></thead>
          <tbody>{tickets.map(ticket => <tr key={ticket.id}>
            <td><div className="tickets-subject">
              <span className="tickets-ticket-id">{ticketLabel(ticket)}</span>
              <strong title={ticket.subject}>{ticket.subject}</strong>
              <small>{ticket.category||'Atendimento técnico'}</small>
            </div></td>
            <td><span className="tickets-client-name">{ticket.customer?.name??'Atendimento interno'}</span></td>
            <td><span className="tickets-assignee"><UserRound size={14} aria-hidden="true"/>
              {ticket.assignee?.name??'Não designado'}</span></td>
            <td><span className={'tickets-priority is-'+ticket.priority}>
              <span className="tickets-priority-dot" aria-hidden="true"/>
              {priorities[ticket.priority]}</span></td>
            <td><span className={'tickets-status is-'+ticket.status}>{statuses[ticket.status]}</span></td>
            <td><time dateTime={ticket.created_at}>{new Date(ticket.created_at).toLocaleDateString('pt-BR')}</time></td>
            <td><button type="button" className="tickets-view-action" onClick={() => setSelected(ticket.id)}
              aria-label={'Ver detalhes de '+ticketLabel(ticket)}>Detalhes <ArrowUpRight size={15}/></button></td>
          </tr>)}</tbody>
        </table>
      </div>}

      {results.data && last>1 && <div className="tickets-pagination">
        <span>Página <strong>{current}</strong> de <strong>{last}</strong></span>
        <div>
          <button type="button" disabled={current<=1} aria-label="Página anterior"
            onClick={() => setPage(Math.max(1,current-1))}><ChevronLeft size={18}/> Anterior</button>
          <button type="button" disabled={current>=last} aria-label="Próxima página"
            onClick={() => setPage(Math.min(last,current+1))}>Próxima <ChevronRight size={18}/></button>
        </div>
      </div>}
    </section>
    {canReopen && <PartRequestsPanel/>}
    {selected!==null&&<Detail id={selected} canReopen={canReopen}
      onClose={()=>setSelected(null)} onChanged={refresh}/>}
  </div>;
}
