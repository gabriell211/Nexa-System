import { useState } from 'react';
import type { ClientAnalytics } from '../types';

const X0 = 62;
const Y0 = 22;
const W = 806;
const H = 254;

const shortDate = (text: string) => {
  const parts = text.split('-');
  return parts.length === 3 ? `${parts[2]}/${parts[1]}` : text;
};
const compact = (number: number) => number.toLocaleString('pt-BR', { maximumFractionDigits: 0 });

/**
 * Render only valid observed day-to-day *intra-day* deltas.
 * A missing/unsupported sample is not a zero and never receives a point.
 */
export function DailyMeterChart({ analytics }: { analytics: ClientAnalytics }) {
  const [metric, setMetric] = useState<'mono' | 'total'>('mono');
  const data = analytics.days.map((day, index) => {
    const raw = day[metric];
    const number = raw !== null && /^\d+$/.test(raw) ? Number(raw) : NaN;
    return { ...day, index, value: Number.isSafeInteger(number) && number >= 0 ? number : null };
  });
  const observed = data.filter(item => item.value !== null);
  const max = Math.max(1, ...observed.map(item => item.value ?? 0));
  const chartMax = Math.max(5, Math.ceil(max / 5) * 5);
  const x = (index: number) => X0 + (index / Math.max(1, data.length - 1)) * W;
  const y = (value: number) => Y0 + H - (value / chartMax) * H;
  const segments: string[][] = [];
  let segment: string[] = [];
  for (const point of data) {
    if (point.value === null) {
      if (segment.length) segments.push(segment);
      segment = [];
    } else {
      segment.push(`${x(point.index)},${y(point.value)}`);
    }
  }
  if (segment.length) segments.push(segment);

  return <section className="report-panel">
    <div className="report-heading">
      <div><h2>Variação observada por dia (últimos 30 dias)</h2>
        <p>Diferença entre leituras válidas do mesmo dia, sem estimar períodos ausentes.</p></div>
      <div className="metric-options" role="group" aria-label="Tipo de contador">
        <button type="button" className={metric === 'mono' ? 'selected' : ''}
          aria-pressed={metric === 'mono'} onClick={() => setMetric('mono')}>P&B</button>
        <button type="button" className={metric === 'total' ? 'selected' : ''}
          aria-pressed={metric === 'total'} onClick={() => setMetric('total')}>Total</button>
      </div>
    </div>
    {!observed.length ? <div className="chart-empty" role="status">
      Não há leituras suficientes para gerar a variação diária. São necessárias ao menos duas
      leituras válidas do mesmo equipamento no mesmo dia.
    </div> : <>
      <div className="chart-scroller"><svg className="daily-chart" viewBox="0 0 900 328" role="img"
        aria-label={`Gráfico dos últimos 30 dias: ${observed.length} dias com diferenças observadas, ${metric === 'mono' ? 'preto e branco' : 'total'}`}>
        {Array.from({ length: 6 }, (_, i) => {
          const value = chartMax * (5 - i) / 5;
          const yy = Y0 + (i / 5) * H;
          return <g key={i}><line x1={X0} y1={yy} x2={X0 + W} y2={yy} className="chart-grid"/>
            <text x={X0 - 10} y={yy + 4} className="axis-label" textAnchor="end">{compact(value)}</text></g>;
        })}
        {data.map((d, i) => <g key={d.date}>
          <line x1={x(i)} y1={Y0} x2={x(i)} y2={Y0 + H} className="chart-grid vertical"/>
          {(i === 0 || i === data.length - 1 || i % 4 === 0) &&
            <text x={x(i)} y={Y0 + H + 23} className="axis-label" textAnchor="middle">{shortDate(d.date)}</text>}
        </g>)}
        {segments.map((points, i) => <polyline key={i} points={points.join(' ')}
          className="chart-series" fill="none"/>)}
        {data.filter(d => d.value !== null).map(point => <circle key={point.date}
          cx={x(point.index)} cy={y(point.value!)} r={4.3} className="chart-dot">
          <title>{shortDate(point.date)}: {compact(point.value!)} — {point.devices_with_evidence} impressora(s) com evidência</title>
        </circle>)}
      </svg></div>
      <div className="chart-legend"><span className="legend-line"/> {metric === 'mono' ? 'P&B observado' : 'Total observado'}</div>
    </>}
    <p className="chart-disclaimer">Datas em UTC. Dias sem comparação válida aparecem sem valor, não como zero.
      Estes números são parciais e não são aprovados para faturamento.</p>
  </section>;
}
