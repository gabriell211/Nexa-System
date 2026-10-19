import { ClipboardList } from 'lucide-react';

export function TicketsPage() {
  return <>
    <div className="page-heading"><div><span className="eyebrow">ATENDIMENTO</span><h1>Chamados</h1>
      <p>Acompanhamento de solicitações técnicas.</p></div></div>
    <section className="report-panel tickets-info">
      <ClipboardList size={30} aria-hidden="true"/>
      <h2>Consulta de chamados ainda não habilitada</h2>
      <p>O portal do cliente ainda não disponibiliza chamados vinculados à sua empresa.
        Solicite o atendimento diretamente à prestadora responsável pelo seu contrato.
        Não são exibidos chamados ou prazos sem confirmação na API.</p>
    </section>
  </>;
}
