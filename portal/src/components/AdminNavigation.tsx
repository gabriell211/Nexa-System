import { useState, type ReactNode } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import {
  AlertTriangle, ArrowRight, ChevronDown, ClipboardCheck,
  ClipboardList, Database, FileText, LayoutDashboard, Printer, Settings,
  ShieldAlert, Users, Wallet,
} from 'lucide-react';

type IconComponent = typeof LayoutDashboard;
type Entry = { label: string; href?: string; title?: string };
type Group = { key: string; title: string; icon: IconComponent; items: Entry[] };

const operational: Group[] = [
  { key: 'printers', title: 'Impressoras', icon: Printer, items: [
    { label: 'Parque completo', href: '/parque' },
    { label: 'Impressoras por cliente', href: '/parque?view=customer' },
    { label: 'Novas impressoras', href: '/parque?new=1' },
  ] },
  { key: 'finance', title: 'Financeiro', icon: Wallet, items: [
    { label: 'Contratos', href: '/contratos' },
    { label: 'Fechamentos', title: 'Fechamento financeiro ainda não homologado' },
  ] },
  { key: 'alerts', title: 'Alertas', icon: AlertTriangle, items: [
    { label: 'Ativos', href: '/alertas?status=active' },
    { label: 'Associados a chamados abertos', href: '/alertas?status=linked' },
    { label: 'Ignorados', href: '/alertas?status=ignored' },
    { label: 'Encerrados', href: '/alertas?status=resolved' },
  ] },
  { key: 'tickets', title: 'Chamados', icon: ClipboardCheck, items: [
    { label: 'Sem responsável', href: '/chamados?status=unassigned' },
    { label: 'Ativos', href: '/chamados?status=open' },
    { label: 'Encerrados', href: '/chamados?status=closed' },
  ] },
  { key: 'reports', title: 'Relatórios', icon: FileText, items: [
    { label: 'Histórico de páginas por impressora', href: '/parque' },
    { label: 'Digitalizações por impressora', title: 'Relatório consolidado de digitalizações ainda não homologado' },
    { label: 'Totais por cliente', title: 'Relatório consolidado por cliente ainda não homologado' },
    { label: 'Chamados', href: '/chamados' },
  ] },
];

export function AdminNavigation({ role, canStock, canAudit }: {
  role: string; canStock: boolean; canAudit: boolean;
}) {
  const location = useLocation();
  const scopes = ['owner', 'admin', 'manager', 'supervisor', 'technician'];
  const canOperate = scopes.includes(role);
  const canCreatePrinters = ['owner','admin','manager'].includes(role);
  const printersGroup: Group = {
    ...operational[0]!,
    items: operational[0]!.items.map(item => item.label === 'Novas impressoras' && !canCreatePrinters
      ? {label: item.label, title: 'Cadastro de impressoras exige perfil administrativo'} : item),
  };
  const reportsGroup: Group = {
    ...operational[4]!,
    items: operational[4]!.items.filter(item => canOperate || item.label !== 'Chamados'),
  };
  const groups: Group[] = [
    printersGroup,
    operational[1]!,
    ...(canOperate ? [operational[2]!] : []),
    ...(canStock ? [{ key: 'supplies', title: 'Suprimentos', icon: ClipboardList, items: [
      { label: 'Catálogo e fornecedores', href: '/suprimentos' },
      { label: 'Níveis dos suprimentos', title: 'Sem leitura padronizada de consumíveis homologada para o parque' },
    ] }] : []),
    ...(canOperate ? [operational[3]!] : []),
    reportsGroup,
    { key: 'settings', title: 'Configurações', icon: Settings, items: [
      ...(canAudit ? [{ label: 'Contas de usuários', href: '/equipe' }] : []),
      { label: 'Envio de e-mail', title: 'Preferências de e-mail dependem da integração e dos eventos homologados' },
      ...(canOperate ? [
        { label: 'Alertas', href: '/alertas' },
        { label: 'Chamados', href: '/chamados' },
        { label: 'Integração (agentes)', href: '/agentes' },
      ] : []),
      { label: 'Informações da empresa', title: 'Edição de dados da prestadora ainda não habilitada' },
    ] },
  ];
  const initial = groups.filter(group =>
    group.items.some(item => item.href?.split('?')[0] === location.pathname)
  ).map(group => group.key);
  const [expanded, setExpanded] = useState<string[]>(initial);
  const navLink = (href: string, label: ReactNode, Icon?: IconComponent) =>
    <NavLink to={href} end={href === '/dashboard'} className={({ isActive }) =>
      'nav-link' + (isActive ? ' active' : '')
    }>{Icon && <Icon size={19} aria-hidden="true"/>}{label}</NavLink>;

  return <nav className="sidebar-nav admin-nav" aria-label="Navegação principal">
    <span className="nav-caption">OPERAÇÃO</span>
    {navLink('/dashboard', 'Painel', LayoutDashboard)}
    {navLink('/clientes', 'Clientes', Users)}
    {groups.map(group => {
      const Icon = group.icon;
      const isOpen = expanded.includes(group.key);
      const active = group.items.some(item =>
        item.href?.split('?')[0] === location.pathname
      );
      return <div className="admin-nav-section" key={group.key}>
        <button type="button" className={'nav-link admin-nav-toggle' + (active ? ' parent-active' : '')}
          aria-controls={'admin-nav-' + group.key} aria-expanded={isOpen}
          onClick={() => setExpanded(values => isOpen
            ? values.filter(v => v !== group.key) : [...values, group.key])}>
          <Icon size={19} aria-hidden="true"/>{group.title}
          <ChevronDown size={16} className={'nav-chevron' + (isOpen ? ' expanded' : '')}/>
        </button>
        {isOpen && <div id={'admin-nav-' + group.key} className="admin-nav-children">
          {group.items.map(item => item.href
            ? <NavLink key={item.label} to={item.href} className={() =>
              'admin-nav-child' + ((location.pathname + location.search) === item.href ? ' selected' : '')
            }>{item.label}</NavLink>
            : <span key={item.label} className="admin-nav-child unavailable"
              title={item.title ?? 'Funcionalidade indisponível'}>{item.label}
              <small>Em implantação</small></span>)}
        </div>}
      </div>;
    })}
    {canStock && <>
      <span className="nav-caption nav-secondary">LOGÍSTICA</span>
      {navLink('/estoque', 'Estoque', Database)}
      {navLink('/reposicoes', 'Remessas', ArrowRight)}
    </>}
    {canAudit && <>
      <span className="nav-caption nav-secondary">GOVERNANÇA</span>
      {navLink('/auditoria', 'Auditoria', ShieldAlert)}
    </>}
  </nav>;
}
