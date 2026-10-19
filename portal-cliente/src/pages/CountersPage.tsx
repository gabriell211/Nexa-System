import { FleetPanel } from '../components/FleetPanel';
import { dateTime, meter } from '../helpers';

export function CountersPage() {
  return <>
    <div className="page-heading"><div><span className="eyebrow">LEITURAS</span>
      <h1>Contadores de páginas</h1><p>Últimas leituras recebidas de cada equipamento.</p></div></div>
    <FleetPanel headings={['Equipamento', 'Endereço IP', 'Total', 'Preto e branco', 'Coloridas', 'Digitalizações', 'Data da coleta']}
      renderColumns={p => <>
        <td><strong>{p.manufacturer} {p.model}</strong><small>{p.serial_number ?? 'Série não informada'}</small></td>
        <td className="mono">{p.ip_address ?? '—'}</td>
        <td className="mono strong-number">{meter(p.latest_reading?.meter_total)}</td>
        <td className="mono">{meter(p.latest_reading?.meter_mono)}</td>
        <td className="mono">{meter(p.latest_reading?.meter_color)}</td>
        <td className="mono">{meter(p.latest_reading?.meter_scan)}</td>
        <td>{dateTime(p.latest_reading?.collected_at)}</td>
      </>} />
    <p className="footnote">Contadores são acumulados e podem ser indisponíveis; o símbolo “—” não representa zero. Nenhum consumo mensal é estimado a partir de leituras incompletas.</p>
  </>;
}
