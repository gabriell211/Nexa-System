import { ChevronLeft, ChevronRight } from 'lucide-react';

export function Pagination({ page, pages, total, setPage }: {
  page: number; pages: number; total: number; setPage: (page: number) => void;
}) {
  return <div className="pagination">
    <span>{total.toLocaleString('pt-BR')} equipamentos · Página {page} de {Math.max(1, pages)}</span>
    <div className="pagination-buttons">
      <button aria-label="Página anterior" disabled={page <= 1} onClick={() => setPage(page - 1)}>
        <ChevronLeft size={18} />
      </button>
      <button aria-label="Próxima página" disabled={page >= pages} onClick={() => setPage(page + 1)}>
        <ChevronRight size={18} />
      </button>
    </div>
  </div>;
}
