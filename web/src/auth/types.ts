export type Role = 'customer' | 'staff' | 'admin';

export interface Me {
  id: number;
  name: string;
  email: string;
  role: Role;
  company: { id: number; name: string } | null;
}
