import { FleetPanel } from '../components/FleetPanel';
import { label } from '../helpers';

export function LocationsPage() {
  return <>
    <div className="page-heading"><div><span className="eyebrow">ORGANIZAÇÃO</span>
      <h1>Localização das máquinas</h1><p>Confira em quais unidades, departamentos e centros de custo suas impressoras foram registradas.</p></div></div>
    <FleetPanel headings={['Equipamento', 'Cliente', 'Unidade / localização', 'Departamento', 'Centro de custo', 'Endereço IP']}
      renderColumns={p => <>
        <td><strong>{p.manufacturer} {p.model}</strong><small>{label(p.serial_number)}</small></td>
        <td>{label(p.customer_name)}</td><td>{label(p.location)}</td>
        <td>{label(p.department)}</td><td>{label(p.cost_center)}{p.cost_center_code && <small>{p.cost_center_code}</small>}</td>
        <td className="mono">{label(p.ip_address)}</td>
      </>} />
    <p className="footnote">Localização é o último vínculo cadastrado pela equipe de suporte; não representa geolocalização em tempo real.</p>
  </>;
}
