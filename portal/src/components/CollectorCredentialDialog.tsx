import { useState } from 'react';
import { Check, ClipboardCopy, KeyRound, X } from 'lucide-react';
import { api, readableError } from '../api';
import type { Customer } from '../types';

interface IssuedCollectorCredentials {
  customer_id: number;
  collector_id: string;
  collector_token: string;
}

interface Props {
  customer: Customer;
  token: string | null;
  onClose: () => void;
  onIssued: () => void;
}

export function CollectorCredentialDialog({ customer, token, onClose, onIssued }: Props) {
  const [issued, setIssued] = useState<IssuedCollectorCredentials | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState<'id' | 'token' | null>(null);

  async function issue(): Promise<void> {
    if (issued || pending) return;
    setPending(true);
    setError(null);
    try {
      const credentials = await api<IssuedCollectorCredentials>(
        `/customers/${customer.id}/collector-credentials`, token, { method: 'POST' },
      );
      setIssued(credentials);
      onIssued();
    } catch (cause) {
      setError(readableError(cause));
    } finally {
      setPending(false);
    }
  }

  async function copy(value: string, field: 'id' | 'token'): Promise<void> {
    setError(null);
    setCopied(null);
    try {
      await navigator.clipboard.writeText(value);
      setCopied(field);
    } catch {
      setError('Não foi possível copiar automaticamente. Selecione o conteúdo e copie manualmente.');
    }
  }

  return (
    <div className="modal-overlay">
      <section role="dialog" aria-modal="true" aria-labelledby="collector-credential-dialog-title" className="modal">
        <div className="modal-header">
          <div>
            <span className="eyebrow">VINCULAÇÃO DO COLLECTOR</span>
            <h2 id="collector-credential-dialog-title">Chave do cliente: {customer.name}</h2>
          </div>
          <button type="button" className="icon-button" aria-label="Fechar" onClick={onClose}>
            <X size={20} />
          </button>
        </div>
        {!issued ? (
          <>
            <p>Crie uma credencial exclusiva para instalar um Collector neste cliente. Cada instalação recebe seu próprio ID e token.</p>
            <p className="stock-footnote">
              O token será exibido uma única vez. Não será possível recuperar a chave depois que esta janela for fechada.
              Gerar outra credencial não revoga as existentes.
            </p>
            {error && <p role="alert" className="notice error">{error}</p>}
            <div className="modal-actions">
              <button type="button" className="button ghost" onClick={onClose} disabled={pending}>Cancelar</button>
              <button type="button" className="button primary" onClick={() => void issue()} disabled={pending}>
                <KeyRound size={16} aria-hidden="true" /> {pending ? 'Gerando...' : 'Gerar chave do Collector'}
              </button>
            </div>
          </>
        ) : (
          <>
            <p role="status" className="notice success">
              Credencial emitida. Copie os dois valores antes de fechar esta janela.
            </p>
            <div className="editor-form">
              <label htmlFor="issued-collector-id">ID do Collector</label>
              <div className="inline">
                <input id="issued-collector-id" type="text" readOnly autoComplete="off" spellCheck={false} value={issued.collector_id} />
                <button type="button" className="button ghost" onClick={() => void copy(issued.collector_id, 'id')}
                  aria-label="Copiar ID do Collector">
                  {copied === 'id' ? <Check size={16} /> : <ClipboardCopy size={16} />}
                  {copied === 'id' ? 'Copiado' : 'Copiar ID'}
                </button>
              </div>
              <label htmlFor="issued-collector-token">Chave do Collector (exibição única)</label>
              <div className="inline">
                <input id="issued-collector-token" type="text" readOnly autoComplete="off" spellCheck={false} value={issued.collector_token} />
                <button type="button" className="button ghost" onClick={() => void copy(issued.collector_token, 'token')}
                  aria-label="Copiar chave do Collector">
                  {copied === 'token' ? <Check size={16} /> : <ClipboardCopy size={16} />}
                  {copied === 'token' ? 'Copiado' : 'Copiar chave'}
                </button>
              </div>
            </div>
            <p className="stock-footnote">
              Configure o collector_id e o token no Collector com armazenamento seguro. Não compartilhe a chave em mensagens públicas.
            </p>
            {error && <p role="alert" className="notice error">{error}</p>}
            <div className="modal-actions">
              <button type="button" className="button primary" onClick={onClose}>Concluir e ocultar a chave</button>
            </div>
          </>
        )}
      </section>
    </div>
  );
}
