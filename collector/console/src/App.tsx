import { invoke } from "@tauri-apps/api/core";
import {
  Activity, AlertTriangle, ArrowDownUp, CheckCircle2, CircleHelp, Database,
  HardDrive, LayoutDashboard, Network, Printer, RefreshCcw, Settings2, ShieldCheck, Usb, Wrench,
} from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type PrinterInfo = {
  printer_id: number; ip: string; oid: string;
  monitored: boolean; last_tested_at: string | null;
  manufacturer: string | null; model: string | null; serial: string | null; mac: string | null;
  reference_model_id: number | null; auto_detect_reference: boolean;
};
type PrinterCommand =
  | { action: "add"; printer_id: number; ip: string; oid: number[]; auto_detect_reference: boolean }
  | { action: "monitor" | "disable" | "remove"; printer_id: number };
type ReferenceStatus = "not_tested" | "approved" | "incompatible";
type UsbScannerInfo = { printer_id: number; profile: string };
type DiscoveryCandidate = { ip: string; print_ports: number[]; http_server: string | null; escl_scanner: boolean };
type UsbDeviceCandidate = {
  friendly_name: string;
  instance_id: string;
  status: string;
  device_type: "printer" | "scanner" | "imaging";
  vid_pid: string | null;
  reference_model_ids: number[];
  reference_usb_status: ReferenceStatus | null;
};
type CanonDrc225Meter = {
  meter_scan_total: string;
  roller_count_resettable: string;
  collected_at: string;
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
type CandidateReview = {
  candidate: DiscoveryCandidate;
  decision: "saved" | "ignored";
  decided_at: string;
};
function sameObservedEvidence(a: DiscoveryCandidate, b: DiscoveryCandidate): boolean {
  return a.ip === b.ip
    && a.escl_scanner === b.escl_scanner
    && a.http_server === b.http_server
    && a.print_ports.join(",") === b.print_ports.join(",");
}
function candidateReviewStatus(candidate: DiscoveryCandidate, reviews: CandidateReview[]): string {
  const reviewed = reviews.find(item => item.candidate.ip === candidate.ip);
  if (!reviewed) return "Aguardando seleção local";
  if (!sameObservedEvidence(candidate, reviewed.candidate)) return "Revisar: evidência mudou";
  return reviewed.decision === "saved" ? "Salvo localmente (não monitorado)" : "Ignorado pelo operador";
}
type DiscoverySchedule = { enabled: boolean; interval_hours: number; cidrs: string[] };
type DiscoveryReport = {
  last_completed_at: string | null;
  last_error_kind: string | null;
  observed_candidates: number;
  candidates: DiscoveryCandidate[];
};
type CollectorSnapshot = {
  configured: boolean;
  collector_id: string | null;
  endpoint: string | null;
  communication_mode: string | null;
  interval_seconds: number | null;
  authorized_subnets: string[];
  discovery_schedule: DiscoverySchedule | null;
  discovery_report: DiscoveryReport | null;
  candidate_reviews: CandidateReview[];
  candidate_reviews_error: string | null;
  local_control_supported: boolean;
  schedule_control_supported: boolean;
  printers: PrinterInfo[];
  usb_scanners: UsbScannerInfo[];
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
type Tab = "overview" | "printers" | "tools" | "quarantine" | "watchdog" | "settings";
type ToolMode = "network" | "usb" | null;

function formatDate(value: string | null): string {
  if (!value) return "Nenhuma execução registrada";
  const timestamp = new Date(value);
  if (Number.isNaN(timestamp.getTime())) return "Data inválida";
  return new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "medium" }).format(timestamp);
}

function referenceStatus(value: ReferenceStatus): string {
  return value === "approved" ? "Homologado no PrintWayy"
    : value === "incompatible" ? "Incompatível no PrintWayy" : "Não testado no PrintWayy";
}

export default function App() {
  const [tab, setTab] = useState<Tab>("overview");
  const [toolMode, setToolMode] = useState<ToolMode>(null);
  const [snapshot, setSnapshot] = useState<CollectorSnapshot | null>(null);
  const [loading, setLoading] = useState(false);
  const [requestError, setRequestError] = useState<string | null>(null);
  const [discovered, setDiscovered] = useState<DiscoveryCandidate[] | null>(null);
  const [discovering, setDiscovering] = useState(false);
  const [discoveryError, setDiscoveryError] = useState<string | null>(null);
  const [rangeDraft, setRangeDraft] = useState("");
  const [rangeDiscovering, setRangeDiscovering] = useState(false);
  const [rangeError, setRangeError] = useState<string | null>(null);
  const [scheduleEnabled, setScheduleEnabled] = useState(false);
  const [scheduleHours, setScheduleHours] = useState(24);
  const [scheduleRanges, setScheduleRanges] = useState("");
  const [scheduleSaving, setScheduleSaving] = useState(false);
  const [scheduleError, setScheduleError] = useState<string | null>(null);
  const [scheduleNotice, setScheduleNotice] = useState<string | null>(null);
  const [reviewingIp, setReviewingIp] = useState<string | null>(null);
  const [reviewError, setReviewError] = useState<string | null>(null);
  const [reviewNotice, setReviewNotice] = useState<string | null>(null);
  const [usbDevices, setUsbDevices] = useState<UsbDeviceCandidate[] | null>(null);
  const [usbDiscovering, setUsbDiscovering] = useState(false);
  const [usbError, setUsbError] = useState<string | null>(null);
  const [canonMeter, setCanonMeter] = useState<CanonDrc225Meter | null>(null);
  const [canonReading, setCanonReading] = useState(false);
  const [canonError, setCanonError] = useState<string | null>(null);
  const [printerFilter, setPrinterFilter] = useState("");
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [printerBusy, setPrinterBusy] = useState(false);
  const [printerError, setPrinterError] = useState<string | null>(null);
  const [printerNotice, setPrinterNotice] = useState<string | null>(null);
  const [addingPrinter, setAddingPrinter] = useState(false);
  const [removeArmed, setRemoveArmed] = useState(false);
  const [expandedPrinter, setExpandedPrinter] = useState<number | null>(null);
  const [newPrinterId, setNewPrinterId] = useState("");
  const [newPrinterIp, setNewPrinterIp] = useState("");
  const [newPrinterOid, setNewPrinterOid] = useState("");
  const [autoDetectOid, setAutoDetectOid] = useState(true);
  const [broadcastBusy, setBroadcastBusy] = useState(false);
  const [broadcastError, setBroadcastError] = useState<string | null>(null);
  const [broadcastNotice, setBroadcastNotice] = useState<string | null>(null);
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

  const discoverRanges = useCallback(async () => {
    setRangeDiscovering(true);
    setRangeError(null);
    const cidrs = rangeDraft.split(/[\s,;]+/).map(value => value.trim()).filter(Boolean);
    try {
      const candidates = await invoke<DiscoveryCandidate[]>("discover_authorized_printers", { cidrs });
      setDiscovered(candidates);
      setDiscoveryError(null);
    } catch (error) {
      setRangeError(String(error));
    } finally {
      setRangeDiscovering(false);
    }
  }, [rangeDraft]);

  const discoverUsb = useCallback(async () => {
    setUsbDiscovering(true);
    setUsbError(null);
    try {
      setUsbDevices(await invoke<UsbDeviceCandidate[]>("discover_usb_printers"));
    } catch (error) {
      setUsbDevices(null);
      setUsbError(String(error));
    } finally {
      setUsbDiscovering(false);
    }
  }, []);

  const readCanon = useCallback(async () => {
    setCanonReading(true);
    setCanonError(null);
    try {
      setCanonMeter(await invoke<CanonDrc225Meter>("read_canon_drc225_meter"));
    } catch (error) {
      setCanonMeter(null);
      setCanonError(String(error));
    } finally {
      setCanonReading(false);
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
  useEffect(() => {
    if (!snapshot?.discovery_schedule) return;
    setScheduleEnabled(snapshot.discovery_schedule.enabled);
    setScheduleHours(snapshot.discovery_schedule.interval_hours);
    setScheduleRanges(snapshot.discovery_schedule.cidrs.join("\n"));
  }, [snapshot]);

  const saveSchedule = useCallback(async () => {
    setScheduleError(null);
    setScheduleNotice(null);
    const cidrs = scheduleRanges.split(/[\s,;]+/).filter(Boolean);
    if (!Number.isInteger(scheduleHours) || scheduleHours < 6 || scheduleHours > 168) {
      setScheduleError("A frequência deve ser um número inteiro entre 6 e 168 horas.");
      return;
    }
    if (cidrs.length > 4 || (scheduleEnabled && cidrs.length === 0)) {
      setScheduleError("Defina entre 1 e 4 faixas IPv4/CIDR ao ativar a agenda.");
      return;
    }
    setScheduleSaving(true);
    try {
      await invoke<void>("save_discovery_schedule", {
        schedule: { enabled: scheduleEnabled, interval_hours: scheduleHours, cidrs },
      });
      await refresh();
      setScheduleNotice("Agenda salva no serviço local. A nova configuração será aplicada na próxima verificação.");
    } catch (error) {
      setScheduleError(String(error));
    } finally {
      setScheduleSaving(false);
    }
  }, [scheduleEnabled, scheduleHours, scheduleRanges, refresh]);

  const reviewCandidate = useCallback(async (
    ip: string, action: "save" | "ignore" | "reset",
  ) => {
    setReviewingIp(ip);
    setReviewError(null);
    setReviewNotice(null);
    try {
      await invoke<void>("review_local_candidate", { ip, action });
      await refresh();
      setReviewNotice(`Decisão local atualizada para ${ip}. Isto não ativa monitoramento.`);
    } catch (error) {
      setReviewError(String(error));
    } finally {
      setReviewingIp(null);
    }
  }, [refresh]);

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
    { id: "tools", label: "Ferramentas", icon: Wrench },
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
          <div className="version">DESKTOP 0.1.2</div>
        </header>
        <div className="content">
          <div className="heading">
            <div>
              <p className="eyebrow">CONTROLE DA ESTAÇÃO</p>
              <h1>{tab === "overview" ? "Visão geral" : tab === "printers" ? "Impressoras" : tab === "tools" ? "Ferramentas" : tab === "quarantine" ? "Quarentena" : tab === "watchdog" ? "Recuperação" : "Configurações"}</h1>
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
                <Metric icon={Printer} title="Equipamentos" value={snapshot ? String(snapshot.printers.length + snapshot.usb_scanners.length) : "—"}
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
              <div className="panel-title"><h2>Dispositivos configurados</h2><span>{snapshot ? snapshot.printers.length + snapshot.usb_scanners.length : 0} registros</span></div>
              <div className="table-scroll"><table>
                <thead><tr><th>ID do equipamento</th><th>Conexão</th><th>Perfil de leitura</th><th>Estado</th></tr></thead>
                <tbody>
                  {snapshot?.printers.map((printer) => (
                    <tr key={`ip-${printer.printer_id}`}>
                      <td>#{printer.printer_id}</td><td>{printer.ip}</td>
                        <td className="mono">SNMP: {printer.oid}{printer.reference_model_id ? ` · Ref. #${printer.reference_model_id}` : ""}</td><td>{printer.auto_detect_reference ? "Nome SNMP comparado a cada coleta; contador depende de validação" : "Contador depende de validação no Nexa"}</td>
                    </tr>
                  ))}
                  {snapshot?.usb_scanners.map((scanner) => (
                    <tr key={`usb-${scanner.printer_id}`}>
                      <td>#{scanner.printer_id}</td><td>USB local</td>
                      <td className="mono">{scanner.profile}</td><td>Leitura experimental em sessão interativa</td>
                    </tr>
                  ))}
                </tbody>
              </table></div>
              {snapshot && snapshot.printers.length + snapshot.usb_scanners.length === 0 && <div className="empty">Nenhum equipamento configurado neste ponto de coleta.</div>}
            </section>
            <section className="panel">
              <div className="panel-title"><h2>Candidatos da última varredura programada</h2>
                <span>Relatório persistido na fila local</span></div>
              <p style={{ padding: "0 24px" }}>Última conclusão: {formatDate(snapshot?.discovery_report?.last_completed_at ?? null)}.
                A busca encontrou {snapshot?.discovery_report?.observed_candidates ?? 0} candidato(s); o histórico retém até 200.</p>
              {snapshot?.discovery_report?.last_error_kind && <div role="alert" className="notice error">
                A última busca terminou com falha ({snapshot.discovery_report.last_error_kind}). Os resultados anteriores não são tratados como atuais.
              </div>}
              <div className="table-scroll"><table><thead><tr><th>IP</th><th>Portas</th><th>eSCL</th><th>Estado</th><th>Revisar</th></tr></thead>
                <tbody>{snapshot?.discovery_report?.candidates.map(candidate => (
                  <tr key={candidate.ip}><td className="mono">{candidate.ip}</td>
                    <td>{candidate.print_ports.join(", ") || "—"}</td>
                    <td>{candidate.escl_scanner ? "Detectado" : "—"}</td>
                    <td>{candidateReviewStatus(candidate, snapshot?.candidate_reviews ?? [])}</td>
                    <td><div className="candidate-actions">
                      <button type="button" className="secondary-button"
                        onClick={() => void reviewCandidate(candidate.ip, "save")}
                        disabled={!snapshot?.local_control_supported || reviewingIp !== null}
                        aria-label={`Salvar candidato ${candidate.ip}`}>Salvar</button>
                      <button type="button" className="secondary-button"
                        onClick={() => void reviewCandidate(candidate.ip, "ignore")}
                        disabled={!snapshot?.local_control_supported || reviewingIp !== null}
                        aria-label={`Ignorar candidato ${candidate.ip}`}>Ignorar</button>
                    </div></td></tr>
                ))}</tbody>
              </table></div>
              {snapshot?.candidate_reviews_error && <div className="notice error" role="alert">
                {snapshot.candidate_reviews_error}
              </div>}
              <div className="panel-title"><h2>Decisões locais do operador</h2>
                <span>Somente leitura · máximo de 200 decisões</span></div>
              <div className="table-scroll"><table>
                <thead><tr><th>IP</th><th>Decisão</th><th>Data da decisão</th><th>Validação</th><th>Ação</th></tr></thead>
                <tbody>{snapshot?.candidate_reviews.map(review => {
                  const recent = snapshot?.discovery_report?.candidates.find(
                    item => item.ip === review.candidate.ip
                  );
                  const current = recent && sameObservedEvidence(recent, review.candidate)
                    && !snapshot?.discovery_report?.last_error_kind;
                  return <tr key={review.candidate.ip}>
                    <td className="mono">{review.candidate.ip}</td>
                    <td>{review.decision === "saved" ? "Salvo" : "Ignorado"}</td>
                    <td>{formatDate(review.decided_at)}</td>
                    <td>{current ? "Observação correspondente" : "Revisar: ausente ou evidência mudou"}</td>
                    <td><button type="button" className="secondary-button"
                      disabled={!snapshot?.local_control_supported || reviewingIp !== null}
                      onClick={() => void reviewCandidate(review.candidate.ip, "reset")}
                      aria-label={`Restaurar candidato ${review.candidate.ip}`}>Restaurar</button></td>
                  </tr>;
                })}</tbody>
              </table></div>
              {!snapshot?.candidate_reviews.length && <div className="empty">
                Nenhuma decisão local registrada. Os candidatos permanecem sem aprovação.
              </div>}
              {reviewError && <div role="alert" className="notice error">{reviewError}</div>}
              {reviewNotice && <div role="status" className="notice">{reviewNotice}</div>}
              <div className="notice">
                {snapshot?.local_control_supported
                  ? "As decisões exigem acesso ao canal privado do serviço. Em caso de acesso negado, utilize uma conta do sistema operacional autorizada."
                  : "A edição por Console está bloqueada neste sistema até existir transporte nativo com permissões homologadas."}
                {" "}Salvar/ignorar não homologa contadores, não reserva IP e não ativa monitoramento.
                Verifique modelo, número de série e OID antes de habilitar qualquer equipamento.
              </div>
              {!snapshot?.discovery_report?.candidates.length && <div className="empty">
                Nenhum candidato disponível nesta varredura. Confira se a agenda foi habilitada pelo administrador.
              </div>}
            </section>

            </>
          )}


          {tab === "tools" && (
            <>
              <section className="tool-selector" aria-label="Ferramentas de descoberta">
                <button type="button" className={`tool-card ${toolMode === "network" ? "selected" : ""}`}
                  aria-pressed={toolMode === "network"} onClick={() => setToolMode("network")}>
                  <Network size={30} aria-hidden="true" />
                  <strong>Busca de impressoras na rede</strong>
                  <span>Localize candidatos em sub-redes autorizadas.</span>
                </button>
                <button type="button" className={`tool-card ${toolMode === "usb" ? "selected" : ""}`}
                  aria-pressed={toolMode === "usb"} onClick={() => setToolMode("usb")}>
                  <Usb size={30} aria-hidden="true" />
                  <strong>Busca de impressoras USB</strong>
                  <span>Encontre impressoras, scanners e dispositivos de imagem conectados.</span>
                </button>
              </section>
              {toolMode === null && (
                <div className="notice" role="status">
                  Escolha uma ferramenta para iniciar a descoberta. Nenhum dispositivo será cadastrado ou monitorado automaticamente.
                </div>
              )}
              {toolMode === "network" && (
                <div className="tool-workspace">
                  <section className="panel">
              <div className="panel-title"><h2>Descoberta na rede local</h2><span>Busca limitada às interfaces privadas deste computador</span></div>
              <p>Portas de impressão e capacidades eSCL são indícios de equipamento. Confirme modelo, cliente e medidores antes do cadastro.</p>
              <button type="button" className="primary-button" onClick={() => void discover()} disabled={discovering}>
                <Network size={16} aria-hidden="true" />{discovering ? "Buscando..." : "Buscar impressoras e scanners"}
              </button>
              {discoveryError && <div className="notice error" role="alert">{discoveryError}</div>}
              {discovered && <div className="table-scroll"><table>
                <thead><tr><th>Endereço IP</th><th>Portas de impressão</th><th>Scanner eSCL</th><th>Servidor HTTP</th><th>Estado</th></tr></thead>
                <tbody>{discovered.map((candidate) => <tr key={candidate.ip}>
                  <td className="mono">{candidate.ip}</td>
                  <td>{candidate.print_ports.length ? candidate.print_ports.join(", ") : "Nenhuma"}</td>
                  <td>{candidate.escl_scanner ? "Capacidades acessíveis" : "Não identificado"}</td>
                  <td>{candidate.http_server ?? "Não identificado"}</td>
                  <td>Aguardando validação</td>
                </tr>)}</tbody>
              </table></div>}
              {discovered?.length === 0 && <div className="empty">Nenhum serviço de impressão ou scanner eSCL respondeu nesta busca.</div>}
            </section>
                  <div className="discovery-settings">
                <h3>Busca manual por faixa autorizada</h3>
                <p>Informe até quatro faixas IPv4/CIDR privadas, separadas por vírgula ou linha.
                  Cada faixa deve caber em /24 e estar integralmente contida nas sub-redes já autorizadas pelo administrador.</p>
                <label htmlFor="manual-discovery-cidrs">Faixas para esta varredura</label>
                <textarea id="manual-discovery-cidrs" rows={3} value={rangeDraft}
                  placeholder="192.168.1.0/24" spellCheck={false} autoComplete="off"
                  onChange={event => setRangeDraft(event.target.value)} maxLength={256} />
                <button type="button" className="primary-button" onClick={() => void discoverRanges()}
                  disabled={rangeDiscovering || !snapshot?.configured || !rangeDraft.trim()}>
                  <Network size={16} aria-hidden="true" />
                  {rangeDiscovering ? "Verificando faixas..." : "Buscar apenas nas faixas informadas"}
                </button>
                {rangeError && <div className="notice error" role="alert">{rangeError}</div>}
                <p>Resultados aparecem em Impressoras apenas como candidatos. Esta consulta não altera o cadastro
                  e não habilita monitoramento. Faixas fora do escopo autorizado continuam bloqueadas pelo serviço.</p>
              </div>
                  <div className="notice">Busca por portas de impressão/eSCL não é leitura SNMP e não comprova fabricante, modelo, número de série ou contadores. Credenciais SNMP não são expostas nesta tela; a homologação depende de inspeção autorizada com o daemon Rust.</div>
                </div>
              )}
              {toolMode === "usb" && (
                <div className="tool-workspace">
                  <section className="panel">
              <div className="panel-title"><h2>Impressoras e dispositivos USB</h2><span>Descoberta local · Windows</span></div>
              <p>Identifica impressoras e dispositivos de imagem conectados por USB, de qualquer fabricante. A identificação não confirma suporte à leitura de contadores.</p>
              <button type="button" className="primary-button" onClick={() => void discoverUsb()} disabled={usbDiscovering} aria-label="Detectar impressoras USB">
                <RefreshCcw size={16} aria-hidden="true" />{usbDiscovering ? "Consultando USB..." : "Detectar impressoras USB"}
              </button>
              {usbError && <div className="notice error" role="alert">{usbError}</div>}
              {usbDevices && <div className="table-scroll"><table>
                <thead><tr><th>Dispositivo</th><th>Tipo</th><th>VID:PID</th><th>Windows</th><th>Ref. PrintWayy</th><th>Contadores Nexa</th></tr></thead>
                <tbody>{usbDevices.map((candidate) => <tr key={candidate.instance_id}>
                  <td>{candidate.friendly_name}</td>
                  <td>{candidate.device_type === "printer" ? "Impressora" : candidate.device_type === "scanner" ? "Scanner" : "Dispositivo de imagem"}</td>
                  <td className="mono">{candidate.vid_pid ?? "Não informado"}</td>
                  <td>{candidate.status}</td>
                  <td>{candidate.reference_usb_status ? `${referenceStatus(candidate.reference_usb_status)} · ID ${candidate.reference_model_ids.join(", ")}` : "Sem correspondência inequívoca pelo nome"}</td>
                  <td>{candidate.device_type === "scanner" && candidate.vid_pid === "1083:1658" && candidate.friendly_name.toUpperCase() === "CANON DR-C225 USB" ? "Total Canon: leitura local experimental" : "Leitura não homologada"}</td>
                </tr>)}</tbody>
              </table></div>}
              {usbDevices?.length === 0 && <div className="empty">Nenhuma impressora ou dispositivo de imagem USB presente foi identificado. Verifique cabo, driver e conexão.</div>}
              {usbDevices?.some(candidate => candidate.device_type === "scanner" && candidate.vid_pid === "1083:1658" && candidate.friendly_name.toUpperCase() === "CANON DR-C225 USB") && <>
                <button type="button" className="primary-button" onClick={() => void readCanon()} disabled={canonReading}>
                  <RefreshCcw size={16} aria-hidden="true" />{canonReading ? "Lendo contador Canon..." : "Ler total do scanner Canon"}
                </button>
                {canonError && <div className="notice error" role="alert">{canonError}</div>}
                {canonMeter && <div className="notice" role="status">
                  Total do scanner: {BigInt(canonMeter.meter_scan_total).toLocaleString("pt-BR")} · Rolos atuais (reiniciável): {BigInt(canonMeter.roller_count_resettable).toLocaleString("pt-BR")} · {formatDate(canonMeter.collected_at)}
                </div>}
              </>}
              <div className="notice">Detecção local somente leitura. Equipamentos não são cadastrados automaticamente e nenhuma medição é transmitida nesta etapa.</div>
            </section>
                </div>
              )}
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
              <div className="panel-title"><h2>Configuração atual</h2><span>Faixas definidas pelo administrador</span></div>
              <div className="config-rows">
                <Info icon={Network} label="Comunicação com o servidor" value={snapshot?.communication_mode ?? "Não configurada"} />
                <Info icon={Network} label="Destino das leituras" value={snapshot?.endpoint || "Não configurado"} />
                <Info icon={Activity} label="Intervalo de coleta" value={snapshot?.interval_seconds ? snapshot.interval_seconds + " segundos" : "Não configurado"} />
                <Info icon={Database} label="Capacidade da fila" value={snapshot?.queue_capacity ? `${snapshot.queue_capacity.toLocaleString("pt-BR")} amostras` : "Não configurado"} />
                <Info icon={HardDrive} label="Orçamento JSON armazenado" value={snapshot?.payload_bytes_limit ? `${snapshot.payload_bytes_limit.toLocaleString("pt-BR")} bytes` : "Não configurado"} />
                <Info icon={HardDrive} label="Limite preventivo DB + WAL + SHM" value={snapshot?.sqlite_footprint ? `${snapshot.sqlite_footprint.soft_limit_bytes.toLocaleString("pt-BR")} bytes` : "Não verificado"} />
                <Info icon={ShieldCheck} label="Criptografia da outbox" value={snapshot?.payload_encryption_enabled === true ? "AES-256-GCM ativo" : snapshot?.payload_encryption_enabled === false ? "Texto claro (legado)" : "Não verificada"} />
                <Info icon={ShieldCheck} label="Identificador do coletor" value={snapshot?.collector_id ?? "Não configurado"} />
                <Info icon={Network} label="Sub-redes autorizadas" value={snapshot?.authorized_subnets?.join(", ") || "Nenhuma faixa autorizada"} />
                <Info icon={Activity} label="Descoberta programada" value={snapshot?.discovery_schedule?.enabled
                  ? `Ativa a cada ${snapshot.discovery_schedule.interval_hours} horas` : "Desabilitada"} />
                <Info icon={Network} label="Faixas programadas" value={snapshot?.discovery_schedule?.cidrs.join(", ") || "Nenhuma"} />
                <Info icon={Activity} label="Última busca programada"
                  value={formatDate(snapshot?.discovery_report?.last_completed_at ?? null)} />
              </div>
              <div className="discovery-settings">
                <h3>Agenda de descoberta automática</h3>
                <p>Somente faixas contidas nas sub-redes autorizadas pelo administrador podem ser programadas. A configuração é salva no serviço local protegido, sem modificar credenciais.</p>
                <label className="schedule-switch" htmlFor="schedule-enabled">
                  <input id="schedule-enabled" type="checkbox" checked={scheduleEnabled}
                    onChange={event => setScheduleEnabled(event.target.checked)}
                    disabled={!snapshot?.schedule_control_supported || scheduleSaving} />
                  Ativar busca periódica
                </label>
                <label htmlFor="schedule-hours">Frequência (6 a 168 horas)</label>
                <input id="schedule-hours" type="number" min={6} max={168} step={1}
                  value={scheduleHours} onChange={event => setScheduleHours(Number(event.target.value))}
                  disabled={!snapshot?.schedule_control_supported || scheduleSaving} />
                <label htmlFor="schedule-cidrs">Faixas IPv4/CIDR programadas (máximo 4)</label>
                <textarea id="schedule-cidrs" rows={3} spellCheck={false} value={scheduleRanges}
                  placeholder="192.168.1.0/24" maxLength={256}
                  onChange={event => setScheduleRanges(event.target.value)}
                  disabled={!snapshot?.schedule_control_supported || scheduleSaving} />
                <button type="button" className="primary-button" onClick={() => void saveSchedule()}
                  disabled={!snapshot?.configured || !snapshot?.schedule_control_supported || scheduleSaving}>
                  <ShieldCheck size={16} aria-hidden="true" />
                  {scheduleSaving ? "Salvando..." : "Salvar agenda no Collector"}
                </button>
                {scheduleError && <div role="alert" className="notice error">{scheduleError}</div>}
                {scheduleNotice && <div role="status" className="notice">{scheduleNotice}</div>}
                <p>Permissões locais do serviço são obrigatórias. No Windows, a edição da agenda permanece desabilitada nesta etapa. Desativar a agenda impede novas varreduras, mas uma busca já iniciada pode concluir suas conexões.</p>
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
