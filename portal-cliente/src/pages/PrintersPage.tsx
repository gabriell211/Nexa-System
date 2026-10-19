import { FleetPanel } from '../components/FleetPanel';
import { label, meter } from '../helpers';

export function PrintersPage() {
  return <>
    <div className="page-heading"><div><span className="eyebrow">INVENTÁRIO</span>
      <h1>Minhas impressoras</h1><p>Consulte os equipamentos, endereços IP e pontos de uso da sua empresa.</p></div></div>
    <FleetPanel headings={['Situação', 'Fabricante', 'Modelo', 'Localização', 'Departamento', 'Centro de custo', 'Endereço IP', 'Número de série', 'Patrimônio', 'Proprietário', 'Total de páginas*']}
      renderColumns={p => <>
        <td><span className={'status ' + (p.active ? 'active' : 'inactive')}>{p.active ? 'Ativa' : 'Inativa'}</span>
          <small>{p.status === 'unknown' ? 'Estado não determinado' : p.status}</small></td>
        <td>{p.manufacturer}</td><td><strong>{p.model}</strong><small>{label(p.customer_name)}</small></td>
        <td>{label(p.location)}</td><td>{label(p.department)}</td>
        <td>{label(p.cost_center)}{p.cost_center_code && <small>{p.cost_center_code}</small>}</td>
        <td className="mono">{label(p.ip_address)}</td>
        <td className="mono">{label(p.serial_number)}</td>
        <td className="mono">{label(p.asset_number)}</td>
        <td>{({unknown:'Não informado',customer:'Cliente',provider:'Prestadora'})[p.owner_type]}</td>
        <td className="mono">{meter(p.latest_reading?.meter_total)}</td>
      </>} />
    <p className="footnote">* Total acumulado informado pelo equipamento, quando disponível. Não equivale necessariamente a páginas faturáveis. Patrimônio e proprietário só aparecem quando registrados. Contrato e ponto de instalação físico exigem vínculo próprio homologado.</p>
  </>;
}
