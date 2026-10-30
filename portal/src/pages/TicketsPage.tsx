import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { ClipboardCheck, MessageSquarePlus, Plus, RotateCcw } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Page, Printer, User } from '../types';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import { PartRequestsPanel } from './PartRequestsPanel';

type Status = 'triage'|'unassigned'|'pending'|'in_progress'|'resolved'|'closed';
type Priority = 'low'|'normal'|'high'|'critical';
type Comment = {id:number;body:string;internal:boolean;created_at:string;author:{id:number;name:string}|null};
interface Ticket {
  id:number; subject:string; description:string; category:string; priority:Priority; status:Status;
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
    onSuccess:async result=>{
      setCustomer('');setPrinter('');setSubject('');setDescription('');setAssignee('');
      await onSuccess(result.data);
    },
  });
  function submit(event:FormEvent<HTMLFormElement>){event.preventDefault();create.mutate();}
  return <form onSubmit={submit} className="stock-form">
    <h3>Abrir ordem de serviço</h3>
    <p className="portfolio-info">Responsável pela abertura: <strong>{currentUser.name}</strong> ({currentUser.email ?? "E-mail não informado"}). O responsável é identificado pela sessão, não por um campo editável.</p>
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
  return <div className="modal-overlay">
    <section className="modal printer-assignment-modal" role="dialog" aria-modal="true" aria-label={'Chamado '+id}>
      <div className="modal-header"><h2>Chamado #{id}</h2>
        <button type="button" className="button ghost" onClick={onClose}>Fechar detalhes</button></div>
      {ticket.isPending?<p>Carregando chamado...</p>:ticket.error?
        <p className="notice error" role="alert">{readableError(ticket.error)}</p>:data&&<>
        <h3>{data.subject}</h3>
        <p>{data.description}</p>
        <p><strong>Aberta por:</strong> {data.opener?.name??'Usuário não identificado'}{data.opener?.email ? ` (${data.opener.email})` : ''}</p>
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

export function TicketsPage({canReopen,currentUser}:{canReopen:boolean;currentUser:User}) {
  const qc=useQueryClient();
  const [selected,setSelected]=useState<number|null>(null);
  const [filterParams, setFilterParams] = useSearchParams();
  const [showCreate, setShowCreate] = useState(filterParams.get('new') === '1');
  useEffect(() => {
    if (filterParams.get('new') === '1') setShowCreate(true);
  }, [filterParams]);
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
    setNotice('Chamado atualizado e registrado na auditoria.');
    await qc.invalidateQueries({queryKey:['tickets']});
  }
  const current=results.data?.meta?.current_page??results.data?.current_page??1;
  const last=results.data?.meta?.last_page??results.data?.last_page??1;
  return <div>
    <div className="page-heading"><div>
      <span className="eyebrow">HELP DESK</span><h1>Chamados técnicos</h1>
      <p>Triagem, atendimento, prioridades, mensagens e histórico persistido.</p>
    </div>{canReopen && <button type="button" className="button primary" onClick={() => setShowCreate(value=>!value)}>
      <Plus size={17}/> {showCreate ? 'Fechar formulário' : 'Abrir OS'}
    </button>}</div>
    {notice&&<p role="status" className="notice success">{notice}</p>}
    <section className="data-card"><div className="stock-field">
      <label htmlFor="ticket-filter">Filtrar estado</label>
      <select id="ticket-filter" value={status} onChange={e=>{
        const value=e.target.value;
        setFilterParams(value==='all'?{}:{status:value});setPage(1);
      }}>
        <option value="all">Todos</option><option value="open">Ativos (não encerrados)</option>{(Object.keys(statuses) as Status[]).map(x=>
          <option key={x} value={x}>{statuses[x]}</option>)}
      </select></div>
      {results.isPending?<div role="status">Carregando chamados...</div>:results.error?
        <p role="alert" className="notice error">{readableError(results.error)}</p>:
        results.data.data.length===0?<p>Nenhum chamado encontrado.</p>:
        <div className="table-wrap"><table><thead><tr>
          <th>Número</th><th>Assunto</th><th>Cliente</th><th>Aberta por</th><th>Técnico</th><th>Prioridade</th><th>Estado</th><th>Ação</th>
        </tr></thead><tbody>{results.data.data.map(t=><tr key={t.id}>
          <td>#{t.id}</td><td><strong>{t.subject}</strong></td><td>{t.customer?.name??'Interno'}</td>
          <td title={t.opener?.email??undefined}>{t.opener?.name??'Não identificado'}</td>
          <td>{t.assignee?.name??'Não designado'}</td><td>{priorities[t.priority]}</td><td>{statuses[t.status]}</td>
          <td><button type="button" className="button ghost" onClick={()=>setSelected(t.id)}>Detalhes</button></td>
        </tr>)}</tbody></table></div>}
      {results.data&&<div className="pagination"><span>Página {current} de {last}</span>
        <div className="inline">
          <button className="button ghost" disabled={current<=1} onClick={()=>setPage(current-1)}>Anterior</button>
          <button className="button ghost" disabled={current>=last} onClick={()=>setPage(current+1)}>Próxima</button>
        </div></div>}
    </section>
    {canReopen&&<PartRequestsPanel/>}
    {canReopen && showCreate && <section className="data-card"><CreateTicket currentUser={currentUser} onSuccess={async newTicket=>{
      await refresh();
      setNotice(`OS #${newTicket.id} aberta por ${currentUser.name} e registrada com sucesso.`);
      setSelected(newTicket.id);
      setShowCreate(false);
      setFilterParams({status:'open'});
    }}/></section>}
    {selected!==null&&<Detail id={selected} canReopen={canReopen}
      onClose={()=>setSelected(null)} onChanged={refresh}/>}
  </div>;
}
