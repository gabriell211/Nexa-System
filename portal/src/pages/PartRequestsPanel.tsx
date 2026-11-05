import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Clock3, PackageCheck, PackageOpen, X } from 'lucide-react';
import { api, readableError } from '../api';

interface PartRequest {
  id:number; ticket_id:number; quantity:number; status:'pending'|'approved'|'rejected';
  reason:string; created_at:string;
  stock_item:{id:number;name:string;sku:string}|null;
  technician:{id:number;name:string}|null;
}
interface Page<T> {data:T[]}

export function PartRequestsPanel() {
  const [notes,setNotes]=useState<Record<number,string>>({});
  const [success,setSuccess]=useState('');
  const queryClient=useQueryClient();
  const query=useQuery({
    queryKey:['ticket-parts-pending'],
    queryFn:()=>api<Page<PartRequest>>('/tickets/part-requests?status=pending',null),
  });
  const review=useMutation({
    mutationFn:(payload:{id:number;status:'approved'|'rejected';review_note:string})=>
      api('/tickets/part-requests/'+payload.id+'/review',null,{
        method:'POST',body:JSON.stringify({status:payload.status,review_note:payload.review_note}),
      }),
    onSuccess:async(_,variables)=>{
      setNotes(previous=>({...previous,[variables.id]:''}));
      setSuccess(variables.status==='approved'
        ? 'Solicitação de peça aprovada e registrada.'
        : 'Solicitação de peça rejeitada e registrada.');
      await queryClient.invalidateQueries({queryKey:['ticket-parts-pending']});
      await queryClient.invalidateQueries({queryKey:['tickets']});
    },
  });
  const pending=query.data?.data??[];

  return <section className="data-card tickets-approval-card"
    aria-labelledby="tickets-parts-title">
    <header className="tickets-section-heading">
      <div>
        <span className="tickets-section-kicker">FLUXO DE PEÇAS</span>
        <h2 id="tickets-parts-title">Peças solicitadas pelos técnicos</h2>
        <p>Analise os pedidos dos atendimentos e registre uma justificativa para cada decisão.</p>
      </div>
      <span className="tickets-result-count">
        {query.isPending?'Consultando…':pending.length+' pendente'+(pending.length===1?'':'s')}
      </span>
    </header>
    {success&&<div className="tickets-approval-message" role="status">
      <Check size={17} aria-hidden="true"/>{success}
      <button type="button" onClick={()=>setSuccess('')} aria-label="Dispensar confirmação">×</button>
    </div>}
    {query.isPending&&<div className="tickets-approval-empty" role="status">
      <Clock3 size={24} aria-hidden="true"/> Verificando pedidos pendentes…
    </div>}
    {query.error&&<div className="tickets-approval-empty" role="alert">
      <PackageOpen size={24} aria-hidden="true"/>
      <span>{readableError(query.error)}</span>
      <button type="button" className="button tickets-refresh"
        onClick={()=>void query.refetch()}>Tentar novamente</button>
    </div>}
    {!query.isPending&&!query.error&&pending.length===0&&
      <div className="tickets-approval-empty">
        <span className="tickets-approval-empty-icon" aria-hidden="true">
          <PackageCheck size={25}/>
        </span>
        <div><strong>Tudo em dia com as peças</strong>
          <p>Nenhuma solicitação aguardando aprovação no momento.</p></div>
      </div>}
    {pending.length>0&&<div className="tickets-requests">
      {pending.map(item=><article className="tickets-request" key={item.id}>
        <div className="tickets-request-heading">
          <span className="tickets-request-icon" aria-hidden="true"><PackageOpen size={21}/></span>
          <div>
            <small>OS #{item.ticket_id} · PEDIDO #{item.id}</small>
            <h3>{item.stock_item?.name??'Peça não identificada'}</h3>
          </div>
          <span className="tickets-request-pending">Aguardando aprovação</span>
        </div>
        <div className="tickets-request-meta">
          <span><strong>Quantidade</strong> {item.quantity} un.</span>
          <span><strong>Técnico</strong> {item.technician?.name??'Não informado'}</span>
          {item.stock_item?.sku&&<span><strong>SKU</strong> {item.stock_item.sku}</span>}
        </div>
        <p className="tickets-request-reason">{item.reason}</p>
        <div className="tickets-request-form">
          <label htmlFor={'part-review-'+item.id}>Justificativa da decisão</label>
          <input id={'part-review-'+item.id} type="text" minLength={5} maxLength={500}
            placeholder="Descreva o motivo da aprovação ou rejeição (mínimo 5 caracteres)"
            value={notes[item.id]??''} onChange={event=>
              setNotes(previous=>({...previous,[item.id]:event.target.value}))}/>
          <div className="tickets-request-actions">
            <button type="button" className="button tickets-approve"
              disabled={review.isPending||(notes[item.id]??'').trim().length<5}
              onClick={()=>review.mutate({id:item.id,status:'approved',review_note:(notes[item.id]??'').trim()})}>
              <Check size={16} aria-hidden="true"/>
              {review.isPending&&review.variables?.id===item.id?'Registrando…':'Aprovar peça'}
            </button>
            <button type="button" className="button tickets-reject"
              disabled={review.isPending||(notes[item.id]??'').trim().length<5}
              onClick={()=>review.mutate({id:item.id,status:'rejected',review_note:(notes[item.id]??'').trim()})}>
              <X size={16} aria-hidden="true"/> Rejeitar pedido
            </button>
          </div>
        </div>
      </article>)}
    </div>}
    {review.error&&<div role="alert" className="tickets-approval-error">{readableError(review.error)}</div>}
    <footer className="tickets-approval-footnote">
      A aprovação registra a autorização. A movimentação de estoque ocorre em fluxo separado.
    </footer>
  </section>;
}
