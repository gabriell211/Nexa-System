import { Search } from 'lucide-react';

export function FleetControls({ search, setSearch, active, setActive, reset }: {
  search: string; setSearch: (value: string) => void;
  active: boolean; setActive: (value: boolean) => void;
  reset: () => void;
}) {
  return <div className="fleet-controls">
    <label className="search-control"><Search size={18} aria-hidden="true" />
      <input type="search" placeholder="Fabricante, modelo, IP ou número de série"
        aria-label="Buscar equipamentos" maxLength={100} value={search}
        onChange={e => { setSearch(e.target.value); reset(); }} />
    </label>
    <label className="checkbox-control">
      <input type="checkbox" checked={active} onChange={e => { setActive(e.target.checked); reset(); }} />
      Apenas ativas
    </label>
  </div>;
}
