import { useEffect, useState, type FormEvent } from 'react';
import { api, readableError } from '../api';

export function ActivationPage() {
  const [invite] = useState(() => {
    const params = new URLSearchParams(window.location.hash.replace(/^#/, ''));
    return { tenant: params.get('tenant') ?? '', token: params.get('token') ?? '' };
  });
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [error, setError] = useState('');
  const [result, setResult] = useState('');
  const [saving, setSaving] = useState(false);

  // The secret lives in the URL fragment only until React loads. Remove it
  // immediately so it will not appear in a copied address or browser history.
  useEffect(() => {
    if (window.location.hash) window.history.replaceState(null, '', '/ativar');
  }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!invite.tenant || !/^[a-f0-9]{64}$/.test(invite.token)) {
      setError('O convite está ausente ou inválido. Solicite um novo link ao administrador.');
      return;
    }
    if (password !== confirmation) {
      setError('As senhas não conferem.');
      return;
    }
    setError('');
    setSaving(true);
    try {
      const answer = await api<{ message: string }>('/auth/activate', null, {
        method: 'POST', body: JSON.stringify({
          tenant: invite.tenant, token: invite.token,
          password, password_confirmation: confirmation,
        }),
      });
      setResult(answer.message);
      setPassword(''); setConfirmation('');
    } catch (cause) { setError(readableError(cause)); }
    finally { setSaving(false); }
  }

  return <main className="login-screen">
    <section className="login-card">
      <div className="brand">NEXA <strong>CLIENTE</strong></div>
      <div className="activation-heading"><span className="eyebrow">ATIVAÇÃO SEGURA</span>
        <h1>Ative sua conta</h1>
        <p>Escolha uma senha para o acesso que sua prestadora liberou.</p>
      </div>
      {result ? <div className="activation-success" role="status"><p>{result}</p>
        <p>Cliente: acesse o portal do cliente fornecido pela prestadora. Colaborador: acesse o painel administrativo.</p>
        <a className="button primary" href="/">Ir para o início</a>
      </div> : <form className="login-form" onSubmit={e => void submit(e)}>
        <label htmlFor="new-password">Nova senha</label>
        <input id="new-password" type="password" autoComplete="new-password"
          minLength={12} required value={password} onChange={e => setPassword(e.target.value)}/>
        <label htmlFor="confirm-password">Confirmar senha</label>
        <input id="confirm-password" type="password" autoComplete="new-password"
          minLength={12} required value={confirmation} onChange={e => setConfirmation(e.target.value)}/>
        <p className="ticket-file-hint">Use pelo menos 12 caracteres, letras maiúsculas, minúsculas e números.
          O convite expira em 24 horas e pode ser usado uma única vez.</p>
        {error && <p role="alert" className="notice error">{error}</p>}
        <button className="button primary" type="submit" disabled={saving}>
          {saving ? 'Ativando...' : 'Ativar minha conta'}
        </button>
      </form>}
    </section>
    <aside className="login-art" aria-hidden="true"><div className="login-art-content">
      <h2>O acesso certo para cada cliente.</h2>
      <p>Senhas pessoais, carteiras explícitas e permissões verificadas pelo servidor.</p>
    </div></aside>
  </main>;
}
