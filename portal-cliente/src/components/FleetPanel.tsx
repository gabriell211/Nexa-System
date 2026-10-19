import type { ReactNode } from 'react';
import { readableError } from '../api';
import { FleetControls } from './FleetControls';
import { Pagination } from './Pagination';
import { pagination } from '../helpers';
import { useFleet } from '../hooks/useFleet';
import type { FleetPrinter } from '../types';

export function FleetPanel({ renderColumns, headings }: {
  headings: string[];
  renderColumns: (printer: FleetPrinter) => ReactNode;
}) {
  const data = useFleet();
  const { request, page, setPage, search, setSearch, active, setActive } = data;
  const meta = pagination(request.data);
  return <section className="panel">
    <FleetControls search={search} setSearch={setSearch} active={active}
      setActive={setActive} reset={() => setPage(1)} />
    {request.isPending ? <p role="status">Carregando equipamentos...</p>
      : request.error ? <p role="alert" className="error">{readableError(request.error)}</p>
      : !request.data || request.data.data.length === 0
        ? <p className="empty">Nenhum equipamento disponível para estes filtros.</p>
        : <div className="table-scroller"><table>
            <thead><tr>{headings.map(name => <th key={name} scope="col">{name}</th>)}</tr></thead>
            <tbody>{request.data.data.map(printer => <tr key={printer.id}>
              {renderColumns(printer)}
            </tr>)}</tbody>
          </table></div>}
    <Pagination {...meta} setPage={setPage} />
  </section>;
}
