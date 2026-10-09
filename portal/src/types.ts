export interface User { id: number; name: string; email?: string }
export interface Tenant { id: number; name: string; slug?: string }
export interface Credentials { email: string; password: string; tenant: string }
export interface CurrentUser { user: User; tenant: Tenant; role: string }
export interface Page<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; total: number };
  current_page?: number;
  last_page?: number;
  total?: number;
}
export interface Dashboard {
  customers: number;
  printers: number;
  printers_with_readings: number;
  locations: number;
  departments: number;
  cost_centers: number;
  note: string;
}
export interface Customer {
  id: number;
  name: string;
  document: string | null;
  email: string | null;
  active: boolean;
  created_at: string;
  updated_at: string;
}
export interface Printer {
  id: number;
  customer_id: number;
  manufacturer: string;
  model: string;
  serial_number: string | null;
  ip_address: string | null;
  status: string;
  active: boolean;
  customer?: { id: number; name: string };
  created_at: string;
  updated_at: string;
}
export interface AuditEntry {
  id: number;
  action: string;
  entity_type: string;
  entity_id: number;
  origin: string;
  actor: { id: number; name: string } | null;
  created_at: string;
}
export type CustomerPayload = Pick<Customer, 'name' | 'document' | 'email'> & { active: boolean };
export type PrinterPayload = Pick<Printer, 'customer_id' | 'manufacturer' | 'model' | 'serial_number' | 'ip_address'>;
