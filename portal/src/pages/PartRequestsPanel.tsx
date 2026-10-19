import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, readableError } from '../api';

interface PartRequest {
  id: number; ticket_id: number; quantity: number; status: 'pending'|'approved'|'rejected';
  reason: string; created_at: string;
  stock_item: { id:number;name:string;sku:string } | null;
  technician: { id:number;name:string } | null;
}
interface Page<T> { data:T[] }

export function PartRequestsPanel() {
  const [notes,setNotes]=useState<Record<number,string>>({});
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
    onSuccess:async()=>{await queryClient.invalidateQueries({queryKey:['ticket-parts-pending']})},
  });
  return <section className="data-card" aria-label="Solicitações de peças dos técnicos">
    <h2>Peças solicitadas pelos técnicos</h2>
    <p>Autorize ou rejeite pedidos. A autorização não movimenta automaticamente o estoque.</p>
    {query.isPending&&<p>Carregando pedidos...</p>}
    {query.error&&<p role="alert" className="notice error">{readableError(query.error)}</p>}
    {(query.data?.data??[]).length===0&&!query.isPending&&<p>Nenhuma peça aguardando aprovação.</p>}
    {(query.data?.data??[]).map(item=><div key={item.id} className="stock-form">
      <h3>Chamado #{item.ticket_id} · {item.stock_item?.name??'Peça'}</h3>
      <p>{item.quantity} unidade(s) · Técnico: {item.technician?.name??'Não informado'}</p>
      <p>{item.reason}</p>
      <div className="stock-field">
        <label htmlFor={'part-review-'+item.id}>Justificativa da decisão</label>
        <input id={'part-review-'+item.id} type="text" minLength={5} maxLength={500}
          value={notes[item.id]??''} onChange={event=>
            setNotes(prev=>({...prev,[item.id]:event.target.value}))}/>
      </div>
      <div className="inline">
        <button className="button primary" type="button"
          disabled={review.isPending||(notes[item.id]??'').trim().length<5}
          onClick={()=>review.mutate({id:item.id,status:'approved',review_note:notes[item.id]!.trim()})}>
          Aprovar peça
        </button>
        <button className="button ghost" type="button"
          disabled={review.isPending||(notes[item.id]??'').trim().length<5}
          onClick={()=>review.mutate({id:item.id,status:'rejected',review_note:notes[item.id]!.trim()})}>
          Rejeitar
        </button>
      </div>
    </div>)}
    {review.error&&<p role="alert" className="notice error">{readableError(review.error)}</p>}
  </section>;
}
