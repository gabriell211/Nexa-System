import { useQuery } from '@tanstack/react-query';
import { Activity, AlertCircle, CircleCheck, Printer, TrendingUp } from 'lucide-react';
import { Link } from 'react-router-dom';
import { api, readableError } from '../api';
import { DailyMeterChart } from '../components/DailyMeterChart';
import { dateTime, label, meter } from '../helpers';
import type { ClientAnalytics, FleetPrinter, FleetSummary, Page } from '../types';

export function OverviewPage({ canViewReports = true }: { canViewReports?: boolean }) {
  const summary = useQuery({
    queryKey: ['customer-summary'],
    queryFn: () => api<FleetSummary>('/client/summary', null),
  });
  const analytics = useQuery({
    queryKey: ['customer-analytics', 'last30days'],
    queryFn: () => api<ClientAnalytics>('/client/analytics', null),
    enabled: canViewReports,
  });
  const recent = useQuery({
    queryKey: ['customer-preview'],
    queryFn: () => api<Page<FleetPrinter>>('/client/printers?per_page=5&active_only=1', null),
  });
  const fleet = summary.data;
  const withReadings = fleet?.printers_with_readings ?? 0;
  const withoutReadings = fleet ? Math.max(0, fleet.printers - withReadings) : null;

  return <>
    <div className="reference-page-title"><TrendingUp size={23} aria-hidden="true"/><h1>Painel</h1></div>
    <section className="fleet-status-panel" aria-label="Resumo do parque">
      <div className="headline-stat">
        <Printer size={23} aria-hidden="true" />
        <strong>{fleet?.printers ?? '—'}</strong><span>Impressoras cadastradas</span>
      </div>
      <div className="fleet-health-group">
        <div className="health-indicator"><span className="health-symbol no-reading"><AlertCircle size={23}/></span>
          <span><strong>{withoutReadings ?? '—'}</strong><small>Sem leitura registrada</small></span></div>
        <div className="health-indicator"><span className="health-symbol reading"><CircleCheck size={23}/></span>
          <span><strong>{fleet ? withReadings : '—'}</strong><small>Com leitura registrada</small></span></div>
        <div className="health-indicator"><span className="health-symbol registered"><Activity size={23}/></span>
          <span><strong>{fleet?.active ?? '—'}</strong><small>Ativas no cadastro</small></span></div>
      </div>
      {summary.isPending && <p role="status" className="summary-note">Carregando indicadores...</p>}
      {summary.error && <p role="alert" className="error">{readableError(summary.error)}</p>}
      <p className="summary-note">Estes estados não representam um teste de rede em tempo real.</p>
    </section>

    {!canViewReports ? <section className="report-panel"><p className="chart-empty">Seu perfil não possui permissão para visualizar gráficos de páginas.</p></section>
      : analytics.isPending ? <section className="report-panel"><p role="status" className="loading-chart">Carregando histórico real dos últimos 30 dias...</p></section>
      : analytics.error ? <section className="report-panel"><h2>Produção observada</h2>
        <p role="alert" className="error">{readableError(analytics.error)}</p></section>
      : analytics.data && <DailyMeterChart analytics={analytics.data} />}

    <section className="report-panel">
      <div className="report-heading"><div><h2>Impressoras com maior variação P&B (últimos 30 dias)</h2>
        <p>Ranking calculado somente onde há diferenças válidas entre leituras no mesmo dia.</p></div>
        {canViewReports && <Link to="/contadores" className="inline-link">Consultar contadores</Link>}</div>
      {!canViewReports ? <div className="chart-empty">Relatório não autorizado para esta conta.</div>
        : !analytics.data ? <div className="chart-empty">Aguardando leituras disponíveis.</div>
        : analytics.data.top_printers.length === 0
          ? <div className="chart-empty">Sem medições suficientes para compor o ranking.</div>
          : <div className="ranking-table table-scroller"><table><thead><tr><th>#</th><th>Impressora</th><th>Endereço IP</th><th>Variação P&B observada</th></tr></thead>
            <tbody>{analytics.data.top_printers.map((item, index) =>
              <tr key={item.id}><td>{index + 1}</td><td><strong>{item.name}</strong></td>
                <td className="mono">{label(item.ip_address)}</td><td className="mono">{meter(item.mono_observed_delta)}</td></tr>)}</tbody></table></div>}
    </section>

    <section className="report-panel">
      <div className="report-heading"><h2>Equipamentos do seu parque</h2>
        <Link to="/impressoras" className="inline-link">Ver todas</Link></div>
      {recent.isPending ? <p role="status" className="loading-chart">Carregando equipamentos...</p>
        : recent.error ? <p role="alert" className="error">{readableError(recent.error)}</p>
        : !recent.data?.data.length ? <p className="chart-empty">Nenhum equipamento encontrado.</p>
        : <div className="table-scroller"><table><thead><tr><th>Equipamento</th><th>Localização</th><th>Endereço IP</th><th>Último total</th><th>Coletado em</th></tr></thead>
          <tbody>{recent.data.data.map(p => <tr key={p.id}><td><strong>{p.manufacturer} {p.model}</strong>
            <small>{label(p.serial_number)}</small></td>
            <td>{label(p.location)}</td><td className="mono">{label(p.ip_address)}</td>
            <td className="mono">{canViewReports ? meter(p.latest_reading?.meter_total) : 'Restrito'}</td>
            <td>{dateTime(p.latest_reading?.collected_at)}</td></tr>)}</tbody></table></div>}
    </section>
    <p className="footnote">Indicadores e contadores são baseados exclusivamente no parque vinculado ao seu acesso.
      As diferenças observadas não substituem conferência de leitura e regras de faturamento.</p>
  </>;
}
