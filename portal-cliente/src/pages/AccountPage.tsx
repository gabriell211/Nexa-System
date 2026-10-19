import type { ClientIdentity } from '../types';

export function AccountPage({ identity }: { identity: ClientIdentity }) {
  return <>
    <div className="page-heading"><div><span className="eyebrow">MINHA CONTA</span>
      <h1>Dados de acesso</h1><p>Seu perfil possui somente permissão de consulta.</p></div></div>
    <section className="panel account-card">
      <dl><dt>Usuário</dt><dd>{identity.user.name}</dd>
      <dt>E-mail</dt><dd>{identity.user.email ?? 'Não informado'}</dd>
      <dt>Empresa prestadora</dt><dd>{identity.tenant.name}</dd>
      <dt>Perfil</dt><dd>Cliente — acesso somente leitura</dd></dl>
      <p className="footnote">Para visualizar outra unidade ou cliente, solicite à empresa responsável a atualização dos vínculos de acesso. Não é possível modificar permissões por este portal.</p>
    </section>
  </>;
}
