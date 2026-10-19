export interface Person { id: number; name: string }
export interface Customer { id: number; name: string }
export interface Printer {
  id: number; manufacturer: string; model: string;
  serial_number?: string | null; ip_address?: string | null;
}
export type TicketStatus = 'triage'|'unassigned'|'pending'|'in_progress'|'resolved'|'closed';
export interface StockItem { id: number; name: string; sku: string; unit: string }
export interface PartRequest {
  id: number; stock_item_id: number; quantity: number; reason: string;
  status: 'pending'|'approved'|'rejected'; created_at: string;
  item?: Pick<StockItem,'id'|'sku'|'name'> | null;
}
export interface Signature {
  signer_name: string; service_summary: string; sha256: string; signed_at: string;
}
export interface Ticket {
  id: number; subject: string; description: string; priority: string; status: TicketStatus;
  assigned_user_id: number; created_at: string;
  customer: Customer | null; printer: Printer | null;
  part_requests?: PartRequest[]; signature?: Signature | null;
}
export interface LoginResponse {
  token: string; expires_at: string;
  user: Person; tenant: { id: number; name: string; slug: string };
}
export interface Page<T> { data: T[]; meta?: {current_page:number;last_page:number;total:number} }
