export interface ClientIdentity {
  user: { id: number; name: string; email?: string };
  tenant: { id: number; name: string; slug?: string };
  role: string;
  customer_scope: 'all' | 'assigned';
  customer_permissions?: { view_reports: boolean; view_other_tickets: boolean };
}
export interface Credentials { email: string; password: string; tenant: string }
export interface Page<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; total: number };
  current_page?: number; last_page?: number; total?: number;
}
export interface Meter {
  meter_total: string | null;
  meter_mono: string | null;
  meter_color: string | null;
  meter_scan: string | null;
  collected_at: string | null;
}
export interface FleetPrinter {
  id: number;
  customer_name: string | null;
  manufacturer: string;
  model: string;
  serial_number: string | null;
  ip_address: string | null;
  status: string;
  active: boolean;
  location: string | null;
  department: string | null;
  cost_center: string | null;
  cost_center_code: string | null;
  latest_reading: Meter | null;
}
export interface FleetSummary {
  printers: number;
  active: number;
  printers_with_readings: number;
  assigned_customers: number;
}
export interface Reading extends Meter {
  id: number;
  received_at: string | null;
}

export interface ClientAnalytics {
  period: { start: string; end: string; timezone: string };
  days: Array<{ date: string; mono: string | null; total: string | null; devices_with_evidence: number }>;
  top_printers: Array<{
    id: number; name: string; ip_address: string | null; mono_observed_delta: string;
  }>;
  method: 'observed_same_day_differences_only';
  not_billing_approved: boolean;
  coverage: string;
}

export type TicketType = 'gaveta_quebrada' | 'painel_atolado' | 'barulho' | 'manchas' | 'toner_reserva' | 'outro';
export interface ClientTicket {
  id: number;
  subject: string;
  description: string;
  category: TicketType;
  status: string;
  priority: string;
  created_at: string | null;
  updated_at: string | null;
  printers: Array<{
    id: number; manufacturer: string; model: string;
    serial_number: string | null; ip_address: string | null;
  }>;
  attachments: Array<{ id: number; name: string; mime_type: string; size: number }>;
}
