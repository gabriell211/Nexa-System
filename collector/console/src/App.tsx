import { invoke } from "@tauri-apps/api/core";
import {
  Activity, AlertTriangle, ArrowDownUp, CheckCircle2, CircleHelp, Database,
  HardDrive, LayoutDashboard, Network, Printer, RefreshCcw, Settings2, ShieldCheck,
} from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type PrinterInfo = { printer_id: number; ip: string; oid: string };
type DeliveryDiagnostics = {
  last_success_at: string | null;
  last_failure_at: string | null;
  last_error_kind: string | null;
  next_attempt_at: string | null;
  consecutive_failures: number;
};
type QuarantinedReading = {
  sample_id: string;
  printer_id: number;
  collected_at: string;
  quarantined_at: string;
  reason: string;
};
type CollectorSnapshot = {
  configured: boolean;
  collector_id: string | null;
  endpoint: string | null;
  interval_seconds: number | null;
  printers: PrinterInfo[];
  queue_pending: number | null;
  quarantine_count: number | null;
  quarantine_preview: QuarantinedReading[];
  queue_capacity: number | null;
  delivery: DeliveryDiagnostics | null;
  last_run: string | null;
  error: string | null;
};
type Tab = "overview" | "printers" | "quarantine" | "settings";

function formatDate(value: string | null): string {
  if (!value) return "Nenhuma execução registrada";
  const timestamp = new Date(value);
  if (Number.isNaN(timestamp.getTime())) return "Data inválida";
  return new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "medium" }).format(timestamp);
}

export default function App() {
  const [tab, setTab] = useState<Tab>("overview");
  const [snapshot, setSnapshot] = useState<CollectorSnapshot | null>(null);
  const [loading, setLoading] = useState(false);
  const [requestError, setRequestError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setRequestError(null);
    try {
      setSnapshot(await invoke<CollectorSnapshot>("get_snapshot"));
    } catch (error) {
      setRequestError(String(error));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void refresh(); }, [refresh]);

  const queuePending = snapshot?.queue_pending ?? 0;
  const queueCapacity = snapshot?.queue_capacity ?? 0;
  const quarantineCount = snapshot?.quarantine_count ?? 0;
  const retainedCount = queuePending + quarantineCount;
  const queueObserved = snapshot?.queue_pending !== null && snapshot?.queue_pending !== undefined
    && snapshot?.queue_capacity !== null && snapshot?.queue_capacity !== undefined && queueCapacity > 0;
  const queuePercent = queueObserved ? Math.min(100, Math.round(retainedCount / queueCapacity * 100)) : null;
  const queueFull = queueObserved && retainedCount >= queueCapacity;
  const queueWarning = queueObserved && retainedCount >= queueCapacity * 0.8;

  const menu: { id: Tab; label: string; icon: typeof LayoutDashboard }[] = [
    { id: "overview", label: "Visão geral", icon: LayoutDashboard },
    { id: "printers", label: "Impressoras", icon: Printer },
    { id: "quarantine", label: "Quarentena", icon: AlertTriangle },
    { id: "settings", label: "Configuração", icon: Settings2 },
  ];

  return (
    <div className="layout">
      <aside className="sidebar">
        <div className="brand"><div className="brandmark">N</div><span>NEXA <b>COLLECTOR</b></span></div>
        <div className="side-label">MONITORAMENTO LOCAL</div>
        <nav aria-label="Menu do Collector">
          {menu.map((entry) => {
            const Icon = entry.icon;
            return (
              <button key={entry.id} type="button" onClick={() => setTab(entry.id)}
                className={tab === entry.id ? "nav-item active" : "nav-item"}
                aria-current={tab === entry.id ? "page" : undefined}>
                <Icon size={18} aria-hidden="true" />{entry.label}
              </button>
            );
          })}
        </nav>
        <div className="sidebar-bottom">
          <ShieldCheck size={18} />
          <div><strong>Dados locais</strong><span>Credenciais fora do painel</span></div>
        </div>
      </aside>

      <main className="main">
        <header className="topbar">
          <div className="crumb">Nexa System <span>/</span> Collector Console</div>
          <div className="version">DESKTOP 0.1.0</div>
        </header>
        <div className="content">
          <div className="heading">
            <div>
              <p className="eyebrow">CONTROLE DA ESTAÇÃO</p>
              <h1>{tab === "overview" ? "Visão geral" : tab === "printers" ? "Impressoras" : tab === "quarantine" ? "Quarentena" : "Configurações"}</h1>
              <p>Informações lidas do arquivo de configuração e da fila local do Nexa Collector.</p>
            </div>
            <button type="button" className="primary-button" onClick={() => void refresh()} disabled={loading}>
              <RefreshCcw size={16} className={loading ? "spin" : ""} aria-hidden="true" />
              {loading ? "Consultando..." : "Atualizar"}
            </button>
          </div>

          {(requestError || snapshot?.error) && (
            <div className="notice error" role="alert">{requestError ?? snapshot?.error}</div>
          )}
          {!snapshot && !requestError && <div className="notice">Carregando estado local...</div>}

          {tab === "overview" && (
            <>
              <section className="metrics" aria-label="Indicadores do coletor">
                <Metric icon={Network} title="Configuração" value={snapshot?.configured ? "Encontrada" : "Pendente"}
                  description="Arquivo local validado" />
                <Metric icon={Printer} title="Impressoras" value={snapshot ? String(snapshot.printers.length) : "—"}
                  description="Equipamentos configurados" />
                <Metric icon={Database} title="Fila de envio" value={snapshot?.queue_pending === null || snapshot === null ? "—" : String(snapshot.queue_pending)}
                  description={queueObserved
                    ? `Amostras pendentes de ${queueCapacity.toLocaleString("pt-BR")} permitidas`
                    : "Amostras aguardando ACK"} />
                <Metric icon={Activity} title="Última execução" value={snapshot?.last_run ? "Registrada" : "Sem registro"}
                  description={formatDate(snapshot?.last_run ?? null)} />
              </section>
              {queueObserved && (
                <section className="panel queue-panel" aria-label="Ocupação da fila">
                  <div className="panel-title"><h2>Capacidade de armazenamento local</h2>
                    <span>{queuePercent}% da capacidade configurada</span></div>
                  <div className="queue-body">
                    <div className="queue-track" role="progressbar" aria-label="Fila de amostras pendentes"
                      aria-valuemin={0} aria-valuemax={queueCapacity} aria-valuenow={retainedCount}>
                      <div className={queueFull ? "queue-fill full" : queueWarning ? "queue-fill warning" : "queue-fill"}
                        style={{ width: `${queuePercent}%` }} />
                    </div>
                    <p>{retainedCount.toLocaleString("pt-BR")} / {queueCapacity.toLocaleString("pt-BR")} amostras retidas
                      ({queuePending.toLocaleString("pt-BR")} pendentes, {quarantineCount.toLocaleString("pt-BR")} isoladas).</p>
                  </div>
                </section>
              )}
              {queueWarning && (
                <div className="notice warning" role="status">
                  {queueFull
                    ? "A fila atingiu o limite. Se o serviço estiver ativo, novas consultas SNMP são suspensas até a confirmação de leituras pendentes."
                    : "A fila está próxima do limite. Verifique a conectividade do serviço pelos logs e a confirmação de uploads no servidor."}
                  {" "}O console mostra apenas o estado persistido; não comprova a conectividade do equipamento.
                </div>
              )}
              {quarantineCount > 0 && (
                <div className="notice warning" role="status">
                  {quarantineCount.toLocaleString("pt-BR")} leitura(s) isolada(s) após rejeição individual HTTP 422.
                  Consulte a aba Quarentena para identificar os UUIDs e recuperar manualmente após corrigir a causa.
                </div>
              )}
              <section className="panel">
                <div className="panel-title"><h2>Estado operacional</h2><span>Informações verificáveis</span></div>
                <div className="detail-grid">
                  <Info icon={CheckCircle2} label="Cadastro local" value={snapshot?.configured ? "Configuração válida" : "Não configurado"} />
                  <Info icon={HardDrive} label="Persistência" value={snapshot?.queue_pending === null || snapshot === null ? "Fila não disponível" : "SQLite WAL acessível"} />
                  <Info icon={ArrowDownUp} label="Transmissão" value="Verificar logs do serviço" />
                  <Info icon={CircleHelp} label="Serviço do sistema" value="Estado não verificado pela interface" />
                </div>
              </section>
              <section className="panel">
                <div className="panel-title"><h2>Diagnóstico de transmissão</h2><span>Histórico local da outbox</span></div>
                <div className="detail-grid">
                  <Info icon={CheckCircle2} label="Último ACK de upload"
                    value={snapshot?.delivery?.last_success_at ? formatDate(snapshot.delivery.last_success_at) : "Sem confirmação registrada"} />
                  <Info icon={Activity} label="Última falha"
                    value={snapshot?.delivery?.last_failure_at ? formatDate(snapshot.delivery.last_failure_at) : "Sem falha registrada"} />
                  <Info icon={CircleHelp} label="Categoria da falha"
                    value={snapshot?.delivery?.last_error_kind ?? "Nenhuma falha pendente"} />
                  <Info icon={RefreshCcw} label="Próxima tentativa (estimada)"
                    value={snapshot?.delivery?.next_attempt_at ? formatDate(snapshot.delivery.next_attempt_at) : "Sem bloqueio de retry registrado"} />
                  <Info icon={Network} label="Falhas consecutivas"
                    value={String(snapshot?.delivery?.consecutive_failures ?? 0)} />
                </div>
              </section>
              <div className="notice">O diagnóstico é persistido localmente pelo daemon e não comprova conectividade com impressoras. O serviço Rust é independente da janela. Este console ainda não controla instalação, reinicialização ou atualizações do serviço.</div>
            </>
          )}

          {tab === "printers" && (
            <section className="panel">
              <div className="panel-title"><h2>Dispositivos configurados</h2><span>{snapshot?.printers.length ?? 0} registros</span></div>
              <div className="table-scroll"><table>
                <thead><tr><th>ID da impressora</th><th>Endereço IP</th><th>OID de contador</th><th>Estado</th></tr></thead>
                <tbody>
                  {snapshot?.printers.map((printer) => (
                    <tr key={printer.printer_id}>
                      <td>#{printer.printer_id}</td><td>{printer.ip}</td>
                      <td className="mono">{printer.oid}</td><td>Leitura não verificada</td>
                    </tr>
                  ))}
                </tbody>
              </table></div>
              {!snapshot?.printers.length && <div className="empty">Nenhuma impressora configurada neste ponto de coleta.</div>}
            </section>
          )}

          {tab === "quarantine" && (
            <>
              <section className="panel">
                <div className="panel-title">
                  <h2>Leituras isoladas para revisão</h2>
                  <span>{quarantineCount.toLocaleString("pt-BR")} registros · primeiros 25</span>
                </div>
                <div className="table-scroll"><table>
                  <thead><tr><th>UUID da leitura</th><th>Impressora</th><th>Coletada</th><th>Isolada</th><th>Motivo</th></tr></thead>
                  <tbody>
                    {snapshot?.quarantine_preview.map((reading) => (
                      <tr key={reading.sample_id}>
                        <td className="mono">{reading.sample_id}</td>
                        <td>#{reading.printer_id}</td>
                        <td>{formatDate(reading.collected_at)}</td>
                        <td>{formatDate(reading.quarantined_at)}</td>
                        <td>{reading.reason === "individually-rejected-422" ? "Rejeição HTTP 422 individual" : "Requer análise"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table></div>
                {!quarantineCount && (
                  <div className="empty">Nenhuma leitura foi isolada neste ponto de coleta.</div>
                )}
              </section>
              <div className="notice">
                Nenhuma leitura isolada foi apagada ou lançada no faturamento. Após verificar a causa da rejeição,
                um administrador do sistema pode restaurar uma amostra pelo comando
                <code> --restore-quarantined &lt;UUID&gt;</code> do daemon. Esta tela não executa restaurações.
              </div>
            </>
          )}

          {tab === "settings" && (
            <section className="panel">
              <div className="panel-title"><h2>Configuração atual</h2><span>Somente leitura</span></div>
              <div className="config-rows">
                <Info icon={Network} label="Endpoint da API" value={snapshot?.endpoint ?? "Não configurado"} />
                <Info icon={Activity} label="Intervalo de coleta" value={snapshot?.interval_seconds ? snapshot.interval_seconds + " segundos" : "Não configurado"} />
                <Info icon={Database} label="Capacidade da fila" value={snapshot?.queue_capacity ? `${snapshot.queue_capacity.toLocaleString("pt-BR")} amostras` : "Não configurado"} />
                <Info icon={ShieldCheck} label="Identificador do coletor" value={snapshot?.collector_id ?? "Não configurado"} />
              </div>
              <div className="notice">Tokens de acesso e comunidades SNMP nunca são carregados ou exibidos nesta interface.</div>
            </section>
          )}
        </div>
      </main>
    </div>
  );
}

function Metric({ icon: Icon, title, value, description }: {
  icon: typeof Printer; title: string; value: string; description: string;
}) {
  return <article className="metric"><div className="metric-icon"><Icon size={20} aria-hidden="true" /></div>
    <span className="metric-label">{title}</span><strong className="metric-value">{value}</strong>
    <span className="metric-description">{description}</span></article>;
}

function Info({ icon: Icon, label, value }: {
  icon: typeof Printer; label: string; value: string;
}) {
  return <div className="info"><Icon size={18} aria-hidden="true" />
    <div><span>{label}</span><strong>{value}</strong></div></div>;
}
