import { useQuery } from '@tanstack/react-query';
import { Activity, Building2, FileText, Printer } from 'lucide-react';
import { Link } from 'react-router-dom';
import { api, readableError } from '../api';
import { dateTime, label, meter } from '../helpers';
import type { FleetPrinter, FleetSummary, Page } from '../types';

export function OverviewPage() {
  const summary = useQuery({ queryKey: ['customer-summary'],
    queryFn: () => api<FleetSummary>('/client/summary', null) });
  const recent = useQuery({ queryKey: ['customer-preview'],
    queryFn: () => api<Page<FleetPrinter>>('/client/printers?per_page=5&active_only=1', null) });
  return <>
    <div className="page-heading"><div><span className="eyebrow">VISÃO GERAL</span>
      <h1>Seu parque de impressão</h1>
      <p>Equipamentos associados à sua empresa e últimas leituras registradas.</p>
    </div><Link className="button primary" to="/impressoras">Ver todas as impressoras</Link></div>
    {summary.error ? <p role="alert" className="error">{readableError(summary.error)}</p>
      : <div className="stats">
        <div className="stat"><Printer size={22}/><span>Equipamentos</span><strong>{summary.data?.printers ?? '—'}</strong></div>
        <div className="stat"><Activity size={22}/><span>Ativos no cadastro</span><strong>{summary.data?.active ?? '—'}</strong></div>
        <div className="stat"><FileText size={22}/><span>Com leituras</span><strong>{summary.data?.printers_with_readings ?? '—'}</strong></div>
        <div className="stat"><Building2 size={22}/><span>Clientes vinculados</span><strong>{summary.data?.assigned_customers ?? '—'}</strong></div>
      </div>}
    <section className="panel"><div className="panel-heading"><h2>Equipamentos em destaque</h2>
      <Link to="/contadores">Consultar contadores</Link></div>
      {recent.isPending ? <p role="status">Carregando...</p> : recent.error
        ? <p role="alert" className="error">{readableError(recent.error)}</p>
        : !recent.data?.data.length ? <p className="empty">Nenhum equipamento vinculado ao seu acesso.</p>
        : <div className="table-scroller"><table><thead><tr><th>Equipamento</th><th>Localização</th><th>IP</th><th>Último total</th><th>Coletado em</th></tr></thead>
          <tbody>{recent.data.data.map(p => <tr key={p.id}>
            <td><strong>{p.manufacturer} {p.model}</strong><small>{label(p.serial_number)}</small></td>
            <td>{label(p.location)}</td><td className="mono">{label(p.ip_address)}</td>
            <td className="mono">{meter(p.latest_reading?.meter_total)}</td>
            <td>{dateTime(p.latest_reading?.collected_at)}</td></tr>)}</tbody></table></div>}
    </section>
    <p className="footnote">Os números são leituras acumuladas reais, não produção mensal ou faturamento. Cadastro ativo não confirma conectividade atual.</p>
  </>;
}
