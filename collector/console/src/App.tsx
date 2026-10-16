import { invoke } from "@tauri-apps/api/core";
import {
  Activity, AlertTriangle, ArrowDownUp, CheckCircle2, CircleHelp, Database,
  HardDrive, LayoutDashboard, Network, Printer, RefreshCcw, Settings2, ShieldCheck,
} from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type PrinterInfo = { printer_id: number; ip: string; oid: string };
type DiscoveryCandidate = { ip: string; print_ports: number[]; http_server: string | null };
type CanonUsbCandidate = {
  friendly_name: string;
  instance_id: string;
  status: string;
  model_confirmed: boolean;
};
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
type SqliteFootprint = {
  database_bytes: number;
  wal_bytes: number;
  shm_bytes: number;
  total_bytes: number;
  soft_limit_bytes: number;
  soft_limit_reached: boolean;
};
type WatchdogSnapshot = {
  recent_incidents: number;
  circuit_open: boolean;
  retained_incidents: number;
  threshold: number;
  window_minutes: number;
  last_reset_at: string | null;
  incidents: { at: string; worker: "main-loop" | "heartbeat-worker" }[];
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
  payload_bytes_retained: number | null;
  payload_encryption_enabled: boolean | null;
  payload_bytes_limit: number | null;
  sqlite_footprint: SqliteFootprint | null;
  delivery: DeliveryDiagnostics | null;
  watchdog: WatchdogSnapshot | null;
  watchdog_error: string | null;
  last_run: string | null;
  error: string | null;
};
type Tab = "overview" | "printers" | "quarantine" | "watchdog" | "settings";

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
  const [discovered, setDiscovered] = useState<DiscoveryCandidate[] | null>(null);
  const [discovering, setDiscovering] = useState(false);
  const [discoveryError, setDiscoveryError] = useState<string | null>(null);
  const [usbScanners, setUsbScanners] = useState<CanonUsbCandidate[] | null>(null);
  const [usbDiscovering, setUsbDiscovering] = useState(false);
  const [usbError, setUsbError] = useState<string | null>(null);

  const discover = useCallback(async () => {
    setDiscovering(true);
    setDiscoveryError(null);
    try {
      setDiscovered(await invoke<DiscoveryCandidate[]>("discover_local_printers"));
    } catch (error) {
      setDiscoveryError(String(error));
    } finally {
      setDiscovering(false);
    }
  }, []);

  const discoverUsb = useCallback(async () => {
    setUsbDiscovering(true);
    setUsbError(null);
    try {
      setUsbScanners(await invoke<CanonUsbCandidate[]>("discover_canon_usb_scanners"));
    } catch (error) {
      setUsbScanners(null);
      setUsbError(String(error));
    } finally {
      setUsbDiscovering(false);
    }
  }, []);

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
  const retainedBytes = snapshot?.payload_bytes_retained ?? 0;
  const byteLimit = snapshot?.payload_bytes_limit ?? 0;
  const bytesObserved = snapshot?.payload_bytes_retained != null && snapshot?.payload_bytes_limit != null && byteLimit > 0;
  const bytePercent = bytesObserved ? Math.min(100, Math.round(retainedBytes / byteLimit * 100)) : null;
  const byteFull = bytesObserved && retainedBytes >= byteLimit;
  const byteWarning = bytesObserved && retainedBytes >= byteLimit * 0.8;
  const footprint = snapshot?.sqlite_footprint ?? null;
  const physicalLimit = footprint?.soft_limit_bytes ?? 0;
  const physicalUsed = footprint?.total_bytes ?? 0;
  const physicalObserved = footprint !== null && physicalLimit > 0;
  const physicalPercent = physicalObserved ? Math.min(100, Math.round(physicalUsed / physicalLimit * 100)) : null;
  const physicalFull = physicalObserved && footprint.soft_limit_reached;
  const physicalWarning = physicalObserved && physicalUsed >= physicalLimit * 0.8;
  const watchdog = snapshot?.watchdog ?? null;
  const watchdogUnhealthy = Boolean(snapshot?.watchdog_error) || watchdog?.circuit_open === true;
  const queueFull = (queueObserved && retainedCount >= queueCapacity) || byteFull || physicalFull;
  const queueWarning = (queueObserved && retainedCount >= queueCapacity * 0.8) || byteWarning || physicalWarning;

  const menu: { id: Tab; label: string; icon: typeof LayoutDashboard }[] = [
    { id: "overview", label: "Visão geral", icon: LayoutDashboard },
    { id: "printers", label: "Impressoras", icon: Printer },
    { id: "quarantine", label: "Quarentena", icon: AlertTriangle },
    { id: "watchdog", label: "Recuperação", icon: Activity },
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
              <h1>{tab === "overview" ? "Visão geral" : tab === "printers" ? "Impressoras" : tab === "quarantine" ? "Quarentena" : tab === "watchdog" ? "Recuperação" : "Configurações"}</h1>
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
                      aria-valuemin={0} aria-valuemax={queueCapacity} aria-valuenow={Math.min(retainedCount,queueCapacity)}>
                      <div className={queueFull ? "queue-fill full" : queueWarning ? "queue-fill warning" : "queue-fill"}
                        style={{ width: `${queuePercent}%` }} />
                    </div>
                    <p>{retainedCount.toLocaleString("pt-BR")} / {queueCapacity.toLocaleString("pt-BR")} amostras retidas
                      ({queuePending.toLocaleString("pt-BR")} pendentes, {quarantineCount.toLocaleString("pt-BR")} isoladas).</p>
                    {bytesObserved && (
                      <>
                        <div className="queue-track" role="progressbar" aria-label="Orçamento de bytes serializados retidos"
                          aria-valuemin={0} aria-valuemax={byteLimit} aria-valuenow={retainedBytes} style={{ marginTop: 18 }}>
                          <div className={byteFull ? "queue-fill full" : byteWarning ? "queue-fill warning" : "queue-fill"}
                            style={{ width: `${bytePercent}%` }} />
                        </div>
                        <p>{retainedBytes.toLocaleString("pt-BR")} / {byteLimit.toLocaleString("pt-BR")} bytes de payload JSON retido.</p>
                      </>
                    )}
                    {physicalObserved && (
                      <>
                        <div className="queue-track" role="progressbar" aria-label="Uso observado dos arquivos SQLite, WAL e SHM"
                          aria-valuemin={0} aria-valuemax={physicalLimit} aria-valuenow={Math.min(physicalUsed, physicalLimit)}
                          style={{ marginTop: 18 }}>
                          <div className={physicalFull ? "queue-fill full" : physicalWarning ? "queue-fill warning" : "queue-fill"}
                            style={{ width: `${physicalPercent}%` }} />
                        </div>
                        <p>SQLite físico: {physicalUsed.toLocaleString("pt-BR")} / {physicalLimit.toLocaleString("pt-BR")} bytes
                        (DB: {footprint.database_bytes.toLocaleString("pt-BR")}, WAL: {footprint.wal_bytes.toLocaleString("pt-BR")},
                        SHM: {footprint.shm_bytes.toLocaleString("pt-BR")}).</p>
                        <p>Limite preventivo, não uma cota rígida de filesystem. Uma transação pode ultrapassar o limite.</p>
                      </>
                    )}
                  </div>
                </section>
              )}
              {queueWarning && (
                <div className="notice warning" role="status">
                  {physicalFull
                    ? "O tamanho físico observado do SQLite atingiu o limite preventivo. Novas coletas são pausadas; uploads e ACKs permanecem permitidos. A liberação física pode exigir manutenção supervisionada."
                    : queueFull
                      ? "A fila atingiu um limite de quantidade ou payload. Novas coletas ficam suspensas até liberar capacidade."
                      : "A fila está próxima de um limite. Verifique o armazenamento local e as confirmações de upload."}
                  {" "}O console mostra apenas o estado persistido; não comprova a conectividade do equipamento.
                </div>
              )}
              {watchdogUnhealthy && (
                <div className="notice error" role="alert">
                  {snapshot?.watchdog_error
                    ? snapshot.watchdog_error
                    : "Circuito de proteção aberto: o Collector registrou reinicializações por travamento em excesso. Inspecione a aba Recuperação."}
                </div>
              )}
              {watchdog && watchdog.recent_incidents > 0 && !watchdog.circuit_open && (
                <div className="notice warning" role="status">
                  {watchdog.recent_incidents} incidente(s) de watchdog nos últimos {watchdog.window_minutes} minutos.
                  Consulte a aba Recuperação antes de considerar a estação saudável.
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
                  <Info icon={ShieldCheck} label="Payload das leituras em repouso"
                    value={snapshot?.payload_encryption_enabled === true ? "AES-256-GCM habilitado" : snapshot?.payload_encryption_enabled === false ? "Sem criptografia de payload" : "Não verificado"} />
                  <Info icon={ArrowDownUp} label="Transmissão" value="Verificar logs do serviço" />
                  <Info icon={CircleHelp} label="Serviço do sistema" value="Processo não inspecionado pelo console" />
                  <Info icon={Activity} label="Circuito do watchdog" value={snapshot?.watchdog_error ? "Histórico inválido/inacessível"
                    : watchdog ? watchdog.circuit_open ? "Bloqueado (requer análise)" : "Sem bloqueio por incidentes" : "Não verificado"} />
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
            <>
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
            <section className="panel">
              <div className="panel-title"><h2>Descoberta na rede local</h2><span>Busca limitada às interfaces privadas deste computador</span></div>
              <p>Portas de impressão acessíveis são indícios de equipamento. Confirme modelo, cliente e OID de contador antes do cadastro.</p>
              <button type="button" className="primary-button" onClick={() => void discover()} disabled={discovering}>
                <Network size={16} aria-hidden="true" />{discovering ? "Buscando..." : "Buscar impressoras"}
              </button>
              {discoveryError && <div className="notice error" role="alert">{discoveryError}</div>}
              {discovered && <div className="table-scroll"><table>
                <thead><tr><th>Endereço IP</th><th>Portas de impressão</th><th>Servidor HTTP</th><th>Estado</th></tr></thead>
                <tbody>{discovered.map((candidate) => <tr key={candidate.ip}>
                  <td className="mono">{candidate.ip}</td>
                  <td>{candidate.print_ports.join(", ")}</td>
                  <td>{candidate.http_server ?? "Não identificado"}</td>
                  <td>Aguardando validação</td>
                </tr>)}</tbody>
              </table></div>}
              {discovered?.length === 0 && <div className="empty">Nenhum serviço de impressão respondeu nesta busca.</div>}
            </section>
            <section className="panel">
              <div className="panel-title"><h2>Scanners Canon por USB</h2><span>Diagnóstico local · Windows</span></div>
              <p>Busca apenas scanners conectados com identificação Canon DR-C225. A detecção não confirma o modelo II nem habilita a leitura dos contadores de digitalização.</p>
              <button type="button" className="primary-button" onClick={() => void discoverUsb()} disabled={usbDiscovering}>
                <RefreshCcw size={16} aria-hidden="true" />{usbDiscovering ? "Consultando USB..." : "Detectar scanners Canon"}
              </button>
              {usbError && <div className="notice error" role="alert">{usbError}</div>}
              {usbScanners && <div className="table-scroll"><table>
                <thead><tr><th>Dispositivo</th><th>Windows</th><th>Modelo</th><th>Contadores</th></tr></thead>
                <tbody>{usbScanners.map((candidate) => <tr key={candidate.instance_id}>
                  <td>{candidate.friendly_name}</td>
                  <td>{candidate.status}</td>
                  <td>{candidate.model_confirmed ? "DR-C225 II indicado pelo driver" : "Revisão não confirmada"}</td>
                  <td>Não homologados — sem leitura automática</td>
                </tr>)}</tbody>
              </table></div>}
              {usbScanners?.length === 0 && <div className="empty">Nenhum DR-C225 USB Canon conectado foi identificado. Verifique cabo e driver.</div>}
              <div className="notice">Este diagnóstico não inicia digitalizações, não zera roletes e não envia medições ao servidor.</div>
            </section>
            </>
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

          {tab === "watchdog" && (
            <>
              <section className="panel" aria-label="Estado do circuito de recuperação">
                <div className="panel-title">
                  <h2>Proteção contra reinicializações repetidas</h2>
                  <span>Diário local · somente leitura</span>
                </div>
                {snapshot?.watchdog_error && <div className="notice error" role="alert">{snapshot.watchdog_error}</div>}
                {!snapshot?.watchdog_error && !watchdog && (
                  <div className="empty">Diário não disponível. Configure o caminho do banco local para consultar o estado.</div>
                )}
                {watchdog && (
                  <div className="detail-grid">
                    <Info icon={ShieldCheck} label="Circuito de recuperação"
                      value={watchdog.circuit_open ? "Aberto — serviço bloqueado" : "Sem bloqueio registrado"} />
                    <Info icon={Activity} label="Incidentes na janela"
                      value={`${watchdog.recent_incidents} / ${watchdog.threshold} em ${watchdog.window_minutes} minutos`} />
                    <Info icon={Database} label="Histórico local retido"
                      value={`${watchdog.retained_incidents} eventos (máximo 32)`} />
                    <Info icon={CheckCircle2} label="Último reconhecimento administrativo"
                      value={watchdog.last_reset_at ? formatDate(watchdog.last_reset_at) : "Ainda não realizado"} />
                  </div>
                )}
              </section>
              {watchdog && (
                <section className="panel">
                  <div className="panel-title"><h2>Histórico de incidentes</h2>
                    <span>Informações persistidas pelo watchdog</span></div>
                  <div className="table-scroll"><table>
                    <thead><tr><th>Data e hora</th><th>Componente</th><th>Tipo</th></tr></thead>
                    <tbody>
                      {[...watchdog.incidents].reverse().map((incident, index) => (
                        <tr key={incident.at + incident.worker + index}>
                          <td>{formatDate(incident.at)}</td>
                          <td>{incident.worker === "main-loop" ? "Coleta / transmissão" : "Heartbeat"}</td>
                          <td>Ausência de progresso</td>
                        </tr>
                      ))}
                    </tbody>
                  </table></div>
                  {watchdog.incidents.length === 0 && <div className="empty">Nenhum incidente registrado neste diário local.</div>}
                </section>
              )}
              <div className="notice">
                Este painel apenas consulta o histórico no disco; não verifica se o serviço está executando, não reinicia
                o Collector e não reconhece automaticamente incidentes. Para liberar um bloqueio após análise da causa,
                pare o serviço e use o comando administrativo <code>--reset-watchdog-circuit</code> do daemon.
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
                <Info icon={HardDrive} label="Orçamento JSON armazenado" value={snapshot?.payload_bytes_limit ? `${snapshot.payload_bytes_limit.toLocaleString("pt-BR")} bytes` : "Não configurado"} />
                <Info icon={HardDrive} label="Limite preventivo DB + WAL + SHM" value={snapshot?.sqlite_footprint ? `${snapshot.sqlite_footprint.soft_limit_bytes.toLocaleString("pt-BR")} bytes` : "Não verificado"} />
                <Info icon={ShieldCheck} label="Criptografia da outbox" value={snapshot?.payload_encryption_enabled === true ? "AES-256-GCM ativo" : snapshot?.payload_encryption_enabled === false ? "Texto claro (legado)" : "Não verificada"} />
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
